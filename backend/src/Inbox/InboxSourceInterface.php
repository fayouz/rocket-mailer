<?php

namespace App\Inbox;

use App\Entity\Mailbox;

/**
 * Where the new messages of a shared inbox come from: its IMAP INBOX (ImapInboxSource), or an in-memory
 * mailbox in demo mode and in the tests (InMemoryInboxSource), so that they never contact a real server.
 */
interface InboxSourceInterface
{
    /**
     * Messages of the INBOX with a UID greater than $afterUid (all of them if $uidValidity changed), oldest first.
     * Messages larger than $maxSize are returned without their content (raw = null).
     */
    public function fetch(Mailbox $mailbox, ?int $uidValidity, int $afterUid, int $maxSize, int $limit): FetchBatch;
}
