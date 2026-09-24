<?php

namespace App\Security;

use App\Entity\Application;
use App\Entity\User;
use App\Repository\ApplicationRepository;
use Lexik\Bundle\JWTAuthenticationBundle\Encoder\JWTEncoderInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Exception\JWTDecodeFailureException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;
use Symfony\Component\Security\Http\Authenticator\AbstractAuthenticator;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;
use Symfony\Component\Security\Http\Authenticator\Token\PostAuthenticationToken;

/**
 * Authenticates the embedded compose UI with "Authorization: Embed <jwt>".
 * The JWT is minted by an application for one user (see EmbedController) and is short-lived.
 * The resulting session is restricted to compose endpoints by EmbedScopeListener.
 */
final class EmbedTokenAuthenticator extends AbstractAuthenticator
{
    public const SCHEME = 'Embed ';
    public const SCOPE = 'embed';

    public function __construct(
        private readonly JWTEncoderInterface $encoder,
        private readonly ApplicationRepository $applications,
    ) {
    }

    public function supports(Request $request): ?bool
    {
        return str_starts_with((string) $request->headers->get('Authorization'), self::SCHEME);
    }

    public function authenticate(Request $request): Passport
    {
        $jwt = substr((string) $request->headers->get('Authorization'), \strlen(self::SCHEME));

        try {
            $payload = $this->encoder->decode($jwt);
        } catch (JWTDecodeFailureException) {
            throw new CustomUserMessageAuthenticationException('Invalid or expired embed token.');
        }

        if (self::SCOPE !== ($payload['scope'] ?? null) || !\is_string($payload['app'] ?? null) || !\is_string($payload['username'] ?? null)) {
            throw new CustomUserMessageAuthenticationException('Invalid embed token.');
        }

        // Re-checked on every request so that disabling an application revokes its embed sessions.
        $application = $this->applications->find($payload['app']);
        if (!$application instanceof Application || !$application->isEnabled() || !$application->canImpersonate()) {
            throw new CustomUserMessageAuthenticationException('Embed token revoked.');
        }

        $passport = new SelfValidatingPassport(new UserBadge($payload['username']));
        $passport->setAttribute('application', $application);

        return $passport;
    }

    public function createToken(Passport $passport, string $firewallName): TokenInterface
    {
        $user = $passport->getUser();
        if (!$user instanceof User) {
            throw new CustomUserMessageAuthenticationException('Invalid embed token.');
        }

        $token = new PostAuthenticationToken(
            $user,
            $firewallName,
            Roles::delegated($user->getRoles(), Roles::APPLICATION, Roles::IMPERSONATION, Roles::EMBED),
        );
        $token->setAttribute(ActorContext::APPLICATION_ATTRIBUTE, $passport->getAttribute('application')->getId()->toRfc4122());

        return $token;
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): ?Response
    {
        return null;
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): ?Response
    {
        $message = $exception instanceof CustomUserMessageAuthenticationException
            ? $exception->getMessageKey()
            : 'Authentication failed.';

        return new JsonResponse(['code' => 401, 'message' => $message], Response::HTTP_UNAUTHORIZED);
    }
}
