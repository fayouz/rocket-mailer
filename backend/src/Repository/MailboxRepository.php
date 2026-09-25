<?php

namespace App\Repository;

use App\Entity\Application;
use App\Entity\Mailbox;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<Mailbox> */
class MailboxRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Mailbox::class);
    }

    /**
     * Mailboxes offered when sending through this application (its own and the shared ones),
     * or in Rocket Mailer itself (null: the shared ones).
     *
     * @return list<Mailbox>
     */
    public function usableBy(?Application $application): array
    {
        $qb = $this->createQueryBuilder('m')
            ->leftJoin('m.applications', 'a')
            ->where('m.enabled = true')
            ->orderBy('m.name');
        if (null === $application) {
            $qb->andWhere('m.availableToUsers = true');
        } else {
            $qb->andWhere('m.availableToUsers = true OR a = :application')->setParameter('application', $application);
        }

        return array_values(array_unique($qb->getQuery()->getResult(), \SORT_REGULAR));
    }
}
