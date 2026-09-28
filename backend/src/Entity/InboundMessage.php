<?php

namespace App\Entity;

use App\Repository\InboundMessageRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * A message received in a shared inbox (fetched from its IMAP INBOX). The HTML is sanitized before being stored.
 */
#[ORM\Entity(repositoryClass: InboundMessageRepository::class)]
#[ORM\Index(fields: ['mailbox', 'messageId'])]
#[ORM\Index(fields: ['conversation', 'date'])]
class InboundMessage
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private Uuid $id;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Mailbox $mailbox;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Conversation $conversation;

    #[ORM\Column(type: Types::BIGINT, nullable: true)]
    private ?string $imapUid = null;

    /** Message-ID, with its angle brackets. */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $messageId = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $inReplyTo = null;

    /** @var list<string> */
    #[ORM\Column(name: 'reference_ids', type: Types::JSON)]
    private array $references = [];

    #[ORM\Column(length: 255)]
    private string $fromAddress = '';

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $fromName = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $replyTo = null;

    /** @var list<string> */
    #[ORM\Column(name: 'recipients_to', type: Types::JSON)]
    private array $to = [];

    /** @var list<string> */
    #[ORM\Column(name: 'recipients_cc', type: Types::JSON)]
    private array $cc = [];

    #[ORM\Column(length: 255)]
    private string $subject = '';

    #[ORM\Column]
    private \DateTimeImmutable $date;

    #[ORM\Column(type: Types::TEXT)]
    private string $text = '';

    /** Sanitized HTML (no scripts, styles sheets, forms…); remote images are removed when displayed unless asked. */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $html = null;

    #[ORM\Column]
    private bool $hasRemoteImages = false;

    #[ORM\Column]
    private int $size = 0;

    #[ORM\Column]
    private \DateTimeImmutable $receivedAt;

    /** @var Collection<int, InboundAttachment> */
    #[ORM\OneToMany(targetEntity: InboundAttachment::class, mappedBy: 'message', cascade: ['persist'])]
    private Collection $attachments;

    public function __construct(Mailbox $mailbox, Conversation $conversation)
    {
        $this->id = Uuid::v7();
        $this->mailbox = $mailbox;
        $this->conversation = $conversation;
        $this->date = $this->receivedAt = new \DateTimeImmutable();
        $this->attachments = new ArrayCollection();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getMailbox(): Mailbox
    {
        return $this->mailbox;
    }

    public function getConversation(): Conversation
    {
        return $this->conversation;
    }

    public function getImapUid(): ?int
    {
        return null === $this->imapUid ? null : (int) $this->imapUid;
    }

    public function setImapUid(?int $uid): static
    {
        $this->imapUid = null === $uid ? null : (string) $uid;

        return $this;
    }

    public function getMessageId(): ?string
    {
        return $this->messageId;
    }

    public function setMessageId(?string $messageId): static
    {
        $this->messageId = null === $messageId ? null : mb_substr($messageId, 0, 255);

        return $this;
    }

    public function getInReplyTo(): ?string
    {
        return $this->inReplyTo;
    }

    public function setInReplyTo(?string $inReplyTo): static
    {
        $this->inReplyTo = null === $inReplyTo ? null : mb_substr($inReplyTo, 0, 255);

        return $this;
    }

    /** @return list<string> */
    public function getReferences(): array
    {
        return $this->references;
    }

    /** @param list<string> $references */
    public function setReferences(array $references): static
    {
        $this->references = \array_slice(array_values($references), -50);

        return $this;
    }

    public function getFromAddress(): string
    {
        return $this->fromAddress;
    }

    public function getFromName(): ?string
    {
        return $this->fromName;
    }

    public function getFromLabel(): string
    {
        return $this->fromName ?? $this->fromAddress;
    }

    public function setFrom(string $address, ?string $name): static
    {
        $this->fromAddress = mb_substr(mb_strtolower($address), 0, 255);
        $this->fromName = null === $name || '' === trim($name) ? null : mb_substr(trim($name), 0, 255);

        return $this;
    }

    public function getReplyTo(): ?string
    {
        return $this->replyTo;
    }

    public function setReplyTo(?string $replyTo): static
    {
        $this->replyTo = null === $replyTo ? null : mb_substr(mb_strtolower($replyTo), 0, 255);

        return $this;
    }

    /** @return list<string> */
    public function getTo(): array
    {
        return $this->to;
    }

    /** @param list<string> $to */
    public function setTo(array $to): static
    {
        $this->to = \array_slice(array_values($to), 0, 100);

        return $this;
    }

    /** @return list<string> */
    public function getCc(): array
    {
        return $this->cc;
    }

    /** @param list<string> $cc */
    public function setCc(array $cc): static
    {
        $this->cc = \array_slice(array_values($cc), 0, 100);

        return $this;
    }

    public function getSubject(): string
    {
        return $this->subject;
    }

    public function setSubject(string $subject): static
    {
        $this->subject = mb_substr($subject, 0, 255);

        return $this;
    }

    public function getDate(): \DateTimeImmutable
    {
        return $this->date;
    }

    public function setDate(\DateTimeImmutable $date): static
    {
        $this->date = $date;

        return $this;
    }

    public function getText(): string
    {
        return $this->text;
    }

    public function setText(string $text): static
    {
        $this->text = $text;

        return $this;
    }

    public function getHtml(): ?string
    {
        return $this->html;
    }

    public function setHtml(?string $html, bool $hasRemoteImages): static
    {
        $this->html = $html;
        $this->hasRemoteImages = $hasRemoteImages;

        return $this;
    }

    public function hasRemoteImages(): bool
    {
        return $this->hasRemoteImages;
    }

    public function getSize(): int
    {
        return $this->size;
    }

    public function setSize(int $size): static
    {
        $this->size = $size;

        return $this;
    }

    public function getReceivedAt(): \DateTimeImmutable
    {
        return $this->receivedAt;
    }

    public function setReceivedAt(\DateTimeImmutable $receivedAt): static
    {
        $this->receivedAt = $receivedAt;

        return $this;
    }

    /** @return Collection<int, InboundAttachment> */
    public function getAttachments(): Collection
    {
        return $this->attachments;
    }

    public function addAttachment(InboundAttachment $attachment): static
    {
        $this->attachments->add($attachment);

        return $this;
    }
}
