<?php

namespace App\Repository;

use App\Entity\Mailbox;
use App\Entity\MailboxMember;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Rocket\Core\Entity\User;

/** @extends ServiceEntityRepository<MailboxMember> */
class MailboxMemberRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, MailboxMember::class);
    }

    public function membership(Mailbox $mailbox, User $user): ?MailboxMember
    {
        return $this->findOneBy(['mailbox' => $mailbox, 'user' => $user]);
    }

    /** @return list<MailboxMember> the user's memberships of enabled shared inboxes, by mailbox name */
    public function forUser(User $user): array
    {
        return $this->createQueryBuilder('mm')
            ->join('mm.mailbox', 'm')->addSelect('m')
            ->where('mm.user = :user')->andWhere('m.enabled = true')
            ->setParameter('user', $user)
            ->orderBy('m.name')
            ->getQuery()->getResult();
    }

    /** @return list<MailboxMember> */
    public function forMailbox(Mailbox $mailbox): array
    {
        return $this->createQueryBuilder('mm')
            ->join('mm.user', 'u')->addSelect('u')
            ->where('mm.mailbox = :mailbox')->setParameter('mailbox', $mailbox)
            ->orderBy('u.email')
            ->getQuery()->getResult();
    }
}
