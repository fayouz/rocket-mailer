<?php

namespace App\Repository;

use App\Entity\Mailbox;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use App\Entity\MailboxMember;
use Rocket\Core\Entity\Application;
use Rocket\Core\Entity\User;

/** @extends ServiceEntityRepository<Mailbox> */
class MailboxRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Mailbox::class);
    }

    /**
     * Mailboxes offered when sending through this application (its own only),
     * or in Rocket Mailer itself (null: the ones shared with all users).
     *
     * @return list<Mailbox>
     */
    public function usableBy(?Application $application): array
    {
        $qb = $this->createQueryBuilder('m')
            ->leftJoin('m.applications', 'a')
            ->where('m.enabled = true')
            ->orderBy('m.name');
        // In Rocket Mailer: the mailboxes shared with all users. Through an application: only its own mailboxes.
        if (null === $application) {
            $qb->andWhere('m.availableToUsers = true');
        } else {
            $qb->andWhere('a = :application')->setParameter('application', $application);
        }

        return array_values(array_unique($qb->getQuery()->getResult(), \SORT_REGULAR));
    }

    /**
     * Mailboxes a user may send from in Rocket Mailer: the ones shared with all users, and the shared inboxes
     * the user is a member of.
     *
     * @return list<Mailbox>
     */
    public function usableByUser(User $user): array
    {
        $members = $this->getEntityManager()->createQueryBuilder()
            ->select('1')->from(MailboxMember::class, 'mm')
            ->where('mm.mailbox = m')->andWhere('mm.user = :user');

        return $this->createQueryBuilder('m')
            ->where('m.enabled = true')
            ->andWhere(\sprintf('m.availableToUsers = true OR EXISTS(%s)', $members->getDQL()))
            ->setParameter('user', $user)
            ->orderBy('m.name')
            ->getQuery()->getResult();
    }
}
