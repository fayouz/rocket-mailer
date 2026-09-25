<?php

namespace App\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use App\Repository\ColorPaletteRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Colors of the interface: the project's palette (Réglages) and, for its embedded composer, an application's one.
 * Semantic colors are "#rrggbb"; the shades (50 to 950) are derived by the front. Empty: Rocket Mailer's default.
 */
#[ORM\Entity(repositoryClass: ColorPaletteRepository::class)]
#[ApiResource(
    operations: [
        new GetCollection(),
        new Get(),
        new Post(),
        new Patch(),
        new Delete(),
    ],
    normalizationContext: ['groups' => ['palette:read', 'tracking']],
    denormalizationContext: ['groups' => ['palette:write']],
    security: "is_granted('ROLE_ADMIN')",
    order: ['name' => 'ASC'],
)]
class ColorPalette
{
    public const NEUTRALS = ['slate', 'gray', 'zinc', 'neutral', 'stone'];
    /** Semantic colors of Nuxt UI a palette can set. */
    public const COLORS = ['primary', 'secondary', 'success', 'info', 'warning', 'error'];
    private const HEX = '/^#[0-9a-f]{6}$/i';

    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[Groups(['palette:read'])]
    private Uuid $id;

    #[ORM\Column(length: 80)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 80)]
    #[Groups(['palette:read', 'palette:write'])]
    private string $name = '';

    #[ORM\Column(name: 'primary_color', length: 7)]
    #[Assert\NotBlank]
    #[Assert\Regex(pattern: self::HEX, message: 'Use a color like #4f46e5.')]
    #[Groups(['palette:read', 'palette:write'])]
    private string $primary = '#f97316';

    #[ORM\Column(length: 7, nullable: true)]
    #[Assert\Regex(pattern: self::HEX, message: 'Use a color like #4f46e5.')]
    #[Groups(['palette:read', 'palette:write'])]
    private ?string $secondary = null;

    #[ORM\Column(length: 7, nullable: true)]
    #[Assert\Regex(pattern: self::HEX, message: 'Use a color like #4f46e5.')]
    #[Groups(['palette:read', 'palette:write'])]
    private ?string $success = null;

    #[ORM\Column(length: 7, nullable: true)]
    #[Assert\Regex(pattern: self::HEX, message: 'Use a color like #4f46e5.')]
    #[Groups(['palette:read', 'palette:write'])]
    private ?string $info = null;

    #[ORM\Column(length: 7, nullable: true)]
    #[Assert\Regex(pattern: self::HEX, message: 'Use a color like #4f46e5.')]
    #[Groups(['palette:read', 'palette:write'])]
    private ?string $warning = null;

    #[ORM\Column(length: 7, nullable: true)]
    #[Assert\Regex(pattern: self::HEX, message: 'Use a color like #4f46e5.')]
    #[Groups(['palette:read', 'palette:write'])]
    private ?string $error = null;

    /** Tone of the grays (backgrounds, borders, text). */
    #[ORM\Column(length: 20)]
    #[Assert\Choice(choices: self::NEUTRALS)]
    #[Groups(['palette:read', 'palette:write'])]
    private string $neutral = 'zinc';

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

    public function getPrimary(): string
    {
        return $this->primary;
    }

    public function setPrimary(string $primary): static
    {
        $this->primary = mb_strtolower(trim($primary));

        return $this;
    }

    public function getSecondary(): ?string
    {
        return $this->secondary;
    }

    public function setSecondary(?string $secondary): static
    {
        $this->secondary = self::color($secondary);

        return $this;
    }

    public function getSuccess(): ?string
    {
        return $this->success;
    }

    public function setSuccess(?string $success): static
    {
        $this->success = self::color($success);

        return $this;
    }

    public function getInfo(): ?string
    {
        return $this->info;
    }

    public function setInfo(?string $info): static
    {
        $this->info = self::color($info);

        return $this;
    }

    public function getWarning(): ?string
    {
        return $this->warning;
    }

    public function setWarning(?string $warning): static
    {
        $this->warning = self::color($warning);

        return $this;
    }

    public function getError(): ?string
    {
        return $this->error;
    }

    public function setError(?string $error): static
    {
        $this->error = self::color($error);

        return $this;
    }

    public function getNeutral(): string
    {
        return $this->neutral;
    }

    public function setNeutral(string $neutral): static
    {
        $this->neutral = $neutral;

        return $this;
    }

    /** @return array{id: string, name: string, colors: array<string, string>, neutral: string} what the front applies */
    public function toTheme(): array
    {
        $colors = [];
        foreach (self::COLORS as $key) {
            if (null !== $value = $this->{$key}) {
                $colors[$key] = $value;
            }
        }

        return ['id' => (string) $this->id, 'name' => $this->name, 'colors' => $colors, 'neutral' => $this->neutral];
    }

    private static function color(?string $value): ?string
    {
        return null === $value || '' === trim($value) ? null : mb_strtolower(trim($value));
    }
}
