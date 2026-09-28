<?php

namespace App\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use App\Repository\MailboxRepository;
use App\State\MailboxProcessor;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Rocket\Core\Entity\Application;
use Rocket\Core\Entity\TrackedTrait;
use Rocket\Core\Entity\User;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Mime\Address;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Serializer\Attribute\SerializedName;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * A sending mailbox: a real email account the composer can send from, through its own SMTP server
 * (or a provider, as a Symfony Mailer DSN), keeping a copy of each email in its IMAP "Sent" folder.
 * Offered to the applications it is attached to, and to every user when "availableToUsers".
 * Credentials are encrypted (see MailboxProcessor, SecretBox) and never exposed by the API.
 *
 * Kind "shared": created by an administrator. Kind "personal": created by a user for themselves (PersonalMailboxController),
 * the owner is its only member; administrators see that it exists (metadata), never its content.
 * Authentication: a password, or OAuth 2 (Google, Microsoft: refresh token encrypted, XOAUTH2 for IMAP and SMTP).
 */
#[ORM\Entity(repositoryClass: MailboxRepository::class)]
#[ApiResource(
    operations: [
        new GetCollection(),
        new Get(),
        new Post(processor: MailboxProcessor::class),
        // A personal mailbox belongs to its owner (PATCH /api/mailboxes/personal/{id}): admins only see it exists.
        new Patch(security: "is_granted('ROLE_ADMIN') and object.isShared()", processor: MailboxProcessor::class),
        new Delete(),
    ],
    normalizationContext: ['groups' => ['mailbox:read', 'tracking']],
    denormalizationContext: ['groups' => ['mailbox:write']],
    security: "is_granted('ROLE_ADMIN')",
    order: ['name' => 'ASC'],
)]
class Mailbox
{
    public const TRANSPORT_SMTP = 'smtp';
    public const TRANSPORT_DSN = 'dsn';
    public const ENCRYPTIONS = ['ssl', 'starttls', 'none'];
    public const KIND_SHARED = 'shared';
    public const KIND_PERSONAL = 'personal';
    public const AUTH_PASSWORD = 'password';
    public const AUTH_OAUTH_GOOGLE = 'oauth_google';
    public const AUTH_OAUTH_MICROSOFT = 'oauth_microsoft';
    public const AUTH_TYPES = [self::AUTH_PASSWORD, self::AUTH_OAUTH_GOOGLE, self::AUTH_OAUTH_MICROSOFT];

    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[Groups(['mailbox:read'])]
    private Uuid $id;

    #[ORM\Column(length: 120)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 120)]
    #[Groups(['mailbox:read', 'mailbox:write'])]
    private string $name = '';

    #[ORM\Column(length: 180)]
    #[Assert\NotBlank]
    #[Assert\Email]
    #[Groups(['mailbox:read', 'mailbox:write'])]
    private string $email = '';

    /** Name shown in the "From" header. */
    #[ORM\Column(length: 120, nullable: true)]
    #[Assert\Length(max: 120)]
    #[Groups(['mailbox:read', 'mailbox:write'])]
    private ?string $displayName = null;

    #[ORM\Column(length: 8)]
    #[Assert\Choice(choices: [self::TRANSPORT_SMTP, self::TRANSPORT_DSN])]
    #[Groups(['mailbox:read', 'mailbox:write'])]
    private string $transport = self::TRANSPORT_SMTP;

    #[ORM\Column(length: 255, nullable: true)]
    #[Groups(['mailbox:read', 'mailbox:write'])]
    private ?string $smtpHost = null;

    #[ORM\Column(nullable: true)]
    #[Assert\Range(min: 1, max: 65535)]
    #[Groups(['mailbox:read', 'mailbox:write'])]
    private ?int $smtpPort = 587;

    #[ORM\Column(length: 8)]
    #[Assert\Choice(choices: self::ENCRYPTIONS)]
    #[Groups(['mailbox:read', 'mailbox:write'])]
    private string $smtpEncryption = 'starttls';

    #[ORM\Column(length: 255, nullable: true)]
    #[Groups(['mailbox:read', 'mailbox:write'])]
    private ?string $smtpUsername = null;

    /** Encrypted. */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $smtpPassword = null;

    /** Encrypted: a provider DSN embeds its API key (brevo+api://KEY@default…). */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $dsn = null;

    /** Scheme and host of the DSN, safe to display. */
    #[ORM\Column(length: 255, nullable: true)]
    #[Groups(['mailbox:read'])]
    private ?string $dsnHint = null;

    #[ORM\Column]
    #[Groups(['mailbox:read', 'mailbox:write'])]
    private bool $imapEnabled = false;

    #[ORM\Column(length: 255, nullable: true)]
    #[Groups(['mailbox:read', 'mailbox:write'])]
    private ?string $imapHost = null;

    #[ORM\Column(nullable: true)]
    #[Assert\Range(min: 1, max: 65535)]
    #[Groups(['mailbox:read', 'mailbox:write'])]
    private ?int $imapPort = 993;

    #[ORM\Column(length: 8)]
    #[Assert\Choice(choices: self::ENCRYPTIONS)]
    #[Groups(['mailbox:read', 'mailbox:write'])]
    private string $imapEncryption = 'ssl';

    /** Empty: same as the SMTP username. */
    #[ORM\Column(length: 255, nullable: true)]
    #[Groups(['mailbox:read', 'mailbox:write'])]
    private ?string $imapUsername = null;

    /** Encrypted. Empty: same as the SMTP password. */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $imapPassword = null;

    /** Empty: detected ("Sent" special-use folder or a usual name), created if missing. */
    #[ORM\Column(length: 255, nullable: true)]
    #[Groups(['mailbox:read', 'mailbox:write'])]
    private ?string $imapSentFolder = null;

    /** Offered to every user in Rocket Mailer (not only through its applications). */
    #[ORM\Column]
    #[Groups(['mailbox:read', 'mailbox:write'])]
    private bool $availableToUsers = false;

    #[ORM\Column]
    #[Groups(['mailbox:read', 'mailbox:write'])]
    private bool $enabled = true;

    /** Shared inbox: new messages of the IMAP INBOX are fetched into conversations (see App\Inbox). */
    #[ORM\Column(options: ['default' => false])]
    #[Groups(['mailbox:read', 'mailbox:write'])]
    private bool $inboxEnabled = false;

    /** UIDVALIDITY of the INBOX when it was last fetched: a change means the UIDs were renumbered. */
    #[ORM\Column(type: Types::BIGINT, nullable: true)]
    private ?string $inboxUidValidity = null;

    /** Highest UID fetched from the INBOX. */
    #[ORM\Column(type: Types::BIGINT, options: ['default' => 0])]
    private string $inboxLastUid = '0';

    #[ORM\Column(nullable: true)]
    #[Groups(['mailbox:read'])]
    private ?\DateTimeImmutable $inboxFetchedAt = null;

    /** Why the last fetch failed (null: it succeeded). */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    #[Groups(['mailbox:read'])]
    private ?string $inboxError = null;

    #[ORM\Column(length: 16, options: ['default' => self::KIND_SHARED])]
    #[Groups(['mailbox:read'])]
    private string $kind = self::KIND_SHARED;

    /** Owner of a personal mailbox. */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'CASCADE')]
    private ?User $owner = null;

    #[ORM\Column(length: 16, options: ['default' => self::AUTH_PASSWORD])]
    #[Groups(['mailbox:read'])]
    private string $authType = self::AUTH_PASSWORD;

    /** Detected provider (MailProviderDetector): ovh, gmail, microsoft… */
    #[ORM\Column(length: 20, nullable: true)]
    #[Groups(['mailbox:read'])]
    private ?string $provider = null;

    /** Encrypted OAuth refresh token. */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $oauthRefreshToken = null;

    /** Encrypted OAuth access token (cache), valid until oauthExpiresAt. */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $oauthAccessToken = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $oauthExpiresAt = null;

    /** @var Collection<int, Application> */
    #[ORM\ManyToMany(targetEntity: Application::class)]
    #[ORM\JoinTable(name: 'mailbox_application')]
    #[Groups(['mailbox:write'])]
    private Collection $applications;

    // Plain secrets received from the API, encrypted by MailboxProcessor. Write-only.
    private ?string $plainSmtpPassword = null;
    private ?string $plainImapPassword = null;
    private ?string $plainDsn = null;

    use TrackedTrait;

    public function __construct()
    {
        $this->id = Uuid::v7();
        $this->applications = new ArrayCollection();
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

    public function getEmail(): string
    {
        return $this->email;
    }

    public function setEmail(string $email): static
    {
        $this->email = mb_strtolower(trim($email));

        return $this;
    }

    public function getDisplayName(): ?string
    {
        return $this->displayName;
    }

    public function setDisplayName(?string $displayName): static
    {
        $this->displayName = null === $displayName || '' === trim($displayName) ? null : trim($displayName);

        return $this;
    }

    public function toAddress(): Address
    {
        return new Address($this->email, $this->displayName ?? '');
    }

    public function getTransport(): string
    {
        return $this->transport;
    }

    public function setTransport(string $transport): static
    {
        $this->transport = $transport;

        return $this;
    }

    public function getSmtpHost(): ?string
    {
        return $this->smtpHost;
    }

    public function setSmtpHost(?string $smtpHost): static
    {
        $this->smtpHost = self::blankToNull($smtpHost);

        return $this;
    }

    public function getSmtpPort(): ?int
    {
        return $this->smtpPort;
    }

    public function setSmtpPort(?int $smtpPort): static
    {
        $this->smtpPort = $smtpPort;

        return $this;
    }

    public function getSmtpEncryption(): string
    {
        return $this->smtpEncryption;
    }

    public function setSmtpEncryption(string $smtpEncryption): static
    {
        $this->smtpEncryption = $smtpEncryption;

        return $this;
    }

    public function getSmtpUsername(): ?string
    {
        return $this->smtpUsername;
    }

    public function setSmtpUsername(?string $smtpUsername): static
    {
        $this->smtpUsername = self::blankToNull($smtpUsername);

        return $this;
    }

    /** New password; empty or null keeps the current one. */
    #[Groups(['mailbox:write'])]
    public function setSmtpPassword(?string $password): static
    {
        $this->plainSmtpPassword = self::blankToNull($password);

        return $this;
    }

    #[Groups(['mailbox:read'])]
    public function getHasSmtpPassword(): bool
    {
        return null !== $this->smtpPassword || null !== $this->plainSmtpPassword;
    }

    public function getEncryptedSmtpPassword(): ?string
    {
        return $this->smtpPassword;
    }

    /** New DSN; empty or null keeps the current one. */
    #[Groups(['mailbox:write'])]
    public function setDsn(?string $dsn): static
    {
        $this->plainDsn = self::blankToNull($dsn);

        return $this;
    }

    public function getEncryptedDsn(): ?string
    {
        return $this->dsn;
    }

    public function getDsnHint(): ?string
    {
        return $this->dsnHint;
    }

    public function isImapEnabled(): bool
    {
        return $this->imapEnabled;
    }

    public function setImapEnabled(bool $imapEnabled): static
    {
        $this->imapEnabled = $imapEnabled;

        return $this;
    }

    public function getImapHost(): ?string
    {
        return $this->imapHost;
    }

    public function setImapHost(?string $imapHost): static
    {
        $this->imapHost = self::blankToNull($imapHost);

        return $this;
    }

    public function getImapPort(): ?int
    {
        return $this->imapPort;
    }

    public function setImapPort(?int $imapPort): static
    {
        $this->imapPort = $imapPort;

        return $this;
    }

    public function getImapEncryption(): string
    {
        return $this->imapEncryption;
    }

    public function setImapEncryption(string $imapEncryption): static
    {
        $this->imapEncryption = $imapEncryption;

        return $this;
    }

    public function getImapUsername(): ?string
    {
        return $this->imapUsername;
    }

    public function setImapUsername(?string $imapUsername): static
    {
        $this->imapUsername = self::blankToNull($imapUsername);

        return $this;
    }

    /** New password; empty or null keeps the current one. */
    #[Groups(['mailbox:write'])]
    public function setImapPassword(?string $password): static
    {
        $this->plainImapPassword = self::blankToNull($password);

        return $this;
    }

    #[Groups(['mailbox:read'])]
    public function getHasImapPassword(): bool
    {
        return null !== $this->imapPassword || null !== $this->plainImapPassword;
    }

    public function getEncryptedImapPassword(): ?string
    {
        return $this->imapPassword;
    }

    public function getImapSentFolder(): ?string
    {
        return $this->imapSentFolder;
    }

    public function setImapSentFolder(?string $imapSentFolder): static
    {
        $this->imapSentFolder = self::blankToNull($imapSentFolder);

        return $this;
    }

    public function isAvailableToUsers(): bool
    {
        return $this->availableToUsers;
    }

    public function setAvailableToUsers(bool $availableToUsers): static
    {
        $this->availableToUsers = $availableToUsers;

        return $this;
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

    public function isInboxEnabled(): bool
    {
        return $this->inboxEnabled;
    }

    public function setInboxEnabled(bool $inboxEnabled): static
    {
        $this->inboxEnabled = $inboxEnabled;

        return $this;
    }

    public function getInboxUidValidity(): ?int
    {
        return null === $this->inboxUidValidity ? null : (int) $this->inboxUidValidity;
    }

    public function getInboxLastUid(): int
    {
        return (int) $this->inboxLastUid;
    }

    /** Records a successful fetch: the INBOX state and the time. */
    public function markInboxFetched(int $uidValidity, int $lastUid): static
    {
        $this->inboxUidValidity = (string) $uidValidity;
        $this->inboxLastUid = (string) $lastUid;
        $this->inboxFetchedAt = new \DateTimeImmutable();
        $this->inboxError = null;

        return $this;
    }

    public function markInboxFailed(string $error): static
    {
        $this->inboxFetchedAt = new \DateTimeImmutable();
        $this->inboxError = mb_substr($error, 0, 2000);

        return $this;
    }

    public function getInboxFetchedAt(): ?\DateTimeImmutable
    {
        return $this->inboxFetchedAt;
    }

    public function getInboxError(): ?string
    {
        return $this->inboxError;
    }

    /** @return Collection<int, Application> */
    public function getApplications(): Collection
    {
        return $this->applications;
    }

    /**
     * Attached applications, as IRIs (embedding them would leak their tracking fields).
     *
     * @return list<string>
     */
    #[Groups(['mailbox:read'])]
    #[SerializedName('applications')]
    public function getApplicationIris(): array
    {
        return array_values($this->applications->map(static fn (Application $a) => '/api/applications/'.$a->getId()->toRfc4122())->toArray());
    }

    public function addApplication(Application $application): static
    {
        if (!$this->applications->contains($application)) {
            $this->applications->add($application);
        }

        return $this;
    }

    public function removeApplication(Application $application): static
    {
        $this->applications->removeElement($application);

        return $this;
    }

    /** May this mailbox be used by this application, or by users of Rocket Mailer itself (null)? */
    public function isUsableBy(?Application $application): bool
    {
        if (!$this->enabled) {
            return false;
        }

        // Through an application, only its own mailboxes: never the ones shared with the users of Rocket Mailer.
        return null === $application ? $this->availableToUsers : $this->applications->contains($application);
    }

    public function getKind(): string
    {
        return $this->kind;
    }

    public function isShared(): bool
    {
        return self::KIND_SHARED === $this->kind;
    }

    public function isPersonal(): bool
    {
        return self::KIND_PERSONAL === $this->kind;
    }

    public function getOwner(): ?User
    {
        return $this->owner;
    }

    public function isOwnedBy(?User $user): bool
    {
        return null !== $user && null !== $this->owner && $this->owner->getId()->equals($user->getId());
    }

    /** Makes it the personal mailbox of the user: never offered to other users nor to applications. */
    public function makePersonal(User $owner): static
    {
        $this->kind = self::KIND_PERSONAL;
        $this->owner = $owner;
        $this->availableToUsers = false;
        $this->applications->clear();

        return $this;
    }

    /** Email of the owner of a personal mailbox (metadata visible to administrators). */
    #[Groups(['mailbox:read'])]
    public function getOwnerEmail(): ?string
    {
        return $this->owner?->getEmail();
    }

    public function getAuthType(): string
    {
        return $this->authType;
    }

    public function setAuthType(string $authType): static
    {
        if (!\in_array($authType, self::AUTH_TYPES, true)) {
            throw new \InvalidArgumentException(\sprintf('Unknown authentication type "%s".', $authType));
        }
        $this->authType = $authType;

        return $this;
    }

    public function isOAuth(): bool
    {
        return self::AUTH_PASSWORD !== $this->authType;
    }

    public function getProvider(): ?string
    {
        return $this->provider;
    }

    public function setProvider(?string $provider): static
    {
        $this->provider = $provider;

        return $this;
    }

    public function getEncryptedOAuthRefreshToken(): ?string
    {
        return $this->oauthRefreshToken;
    }

    public function getEncryptedOAuthAccessToken(): ?string
    {
        return $this->oauthAccessToken;
    }

    public function getOAuthExpiresAt(): ?\DateTimeImmutable
    {
        return $this->oauthExpiresAt;
    }

    /** OAuth connected: a refresh token is stored. */
    #[Groups(['mailbox:read'])]
    public function getOauthConnected(): bool
    {
        return null !== $this->oauthRefreshToken;
    }

    /**
     * Stores encrypted OAuth tokens (a null refresh token keeps the current one: providers do not always rotate it).
     */
    public function storeOAuthTokens(?string $encryptedRefreshToken, ?string $encryptedAccessToken, ?\DateTimeImmutable $expiresAt): static
    {
        if (null !== $encryptedRefreshToken) {
            $this->oauthRefreshToken = $encryptedRefreshToken;
        }
        $this->oauthAccessToken = $encryptedAccessToken;
        $this->oauthExpiresAt = $expiresAt;

        return $this;
    }

    /** Switches to a password: forgets the OAuth tokens. */
    public function clearOAuth(): static
    {
        $this->oauthRefreshToken = $this->oauthAccessToken = null;
        $this->oauthExpiresAt = null;

        return $this;
    }

    /**
     * Encrypts the plain secrets received from the API (called by MailboxProcessor).
     *
     * @param callable(string): string $encrypt
     */
    public function sealSecrets(callable $encrypt): void
    {
        if (null !== $this->plainSmtpPassword) {
            $this->smtpPassword = $encrypt($this->plainSmtpPassword);
        }
        if (null !== $this->plainImapPassword) {
            $this->imapPassword = $encrypt($this->plainImapPassword);
        }
        if (null !== $this->plainDsn) {
            $this->dsn = $encrypt($this->plainDsn);
            $parts = parse_url($this->plainDsn);
            $this->dsnHint = \is_array($parts) && isset($parts['scheme']) ? $parts['scheme'].'://'.($parts['host'] ?? '') : null;
        }
        $this->plainSmtpPassword = $this->plainImapPassword = $this->plainDsn = null;
    }

    #[Assert\Callback]
    public function validateTransport(ExecutionContextInterface $context): void
    {
        if ($this->isOAuth() && self::TRANSPORT_SMTP !== $this->transport) {
            $context->buildViolation('An OAuth mailbox sends through SMTP.')->atPath('transport')->addViolation();
        }
        if (self::TRANSPORT_SMTP === $this->transport && null === $this->smtpHost) {
            $context->buildViolation('The SMTP server is required.')->atPath('smtpHost')->addViolation();
        }
        if (self::TRANSPORT_DSN === $this->transport && null === $this->dsn && null === $this->plainDsn) {
            $context->buildViolation('The provider DSN is required.')->atPath('dsn')->addViolation();
        }
        if (null !== $this->plainDsn && !preg_match('#^[a-z][a-z0-9+.-]*://#i', $this->plainDsn)) {
            $context->buildViolation('A DSN looks like "scheme://credentials@host", e.g. brevo+api://KEY@default.')->atPath('dsn')->addViolation();
        }
        if ($this->imapEnabled && null === $this->imapHost) {
            $context->buildViolation('The IMAP server is required to keep a copy of sent emails.')->atPath('imapHost')->addViolation();
        }
        if ($this->inboxEnabled && null === $this->imapHost) {
            $context->buildViolation('The IMAP server is required to receive emails in a shared inbox.')->atPath('imapHost')->addViolation();
        }
    }

    private static function blankToNull(?string $value): ?string
    {
        return null === $value || '' === trim($value) ? null : trim($value);
    }
}
