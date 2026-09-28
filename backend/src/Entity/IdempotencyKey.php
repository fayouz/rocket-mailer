<?php

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * "Idempotency-Key" of a sending request (POST /api/emails, replies), kept 24 hours: the same key replays the
 * stored response, another payload with the same key is refused (see App\Idempotency\IdempotencyListener).
 * Written through DBAL: the row must survive a request that fails.
 */
#[ORM\Entity]
#[ORM\Table(name: 'idempotency_key')]
#[ORM\UniqueConstraint(name: 'uniq_idempotency_scope_key', fields: ['scope', 'idempotencyKey'])]
#[ORM\Index(fields: ['createdAt'])]
class IdempotencyKey
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** Who sent it: "app:<id>", "user:<id>" or both. */
    #[ORM\Column(length: 120)]
    private string $scope = '';

    #[ORM\Column(name: 'idempotency_key', length: 190)]
    private string $idempotencyKey = '';

    /** sha256 of the method, the path and the body. */
    #[ORM\Column(length: 64)]
    private string $requestHash = '';

    /** Null while the first request is being processed. */
    #[ORM\Column(nullable: true)]
    private ?int $responseStatus = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $responseBody = null;

    #[ORM\Column(length: 120, nullable: true)]
    private ?string $responseContentType = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }
}
