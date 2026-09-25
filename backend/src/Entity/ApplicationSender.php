<?php

namespace App\Entity;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Link;
use ApiPlatform\Metadata\Patch;
use App\Repository\ApplicationSenderRepository;
use App\State\ApplicationSenderProvider;
use Doctrine\ORM\Mapping as ORM;
use Rocket\Core\Entity\Application;
use Symfony\Component\Mime\Address;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Sender settings of an external application (Rocket\Core\Entity\Application): its own "From" address and the
 * addresses it may impose. Identified by the application: /api/application_senders/{application id}, readable
 * (with defaults) before anything was saved.
 */
#[ORM\Entity(repositoryClass: ApplicationSenderRepository::class)]
#[ApiResource(
    operations: [
        new GetCollection(),
        new Get(uriVariables: ['id' => new Link(fromClass: self::class, identifiers: ['id'])], provider: ApplicationSenderProvider::class),
        new Patch(uriVariables: ['id' => new Link(fromClass: self::class, identifiers: ['id'])], provider: ApplicationSenderProvider::class),
    ],
    normalizationContext: ['groups' => ['app_sender:read']],
    denormalizationContext: ['groups' => ['app_sender:write']],
    security: "is_granted('ROLE_ADMIN')",
)]
class ApplicationSender
{
    #[ORM\Id]
    #[ORM\OneToOne(targetEntity: Application::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    #[ApiProperty(identifier: false)]
    private Application $application;

    /**
     * The application's own sender: the default "From" of its composer and of its API sends.
     * Required to send: an application never sends from the platform's addresses (settings, Rocket Mailer).
     */
    #[ORM\Column(length: 180, nullable: true)]
    #[Assert\Email]
    #[Assert\Length(max: 180)]
    #[Groups(['app_sender:read', 'app_sender:write'])]
    private ?string $senderEmail = null;

    #[ORM\Column(length: 120, nullable: true)]
    #[Assert\Length(max: 120)]
    #[Groups(['app_sender:read', 'app_sender:write'])]
    private ?string $senderName = null;

    /**
     * "From" addresses this application may impose on the fly, besides its own sender:
     * exact addresses or whole domains ("*@crm.example.com").
     *
     * @var list<string>
     */
    #[ORM\Column(options: ['default' => '[]'])]
    #[Assert\All([new Assert\Regex(pattern: '/^(\*|[^@\s*]+)@[a-z0-9-]+(\.[a-z0-9-]+)+$/i', message: 'Use an email address or "*@domain".')])]
    #[Groups(['app_sender:read', 'app_sender:write'])]
    private array $allowedSenders = [];

    public function __construct(Application $application)
    {
        $this->application = $application;
    }

    /** Identifier of the application. */
    #[ApiProperty(identifier: true)]
    #[Groups(['app_sender:read'])]
    public function getId(): Uuid
    {
        return $this->application->getId();
    }

    public function getApplication(): Application
    {
        return $this->application;
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
}
