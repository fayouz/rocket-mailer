<?php

namespace App\Repository;

use App\Entity\Conversation;
use App\Entity\ConversationRead;
use App\Entity\Email;
use App\Entity\InboundMessage;
use App\Entity\Mailbox;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Rocket\Core\Entity\User;

/** @extends ServiceEntityRepository<Conversation> */
class ConversationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Conversation::class);
    }

    /**
     * Conversations of a mailbox, most recent activity first, with the unread flag of the user.
     *
     * @return list<array{0: Conversation, unread: bool}>
     */
    public function search(Mailbox $mailbox, ?User $user, ?string $status, bool $mine, ?string $query, int $limit = 100, ?string $participant = null, ?string $externalRef = null): array
    {
        $qb = $this->createQueryBuilder('c')
            ->addSelect('CASE WHEN r.id IS NULL OR r.readAt < c.lastMessageAt THEN true ELSE false END AS unread')
            ->leftJoin(ConversationRead::class, 'r', 'WITH', 'r.conversation = c AND r.user = :user')
            ->leftJoin('c.assignee', 'a')->addSelect('a')
            ->where('c.mailbox = :mailbox')
            ->setParameter('mailbox', $mailbox)
            ->setParameter('user', $user)
            ->orderBy('c.lastActivityAt', 'DESC')
            ->setMaxResults($limit);
        if (null !== $status) {
            $qb->andWhere('c.status = :status')->setParameter('status', $status);
        }
        if ($mine) {
            $qb->andWhere(null === $user ? '1 = 0' : 'c.assignee = :user');
        }
        if (null !== $externalRef) {
            $qb->andWhere('c.externalRef = :externalRef')->setParameter('externalRef', $externalRef);
        }
        if (null !== $participant) {
            // Participants: a JSON list of lowercase addresses; exact match through PostgreSQL's jsonb containment.
            $ids = $this->getEntityManager()->getConnection()->fetchFirstColumn(
                'SELECT id FROM conversation WHERE mailbox_id = :mailbox AND participants::jsonb @> jsonb_build_array(CAST(:participant AS text))',
                ['mailbox' => $mailbox->getId()->toRfc4122(), 'participant' => mb_strtolower($participant)],
            );
            if ([] === $ids) {
                return [];
            }
            $qb->andWhere('c.id IN (:ids)')->setParameter('ids', $ids);
        }
        if (null !== $query && '' !== trim($query)) {
            $like = '%'.addcslashes(mb_strtolower(trim($query)), '%_\\').'%';
            $messages = $this->getEntityManager()->createQueryBuilder()
                ->select('1')->from(InboundMessage::class, 'm')
                ->where('m.conversation = c')
                ->andWhere('LOWER(m.fromAddress) LIKE :q OR LOWER(m.fromName) LIKE :q OR LOWER(m.text) LIKE :q');
            $qb->andWhere(\sprintf('LOWER(c.subject) LIKE :q OR LOWER(c.lastFrom) LIKE :q OR EXISTS(%s)', $messages->getDQL()))
                ->setParameter('q', $like);
        }

        // Without a user (an application for itself), nothing is "unread".
        return array_map(static fn (array $row) => [0 => $row[0], 'unread' => null !== $user && (bool) $row['unread']], $qb->getQuery()->getResult());
    }

    /** Unread open conversations of a mailbox for the user. */
    public function countUnread(Mailbox $mailbox, User $user): int
    {
        return (int) $this->createQueryBuilder('c')
            ->select('COUNT(c.id)')
            ->leftJoin(ConversationRead::class, 'r', 'WITH', 'r.conversation = c AND r.user = :user')
            ->where('c.mailbox = :mailbox')->andWhere('c.status = :open')
            ->andWhere('r.id IS NULL OR r.readAt < c.lastMessageAt')
            ->setParameter('mailbox', $mailbox)->setParameter('user', $user)->setParameter('open', Conversation::STATUS_OPEN)
            ->getQuery()->getSingleScalarResult();
    }

    public function countOpen(Mailbox $mailbox): int
    {
        return $this->count(['mailbox' => $mailbox, 'status' => Conversation::STATUS_OPEN]);
    }

    /** Conversation holding one of these Message-IDs (received message or reply sent from the mailbox). */
    public function findByMessageIds(Mailbox $mailbox, array $messageIds): ?Conversation
    {
        if ([] === $messageIds) {
            return null;
        }
        $em = $this->getEntityManager();
        $inbound = $em->createQueryBuilder()->select('m')->from(InboundMessage::class, 'm')
            ->join('m.conversation', 'c')
            ->where('m.mailbox = :mailbox')->andWhere('m.messageId IN (:ids)')
            ->setParameter('mailbox', $mailbox)->setParameter('ids', $messageIds)
            ->orderBy('m.date', 'DESC')->setMaxResults(1)
            ->getQuery()->getOneOrNullResult();
        if ($inbound instanceof InboundMessage) {
            return $inbound->getConversation();
        }
        $reply = $em->createQueryBuilder()->select('e')->from(Email::class, 'e')
            ->where('e.mailbox = :mailbox')->andWhere('e.messageId IN (:ids)')->andWhere('e.conversation IS NOT NULL')
            ->setParameter('mailbox', $mailbox)->setParameter('ids', $messageIds)
            ->setMaxResults(1)
            ->getQuery()->getOneOrNullResult();

        return $reply instanceof Email ? $reply->getConversation() : null;
    }

    /** Fallback threading: the latest conversation with the same normalized subject and this participant (last 90 days). */
    public function findBySubjectAndParticipant(Mailbox $mailbox, string $normalizedSubject, string $participant): ?Conversation
    {
        if ('' === $normalizedSubject) {
            return null;
        }
        $candidates = $this->createQueryBuilder('c')
            ->where('c.mailbox = :mailbox')->andWhere('c.normalizedSubject = :subject')->andWhere('c.lastActivityAt > :since')
            ->setParameter('mailbox', $mailbox)->setParameter('subject', $normalizedSubject)->setParameter('since', new \DateTimeImmutable('-90 days'))
            ->orderBy('c.lastActivityAt', 'DESC')->setMaxResults(20)
            ->getQuery()->getResult();
        foreach ($candidates as $conversation) {
            if (\in_array(mb_strtolower($participant), $conversation->getParticipants(), true)) {
                return $conversation;
            }
        }

        return null;
    }

    public function markRead(Conversation $conversation, User $user): void
    {
        $em = $this->getEntityManager();
        $read = $em->getRepository(ConversationRead::class)->findOneBy(['conversation' => $conversation, 'user' => $user]);
        if (null === $read) {
            $em->persist(new ConversationRead($conversation, $user));
        } else {
            $read->markRead(new \DateTimeImmutable());
        }
    }
}
