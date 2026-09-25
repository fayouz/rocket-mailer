<?php

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use App\Repository\EmailTemplateVersionRepository;
use Gedmo\Loggable\LogEntryInterface;

/**
 * One version of an EmailTemplate, written by Gedmo Loggable.
 * Gedmo's bundled LogEntry uses the "array" column type, which DBAL 4 removed: data is stored as JSON here.
 *
 * @implements LogEntryInterface<EmailTemplate>
 */
#[ORM\Entity(repositoryClass: EmailTemplateVersionRepository::class)]
#[ORM\Table(name: 'email_template_version')]
#[ORM\Index(name: 'email_template_version_lookup_idx', columns: ['object_class', 'object_id', 'version'])]
class EmailTemplateVersion implements LogEntryInterface
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 8)]
    private string $action = '';

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $loggedAt;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $objectId = null;

    #[ORM\Column(length: 191)]
    private string $objectClass = '';

    #[ORM\Column]
    private int $version = 1;

    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $data = null;

    #[ORM\Column(length: 191, nullable: true)]
    private ?string $username = null;

    public function __construct()
    {
        $this->loggedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function setAction(string $action): void
    {
        $this->action = $action;
    }

    public function getAction(): string
    {
        return $this->action;
    }

    /** Null when there is no authenticated user (console commands, fixtures). */
    public function setUsername(?string $username): void
    {
        $this->username = $username;
    }

    public function getUsername(): ?string
    {
        return $this->username;
    }

    public function setObjectClass(string $objectClass): void
    {
        $this->objectClass = $objectClass;
    }

    public function getObjectClass(): string
    {
        return $this->objectClass;
    }

    public function setLoggedAt(): void
    {
        $this->loggedAt = new \DateTimeImmutable();
    }

    public function getLoggedAt(): \DateTimeImmutable
    {
        return $this->loggedAt;
    }

    public function setObjectId(string $objectId): void
    {
        $this->objectId = $objectId;
    }

    public function getObjectId(): ?string
    {
        return $this->objectId;
    }

    public function setData(array $data): void
    {
        $this->data = $data;
    }

    public function getData(): ?array
    {
        return $this->data;
    }

    public function setVersion(int $version): void
    {
        $this->version = $version;
    }

    public function getVersion(): int
    {
        return $this->version;
    }
}
