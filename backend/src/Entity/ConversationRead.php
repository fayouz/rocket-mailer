<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Rocket\Core\Entity\User;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/** Read marker of a member: the conversation is unread when a message arrived after readAt. */
#[ORM\Entity]
#[ORM\UniqueConstraint(fields: ['conversation', 'user'])]
class ConversationRead
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private Uuid $id;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Conversation $conversation;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column]
    private \DateTimeImmutable $readAt;

    public function __construct(Conversation $conversation, User $user)
    {
        $this->id = Uuid::v7();
        $this->conversation = $conversation;
        $this->user = $user;
        $this->readAt = new \DateTimeImmutable();
    }

    public function markRead(\DateTimeImmutable $at): void
    {
        $this->readAt = $at;
    }

    public function getReadAt(): \DateTimeImmutable
    {
        return $this->readAt;
    }
}
