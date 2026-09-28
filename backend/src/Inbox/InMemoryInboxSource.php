<?php

namespace App\Inbox;

use App\Entity\Mailbox;

/**
 * In-memory INBOXes, by mailbox address: used in demo mode and in the tests instead of an IMAP server.
 * Messages are added with deliver().
 */
final class InMemoryInboxSource implements InboxSourceInterface
{
    public const UID_VALIDITY = 1;

    /** @var array<string, array<int, string>> address => uid => raw message */
    private array $boxes = [];

    public function deliver(string $mailboxEmail, string $raw): int
    {
        $box = &$this->boxes[mb_strtolower($mailboxEmail)];
        $box ??= [];
        $uid = [] === $box ? 1 : max(array_keys($box)) + 1;
        $box[$uid] = $raw;

        return $uid;
    }

    public function fetch(Mailbox $mailbox, ?int $uidValidity, int $afterUid, int $maxSize, int $limit): FetchBatch
    {
        $messages = [];
        foreach ($this->boxes[$mailbox->getEmail()] ?? [] as $uid => $raw) {
            if ($uid <= $afterUid) {
                continue;
            }
            $messages[] = new FetchedMessage($uid, \strlen($raw), \strlen($raw) > $maxSize ? null : $raw);
            if (\count($messages) >= $limit) {
                break;
            }
        }

        return new FetchBatch(self::UID_VALIDITY, $messages);
    }
}
