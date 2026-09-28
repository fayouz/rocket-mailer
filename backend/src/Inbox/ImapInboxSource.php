<?php

namespace App\Inbox;

use App\Entity\Mailbox;
use App\Mailbox\MailboxConnector;

/** The INBOX of the mailbox's IMAP server, opened read-only: messages stay on the server, flags untouched. */
final class ImapInboxSource implements InboxSourceInterface
{
    public function __construct(private readonly MailboxConnector $connector)
    {
    }

    public function fetch(Mailbox $mailbox, ?int $uidValidity, int $afterUid, int $maxSize, int $limit): FetchBatch
    {
        $client = $this->connector->openImap($mailbox);
        try {
            $validity = $client->examine('INBOX');
            if (null !== $uidValidity && $validity !== $uidValidity) {
                $afterUid = 0; // Renumbered: fetch again (duplicates are skipped by Message-ID).
            }
            $uids = \array_slice($client->uidsAfter($afterUid), 0, $limit);
            $sizes = $client->sizes($uids);
            $messages = [];
            foreach ($uids as $uid) {
                $size = $sizes[$uid] ?? 0;
                $raw = $size > $maxSize ? null : $client->fetchRaw($uid);
                if (null === $raw && $size <= $maxSize) {
                    continue; // Expunged meanwhile.
                }
                $messages[] = new FetchedMessage($uid, $size ?: \strlen((string) $raw), $raw);
            }

            return new FetchBatch($validity, $messages);
        } finally {
            $client->logout();
        }
    }
}
