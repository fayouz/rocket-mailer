<?php

namespace App\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Post;
use App\Enum\EmailStatus;
use App\Repository\EmailRepository;
use App\State\EmailSendProcessor;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: EmailRepository::class)]
#[ORM\Index(fields: ['status'])]
#[ApiResource(
    operations: [
        new GetCollection(normalizationContext: ['groups' => ['email:list', 'user:summary', 'tracking']]),
        new Get(security: "is_granted('ROLE_ADMIN') or object.getSender() == user"),
        new Post(processor: EmailSendProcessor::class, status: 202),
    ],
    normalizationContext: ['groups' => ['email:list', 'email:read', 'user:summary', 'tracking']],
    denormalizationContext: ['groups' => ['email:write']],
    order: ['createdAt' => 'DESC'],
)]
class Email
{
    public const MAX_RECIPIENTS = 50;

    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[Groups(['email:list'])]
    private Uuid $id;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    #[Groups(['email:list'])]
    private ?User $sender = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Application $application = null;

    /** @var list<string> */
    #[ORM\Column(name: 'recipients_to')]
    #[Assert\Count(min: 1, max: self::MAX_RECIPIENTS)]
    #[Assert\All([new Assert\NotBlank(), new Assert\Email()])]
    #[Groups(['email:list', 'email:write'])]
    private array $to = [];

    /** @var list<string> */
    #[ORM\Column(name: 'recipients_cc')]
    #[Assert\Count(max: self::MAX_RECIPIENTS)]
    #[Assert\All([new Assert\NotBlank(), new Assert\Email()])]
    #[Groups(['email:read', 'email:write'])]
    private array $cc = [];

    /** @var list<string> */
    #[ORM\Column(name: 'recipients_bcc')]
    #[Assert\Count(max: self::MAX_RECIPIENTS)]
    #[Assert\All([new Assert\NotBlank(), new Assert\Email()])]
    #[Groups(['email:read', 'email:write'])]
    private array $bcc = [];

    #[ORM\Column(length: 255)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 255)]
    #[Groups(['email:list', 'email:write'])]
    private string $subject = '';

    #[ORM\Column(type: Types::TEXT)]
    #[Assert\NotBlank]
    #[Groups(['email:read', 'email:write'])]
    private string $htmlBody = '';

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    #[Groups(['email:read', 'email:write'])]
    private ?EmailTemplate $template = null;

    #[ORM\Column(length: 16, enumType: EmailStatus::class)]
    #[Groups(['email:list'])]
    private EmailStatus $status = EmailStatus::Queued;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    #[Groups(['email:read'])]
    private ?string $errorMessage = null;

    #[ORM\Column(nullable: true)]
    #[Groups(['email:list'])]
    private ?\DateTimeImmutable $sentAt = null;

    use TrackedTrait;

    public function __construct()
    {
        $this->id = Uuid::v7();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getSender(): ?User
    {
        return $this->sender;
    }

    public function setSender(User $sender): static
    {
        $this->sender = $sender;

        return $this;
    }

    public function getApplication(): ?Application
    {
        return $this->application;
    }

    public function setApplication(?Application $application): static
    {
        $this->application = $application;

        return $this;
    }

    #[Groups(['email:list'])]
    public function getApplicationName(): ?string
    {
        return $this->application?->getName();
    }

    /** @return list<string> */
    public function getTo(): array
    {
        return $this->to;
    }

    /** @param list<string> $to */
    public function setTo(array $to): static
    {
        $this->to = self::normalizeAddresses($to);

        return $this;
    }

    /** @return list<string> */
    public function getCc(): array
    {
        return $this->cc;
    }

    /** @param list<string> $cc */
    public function setCc(array $cc): static
    {
        $this->cc = self::normalizeAddresses($cc);

        return $this;
    }

    /** @return list<string> */
    public function getBcc(): array
    {
        return $this->bcc;
    }

    /** @param list<string> $bcc */
    public function setBcc(array $bcc): static
    {
        $this->bcc = self::normalizeAddresses($bcc);

        return $this;
    }

    public function getSubject(): string
    {
        return $this->subject;
    }

    public function setSubject(string $subject): static
    {
        $this->subject = $subject;

        return $this;
    }

    public function getHtmlBody(): string
    {
        return $this->htmlBody;
    }

    public function setHtmlBody(string $htmlBody): static
    {
        $this->htmlBody = $htmlBody;

        return $this;
    }

    public function getTemplate(): ?EmailTemplate
    {
        return $this->template;
    }

    public function setTemplate(?EmailTemplate $template): static
    {
        $this->template = $template;

        return $this;
    }

    public function getStatus(): EmailStatus
    {
        return $this->status;
    }

    public function getErrorMessage(): ?string
    {
        return $this->errorMessage;
    }

    public function getSentAt(): ?\DateTimeImmutable
    {
        return $this->sentAt;
    }

    public function markSent(): static
    {
        $this->status = EmailStatus::Sent;
        $this->sentAt = new \DateTimeImmutable();
        $this->errorMessage = null;

        return $this;
    }

    public function markFailed(string $error): static
    {
        $this->status = EmailStatus::Failed;
        $this->errorMessage = mb_substr($error, 0, 2000);

        return $this;
    }

    /**
     * @param list<string> $addresses
     *
     * @return list<string>
     */
    private static function normalizeAddresses(array $addresses): array
    {
        return array_values(array_unique(array_filter(array_map(static fn ($a) => trim((string) $a), $addresses), static fn (string $a) => '' !== $a)));
    }
}
