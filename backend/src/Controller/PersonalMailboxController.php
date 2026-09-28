<?php

namespace App\Controller;

use App\Entity\Mailbox;
use App\Entity\MailboxMember;
use App\Mailbox\MailProviderDetector;
use App\Mailbox\PersonalMailboxInput;
use App\Mailbox\SecretBox;
use App\Repository\MailboxRepository;
use Doctrine\ORM\EntityManagerInterface;
use Rocket\Core\Entity\User;
use Rocket\Core\Security\ActorContext;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Personal mailboxes (/api/mailboxes/personal): created by a user for themselves, visible to them only (an application
 * impersonating the user acts for them). The owner is the only member (manager) of its inbox. Administrators see that
 * it exists in /api/mailboxes (metadata), never its content, and cannot change its servers.
 * Also the detection of the servers of an address (/api/mailboxes/detect).
 */
#[Route('/api/mailboxes')]
final class PersonalMailboxController extends AbstractController
{
    public function __construct(
        private readonly ActorContext $actor,
        private readonly EntityManagerInterface $em,
        private readonly MailboxRepository $mailboxes,
        private readonly MailProviderDetector $detector,
        private readonly SecretBox $secrets,
        private readonly ValidatorInterface $validator,
    ) {
    }

    /** Provider and IMAP/SMTP settings of an address: ?email=… */
    #[Route('/detect', name: 'api_mailbox_detect', methods: ['GET'], priority: 10)]
    public function detect(Request $request): JsonResponse
    {
        $this->user();
        try {
            return $this->json($this->detector->detect($request->query->getString('email')));
        } catch (\InvalidArgumentException $e) {
            throw new UnprocessableEntityHttpException($e->getMessage());
        }
    }

    #[Route('/personal', name: 'api_mailbox_personal_list', methods: ['GET'], priority: 10)]
    public function list(): JsonResponse
    {
        $user = $this->user();

        return $this->json(array_map(self::present(...), $this->mailboxes->personalOf($user)));
    }

    #[Route('/personal/{id}', name: 'api_mailbox_personal_get', methods: ['GET'], priority: 10)]
    public function get(Mailbox $mailbox): JsonResponse
    {
        return $this->json(self::present($this->owned($mailbox)));
    }

    /** A personal mailbox with a password (OAuth: /api/mailboxes/oauth/{provider}/start). */
    #[Route('/personal', name: 'api_mailbox_personal_create', methods: ['POST'], priority: 10)]
    public function create(#[MapRequestPayload] PersonalMailboxInput $input): JsonResponse
    {
        $user = $this->user();
        $email = mb_strtolower(trim((string) $input->email));
        if ('' === $email) {
            throw new UnprocessableEntityHttpException('L’adresse email est obligatoire.');
        }
        if (null === $input->password || '' === $input->password) {
            throw new UnprocessableEntityHttpException('Le mot de passe est obligatoire (ou utilisez la connexion Google / Microsoft).');
        }
        $existing = $this->mailboxes->personalOf($user, $email);
        if (null !== $existing) {
            return $this->json(['error' => 'Vous avez déjà une boîte personnelle pour cette adresse.', 'mailbox' => self::present($existing)], Response::HTTP_CONFLICT);
        }

        $mailbox = self::createPersonal($user, $email, $this->detector->detect($email));
        $this->apply($mailbox, $input);
        $this->save($mailbox, true);

        return $this->json(self::present($mailbox), Response::HTTP_CREATED);
    }

    #[Route('/personal/{id}', name: 'api_mailbox_personal_update', methods: ['PATCH'], priority: 10)]
    public function update(Mailbox $mailbox, Request $request, ValidatorInterface $validator): JsonResponse
    {
        $this->owned($mailbox);
        $input = new PersonalMailboxInput();
        foreach ($request->toArray() as $field => $value) {
            if (property_exists($input, $field) && 'email' !== $field) {
                try {
                    $input->{$field} = $value;
                } catch (\TypeError) {
                    throw new UnprocessableEntityHttpException(\sprintf('Valeur invalide pour « %s ».', $field));
                }
            }
        }
        $violations = $validator->validate($input);
        if (\count($violations) > 0) {
            throw new UnprocessableEntityHttpException($violations->get(0)->getPropertyPath().': '.$violations->get(0)->getMessage());
        }
        if (null !== $input->password && '' !== $input->password && $mailbox->isOAuth()) {
            // From OAuth back to a password.
            $mailbox->setAuthType(Mailbox::AUTH_PASSWORD)->clearOAuth();
        }
        $this->apply($mailbox, $input);
        $this->save($mailbox, false);

        return $this->json(self::present($mailbox));
    }

    #[Route('/personal/{id}', name: 'api_mailbox_personal_delete', methods: ['DELETE'], priority: 10)]
    public function delete(Mailbox $mailbox): Response
    {
        $this->em->remove($this->owned($mailbox));
        $this->em->flush();

        return new Response(null, Response::HTTP_NO_CONTENT);
    }

    /**
     * A new personal mailbox of the user, with the detected servers (not persisted).
     *
     * @param array{provider: string, imap: array{host: string, port: int, encryption: string}, smtp: array{host: string, port: int, encryption: string}} $detected
     */
    public static function createPersonal(User $user, string $email, array $detected): Mailbox
    {
        return (new Mailbox())
            ->makePersonal($user)
            ->setName($email)
            ->setEmail($email)
            ->setProvider($detected['provider'])
            ->setTransport(Mailbox::TRANSPORT_SMTP)
            ->setSmtpHost($detected['smtp']['host'])->setSmtpPort($detected['smtp']['port'])->setSmtpEncryption($detected['smtp']['encryption'])
            ->setImapHost($detected['imap']['host'])->setImapPort($detected['imap']['port'])->setImapEncryption($detected['imap']['encryption'])
            ->setImapEnabled(true)
            ->setInboxEnabled(true);
    }

    /** The owner joins as the only member (manager) of the inbox of a new personal mailbox. */
    public static function addOwnerMembership(EntityManagerInterface $em, Mailbox $mailbox): void
    {
        $owner = $mailbox->getOwner() ?? throw new \LogicException('A personal mailbox has an owner.');
        $em->persist(new MailboxMember($mailbox, $owner, MailboxMember::ROLE_MANAGER));
    }

    /** @return array<string, mixed> */
    public static function present(Mailbox $mailbox): array
    {
        return [
            'id' => $mailbox->getId()->toRfc4122(),
            'kind' => $mailbox->getKind(),
            'name' => $mailbox->getName(),
            'email' => $mailbox->getEmail(),
            'displayName' => $mailbox->getDisplayName(),
            'provider' => $mailbox->getProvider(),
            'authType' => $mailbox->getAuthType(),
            'oauthConnected' => $mailbox->getOauthConnected(),
            'hasPassword' => $mailbox->getHasSmtpPassword(),
            'username' => $mailbox->getSmtpUsername() ?? $mailbox->getEmail(),
            'imap' => ['host' => $mailbox->getImapHost(), 'port' => $mailbox->getImapPort(), 'encryption' => $mailbox->getImapEncryption()],
            'smtp' => ['host' => $mailbox->getSmtpHost(), 'port' => $mailbox->getSmtpPort(), 'encryption' => $mailbox->getSmtpEncryption()],
            'inboxEnabled' => $mailbox->isInboxEnabled(),
            'keepSentCopy' => $mailbox->isImapEnabled(),
            'enabled' => $mailbox->isEnabled(),
            'inboxFetchedAt' => $mailbox->getInboxFetchedAt()?->format(\DATE_ATOM),
            'inboxError' => $mailbox->getInboxError(),
            'createdAt' => $mailbox->getCreatedAt()?->format(\DATE_ATOM),
        ];
    }

    private function apply(Mailbox $mailbox, PersonalMailboxInput $input): void
    {
        if (null !== $input->name && '' !== trim($input->name)) {
            $mailbox->setName($input->name);
        }
        if (null !== $input->displayName) {
            $mailbox->setDisplayName($input->displayName);
        }
        if (null !== $input->imapHost && '' !== trim($input->imapHost)) {
            $mailbox->setImapHost($input->imapHost);
        }
        if (null !== $input->imapPort) {
            $mailbox->setImapPort($input->imapPort);
        }
        if (null !== $input->imapEncryption) {
            $mailbox->setImapEncryption($input->imapEncryption);
        }
        if (null !== $input->smtpHost && '' !== trim($input->smtpHost)) {
            $mailbox->setSmtpHost($input->smtpHost);
        }
        if (null !== $input->smtpPort) {
            $mailbox->setSmtpPort($input->smtpPort);
        }
        if (null !== $input->smtpEncryption) {
            $mailbox->setSmtpEncryption($input->smtpEncryption);
        }
        if (null !== $input->inboxEnabled) {
            $mailbox->setInboxEnabled($input->inboxEnabled);
        }
        if (null !== $input->keepSentCopy) {
            $mailbox->setImapEnabled($input->keepSentCopy);
        }
        if (null !== $input->enabled) {
            $mailbox->setEnabled($input->enabled);
        }
        if (!$mailbox->isOAuth()) {
            if (null !== $input->username && '' !== trim($input->username)) {
                $mailbox->setSmtpUsername($input->username);
            } elseif (null === $mailbox->getSmtpUsername()) {
                $mailbox->setSmtpUsername($mailbox->getEmail());
            }
            // One password for IMAP and SMTP (the IMAP password falls back to the SMTP one).
            $mailbox->setSmtpPassword($input->password);
        }
    }

    private function save(Mailbox $mailbox, bool $new): void
    {
        $violations = $this->validator->validate($mailbox);
        if (\count($violations) > 0) {
            throw new UnprocessableEntityHttpException($violations->get(0)->getPropertyPath().': '.$violations->get(0)->getMessage());
        }
        $mailbox->sealSecrets($this->secrets->encrypt(...));
        if ($new) {
            $this->em->persist($mailbox);
            self::addOwnerMembership($this->em, $mailbox);
        }
        $this->em->flush();
    }

    private function owned(Mailbox $mailbox): Mailbox
    {
        if (!$mailbox->isPersonal() || !$mailbox->isOwnedBy($this->user())) {
            // Someone else's mailbox: as if it did not exist.
            throw new NotFoundHttpException('Boîte introuvable.');
        }

        return $mailbox;
    }

    private function user(): User
    {
        if ($this->actor->isEmbed()) {
            throw new AccessDeniedHttpException('Not available in the embedded composer.');
        }

        return $this->actor->getUser() ?? throw new AccessDeniedHttpException('Personal mailboxes belong to a user: impersonate them (X-Impersonate-User).');
    }
}
