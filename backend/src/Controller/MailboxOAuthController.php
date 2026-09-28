<?php

namespace App\Controller;

use App\Entity\Mailbox;
use App\Mailbox\MailProviderDetector;
use App\Mailbox\OAuth\OAuthClient;
use App\Mailbox\OAuth\OAuthException;
use App\Mailbox\OAuth\OAuthProvider;
use App\Mailbox\OAuth\OAuthSettings;
use App\Mailbox\OAuth\OAuthState;
use App\Mailbox\OAuth\OAuthTokens;
use App\Repository\MailboxRepository;
use Doctrine\ORM\EntityManagerInterface;
use Rocket\Core\Entity\User;
use Rocket\Core\Security\ActorContext;
use Rocket\Core\Security\Roles;
use Rocket\Core\Suite\SuiteSettings;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * OAuth mailboxes (Google, Microsoft):
 * - POST /api/mailboxes/oauth/{provider}/start (user, or application impersonating them): the consent URL;
 * - GET /api/mailboxes/oauth/callback (public, called by the provider): stores the refresh token (encrypted) in a new
 *   personal mailbox (or the one being reconnected), then redirects to the return URL with ?mailbox=…&status=connected
 *   (or status=error&message=…);
 * - GET|PUT /api/mailboxes/oauth/apps[/{provider}] (admin): client id and secret of the OAuth applications.
 */
#[Route('/api/mailboxes/oauth')]
final class MailboxOAuthController extends AbstractController
{
    public function __construct(
        private readonly OAuthClient $client,
        private readonly OAuthSettings $settings,
        private readonly OAuthState $state,
        private readonly SuiteSettings $suite,
        private readonly UrlGeneratorInterface $urls,
    ) {
    }

    /**
     * { "email": "…" (login hint, required for a new mailbox), "mailboxId": "…" (reconnect), "returnUrl": "…" }.
     * returnUrl: this interface, or an allowed origin of the impersonating application.
     */
    #[Route('/{provider}/start', name: 'api_mailbox_oauth_start', methods: ['POST'], requirements: ['provider' => 'google|microsoft'], priority: 10)]
    public function start(string $provider, Request $request, ActorContext $actor, MailboxRepository $mailboxes): JsonResponse
    {
        if ($actor->isEmbed()) {
            throw new AccessDeniedHttpException('Not available in the embedded composer.');
        }
        $user = $actor->getUser() ?? throw new AccessDeniedHttpException('An OAuth mailbox belongs to a user: impersonate them (X-Impersonate-User).');
        $body = '' === $request->getContent() ? [] : $request->toArray();
        $email = mb_strtolower(trim(\is_string($body['email'] ?? null) ? $body['email'] : ''));
        $mailboxId = \is_string($body['mailboxId'] ?? null) ? $body['mailboxId'] : null;
        if (null !== $mailboxId) {
            $mailbox = $mailboxes->find($mailboxId);
            if (null === $mailbox || !$mailbox->isPersonal() || !$mailbox->isOwnedBy($user)) {
                throw new NotFoundHttpException('Boîte introuvable.');
            }
            $email = $mailbox->getEmail();
        } elseif (!filter_var($email, \FILTER_VALIDATE_EMAIL)) {
            throw new UnprocessableEntityHttpException('L’adresse email est obligatoire.');
        }
        $returnUrl = \is_string($body['returnUrl'] ?? null) && '' !== $body['returnUrl'] ? $body['returnUrl'] : $this->publicUrl().'/mailboxes/mine';
        if (!$this->isAllowedReturnUrl($returnUrl, $actor)) {
            throw new UnprocessableEntityHttpException('returnUrl: this address is not allowed (the interface of Rocket Mailer, or an allowed origin of the application).');
        }

        try {
            $redirectUri = $this->redirectUri($provider);
            $verifier = rtrim(strtr(base64_encode(random_bytes(48)), '+/', '-_'), '=');
            $state = $this->state->encode([
                'provider' => $provider,
                'user' => $user->getId()->toRfc4122(),
                'email' => $email,
                'mailbox' => $mailboxId,
                'returnUrl' => $returnUrl,
                'verifier' => $verifier,
                'redirectUri' => $redirectUri,
            ]);

            return $this->json([
                'provider' => $provider,
                'authorizationUrl' => $this->client->authorizationUrl($provider, $state, $verifier, $redirectUri, $email),
                'redirectUri' => $redirectUri,
            ]);
        } catch (OAuthException $e) {
            throw new UnprocessableEntityHttpException($e->getMessage(), $e);
        }
    }

    #[Route('/callback', name: 'api_mailbox_oauth_callback', methods: ['GET'], priority: 10)]
    public function callback(Request $request, EntityManagerInterface $em, MailboxRepository $mailboxes, MailProviderDetector $detector, OAuthTokens $tokens): RedirectResponse
    {
        try {
            $state = $this->state->decode($request->query->getString('state'));
        } catch (OAuthException $e) {
            return $this->back($this->publicUrl().'/mailboxes/mine', ['status' => 'error', 'message' => $e->getMessage()]);
        }
        $returnUrl = (string) $state['returnUrl'];
        try {
            if ('' !== $request->query->getString('error')) {
                throw new OAuthException('access_denied' === $request->query->getString('error')
                    ? 'Connexion annulée : l’accès à la boîte n’a pas été autorisé.'
                    : 'Le fournisseur a refusé la connexion : '.$request->query->getString('error_description', $request->query->getString('error')));
            }
            $user = $em->find(User::class, (string) $state['user']);
            if (!$user instanceof User || !$user->isEnabled()) {
                throw new OAuthException('Compte utilisateur introuvable ou désactivé.');
            }
            $provider = OAuthProvider::get((string) $state['provider']);
            $received = $this->client->exchangeCode($provider->id, $request->query->getString('code'), (string) $state['verifier'], (string) $state['redirectUri']);

            $mailbox = null !== $state['mailbox'] ? $mailboxes->find((string) $state['mailbox']) : $mailboxes->personalOf($user, (string) $state['email']);
            $new = null === $mailbox;
            if ($new) {
                $detected = $detector->detect((string) $state['email']);
                // A custom domain hosted by the provider (Google Workspace, Microsoft 365): its servers anyway.
                $providerSettings = MailProviderDetector::PROVIDERS[OAuthProvider::GOOGLE === $provider->id ? 'gmail' : 'microsoft'];
                $detected = ['provider' => OAuthProvider::GOOGLE === $provider->id ? 'gmail' : 'microsoft', 'imap' => $providerSettings['imap'], 'smtp' => $providerSettings['smtp']] + $detected;
                $mailbox = PersonalMailboxController::createPersonal($user, (string) $state['email'], $detected);
            } elseif (!$mailbox->isPersonal() || !$mailbox->isOwnedBy($user)) {
                throw new OAuthException('Boîte introuvable.');
            }
            if (null === $received['refreshToken'] && null === $mailbox->getEncryptedOAuthRefreshToken()) {
                throw new OAuthException(\sprintf('%s n’a pas fourni de jeton de rafraîchissement : retirez l’accès de Rocket Mailer dans votre compte, puis reconnectez-vous.', $provider->label));
            }
            $mailbox->setAuthType($provider->authType)->setSmtpUsername($mailbox->getEmail())->setImapUsername(null);
            $tokens->store($mailbox, $received);
            if ($new) {
                $em->persist($mailbox);
                PersonalMailboxController::addOwnerMembership($em, $mailbox);
            }
            $em->flush();

            return $this->back($returnUrl, ['status' => 'connected', 'mailbox' => $mailbox->getId()->toRfc4122()]);
        } catch (OAuthException $e) {
            return $this->back($returnUrl, ['status' => 'error', 'message' => $e->getMessage()]);
        }
    }

    #[Route('/apps', name: 'api_mailbox_oauth_apps', methods: ['GET'], priority: 10)]
    #[IsGranted(Roles::ADMIN)]
    public function apps(): JsonResponse
    {
        return $this->json(array_map(fn (string $provider) => $this->presentApp($provider), OAuthProvider::IDS));
    }

    /** { "clientId": "…", "clientSecret": "…" (empty: unchanged), "tenant": "common" (Microsoft), "redirectUri": "…" (optional) }; an empty clientId removes it. */
    #[Route('/apps/{provider}', name: 'api_mailbox_oauth_app_save', methods: ['PUT'], requirements: ['provider' => 'google|microsoft'], priority: 10)]
    #[IsGranted(Roles::ADMIN)]
    public function saveApp(string $provider, Request $request): JsonResponse
    {
        $body = $request->toArray();
        $string = static fn (string $key): ?string => \is_string($body[$key] ?? null) ? $body[$key] : null;
        $redirectUri = $string('redirectUri');
        if (null !== $redirectUri && '' !== trim($redirectUri) && !preg_match('#^https?://#', trim($redirectUri))) {
            throw new UnprocessableEntityHttpException('redirectUri: an absolute URL is expected.');
        }
        $this->settings->save($provider, (string) $string('clientId'), $string('clientSecret'), $string('tenant'), $redirectUri);

        return $this->json($this->presentApp($provider));
    }

    /** @return array<string, mixed> */
    private function presentApp(string $provider): array
    {
        return $this->settings->present($provider) + [
            'label' => OAuthProvider::get($provider)->label,
            'scope' => OAuthProvider::get($provider)->scope,
            'effectiveRedirectUri' => $this->redirectUri($provider),
        ];
    }

    /** Callback URL registered at the provider: the configured one, else this interface (which proxies /api). */
    private function redirectUri(string $provider): string
    {
        $configured = $this->settings->present($provider)['redirectUri'];
        if (null !== $configured) {
            return $configured;
        }
        $path = $this->urls->generate('api_mailbox_oauth_callback');

        return '' !== $this->publicUrl() ? $this->publicUrl().$path : $this->urls->generate('api_mailbox_oauth_callback', [], UrlGeneratorInterface::ABSOLUTE_URL);
    }

    private function publicUrl(): string
    {
        return $this->suite->publicUrl();
    }

    private function isAllowedReturnUrl(string $url, ActorContext $actor): bool
    {
        $origin = self::origin($url);
        if (null === $origin) {
            return false;
        }
        $allowed = [self::origin($this->publicUrl())];
        foreach ($actor->getApplication()?->getAllowedOrigins() ?? [] as $applicationOrigin) {
            $allowed[] = self::origin($applicationOrigin);
        }

        return \in_array($origin, $allowed, true);
    }

    private static function origin(string $url): ?string
    {
        $parts = parse_url(trim($url));
        if (!\is_array($parts) || !isset($parts['scheme'], $parts['host']) || !\in_array(strtolower($parts['scheme']), ['http', 'https'], true)) {
            return null;
        }

        return strtolower($parts['scheme'].'://'.$parts['host']).(isset($parts['port']) ? ':'.$parts['port'] : '');
    }

    /** @param array<string, string> $query */
    private function back(string $returnUrl, array $query): RedirectResponse
    {
        return new RedirectResponse($returnUrl.(str_contains($returnUrl, '?') ? '&' : '?').http_build_query($query));
    }
}
