<?php

namespace App\Repository;

use App\Entity\Attachment;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<Attachment> */
class AttachmentRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Attachment::class);
    }

    /** @return list<Attachment> uploads never attached to an email, older than $before */
    public function findOrphansBefore(\DateTimeImmutable $before): array
    {
        return $this->createQueryBuilder('a')
            ->where('a.email IS NULL')
            ->andWhere('a.createdAt < :before')
            ->setParameter('before', $before)
            ->getQuery()
            ->getResult();
    }
}
