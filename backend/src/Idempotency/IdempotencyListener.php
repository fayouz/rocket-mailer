<?php

namespace App\Idempotency;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Rocket\Core\Security\ActorContext;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * "Idempotency-Key: <key>" on the sending endpoints (POST /api/emails, POST /api/inbox/conversations/{id}/reply):
 * a retry with the same key and the same payload, by the same caller (application and/or user), within 24 hours,
 * gets the first response again (header "Idempotent-Replayed: true") instead of sending twice. The same key with
 * another payload: 409. The same key while the first request is still running: 409 too.
 * Only successful responses are kept: after an error, the key can be used again.
 */
final class IdempotencyListener
{
    public const HEADER = 'Idempotency-Key';
    private const TTL = '-24 hours';
    /** A request that never completed (crash) frees its key after this delay. */
    private const PENDING_TTL = '-5 minutes';
    private const ATTRIBUTE = '_idempotency_key_id';
    private const ROUTES = [
        '#^/api/emails$#',
        '#^/api/inbox/conversations/[0-9a-f-]{36}/reply$#',
    ];

    public function __construct(
        private readonly Connection $db,
        private readonly ActorContext $actor,
    ) {
    }

    /** After the firewall and the scope guard (8, 7). */
    #[AsEventListener(event: KernelEvents::REQUEST, priority: 6)]
    public function onRequest(RequestEvent $event): void
    {
        $request = $event->getRequest();
        if (!$event->isMainRequest() || !$request->isMethod('POST') || !$request->headers->has(self::HEADER) || !self::supports($request)) {
            return;
        }
        $key = trim((string) $request->headers->get(self::HEADER));
        if ('' === $key || \strlen($key) > 190 || !preg_match('/^[\x21-\x7e]+$/', $key)) {
            $event->setResponse(self::problem(Response::HTTP_BAD_REQUEST, 'Idempotency-Key: 1 to 190 printable ASCII characters.'));

            return;
        }
        $scope = $this->scope();
        if (null === $scope) {
            return; // Not authenticated: the firewall answers.
        }
        $hash = hash('sha256', $request->getMethod()."\n".$request->getPathInfo()."\n".$request->getContent());

        $this->db->executeStatement('DELETE FROM idempotency_key WHERE created_at < :expired OR (response_status IS NULL AND created_at < :abandoned)', [
            'expired' => (new \DateTimeImmutable(self::TTL))->format('Y-m-d H:i:s'),
            'abandoned' => (new \DateTimeImmutable(self::PENDING_TTL))->format('Y-m-d H:i:s'),
        ]);

        try {
            $id = $this->db->transactional(fn (Connection $db) => $db->fetchOne(
                'INSERT INTO idempotency_key (scope, idempotency_key, request_hash, created_at) VALUES (:scope, :key, :hash, :now) RETURNING id',
                ['scope' => $scope, 'key' => $key, 'hash' => $hash, 'now' => (new \DateTimeImmutable())->format('Y-m-d H:i:s')],
            ));
            $request->attributes->set(self::ATTRIBUTE, (int) $id);

            return;
        } catch (UniqueConstraintViolationException) {
            // Already used: replay or refuse below.
        }

        $row = $this->db->fetchAssociative('SELECT * FROM idempotency_key WHERE scope = :scope AND idempotency_key = :key', ['scope' => $scope, 'key' => $key]);
        if (false === $row) {
            $event->setResponse(self::problem(Response::HTTP_CONFLICT, 'This Idempotency-Key is being processed: retry later.'));
        } elseif (!hash_equals($row['request_hash'], $hash)) {
            $event->setResponse(self::problem(Response::HTTP_CONFLICT, 'This Idempotency-Key was already used with another request.'));
        } elseif (null === $row['response_status']) {
            $event->setResponse(self::problem(Response::HTTP_CONFLICT, 'This Idempotency-Key is being processed: retry later.'));
        } else {
            $response = new Response((string) $row['response_body'], (int) $row['response_status']);
            if (null !== $row['response_content_type']) {
                $response->headers->set('Content-Type', $row['response_content_type']);
            }
            $response->headers->set('Idempotent-Replayed', 'true');
            $event->setResponse($response);
        }
    }

    #[AsEventListener(event: KernelEvents::RESPONSE)]
    public function onResponse(ResponseEvent $event): void
    {
        $id = $event->getRequest()->attributes->get(self::ATTRIBUTE);
        if (!\is_int($id)) {
            return;
        }
        $event->getRequest()->attributes->remove(self::ATTRIBUTE);
        $response = $event->getResponse();
        if (!$response->isSuccessful()) {
            $this->db->executeStatement('DELETE FROM idempotency_key WHERE id = :id', ['id' => $id]);

            return;
        }
        $this->db->executeStatement(
            'UPDATE idempotency_key SET response_status = :status, response_body = :body, response_content_type = :type WHERE id = :id',
            ['status' => $response->getStatusCode(), 'body' => (string) $response->getContent(), 'type' => $response->headers->get('Content-Type'), 'id' => $id],
        );
    }

    private static function supports(Request $request): bool
    {
        foreach (self::ROUTES as $pattern) {
            if (preg_match($pattern, $request->getPathInfo())) {
                return true;
            }
        }

        return false;
    }

    /** The caller: the application and/or the user it acts as. */
    private function scope(): ?string
    {
        $parts = [];
        if (null !== $application = $this->actor->getApplication()) {
            $parts[] = 'app:'.$application->getId()->toRfc4122();
        }
        if (null !== $user = $this->actor->getUser()) {
            $parts[] = 'user:'.$user->getId()->toRfc4122();
        }

        return [] === $parts ? null : implode('|', $parts);
    }

    private static function problem(int $status, string $detail): JsonResponse
    {
        return new JsonResponse(['status' => $status, 'detail' => $detail], $status);
    }
}
