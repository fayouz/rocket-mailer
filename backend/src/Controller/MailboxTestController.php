<?php

namespace App\Controller;

use App\Entity\Mailbox;
use App\Mailbox\MailboxConnector;
use App\Mailbox\MailboxTestInput;
use Rocket\Core\Security\Roles;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

final class MailboxTestController extends AbstractController
{
    /** Checks the SMTP and IMAP connections of a mailbox; { "sendTo": "…" } also sends a test email. */
    #[Route('/api/mailboxes/{id}/test', name: 'api_mailbox_test', methods: ['POST'])]
    #[IsGranted(Roles::ADMIN)]
    public function __invoke(Mailbox $mailbox, MailboxConnector $connector, #[MapRequestPayload] ?MailboxTestInput $input = null): JsonResponse
    {
        return $this->json($connector->test($mailbox, $input?->sendTo ?: null));
    }
}
