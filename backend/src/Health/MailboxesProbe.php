<?php

namespace App\Health;

use App\Mailbox\MailboxConnector;
use App\Repository\MailboxRepository;
use Rocket\Core\Health\ServiceProbeInterface;

/** The enabled sending mailboxes on the dashboard: SMTP, and IMAP when the copy is on, checked every 5 minutes by the worker. */
final class MailboxesProbe implements ServiceProbeInterface
{
    public function __construct(
        private readonly MailboxRepository $mailboxes,
        private readonly MailboxConnector $connector,
    ) {
    }

    public function id(): string
    {
        return 'mailboxes';
    }

    public function label(): string
    {
        return 'Boîtes d’envoi';
    }

    public function targets(): iterable
    {
        foreach ($this->mailboxes->findBy(['enabled' => true], ['name' => 'ASC']) as $mailbox) {
            $id = $mailbox->getId()->toRfc4122();
            yield $id.':smtp' => ['name' => $mailbox->getName().' (envoi)', 'check' => fn () => $this->connector->checkSmtp($mailbox)];
            if ($mailbox->isImapEnabled()) {
                yield $id.':imap' => ['name' => $mailbox->getName().' (IMAP)', 'check' => fn () => $this->connector->checkImap($mailbox)];
            }
        }
    }
}
