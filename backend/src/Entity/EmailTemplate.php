<?php

namespace App\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use App\Repository\EmailTemplateRepository;
use App\State\EmailTemplateProcessor;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Gedmo\Mapping\Annotation as Gedmo;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: EmailTemplateRepository::class)]
#[Gedmo\Loggable(logEntryClass: EmailTemplateVersion::class)]
#[ApiResource(
    operations: [
        new GetCollection(normalizationContext: ['groups' => ['template:list', 'user:summary', 'tracking']]),
        new Get(security: "is_granted('ROLE_ADMIN') or object.isShared() or object.getOwner() == user"),
        new Post(processor: EmailTemplateProcessor::class, securityPostDenormalize: "not is_granted('ROLE_EMBED')"),
        new Patch(security: "not is_granted('ROLE_EMBED') and (is_granted('ROLE_ADMIN') or object.getOwner() == user)"),
        new Delete(security: "not is_granted('ROLE_EMBED') and (is_granted('ROLE_ADMIN') or object.getOwner() == user)"),
    ],
    normalizationContext: ['groups' => ['template:list', 'template:read', 'user:summary', 'tracking']],
    denormalizationContext: ['groups' => ['template:write']],
    order: ['name' => 'ASC'],
)]
class EmailTemplate
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[Groups(['template:list'])]
    private Uuid $id;

    #[ORM\Column(length: 150)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 150)]
    #[Gedmo\Versioned]
    #[Groups(['template:list', 'template:write'])]
    private string $name = '';

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    #[Gedmo\Versioned]
    #[Groups(['template:list', 'template:write'])]
    private ?string $description = null;

    #[ORM\Column(length: 255, nullable: true)]
    #[Gedmo\Versioned]
    #[Groups(['template:list', 'template:write'])]
    private ?string $defaultSubject = null;

    /** GrapesJS project data, reloaded into the editor as-is. */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    #[Gedmo\Versioned]
    #[Groups(['template:read', 'template:write'])]
    private ?array $projectData = null;

    /** Email-ready HTML (CSS inlined) produced by GrapesJS, imported into the compose editor. */
    #[ORM\Column(type: Types::TEXT)]
    #[Gedmo\Versioned]
    #[Groups(['template:read', 'template:write'])]
    private string $html = '';

    #[ORM\Column]
    #[Gedmo\Versioned]
    #[Groups(['template:list', 'template:write'])]
    private bool $shared = false;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    #[Groups(['template:list'])]
    private ?User $owner = null;

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
        $this->name = $name;

        return $this;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): static
    {
        $this->description = $description;

        return $this;
    }

    public function getDefaultSubject(): ?string
    {
        return $this->defaultSubject;
    }

    public function setDefaultSubject(?string $defaultSubject): static
    {
        $this->defaultSubject = $defaultSubject;

        return $this;
    }

    public function getProjectData(): ?array
    {
        return $this->projectData;
    }

    public function setProjectData(?array $projectData): static
    {
        $this->projectData = $projectData;

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

    public function isShared(): bool
    {
        return $this->shared;
    }

    public function setShared(bool $shared): static
    {
        $this->shared = $shared;

        return $this;
    }

    public function getOwner(): ?User
    {
        return $this->owner;
    }

    public function setOwner(User $owner): static
    {
        $this->owner = $owner;

        return $this;
    }
}
