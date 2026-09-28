<?php

namespace App\Inbox;

use App\Attachment\AttachmentStorage;
use App\Entity\Conversation;
use App\Entity\InboundAttachment;
use App\Entity\InboundMessage;
use App\Entity\Mailbox;
use App\Repository\ConversationRepository;
use App\Repository\InboundMessageRepository;
use App\Repository\MailboxRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Lock\LockFactory;

/**
 * Fetches the new messages of the shared inboxes and files them into conversations:
 * - by In-Reply-To / References (a received message or a reply sent from the mailbox);
 * - else by normalized subject and participant;
 * - else a new conversation.
 */
final class InboxFetcher
{
    /** Messages fetched per mailbox and per run (the next run continues). */
    public const BATCH = 50;
    public const MAX_ATTACHMENTS = 20;

    public function __construct(
        private readonly InboxSourceInterface $source,
        private readonly MimeParser $parser,
        private readonly MessageSanitizer $sanitizer,
        private readonly AttachmentStorage $storage,
        private readonly ConversationRepository $conversations,
        private readonly InboundMessageRepository $messages,
        private readonly MailboxRepository $mailboxes,
        private readonly EntityManagerInterface $em,
        private readonly LockFactory $locks,
        private readonly LoggerInterface $logger,
        #[Autowire(env: 'int:INBOX_MAX_MESSAGE_SIZE')] private readonly int $maxMessageSize = 26_214_400,
    ) {
    }

    /** @return array<string, int|string> mailbox name => new messages, or the error */
    public function fetchAll(): array
    {
        $results = [];
        foreach ($this->mailboxes->findBy(['inboxEnabled' => true, 'enabled' => true]) as $mailbox) {
            try {
                $results[$mailbox->getName()] = $this->fetch($mailbox);
            } catch (\Throwable $e) {
                $results[$mailbox->getName()] = $e->getMessage();
            }
        }

        return $results;
    }

    /**
     * Fetches the new messages of one shared inbox.
     *
     * @return int number of new messages
     */
    public function fetch(Mailbox $mailbox): int
    {
        if (!$mailbox->isInboxEnabled() || !$mailbox->isEnabled()) {
            throw new \RuntimeException('Cette boîte ne reçoit pas de messages.');
        }
        $lock = $this->locks->createLock('inbox-fetch-'.$mailbox->getId()->toRfc4122(), 300);
        if (!$lock->acquire()) {
            return 0; // Another fetch is running.
        }

        try {
            try {
                $batch = $this->source->fetch($mailbox, $mailbox->getInboxUidValidity(), $mailbox->getInboxUidValidity() === null ? 0 : $mailbox->getInboxLastUid(), $this->maxMessageSize, self::BATCH);
            } catch (\Throwable $e) {
                $this->logger->warning('Fetching the shared inbox {mailbox} failed: {error}', ['mailbox' => $mailbox->getEmail(), 'error' => $e->getMessage()]);
                $mailbox->markInboxFailed($e->getMessage());
                $this->em->flush();

                throw new \RuntimeException('Relève impossible : '.$e->getMessage(), 0, $e);
            }

            $lastUid = $mailbox->getInboxUidValidity() === $batch->uidValidity ? $mailbox->getInboxLastUid() : 0;
            $count = 0;
            foreach ($batch->messages as $fetched) {
                if (null !== $this->import($mailbox, $fetched->raw, $fetched->uid, $fetched->size)) {
                    ++$count;
                }
                $lastUid = max($lastUid, $fetched->uid);
                // Each message is committed with the new position: a failure never fetches it twice.
                $mailbox->markInboxFetched($batch->uidValidity, $lastUid);
                $this->em->flush();
            }
            $mailbox->markInboxFetched($batch->uidValidity, $lastUid);
            $this->em->flush();

            return $count;
        } finally {
            $lock->release();
        }
    }

    /**
     * Files one raw message into a conversation (null: already imported). A null $raw (too large) keeps a
     * placeholder message so that the team knows about it.
     */
    public function import(Mailbox $mailbox, ?string $raw, ?int $uid = null, ?int $size = null, ?\DateTimeImmutable $receivedAt = null): ?InboundMessage
    {
        $parsed = null === $raw ? null : $this->parser->parse($raw);
        if (null !== $parsed?->messageId && $this->messages->exists($mailbox, $parsed->messageId)) {
            return null;
        }
        if (null === $parsed) {
            $parsed = new ParsedMessage();
            $parsed->subject = '(message trop volumineux)';
            $parsed->fromAddress = 'inconnu@invalid';
            $parsed->text = \sprintf('Ce message de %d Mo dépasse la limite de %d Mo : consultez-le directement dans la boîte.', (int) ceil(($size ?? 0) / 1048576), intdiv($this->maxMessageSize, 1048576));
        }
        $receivedAt ??= new \DateTimeImmutable();
        $date = $parsed->date ?? $receivedAt;
        if ($date > $receivedAt) {
            $date = $receivedAt; // Never in the future.
        }

        $conversation = $this->conversations->findByMessageIds($mailbox, array_values(array_filter([$parsed->inReplyTo, ...array_reverse($parsed->references)])))
            ?? (null === $raw ? null : $this->conversations->findBySubjectAndParticipant($mailbox, Conversation::normalizeSubject($parsed->subject), $parsed->fromAddress));
        if (null === $conversation) {
            $conversation = new Conversation($mailbox, $parsed->subject, $receivedAt);
            $this->em->persist($conversation);
        }

        $message = (new InboundMessage($mailbox, $conversation))
            ->setImapUid($uid)
            ->setMessageId($parsed->messageId)
            ->setInReplyTo($parsed->inReplyTo)
            ->setReferences($parsed->references)
            ->setFrom($parsed->fromAddress, $parsed->fromName)
            ->setReplyTo($parsed->replyTo)
            ->setTo($parsed->to)
            ->setCc($parsed->cc)
            ->setSubject($parsed->subject)
            ->setDate($date)
            ->setReceivedAt($receivedAt)
            ->setText(mb_substr($parsed->text, 0, 500_000))
            ->setSize($size ?? \strlen((string) $raw));
        if (null !== $parsed->html) {
            $clean = $this->sanitizer->sanitize(mb_substr($parsed->html, 0, MessageSanitizer::MAX_INPUT));
            $message->setHtml($clean['html'], $clean['hasRemoteImages']);
        }

        foreach (\array_slice($parsed->attachments, 0, self::MAX_ATTACHMENTS) as $file) {
            $attachment = new InboundAttachment($message, $file['filename'], $file['mimeType'], \strlen($file['content']));
            $this->storage->storeContent($attachment->getStorageName(), $file['content']);
            $message->addAttachment($attachment);
            $this->em->persist($attachment);
        }

        $conversation->recordInbound($message);
        $this->em->persist($message);

        return $message;
    }
}
