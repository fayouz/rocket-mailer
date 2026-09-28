<?php

namespace App\Inbox;

use App\Entity\Mailbox;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/** The IMAP server of the mailbox, or the in-memory INBOXes in demo mode (DEMO_MODE=1, also set in the tests). */
#[AsAlias(InboxSourceInterface::class)]
final class InboxSource implements InboxSourceInterface
{
    public function __construct(
        private readonly ImapInboxSource $imap,
        private readonly InMemoryInboxSource $memory,
        #[Autowire(env: 'bool:DEMO_MODE')] private readonly bool $demoMode,
    ) {
    }

    public function fetch(Mailbox $mailbox, ?int $uidValidity, int $afterUid, int $maxSize, int $limit): FetchBatch
    {
        return ($this->demoMode ? $this->memory : $this->imap)->fetch($mailbox, $uidValidity, $afterUid, $maxSize, $limit);
    }
}
