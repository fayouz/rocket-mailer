<?php

namespace App\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use App\Repository\EmailLayoutRepository;
use App\Template\Layouts;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * An HTML layout around templates: the organization's header, footer, fonts and colors.
 * Its HTML contains the {{ content }} slot, replaced by the template's content (see Layouts).
 */
#[ORM\Entity(repositoryClass: EmailLayoutRepository::class)]
#[ApiResource(
    operations: [
        new GetCollection(security: "is_granted('ROLE_USER') and not is_granted('ROLE_EMBED')"),
        new Get(security: "is_granted('ROLE_USER') and not is_granted('ROLE_EMBED')"),
        new Post(security: "is_granted('ROLE_ADMIN')"),
        new Patch(security: "is_granted('ROLE_ADMIN')"),
        new Delete(security: "is_granted('ROLE_ADMIN')"),
    ],
    normalizationContext: ['groups' => ['layout:read', 'tracking']],
    denormalizationContext: ['groups' => ['layout:write']],
    order: ['name' => 'ASC'],
)]
class EmailLayout
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[Groups(['layout:read'])]
    private Uuid $id;

    #[ORM\Column(length: 120)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 120)]
    #[Groups(['layout:read', 'layout:write'])]
    private string $name = '';

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    #[Groups(['layout:read', 'layout:write'])]
    private ?string $description = null;

    /** Full HTML document with the {{ content }} slot. */
    #[ORM\Column(type: Types::TEXT)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 500000)]
    #[Groups(['layout:read', 'layout:write'])]
    private string $html = '';

    use TrackedTrait;

    public function __construct()
    {
        $this->id = Uuid::v7();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): static
    {
        $this->name = trim($name);

        return $this;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): static
    {
        $this->description = null === $description || '' === trim($description) ? null : trim($description);

        return $this;
    }

    public function getHtml(): string
    {
        return $this->html;
    }

    public function setHtml(string $html): static
    {
        $this->html = $html;

        return $this;
    }

    #[Assert\Callback]
    public function validateSlot(ExecutionContextInterface $context): void
    {
        if ('' !== $this->html && !Layouts::hasSlot($this->html)) {
            $context->buildViolation('The layout must contain the {{ content }} slot, where the template content goes.')
                ->atPath('html')
                ->addViolation();
        }
    }
}
