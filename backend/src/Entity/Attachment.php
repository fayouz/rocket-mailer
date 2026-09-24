<?php

namespace App\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use App\Repository\AttachmentRepository;
use App\State\AttachmentDeleteProcessor;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;

/**
 * A file uploaded by a user, then attached to one email when it is sent.
 * Uploaded with POST /api/attachments (multipart), see AttachmentController.
 */
#[ORM\Entity(repositoryClass: AttachmentRepository::class)]
#[ORM\Index(fields: ['createdAt'])]
#[ApiResource(
    operations: [
        new Get(security: "is_granted('ROLE_ADMIN') or object.getOwner() == user"),
        new Delete(
            security: "object.getOwner() == user and object.getEmail() === null",
            securityMessage: 'Only your own attachments that have not been sent can be removed.',
            processor: AttachmentDeleteProcessor::class,
        ),
    ],
    normalizationContext: ['groups' => ['attachment:read', 'tracking']],
)]
class Attachment
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[Groups(['attachment:read', 'email:read'])]
    private Uuid $id;

    #[ORM\Column(length: 255)]
    #[Groups(['attachment:read', 'email:read'])]
    private string $filename;

    #[ORM\Column(length: 127)]
    #[Groups(['attachment:read', 'email:read'])]
    private string $mimeType;

    #[ORM\Column]
    #[Groups(['attachment:read', 'email:read'])]
    private int $size;

    /** Name of the file in the attachments storage directory (never exposed). */
    #[ORM\Column(length: 64, unique: true)]
    private string $storageName;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $owner;

    #[ORM\ManyToOne(inversedBy: 'attachments')]
    #[ORM\JoinColumn(nullable: true, onDelete: 'CASCADE')]
    private ?Email $email = null;

    use TrackedTrait;

    public function __construct(User $owner, string $filename, string $mimeType, int $size)
    {
        $this->id = Uuid::v7();
        $this->storageName = $this->id->toRfc4122();
        $this->owner = $owner;
        $this->filename = $filename;
        $this->mimeType = $mimeType;
        $this->size = $size;
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getFilename(): string
    {
        return $this->filename;
    }

    public function getMimeType(): string
    {
        return $this->mimeType;
    }

    public function getSize(): int
    {
        return $this->size;
    }

    public function getStorageName(): string
    {
        return $this->storageName;
    }

    public function getOwner(): User
    {
        return $this->owner;
    }

    public function getEmail(): ?Email
    {
        return $this->email;
    }

    public function attachTo(Email $email): void
    {
        if (null !== $this->email) {
            throw new \LogicException('This attachment has already been sent.');
        }

        $this->email = $email;
    }
}
