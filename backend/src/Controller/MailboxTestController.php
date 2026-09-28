<?php

namespace App\Controller;

use App\Entity\Mailbox;
use App\Mailbox\MailboxConnector;
use App\Mailbox\MailboxTestInput;
use Rocket\Core\Security\ActorContext;
use Rocket\Core\Security\Roles;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;

final class MailboxTestController extends AbstractController
{
    /**
     * Checks the SMTP (EHLO, authentication) and IMAP (login, "Sent" folder) connections of a mailbox, with French
     * messages; { "sendTo": "…" } also sends a test email. A shared mailbox: administrators. A personal one: its owner
     * only (also through an application impersonating them).
     */
    #[Route('/api/mailboxes/{id}/test', name: 'api_mailbox_test', methods: ['POST'])]
    public function __invoke(Mailbox $mailbox, MailboxConnector $connector, ActorContext $actor, #[MapRequestPayload] ?MailboxTestInput $input = null): JsonResponse
    {
        $allowed = $mailbox->isPersonal()
            ? !$actor->isEmbed() && $mailbox->isOwnedBy($actor->getUser())
            : $this->isGranted(Roles::ADMIN) && null === $actor->getApplication();
        if (!$allowed) {
            throw $mailbox->isPersonal() ? $this->createNotFoundException('Boîte introuvable.') : $this->createAccessDeniedException();
        }

        return $this->json($connector->test($mailbox, $input?->sendTo ?: null));
    }
}
