<?php

namespace App\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use App\Repository\EmailTemplateRepository;
use App\Template\Layouts;
use App\Template\Placeholders;
use App\State\EmailTemplateProcessor;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Gedmo\Mapping\Annotation as Gedmo;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Serializer\Attribute\SerializedName;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

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

    /** HTML layout around the content (header, footer, colors…). */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    #[Gedmo\Versioned]
    #[Groups(['template:write'])]
    private ?EmailLayout $layout = null;

    /**
     * Declared variables ("{{ name }}" placeholders): label and default value shown to whoever fills them.
     *
     * @var list<array{name: string, label: ?string, defaultValue: ?string}>
     */
    #[ORM\Column(type: Types::JSON, options: ['default' => '[]'])]
    #[Gedmo\Versioned]
    private array $variables = [];

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

    /**
     * Declared variables, then the placeholders used in the content or the subject without being declared.
     *
     * @return list<array{name: string, label: ?string, defaultValue: ?string, used: bool}>
     */
    #[Groups(['template:list'])]
    public function getVariables(): array
    {
        $used = array_values(array_diff(Placeholders::names($this->defaultSubject, $this->getRenderedHtml()), [Layouts::SLOT]));
        $variables = array_map(static fn (array $v) => $v + ['used' => \in_array($v['name'], $used, true)], $this->variables);
        $declared = array_column($this->variables, 'name');
        foreach (array_diff($used, $declared) as $name) {
            $variables[] = ['name' => $name, 'label' => null, 'defaultValue' => null, 'used' => true];
        }

        return $variables;
    }

    /** @param list<array{name?: mixed, label?: mixed, defaultValue?: mixed}> $variables */
    #[Groups(['template:write'])]
    public function setVariables(array $variables): static
    {
        $this->variables = [];
        foreach ($variables as $variable) {
            $name = trim((string) ($variable['name'] ?? ''));
            if ('' === $name || \in_array($name, array_column($this->variables, 'name'), true)) {
                continue;
            }
            $label = trim((string) ($variable['label'] ?? ''));
            $default = $variable['defaultValue'] ?? null;
            $this->variables[] = [
                'name' => $name,
                'label' => '' === $label ? null : mb_substr($label, 0, 150),
                'defaultValue' => null === $default || '' === (string) $default ? null : mb_substr((string) $default, 0, 1000),
            ];
        }

        return $this;
    }

    /** @return array<string, string> default values of the declared variables */
    public function getVariableDefaults(): array
    {
        $defaults = [];
        foreach ($this->variables as $variable) {
            if (null !== $variable['defaultValue']) {
                $defaults[$variable['name']] = $variable['defaultValue'];
            }
        }

        return $defaults;
    }

    #[Assert\Callback]
    public function validateVariables(ExecutionContextInterface $context): void
    {
        foreach ($this->variables as $index => $variable) {
            if (!Placeholders::isValidName($variable['name'])) {
                $context->buildViolation('"{{ name }}" is not a valid variable name: use letters, digits, "_" and "." (e.g. client.firstName).')
                    ->setParameter('{{ name }}', $variable['name'])
                    ->atPath("variables[$index].name")
                    ->addViolation();
            }
        }
        if (\count($this->variables) > 50) {
            $context->buildViolation('50 variables at most.')->atPath('variables')->addViolation();
        }
    }

    public function getLayout(): ?EmailLayout
    {
        return $this->layout;
    }

    public function setLayout(?EmailLayout $layout): static
    {
        $this->layout = $layout;

        return $this;
    }

    /** Layout as IRI and name, for the editor and the pickers. */
    #[Groups(['template:list'])]
    #[SerializedName('layout')]
    public function getLayoutSummary(): ?array
    {
        return null === $this->layout ? null : [
            '@id' => '/api/email_layouts/'.$this->layout->getId()->toRfc4122(),
            'id' => $this->layout->getId()->toRfc4122(),
            'name' => $this->layout->getName(),
        ];
    }

    /** What is imported in the composer and sent: the content in its layout, if any. */
    #[Groups(['template:read'])]
    public function getRenderedHtml(): string
    {
        return null === $this->layout ? $this->html : Layouts::wrap($this->layout->getHtml(), $this->html);
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
