<?php

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Rocket\Core\Entity\User;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/** An internal note on a conversation: seen by the members only, never sent. Plain text. */
#[ORM\Entity]
#[ORM\Index(fields: ['conversation', 'createdAt'])]
class ConversationNote
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private Uuid $id;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Conversation $conversation;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $author;

    #[ORM\Column(type: Types::TEXT)]
    private string $body;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct(Conversation $conversation, User $author, string $body)
    {
        $this->id = Uuid::v7();
        $this->conversation = $conversation;
        $this->author = $author;
        $this->body = $body;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getConversation(): Conversation
    {
        return $this->conversation;
    }

    public function getAuthor(): ?User
    {
        return $this->author;
    }

    public function getBody(): string
    {
        return $this->body;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
