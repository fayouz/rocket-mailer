<?php

namespace App\Controller;

use App\Entity\Mailbox;
use App\Mailbox\MailboxConnector;
use App\Security\Roles;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

final class MailboxTestController extends AbstractController
{
    /** Checks the SMTP and IMAP connections of a mailbox; { "sendTo": "…" } also sends a test email. */
    #[Route('/api/mailboxes/{id}/test', name: 'api_mailbox_test', methods: ['POST'])]
    #[IsGranted(Roles::ADMIN)]
    public function __invoke(Mailbox $mailbox, Request $request, MailboxConnector $connector): JsonResponse
    {
        $sendTo = $request->getPayload()->get('sendTo');
        if (null !== $sendTo && ('' === $sendTo || false === filter_var($sendTo, \FILTER_VALIDATE_EMAIL))) {
            throw new UnprocessableEntityHttpException('sendTo must be an email address.');
        }

        return $this->json($connector->test($mailbox, $sendTo));
    }
}
