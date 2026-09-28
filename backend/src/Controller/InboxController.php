<?php

namespace App\Controller;

use App\Attachment\AttachmentStorage;
use App\Entity\Conversation;
use App\Entity\ConversationNote;
use App\Entity\ConversationRead;
use App\Entity\Email;
use App\Entity\InboundAttachment;
use App\Entity\InboundMessage;
use App\Entity\Mailbox;
use App\Inbox\InboxFetcher;
use App\Inbox\InboxReplier;
use App\Inbox\MessageSanitizer;
use App\Repository\ConversationRepository;
use App\Repository\MailboxMemberRepository;
use App\Security\MailboxVoter;
use Doctrine\ORM\EntityManagerInterface;
use Rocket\Core\Entity\User;
use Rocket\Core\Security\ActorContext;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

/**
 * Shared inboxes (/api/inbox): the mailboxes the user is a member of, their conversations, replies, notes,
 * assignment and status. Access: MailboxVoter (members only, never through an application).
 */
#[Route('/api/inbox')]
final class InboxController extends AbstractController
{
    public function __construct(
        private readonly ActorContext $actor,
        private readonly ConversationRepository $conversations,
        private readonly MailboxMemberRepository $members,
        private readonly EntityManagerInterface $em,
        private readonly MessageSanitizer $sanitizer,
    ) {
    }

    /** Shared inboxes of the current user, with their unread and open conversations. */
    #[Route('/mailboxes', name: 'api_inbox_mailboxes', methods: ['GET'])]
    public function mailboxes(): JsonResponse
    {
        $user = $this->user();
        $list = [];
        foreach ($this->members->forUser($user) as $membership) {
            $mailbox = $membership->getMailbox();
            if (!$mailbox->isInboxEnabled()) {
                continue;
            }
            $list[] = [
                'id' => $mailbox->getId()->toRfc4122(),
                'name' => $mailbox->getName(),
                'email' => $mailbox->getEmail(),
                'role' => $membership->getRole(),
                'unread' => $this->conversations->countUnread($mailbox, $user),
                'open' => $this->conversations->countOpen($mailbox),
                'fetchedAt' => $mailbox->getInboxFetchedAt()?->format(\DATE_ATOM),
                'error' => $mailbox->getInboxError(),
            ];
        }

        return $this->json($list);
    }

    /** ?status=open|closed, ?mine=1 (assigned to me), ?q= (subject, sender, text). */
    #[Route('/mailboxes/{id}/conversations', name: 'api_inbox_conversations', methods: ['GET'])]
    public function conversations(Mailbox $mailbox, Request $request): JsonResponse
    {
        $this->denyAccessUnlessGranted(MailboxVoter::READ, $mailbox);
        $status = $request->query->getString('status');
        $rows = $this->conversations->search(
            $mailbox,
            $this->user(),
            \in_array($status, [Conversation::STATUS_OPEN, Conversation::STATUS_CLOSED], true) ? $status : null,
            $request->query->getBoolean('mine'),
            mb_substr($request->query->getString('q'), 0, 100),
        );

        return $this->json(array_map(fn (array $row) => $this->summary($row[0], $row['unread']), $rows));
    }

    #[Route('/mailboxes/{id}/fetch', name: 'api_inbox_fetch', methods: ['POST'])]
    public function fetch(Mailbox $mailbox, InboxFetcher $fetcher): JsonResponse
    {
        $this->denyAccessUnlessGranted(MailboxVoter::READ, $mailbox);
        try {
            return $this->json(['fetched' => $fetcher->fetch($mailbox)]);
        } catch (\RuntimeException $e) {
            return $this->json(['detail' => $e->getMessage()], Response::HTTP_BAD_GATEWAY);
        }
    }

    /** The thread (received messages, replies, notes, oldest first); marks it as read. ?images=1 keeps remote images. */
    #[Route('/conversations/{id}', name: 'api_inbox_conversation', methods: ['GET'])]
    public function conversation(Conversation $conversation, Request $request): JsonResponse
    {
        $this->denyAccessUnlessGranted(MailboxVoter::READ, $conversation);
        $this->conversations->markRead($conversation, $this->user());
        $this->em->flush();

        return $this->json($this->detail($conversation, $request->query->getBoolean('images')));
    }

    /** { "status": "open"|"closed", "assignee": "<user id>"|null } */
    #[Route('/conversations/{id}', name: 'api_inbox_conversation_update', methods: ['PATCH'])]
    public function update(Conversation $conversation, Request $request): JsonResponse
    {
        $this->denyAccessUnlessGranted(MailboxVoter::READ, $conversation);
        $payload = $request->toArray();
        if (\array_key_exists('status', $payload)) {
            if (!\in_array($payload['status'], [Conversation::STATUS_OPEN, Conversation::STATUS_CLOSED], true)) {
                throw new UnprocessableEntityHttpException('status: "open" or "closed".');
            }
            $conversation->setStatus($payload['status']);
        }
        if (\array_key_exists('assignee', $payload)) {
            $conversation->setAssignee(null === $payload['assignee'] ? null : $this->memberUser($conversation->getMailbox(), (string) $payload['assignee']));
        }
        $this->em->flush();

        return $this->json($this->summary($conversation, false));
    }

    /** Marks the conversation as unread for the current user. */
    #[Route('/conversations/{id}/unread', name: 'api_inbox_conversation_unread', methods: ['POST'])]
    public function unread(Conversation $conversation): JsonResponse
    {
        $this->denyAccessUnlessGranted(MailboxVoter::READ, $conversation);
        $read = $this->em->getRepository(ConversationRead::class)->findOneBy(['conversation' => $conversation, 'user' => $this->user()]);
        if (null !== $read) {
            $this->em->remove($read);
            $this->em->flush();
        }

        return $this->json(null, Response::HTTP_NO_CONTENT);
    }

    /** { "htmlBody": "…", "to"?: [..], "cc"?: [..] }: sent from the mailbox, threaded. */
    #[Route('/conversations/{id}/reply', name: 'api_inbox_reply', methods: ['POST'])]
    public function reply(Conversation $conversation, Request $request, InboxReplier $replier): JsonResponse
    {
        $this->denyAccessUnlessGranted(MailboxVoter::READ, $conversation);
        $payload = $request->toArray();
        $to = isset($payload['to']) && \is_array($payload['to']) && [] !== $payload['to'] ? array_map('strval', $payload['to']) : null;
        $cc = isset($payload['cc']) && \is_array($payload['cc']) ? array_map('strval', $payload['cc']) : [];
        $email = $replier->reply($conversation, $this->user(), (string) ($payload['htmlBody'] ?? ''), $to, $cc);
        $this->conversations->markRead($conversation, $this->user());
        $this->em->flush();

        return $this->json($this->replyItem($email), Response::HTTP_ACCEPTED);
    }

    /** { "body": "…" }: internal note, visible to the members only. */
    #[Route('/conversations/{id}/notes', name: 'api_inbox_note', methods: ['POST'])]
    public function note(Conversation $conversation, Request $request): JsonResponse
    {
        $this->denyAccessUnlessGranted(MailboxVoter::READ, $conversation);
        $body = trim((string) ($request->toArray()['body'] ?? ''));
        if ('' === $body || mb_strlen($body) > 10_000) {
            throw new UnprocessableEntityHttpException('body: the note is required (10,000 characters at most).');
        }
        $note = new ConversationNote($conversation, $this->user(), $body);
        $conversation->touch($note->getCreatedAt());
        $this->em->persist($note);
        $this->em->flush();

        return $this->json($this->noteItem($note), Response::HTTP_CREATED);
    }

    /** A received file, always downloaded (never rendered by the browser). */
    #[Route('/attachments/{id}', name: 'api_inbox_attachment', methods: ['GET'])]
    public function attachment(InboundAttachment $attachment, AttachmentStorage $storage): Response
    {
        $this->denyAccessUnlessGranted(MailboxVoter::READ, $attachment->getMessage()->getConversation());
        $path = $storage->pathOf($attachment->getStorageName());
        if (!is_file($path)) {
            throw new NotFoundHttpException('This file is missing from the storage.');
        }
        $response = new BinaryFileResponse($path);
        $response->headers->set('Content-Type', 'application/octet-stream');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Content-Security-Policy', "default-src 'none'; sandbox");
        $response->setContentDisposition(ResponseHeaderBag::DISPOSITION_ATTACHMENT, $attachment->getFilename(), 'piece-jointe');

        return $response;
    }

    private function user(): User
    {
        if (null !== $this->actor->getApplication() || $this->actor->isEmbed()) {
            throw new AccessDeniedHttpException('Shared inboxes are only available in Rocket Mailer.');
        }

        return $this->actor->requireUser();
    }

    private function memberUser(Mailbox $mailbox, string $userId): User
    {
        $user = Uuid::isValid($userId) ? $this->em->getRepository(User::class)->find($userId) : null;
        if (null === $user || null === $this->members->membership($mailbox, $user)) {
            throw new UnprocessableEntityHttpException('assignee: only a member of the mailbox can be assigned.');
        }

        return $user;
    }

    /** @return array<string, mixed> */
    private function summary(Conversation $conversation, bool $unread): array
    {
        return [
            'id' => $conversation->getId()->toRfc4122(),
            'subject' => $conversation->getSubject(),
            'status' => $conversation->getStatus(),
            'assignee' => self::person($conversation->getAssignee()),
            'participants' => $conversation->getParticipants(),
            'lastFrom' => $conversation->getLastFrom(),
            'snippet' => $conversation->getSnippet(),
            'messageCount' => $conversation->getMessageCount(),
            'lastMessageAt' => $conversation->getLastMessageAt()->format(\DATE_ATOM),
            'lastActivityAt' => $conversation->getLastActivityAt()->format(\DATE_ATOM),
            'unread' => $unread,
        ];
    }

    /** @return array<string, mixed> */
    private function detail(Conversation $conversation, bool $images): array
    {
        $mailbox = $conversation->getMailbox();
        $items = [];
        foreach ($this->em->getRepository(InboundMessage::class)->findBy(['conversation' => $conversation]) as $message) {
            $html = $message->getHtml();
            $items[] = [
                'type' => 'inbound',
                'id' => $message->getId()->toRfc4122(),
                'at' => $message->getDate()->format(\DATE_ATOM),
                // Arrival in the mailbox: orders the thread (the Date header is the sender's clock).
                'receivedAt' => $message->getReceivedAt()->format(\DATE_ATOM),
                'from' => $message->getFromAddress(),
                'fromName' => $message->getFromName(),
                'replyTo' => $message->getReplyTo(),
                'to' => $message->getTo(),
                'cc' => $message->getCc(),
                'subject' => $message->getSubject(),
                'text' => $message->getText(),
                'html' => null === $html || $images ? $html : $this->sanitizer->withoutRemoteImages($html),
                'hasRemoteImages' => $message->hasRemoteImages(),
                'attachments' => array_values($message->getAttachments()->map(static fn (InboundAttachment $a) => [
                    'id' => $a->getId()->toRfc4122(),
                    'filename' => $a->getFilename(),
                    'mimeType' => $a->getMimeType(),
                    'size' => $a->getSize(),
                ])->toArray()),
            ];
        }
        foreach ($this->em->getRepository(Email::class)->findBy(['conversation' => $conversation]) as $email) {
            $items[] = $this->replyItem($email);
        }
        foreach ($this->em->getRepository(ConversationNote::class)->findBy(['conversation' => $conversation]) as $note) {
            $items[] = $this->noteItem($note);
        }
        // Same second: the ids (UUID v7) keep the order of creation.
        usort($items, static fn (array $a, array $b) => [$a['receivedAt'] ?? $a['at'], $a['id']] <=> [$b['receivedAt'] ?? $b['at'], $b['id']]);

        return $this->summary($conversation, false) + [
            'mailbox' => ['id' => $mailbox->getId()->toRfc4122(), 'name' => $mailbox->getName(), 'email' => $mailbox->getEmail()],
            'members' => array_map(static fn ($m) => self::person($m->getUser()), $this->members->forMailbox($mailbox)),
            'items' => $items,
        ];
    }

    /** @return array<string, mixed> */
    private function replyItem(Email $email): array
    {
        return [
            'type' => 'reply',
            'id' => $email->getId()->toRfc4122(),
            'at' => ($email->getCreatedAt() ?? new \DateTimeImmutable())->format(\DATE_ATOM),
            'author' => self::person($email->getSender()),
            'to' => $email->getTo(),
            'cc' => $email->getCc(),
            'subject' => $email->getSubject(),
            // Written by a member in Rocket Mailer; displayed sanitized like any message.
            'html' => $this->sanitizer->sanitize($email->getHtmlBody())['html'],
            'status' => $email->getStatus()->value,
            'error' => $email->getErrorMessage(),
        ];
    }

    /** @return array<string, mixed> */
    private function noteItem(ConversationNote $note): array
    {
        return [
            'type' => 'note',
            'id' => $note->getId()->toRfc4122(),
            'at' => $note->getCreatedAt()->format(\DATE_ATOM),
            'author' => self::person($note->getAuthor()),
            'body' => $note->getBody(),
        ];
    }

    /** @return array{id: string, email: string, displayName: string}|null */
    public static function person(?User $user): ?array
    {
        return null === $user ? null : ['id' => $user->getId()->toRfc4122(), 'email' => $user->getEmail(), 'displayName' => $user->getDisplayName()];
    }
}
