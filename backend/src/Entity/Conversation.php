<?php

namespace App\Entity;

use App\Repository\ConversationRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Rocket\Core\Entity\User;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * A thread of a shared inbox: received messages (InboundMessage), replies (Email) and internal notes.
 */
#[ORM\Entity(repositoryClass: ConversationRepository::class)]
#[ORM\Index(fields: ['mailbox', 'lastMessageAt'])]
#[ORM\Index(fields: ['mailbox', 'normalizedSubject'])]
class Conversation
{
    public const STATUS_OPEN = 'open';
    public const STATUS_CLOSED = 'closed';

    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private Uuid $id;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Mailbox $mailbox;

    #[ORM\Column(length: 255)]
    private string $subject;

    /** Subject without "Re:", "Fwd:"… lowercased: fallback threading key. */
    #[ORM\Column(length: 255)]
    private string $normalizedSubject;

    /** @var list<string> external addresses taking part (lowercased) */
    #[ORM\Column(type: Types::JSON)]
    private array $participants = [];

    #[ORM\Column(length: 8)]
    private string $status = self::STATUS_OPEN;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $assignee = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    /** Last received message (drives the unread markers). */
    #[ORM\Column]
    private \DateTimeImmutable $lastMessageAt;

    /** Last activity (received message, reply or note): list order. */
    #[ORM\Column]
    private \DateTimeImmutable $lastActivityAt;

    #[ORM\Column]
    private int $messageCount = 0;

    #[ORM\Column(length: 255)]
    private string $snippet = '';

    #[ORM\Column(length: 255)]
    private string $lastFrom = '';

    public function __construct(Mailbox $mailbox, string $subject, \DateTimeImmutable $at)
    {
        $this->id = Uuid::v7();
        $this->mailbox = $mailbox;
        $this->subject = mb_substr('' === trim($subject) ? '(sans objet)' : trim($subject), 0, 255);
        $this->normalizedSubject = self::normalizeSubject($subject);
        $this->createdAt = new \DateTimeImmutable();
        $this->lastMessageAt = $at;
        $this->lastActivityAt = $at;
    }

    /** "Re: TR: Fwd:  Hello " → "hello". */
    public static function normalizeSubject(string $subject): string
    {
        $subject = trim($subject);
        do {
            $before = $subject;
            $subject = trim((string) preg_replace('/^(?:re|fw|fwd|tr|aw|wg|sv|rv)\s*(?:\[\d+\])?\s*:\s*/iu', '', $subject));
        } while ($subject !== $before);

        return mb_substr(mb_strtolower((string) preg_replace('/\s+/u', ' ', $subject)), 0, 255);
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getMailbox(): Mailbox
    {
        return $this->mailbox;
    }

    public function getSubject(): string
    {
        return $this->subject;
    }

    public function getNormalizedSubject(): string
    {
        return $this->normalizedSubject;
    }

    /** @return list<string> */
    public function getParticipants(): array
    {
        return $this->participants;
    }

    public function addParticipant(string $email): static
    {
        $email = mb_strtolower(trim($email));
        if ('' !== $email && $email !== $this->mailbox->getEmail() && !\in_array($email, $this->participants, true)) {
            $this->participants[] = $email;
        }

        return $this;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): static
    {
        if (!\in_array($status, [self::STATUS_OPEN, self::STATUS_CLOSED], true)) {
            throw new \InvalidArgumentException('Unknown status.');
        }
        $this->status = $status;

        return $this;
    }

    public function getAssignee(): ?User
    {
        return $this->assignee;
    }

    public function setAssignee(?User $assignee): static
    {
        $this->assignee = $assignee;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getLastMessageAt(): \DateTimeImmutable
    {
        return $this->lastMessageAt;
    }

    public function getLastActivityAt(): \DateTimeImmutable
    {
        return $this->lastActivityAt;
    }

    public function getMessageCount(): int
    {
        return $this->messageCount;
    }

    public function getSnippet(): string
    {
        return $this->snippet;
    }

    public function getLastFrom(): string
    {
        return $this->lastFrom;
    }

    /** A message was received: the conversation (re)opens and becomes unread for everyone. */
    public function recordInbound(InboundMessage $message): void
    {
        ++$this->messageCount;
        $this->addParticipant($message->getFromAddress());
        $this->snippet = self::snippet($message->getText());
        $this->lastFrom = mb_substr($message->getFromLabel(), 0, 255);
        if ($message->getReceivedAt() > $this->lastMessageAt) {
            $this->lastMessageAt = $message->getReceivedAt();
        }
        $this->touch($message->getReceivedAt());
        $this->status = self::STATUS_OPEN;
    }

    /** A reply was sent from the conversation. */
    public function recordReply(Email $email, string $text): void
    {
        ++$this->messageCount;
        $this->snippet = self::snippet($text);
        $this->lastFrom = $this->mailbox->getDisplayName() ?? $this->mailbox->getEmail();
        $this->touch(new \DateTimeImmutable());
    }

    public function touch(\DateTimeImmutable $at): void
    {
        if ($at > $this->lastActivityAt) {
            $this->lastActivityAt = $at;
        }
    }

    private static function snippet(string $text): string
    {
        return mb_substr(trim((string) preg_replace('/\s+/u', ' ', $text)), 0, 200);
    }
}
