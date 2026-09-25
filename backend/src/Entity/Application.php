<?php

namespace App\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use App\Repository\ApplicationRepository;
use App\State\ApplicationCreateProcessor;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Mime\Address;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Serializer\Attribute\SerializedName;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * An external application authenticated by a secret token, optionally allowed to act on behalf of users.
 */
#[ORM\Entity(repositoryClass: ApplicationRepository::class)]
#[ApiResource(
    operations: [
        new GetCollection(),
        new Get(),
        new Post(processor: ApplicationCreateProcessor::class, normalizationContext: ['groups' => ['app:read', 'app:token', 'tracking']]),
        new Patch(),
        new Delete(),
    ],
    normalizationContext: ['groups' => ['app:read', 'tracking']],
    denormalizationContext: ['groups' => ['app:write']],
    security: "is_granted('ROLE_ADMIN')",
    order: ['name' => 'ASC'],
)]
class Application
{
    public const TOKEN_PREFIX = 'rma_';

    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[Groups(['app:read'])]
    private Uuid $id;

    #[ORM\Column(length: 120)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 120)]
    #[Groups(['app:read', 'app:write'])]
    private string $name = '';

    #[ORM\Column(type: 'text', nullable: true)]
    #[Groups(['app:read', 'app:write'])]
    private ?string $description = null;

    #[ORM\Column(length: 64, unique: true)]
    private string $tokenHash = '';

    #[ORM\Column(length: 16)]
    #[Groups(['app:read'])]
    private string $tokenHint = '';

    #[Groups(['app:token'])]
    private ?string $plainToken = null;

    #[ORM\Column]
    #[Groups(['app:read', 'app:write'])]
    private bool $canImpersonate = false;

    /** @var list<string> Origins allowed to embed the compose UI (CSP frame-ancestors). */
    #[ORM\Column]
    #[Assert\All([new Assert\Regex(pattern: '#^https?://[a-z0-9.\-]+(:\d+)?$#i', message: 'Each origin must look like https://example.com[:port].')])]
    #[Groups(['app:read', 'app:write'])]
    private array $allowedOrigins = [];

    /**
     * "From" addresses this application may impose on the fly, besides the settings' sender addresses:
     * exact addresses or whole domains ("*@crm.example.com").
     *
     * @var list<string>
     */
    #[ORM\Column(options: ['default' => '[]'])]
    #[Assert\All([new Assert\Regex(pattern: '/^(\*|[^@\s*]+)@[a-z0-9-]+(\.[a-z0-9-]+)+$/i', message: 'Use an email address or "*@domain".')])]
    #[Groups(['app:read', 'app:write'])]
    private array $allowedSenders = [];

    /**
     * The application's own sender: the default "From" of its composer and of its API sends.
     * Required: an application never sends from the platform's addresses (settings, Rocket Mailer).
     */
    #[ORM\Column(length: 180, nullable: true)]
    #[Assert\Email]
    #[Assert\Length(max: 180)]
    #[Groups(['app:read', 'app:write'])]
    private ?string $senderEmail = null;

    #[ORM\Column(length: 120, nullable: true)]
    #[Assert\Length(max: 120)]
    #[Groups(['app:read', 'app:write'])]
    private ?string $senderName = null;

    /** Colors of its embedded composer; null: the project's palette. */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    #[Groups(['app:write'])]
    private ?ColorPalette $palette = null;

    #[ORM\Column]
    #[Groups(['app:read', 'app:write'])]
    private bool $enabled = true;

    #[ORM\Column(nullable: true)]
    #[Groups(['app:read'])]
    private ?\DateTimeImmutable $lastUsedAt = null;

    use TrackedTrait;

    public function __construct()
    {
        $this->id = Uuid::v7();
    }

    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    /** Generates a new secret, stores its hash and returns the plain value (shown once). */
    public function rotateToken(): string
    {
        $token = self::TOKEN_PREFIX.bin2hex(random_bytes(32));
        $this->tokenHash = self::hashToken($token);
        $this->tokenHint = substr($token, 0, 10);
        $this->plainToken = $token;

        return $token;
    }

    /** Installs a known secret (demo environment only, see app:demo:seed). */
    public function useToken(string $token): void
    {
        if (!str_starts_with($token, self::TOKEN_PREFIX) || \strlen($token) < 36) {
            throw new \InvalidArgumentException(\sprintf('An application token must start with "%s" and be at least 36 characters long.', self::TOKEN_PREFIX));
        }

        $this->tokenHash = self::hashToken($token);
        $this->tokenHint = substr($token, 0, 10);
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

    public function getTokenHint(): string
    {
        return $this->tokenHint;
    }

    public function getPlainToken(): ?string
    {
        return $this->plainToken;
    }

    public function canImpersonate(): bool
    {
        return $this->canImpersonate;
    }

    /** Accessor used by the serializer (it does not map a bare "canImpersonate()" to the property). */
    public function getCanImpersonate(): bool
    {
        return $this->canImpersonate;
    }

    public function setCanImpersonate(bool $canImpersonate): static
    {
        $this->canImpersonate = $canImpersonate;

        return $this;
    }

    /** @return list<string> */
    public function getAllowedOrigins(): array
    {
        return $this->allowedOrigins;
    }

    /** @param list<string> $allowedOrigins */
    public function setAllowedOrigins(array $allowedOrigins): static
    {
        $this->allowedOrigins = array_values(array_unique(array_map(static fn (string $o) => rtrim(trim($o), '/'), $allowedOrigins)));

        return $this;
    }

    /** @return list<string> */
    public function getAllowedSenders(): array
    {
        return $this->allowedSenders;
    }

    /** @param list<string> $allowedSenders */
    public function setAllowedSenders(array $allowedSenders): static
    {
        $this->allowedSenders = array_values(array_unique(array_filter(array_map(
            static fn (string $s) => mb_strtolower(trim($s)),
            $allowedSenders,
        ))));

        return $this;
    }

    public function allowsSender(string $email): bool
    {
        $email = mb_strtolower($email);
        $domain = substr((string) strrchr($email, '@'), 1);

        foreach ($this->allowedSenders as $pattern) {
            if ($pattern === $email || ('' !== $domain && $pattern === '*@'.$domain)) {
                return true;
            }
        }

        return false;
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function setEnabled(bool $enabled): static
    {
        $this->enabled = $enabled;

        return $this;
    }

    public function getLastUsedAt(): ?\DateTimeImmutable
    {
        return $this->lastUsedAt;
    }

    public function getSenderEmail(): ?string
    {
        return $this->senderEmail;
    }

    public function setSenderEmail(?string $senderEmail): static
    {
        $this->senderEmail = null === $senderEmail || '' === trim($senderEmail) ? null : mb_strtolower(trim($senderEmail));

        return $this;
    }

    public function getSenderName(): ?string
    {
        return $this->senderName;
    }

    public function setSenderName(?string $senderName): static
    {
        $this->senderName = null === $senderName || '' === trim($senderName) ? null : trim($senderName);

        return $this;
    }

    /** The application's own sender, null when not configured yet. */
    public function getSenderAddress(): ?Address
    {
        return null === $this->senderEmail ? null : new Address($this->senderEmail, $this->senderName ?? '');
    }

    public function getPalette(): ?ColorPalette
    {
        return $this->palette;
    }

    public function setPalette(?ColorPalette $palette): static
    {
        $this->palette = $palette;

        return $this;
    }

    /** @return array{'@id': string, id: string, name: string}|null */
    #[Groups(['app:read'])]
    #[SerializedName('palette')]
    public function getPaletteSummary(): ?array
    {
        return null === $this->palette ? null : [
            '@id' => '/api/color_palettes/'.$this->palette->getId(),
            'id' => (string) $this->palette->getId(),
            'name' => $this->palette->getName(),
        ];
    }
}
