<?php

namespace App\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use App\Enum\AuthenticationServerType;
use App\Repository\AuthenticationServerRepository;
use App\State\AuthenticationServerDeleteProcessor;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: AuthenticationServerRepository::class)]
#[ApiResource(
    operations: [new GetCollection(), new Get(), new Post(), new Patch(), new Delete(processor: AuthenticationServerDeleteProcessor::class)],
    normalizationContext: ['groups' => ['authentication_server:read', 'tracking']],
    denormalizationContext: ['groups' => ['authentication_server:write']],
    security: "is_granted('ROLE_ADMIN')",
    order: ['name' => 'ASC'],
)]
class AuthenticationServer
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[Groups(['authentication_server:read'])]
    private Uuid $id;

    #[ORM\Column(length: 120)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 120)]
    #[Groups(['authentication_server:read', 'authentication_server:write'])]
    private string $name = '';

    #[ORM\Column(length: 16, enumType: AuthenticationServerType::class)]
    #[Groups(['authentication_server:read', 'authentication_server:write'])]
    private AuthenticationServerType $type = AuthenticationServerType::Ldap;

    #[ORM\Column]
    #[Groups(['authentication_server:read', 'authentication_server:write'])]
    private bool $enabled = false;

    #[ORM\Column(length: 255)]
    #[Groups(['authentication_server:read', 'authentication_server:write'])]
    private string $url = 'ldap://localhost:389';

    #[ORM\Column]
    private bool $startTls = false;

    #[ORM\Column(length: 512)]
    private string $baseDn = '';

    #[ORM\Column(length: 512)]
    private string $bindDn = '';

    /** Encrypted. */
    #[ORM\Column(type: Types::TEXT)]
    private string $bindPassword = '';

    #[ORM\Column(type: Types::TEXT)]
    private string $userFilter = '(objectClass=inetOrgPerson)';

    #[ORM\Column(length: 512)]
    private string $adminGroupDn = '';

    /** @var array{email: string, firstName: string, lastName: string, groups: string} */
    #[ORM\Column(type: Types::JSON)]
    private array $attributes = [];

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

    public function getType(): AuthenticationServerType
    {
        return $this->type;
    }

    public function setType(AuthenticationServerType $type): static
    {
        $this->type = $type;

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

    public function getUrl(): string
    {
        return $this->url;
    }

    public function setUrl(string $url): static
    {
        $this->url = trim($url);

        return $this;
    }

    public function hasStartTls(): bool
    {
        return $this->startTls;
    }

    public function setStartTls(bool $startTls): static
    {
        $this->startTls = $startTls;

        return $this;
    }

    public function getBaseDn(): string
    {
        return $this->baseDn;
    }

    public function setBaseDn(string $baseDn): static
    {
        $this->baseDn = trim($baseDn);

        return $this;
    }

    public function getBindDn(): string
    {
        return $this->bindDn;
    }

    public function setBindDn(string $bindDn): static
    {
        $this->bindDn = trim($bindDn);

        return $this;
    }

    public function getBindPassword(): string
    {
        return $this->bindPassword;
    }

    public function setBindPassword(string $bindPassword): static
    {
        $this->bindPassword = $bindPassword;

        return $this;
    }

    public function getUserFilter(): string
    {
        return $this->userFilter;
    }

    public function setUserFilter(string $userFilter): static
    {
        $this->userFilter = trim($userFilter);

        return $this;
    }

    public function getAdminGroupDn(): string
    {
        return $this->adminGroupDn;
    }

    public function setAdminGroupDn(string $adminGroupDn): static
    {
        $this->adminGroupDn = trim($adminGroupDn);

        return $this;
    }

    /** @return array{email: string, firstName: string, lastName: string, groups: string} */
    public function getAttributes(): array
    {
        return $this->attributes;
    }

    /** @param array{email: string, firstName: string, lastName: string, groups: string} $attributes */
    public function setAttributes(array $attributes): static
    {
        $this->attributes = $attributes;

        return $this;
    }
}
