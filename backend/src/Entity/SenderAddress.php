<?php

namespace App\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use App\Repository\SenderAddressRepository;
use App\State\SenderAddressProcessor;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Mime\Address;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * A "From" address offered in the composer, managed in the settings. Exactly one is the default.
 * The first one is created from MAILER_DEFAULT_FROM at install (see SenderPolicy).
 */
#[ORM\Entity(repositoryClass: SenderAddressRepository::class)]
#[UniqueEntity('email')]
#[ApiResource(
    operations: [
        new GetCollection(),
        new Post(processor: SenderAddressProcessor::class),
        new Patch(processor: SenderAddressProcessor::class),
        new Delete(processor: SenderAddressProcessor::class),
    ],
    normalizationContext: ['groups' => ['sender:read', 'tracking']],
    denormalizationContext: ['groups' => ['sender:write']],
    security: "is_granted('ROLE_ADMIN')",
    order: ['isDefault' => 'DESC', 'email' => 'ASC'],
)]
class SenderAddress
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[Groups(['sender:read'])]
    private Uuid $id;

    #[ORM\Column(length: 180, unique: true)]
    #[Assert\NotBlank]
    #[Assert\Email]
    #[Groups(['sender:read', 'sender:write'])]
    private string $email = '';

    #[ORM\Column(length: 120, nullable: true)]
    #[Assert\Length(max: 120)]
    #[Groups(['sender:read', 'sender:write'])]
    private ?string $name = null;

    #[ORM\Column]
    #[Groups(['sender:read', 'sender:write'])]
    private bool $isDefault = false;

    use TrackedTrait;

    public function __construct()
    {
        $this->id = Uuid::v7();
    }

    public static function fromAddress(Address $address, bool $isDefault = false): self
    {
        return (new self())
            ->setEmail($address->getAddress())
            ->setName('' !== $address->getName() ? $address->getName() : null)
            ->setIsDefault($isDefault);
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getEmail(): string
    {
        return $this->email;
    }

    public function setEmail(string $email): static
    {
        $this->email = mb_strtolower(trim($email));

        return $this;
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function setName(?string $name): static
    {
        $name = null === $name ? null : trim($name);
        $this->name = '' === $name ? null : $name;

        return $this;
    }

    public function isDefault(): bool
    {
        return $this->isDefault;
    }

    public function getIsDefault(): bool
    {
        return $this->isDefault;
    }

    public function setIsDefault(bool $isDefault): static
    {
        $this->isDefault = $isDefault;

        return $this;
    }

    public function toAddress(): Address
    {
        return new Address($this->email, $this->name ?? '');
    }
}
