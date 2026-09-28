<?php

namespace App\Controller;

use App\Entity\Mailbox;
use App\Entity\MailboxMember;
use App\Repository\MailboxMemberRepository;
use App\Security\MailboxVoter;
use Doctrine\ORM\EntityManagerInterface;
use Rocket\Core\Entity\User;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Routing\Attribute\Route;

/** Members of a shared inbox, managed by its managers and the administrators (INBOX_MANAGE). */
#[Route('/api/inbox')]
final class InboxMemberController extends AbstractController
{
    public function __construct(
        private readonly MailboxMemberRepository $members,
        private readonly EntityManagerInterface $em,
    ) {
    }

    #[Route('/mailboxes/{id}/members', name: 'api_inbox_members', methods: ['GET'])]
    public function list(Mailbox $mailbox): JsonResponse
    {
        $this->denyAccessUnlessGranted(MailboxVoter::MANAGE, $mailbox);

        return $this->json(array_map(self::present(...), $this->members->forMailbox($mailbox)));
    }

    /** { "email": "user@…", "role": "member"|"manager" }: the user must have an account. */
    #[Route('/mailboxes/{id}/members', name: 'api_inbox_member_add', methods: ['POST'])]
    public function add(Mailbox $mailbox, Request $request): JsonResponse
    {
        $this->denyAccessUnlessGranted(MailboxVoter::MANAGE, $mailbox);
        $payload = $request->toArray();
        $role = self::role($payload['role'] ?? MailboxMember::ROLE_MEMBER);
        $user = $this->em->getRepository(User::class)->findOneBy(['email' => mb_strtolower(trim((string) ($payload['email'] ?? '')))]);
        if (null === $user) {
            throw new UnprocessableEntityHttpException('email: no user has this address.');
        }
        $member = $this->members->membership($mailbox, $user);
        if (null !== $member) {
            throw new UnprocessableEntityHttpException('email: this user is already a member.');
        }
        $member = new MailboxMember($mailbox, $user, $role);
        $this->em->persist($member);
        $this->em->flush();

        return $this->json(self::present($member), Response::HTTP_CREATED);
    }

    /** { "role": "member"|"manager" } */
    #[Route('/members/{id}', name: 'api_inbox_member_update', methods: ['PATCH'])]
    public function update(MailboxMember $member, Request $request): JsonResponse
    {
        $this->denyAccessUnlessGranted(MailboxVoter::MANAGE, $member->getMailbox());
        $member->setRole(self::role($request->toArray()['role'] ?? ''));
        $this->em->flush();

        return $this->json(self::present($member));
    }

    #[Route('/members/{id}', name: 'api_inbox_member_remove', methods: ['DELETE'])]
    public function remove(MailboxMember $member): JsonResponse
    {
        $this->denyAccessUnlessGranted(MailboxVoter::MANAGE, $member->getMailbox());
        // Their conversations are no longer assigned.
        $this->em->createQuery('UPDATE App\Entity\Conversation c SET c.assignee = NULL WHERE c.mailbox = :mailbox AND c.assignee = :user')
            ->setParameter('mailbox', $member->getMailbox())->setParameter('user', $member->getUser())
            ->execute();
        $this->em->remove($member);
        $this->em->flush();

        return $this->json(null, Response::HTTP_NO_CONTENT);
    }

    private static function role(mixed $role): string
    {
        if (!\in_array($role, MailboxMember::ROLES, true)) {
            throw new UnprocessableEntityHttpException('role: "member" or "manager".');
        }

        return $role;
    }

    /** @return array<string, mixed> */
    private static function present(MailboxMember $member): array
    {
        return ['id' => $member->getId()->toRfc4122(), 'role' => $member->getRole(), 'user' => InboxController::person($member->getUser())];
    }
}
