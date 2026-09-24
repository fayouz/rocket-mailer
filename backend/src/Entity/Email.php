<?php

namespace App\Entity;

use ApiPlatform\Doctrine\Orm\Filter\DateFilter;
use ApiPlatform\Doctrine\Orm\Filter\ExactFilter;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Post;
use ApiPlatform\Metadata\QueryParameter;
use App\Doctrine\EmailSearchFilter;
use App\Enum\EmailStatus;
use App\Repository\EmailRepository;
use App\Sender\AddressFormatter;
use App\State\EmailSendProcessor;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Mime\Address;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

#[ORM\Entity(repositoryClass: EmailRepository::class)]
#[ORM\Index(fields: ['status'])]
#[ORM\Index(fields: ['createdAt'])]
#[ApiResource(
    operations: [
        new GetCollection(
            normalizationContext: ['groups' => ['email:list', 'user:summary', 'tracking']],
            parameters: [
                'q' => new QueryParameter(filter: new EmailSearchFilter(), description: 'Search in the subject, the sender address and the recipients'),
                'status' => new QueryParameter(filter: new ExactFilter(), property: 'status', schema: ['type' => 'string', 'enum' => ['queued', 'sent', 'failed']]),
                'sender' => new QueryParameter(filter: new ExactFilter(), property: 'sender', description: 'User id', constraints: [new Assert\Uuid()]),
                'application' => new QueryParameter(filter: new ExactFilter(), property: 'application', description: 'Application id', constraints: [new Assert\Uuid()]),
                'createdAt' => new QueryParameter(filter: new DateFilter(), property: 'createdAt', description: 'createdAt[after]=2026-09-01&createdAt[before]=2026-09-30'),
            ],
        ),
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
    public const MAX_ATTACHMENTS = 10;

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

    /** Resolved "From" (see SenderPolicy); null for emails sent before it existed. */
    #[ORM\Column(length: 180, nullable: true)]
    private ?string $fromAddress = null;

    #[ORM\Column(length: 180, nullable: true)]
    private ?string $fromName = null;

    /** "From" as requested by the caller ("Name <email>" or "email"), validated by SenderPolicy. */
    private ?string $requestedFrom = null;

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

    /** Optional with a template: its default subject is used (see EmailSendProcessor). */
    #[ORM\Column(length: 255)]
    #[Assert\Length(max: 255)]
    #[Groups(['email:list', 'email:write'])]
    private string $subject = '';

    /** Optional with a template: its content is used (see EmailSendProcessor). */
    #[ORM\Column(type: Types::TEXT)]
    #[Groups(['email:read', 'email:write'])]
    private string $htmlBody = '';

    /**
     * Values of the template variables ("{{ name }}"), flat ({"client.firstName": "Jean"}) or nested
     * ({"client": {"firstName": "Jean"}}). Applied to the subject and the body before sending; not stored.
     *
     * @var array<mixed>
     */
    #[Groups(['email:write'])]
    private array $variables = [];

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    #[Groups(['email:read', 'email:write'])]
    private ?EmailTemplate $template = null;

    /**
     * Uploaded with POST /api/attachments, referenced here by IRI.
     * The owning side (Attachment::$email) is set by EmailSendProcessor once ownership is checked.
     *
     * @var Collection<int, Attachment>
     */
    #[ORM\OneToMany(targetEntity: Attachment::class, mappedBy: 'email')]
    #[Assert\Count(max: self::MAX_ATTACHMENTS)]
    #[Groups(['email:read', 'email:write'])]
    private Collection $attachments;

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
        $this->attachments = new ArrayCollection();
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
    public function getFrom(): ?string
    {
        return null === $this->fromAddress ? null : AddressFormatter::format(new Address($this->fromAddress, $this->fromName ?? ''));
    }

    #[Groups(['email:write'])]
    public function setFrom(?string $from): static
    {
        $this->requestedFrom = $from;

        return $this;
    }

    public function getRequestedFrom(): ?string
    {
        return $this->requestedFrom;
    }

    public function getFromAddress(): ?Address
    {
        return null === $this->fromAddress ? null : new Address($this->fromAddress, $this->fromName ?? '');
    }

    public function applyFrom(Address $from): static
    {
        $this->fromAddress = $from->getAddress();
        $this->fromName = '' !== $from->getName() ? $from->getName() : null;

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

    /** @return array<mixed> */
    public function getVariables(): array
    {
        return $this->variables;
    }

    /** @param array<mixed> $variables */
    public function setVariables(array $variables): static
    {
        $this->variables = $variables;

        return $this;
    }

    #[Assert\Callback]
    public function validateContent(ExecutionContextInterface $context): void
    {
        // Without a template, the subject and the body must be given.
        if (null !== $this->template) {
            return;
        }
        if ('' === trim($this->subject)) {
            $context->buildViolation('This value should not be blank.')->atPath('subject')->addViolation();
        }
        if ('' === trim($this->htmlBody)) {
            $context->buildViolation('This value should not be blank.')->atPath('htmlBody')->addViolation();
        }
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

    /** @return Collection<int, Attachment> */
    public function getAttachments(): Collection
    {
        return $this->attachments;
    }

    public function addAttachment(Attachment $attachment): static
    {
        if (!$this->attachments->contains($attachment)) {
            $this->attachments->add($attachment);
        }

        return $this;
    }

    public function removeAttachment(Attachment $attachment): static
    {
        $this->attachments->removeElement($attachment);

        return $this;
    }

    #[Groups(['email:list'])]
    public function getAttachmentCount(): int
    {
        return $this->attachments->count();
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
