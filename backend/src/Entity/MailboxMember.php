<?php

namespace App\Entity;

use App\Repository\MailboxMemberRepository;
use Doctrine\ORM\Mapping as ORM;
use Rocket\Core\Entity\TrackedTrait;
use Rocket\Core\Entity\User;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * A user of a shared inbox. "member": reads the conversations, replies and sends from the mailbox;
 * "manager": also manages the members. Managed through /api/inbox (see InboxMemberController).
 */
#[ORM\Entity(repositoryClass: MailboxMemberRepository::class)]
#[ORM\UniqueConstraint(fields: ['mailbox', 'user'])]
class MailboxMember
{
    public const ROLE_MEMBER = 'member';
    public const ROLE_MANAGER = 'manager';
    public const ROLES = [self::ROLE_MEMBER, self::ROLE_MANAGER];

    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private Uuid $id;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Mailbox $mailbox;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column(length: 16)]
    private string $role;

    use TrackedTrait;

    public function __construct(Mailbox $mailbox, User $user, string $role = self::ROLE_MEMBER)
    {
        $this->id = Uuid::v7();
        $this->mailbox = $mailbox;
        $this->user = $user;
        $this->setRole($role);
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getMailbox(): Mailbox
    {
        return $this->mailbox;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getRole(): string
    {
        return $this->role;
    }

    public function setRole(string $role): static
    {
        if (!\in_array($role, self::ROLES, true)) {
            throw new \InvalidArgumentException(\sprintf('Unknown role "%s".', $role));
        }
        $this->role = $role;

        return $this;
    }

    public function isManager(): bool
    {
        return self::ROLE_MANAGER === $this->role;
    }
}
