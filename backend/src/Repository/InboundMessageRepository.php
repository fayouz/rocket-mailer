<?php

namespace App\Repository;

use App\Entity\InboundMessage;
use App\Entity\Mailbox;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<InboundMessage> */
class InboundMessageRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, InboundMessage::class);
    }

    public function exists(Mailbox $mailbox, string $messageId): bool
    {
        return $this->count(['mailbox' => $mailbox, 'messageId' => $messageId]) > 0;
    }
}
