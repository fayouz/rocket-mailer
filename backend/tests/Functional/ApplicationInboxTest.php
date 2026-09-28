<?php

namespace App\Tests\Functional;

use App\Entity\Email;
use App\Inbox\InMemoryInboxSource;
use App\Tests\ApiTestTrait;
use Rocket\Core\Entity\Application;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Shared inboxes through the API of applications (e.g. a PMS): the application itself on the shared inboxes
 * attached to it, or impersonating a member; participant and externalRef filters; Idempotency-Key.
 */
final class ApplicationInboxTest extends WebTestCase
{
    use ApiTestTrait {
        setUp as apiSetUp;
    }

    private string $admin;
    private string $alice;
    private string $mailboxId;

    protected function setUp(): void
    {
        $this->apiSetUp();
        $this->client->disableReboot(); // Keeps the in-memory INBOX between requests.
        $this->admin = 'Bearer '.$this->jwtFor($this->createUser('admin@example.org', ['ROLE_ADMIN']));
        $this->alice = 'Bearer '.$this->jwtFor($this->createUser('alice@example.org'));
        $this->createUser('bob@example.org');

        $mailbox = $this->api('POST', '/api/mailboxes', [
            'name' => 'Réservations',
            'email' => 'contact@example.org',
            'transport' => 'dsn',
            'dsn' => 'null://null',
            'imapHost' => 'imap.example.test',
            'inboxEnabled' => true,
        ], $this->admin);
        $this->assertStatus(201);
        $this->mailboxId = $mailbox['id'];
        $this->api('POST', "/api/inbox/mailboxes/{$this->mailboxId}/members", ['email' => 'alice@example.org'], $this->admin);
        $this->assertStatus(201);
    }

    private function deliver(string $id, string $subject, string $from, array $headers = []): void
    {
        $lines = ['From: '.$from, 'To: contact@example.org', 'Subject: '.$subject, 'Date: Mon, 28 Sep 2026 10:00:00 +0200', 'Message-ID: <'.$id.'>', 'MIME-Version: 1.0', 'Content-Type: text/plain; charset=utf-8'];
        static::getContainer()->get(InMemoryInboxSource::class)->deliver('contact@example.org', implode("\r\n", [...$lines, ...$headers])."\r\n\r\nBonjour");
        $this->api('POST', "/api/inbox/mailboxes/{$this->mailboxId}/fetch", [], $this->alice);
        $this->assertStatus(200);
    }

    private function attach(Application $application): void
    {
        $this->api('PATCH', "/api/mailboxes/{$this->mailboxId}", ['applications' => ['/api/applications/'.$application->getId()]], $this->admin);
        $this->assertStatus(200);
    }

    /** @return list<array<string, mixed>> */
    private function conversations(string $auth, string $query = '', array $headers = []): array
    {
        $list = $this->api('GET', "/api/inbox/mailboxes/{$this->mailboxId}/conversations".$query, authorization: $auth, headers: $headers);
        $this->assertStatus(200);

        return $list;
    }

    public function testAttachedApplicationListsReadsAndRepliesForItself(): void
    {
        [$pms, $token] = $this->createApplication(canImpersonate: false, name: 'PMS');
        $app = 'Bearer '.$token;
        $this->deliver('b1@example.com', 'Arrivée', 'Guest <Guest@Example.com>');
        $this->deliver('b2@example.com', 'Facture', 'Autre <other@example.com>');

        // Not attached yet: nothing.
        self::assertSame([], $this->api('GET', '/api/inbox/mailboxes', authorization: $app));
        $this->api('GET', "/api/inbox/mailboxes/{$this->mailboxId}/conversations", authorization: $app);
        $this->assertStatus(403);

        $this->attach($pms);
        $mailboxes = $this->api('GET', '/api/inbox/mailboxes', authorization: $app);
        self::assertSame([['Réservations', 'application', 0]], array_map(static fn (array $m) => [$m['name'], $m['role'], $m['unread']], $mailboxes));
        self::assertCount(2, $this->conversations($app));

        // ?participant: exact address, case-insensitive.
        $guest = $this->conversations($app, '?participant=GUEST@example.COM');
        self::assertSame(['Arrivée'], array_column($guest, 'subject'));
        self::assertSame([], $this->conversations($app, '?participant=guest@example'));
        $id = $guest[0]['id'];

        // Reading through the application does not mark the conversation as read for the members.
        $detail = $this->api('GET', "/api/inbox/conversations/{$id}", authorization: $app);
        $this->assertStatus(200);
        self::assertSame(['inbound'], array_column($detail['items'], 'type'));
        self::assertTrue($this->conversations($this->alice, '?participant=guest@example.com')[0]['unread']);

        $reply = $this->api('POST', "/api/inbox/conversations/{$id}/reply", ['htmlBody' => '<p>Bienvenue</p>', 'externalRef' => 'booking-42'], $app);
        $this->assertStatus(202);
        self::assertNull($reply['author']);
        self::assertSame('PMS', $reply['application']);
        self::assertSame('booking-42', $reply['externalRef']);
        self::assertSame(['guest@example.com'], $reply['to']);

        $this->em()->clear();
        $email = $this->em()->getRepository(Email::class)->find($reply['id']);
        self::assertSame('sent', $email->getStatus()->value, (string) $email->getErrorMessage());
        self::assertNull($email->getSender());
        self::assertSame('contact@example.org', $email->getFromAddress()->getAddress());

        $linked = $this->conversations($app, '?externalRef=booking-42');
        self::assertSame([$id], array_column($linked, 'id'));
        self::assertSame('booking-42', $linked[0]['externalRef']);
        self::assertSame([], $this->conversations($app, '?externalRef=booking-4'));

        // The members see the reply of the application; the dashboard copes with an email without user.
        $items = $this->api('GET', "/api/inbox/conversations/{$id}", authorization: $this->alice)['items'];
        self::assertSame(['inbound', 'reply'], array_column($items, 'type'));
        $this->api('GET', '/api/dashboard', authorization: $this->admin);
        $this->assertStatus(200);

        // Everything else stays closed to the application.
        foreach ([
            ['PATCH', "/api/inbox/conversations/{$id}", ['status' => 'closed']],
            ['POST', "/api/inbox/conversations/{$id}/notes", ['body' => 'x']],
            ['POST', "/api/inbox/conversations/{$id}/unread", []],
            ['POST', "/api/inbox/mailboxes/{$this->mailboxId}/fetch", []],
            ['GET', "/api/inbox/mailboxes/{$this->mailboxId}/members", null],
            ['POST', '/api/emails', ['to' => ['x@example.com'], 'subject' => 'x', 'htmlBody' => '<p>x</p>']],
        ] as [$method, $uri, $json]) {
            $this->api($method, $uri, $json, $app);
            $this->assertStatus(403);
        }

        // Another application, not attached: refused.
        [, $other] = $this->createApplication(canImpersonate: false, name: 'Other');
        $this->api('GET', "/api/inbox/conversations/{$id}", authorization: 'Bearer '.$other);
        $this->assertStatus(403);

        // Inbox disabled: refused, even when attached.
        $this->api('PATCH', "/api/mailboxes/{$this->mailboxId}", ['inboxEnabled' => false], $this->admin);
        $this->api('GET', "/api/inbox/conversations/{$id}", authorization: $app);
        $this->assertStatus(403);
    }

    public function testApplicationImpersonatingAMember(): void
    {
        [, $token] = $this->createApplication(canImpersonate: true, name: 'PMS');
        $app = 'Bearer '.$token;
        $this->deliver('i1@example.com', 'Question', 'guest@example.com');
        $asAlice = ['X-Impersonate-User' => 'alice@example.org'];
        $asBob = ['X-Impersonate-User' => 'bob@example.org'];

        $mailboxes = $this->api('GET', '/api/inbox/mailboxes', authorization: $app, headers: $asAlice);
        self::assertSame(['member'], array_column($mailboxes, 'role'));
        $id = $this->conversations($app, '', $asAlice)[0]['id'];
        $this->api('GET', "/api/inbox/conversations/{$id}", authorization: $app, headers: $asAlice);
        $this->assertStatus(200);
        $reply = $this->api('POST', "/api/inbox/conversations/{$id}/reply", ['htmlBody' => '<p>Oui</p>'], $app, $asAlice);
        $this->assertStatus(202);
        self::assertSame('alice@example.org', $reply['author']['email']);
        self::assertSame('PMS', $reply['application']);

        // Not a member: refused; members only features stay in Rocket Mailer.
        $this->api('GET', "/api/inbox/conversations/{$id}", authorization: $app, headers: $asBob);
        $this->assertStatus(403);
        $this->api('POST', "/api/inbox/conversations/{$id}/reply", ['htmlBody' => '<p>Oui</p>'], $app, $asBob);
        $this->assertStatus(403);
        $this->api('PATCH', "/api/inbox/conversations/{$id}", ['status' => 'closed'], $app, $asAlice);
        $this->assertStatus(403);
        $this->api('POST', "/api/inbox/conversations/{$id}/notes", ['body' => 'x'], $app, $asAlice);
        $this->assertStatus(403);
    }

    public function testIdempotencyKey(): void
    {
        $email = ['to' => ['guest@example.com'], 'subject' => 'Confirmation', 'htmlBody' => '<p>OK</p>', 'mailbox' => '/api/mailboxes/'.$this->mailboxId];
        $first = $this->api('POST', '/api/emails', $email, $this->alice, ['Idempotency-Key' => 'booking-42-confirmation']);
        $this->assertStatus(202);
        self::assertNull($this->client->getResponse()->headers->get('Idempotent-Replayed'));
        $again = $this->api('POST', '/api/emails', $email, $this->alice, ['Idempotency-Key' => 'booking-42-confirmation']);
        $this->assertStatus(202);
        self::assertSame('true', $this->client->getResponse()->headers->get('Idempotent-Replayed'));
        self::assertSame($first['id'], $again['id']);
        self::assertSame(1, $this->em()->getRepository(Email::class)->count(['subject' => 'Confirmation']));

        // Same key, another payload: conflict. Invalid key: 400.
        $this->api('POST', '/api/emails', ['subject' => 'Autre'] + $email, $this->alice, ['Idempotency-Key' => 'booking-42-confirmation']);
        $this->assertStatus(409);
        $this->api('POST', '/api/emails', $email, $this->alice, ['Idempotency-Key' => str_repeat('x', 191)]);
        $this->assertStatus(400);

        // A failed request does not keep the key.
        $this->api('POST', '/api/emails', ['subject' => ''] + $email, $this->alice, ['Idempotency-Key' => 'k2']);
        $this->assertStatus(422);
        $this->api('POST', '/api/emails', $email, $this->alice, ['Idempotency-Key' => 'k2']);
        $this->assertStatus(202);

        // Keys are per caller: the same key through an application is another request.
        [$pms, $token] = $this->createApplication(canImpersonate: false, name: 'PMS');
        $this->attach($pms);
        $this->deliver('k1@example.com', 'Question', 'guest@example.com');
        $id = $this->conversations($this->alice)[0]['id'];
        $headers = ['Idempotency-Key' => 'booking-42-confirmation'];
        $reply = $this->api('POST', "/api/inbox/conversations/{$id}/reply", ['htmlBody' => '<p>Oui</p>'], 'Bearer '.$token, $headers);
        $this->assertStatus(202);
        self::assertSame($reply['id'], $this->api('POST', "/api/inbox/conversations/{$id}/reply", ['htmlBody' => '<p>Oui</p>'], 'Bearer '.$token, $headers)['id']);
        $this->assertStatus(202);
        self::assertSame(['inbound', 'reply'], array_column($this->api('GET', "/api/inbox/conversations/{$id}", authorization: $this->alice)['items'], 'type'));
        $this->api('POST', "/api/inbox/conversations/{$id}/reply", ['htmlBody' => '<p>Non</p>'], 'Bearer '.$token, $headers);
        $this->assertStatus(409);
    }

    public function testEmailWithExternalRefStartsAConversation(): void
    {
        [$pms, $token] = $this->createApplication(canImpersonate: true, name: 'PMS');
        $this->attach($pms);
        $sent = $this->api('POST', '/api/emails', [
            'to' => ['guest@example.com'], 'subject' => 'Votre réservation', 'htmlBody' => '<p>Confirmée</p>',
            'mailbox' => '/api/mailboxes/'.$this->mailboxId, 'externalRef' => 'booking-7',
        ], 'Bearer '.$token, ['X-Impersonate-User' => 'alice@example.org']);
        $this->assertStatus(202);
        self::assertSame('booking-7', $sent['externalRef']);
        self::assertSame([$sent['id']], array_column($this->api('GET', '/api/emails?externalRef=booking-7', authorization: $this->alice), 'id'));

        $conversations = $this->conversations('Bearer '.$token, '?externalRef=booking-7');
        self::assertCount(1, $conversations);
        self::assertSame('closed', $conversations[0]['status']);
        self::assertSame(['guest@example.com'], $conversations[0]['participants']);

        // The guest answers: same conversation, reopened, the reply of the PMS inherits the reference.
        $this->em()->clear();
        $messageId = $this->em()->getRepository(Email::class)->find($sent['id'])->getMessageId();
        self::assertNotNull($messageId);
        $this->deliver('g1@example.com', 'Re: Votre réservation', 'guest@example.com', ['In-Reply-To: '.$messageId]);
        $list = $this->conversations('Bearer '.$token, '?participant=guest@example.com');
        self::assertSame([[$conversations[0]['id'], 'open', 'booking-7']], array_map(static fn (array $c) => [$c['id'], $c['status'], $c['externalRef']], $list));
        $reply = $this->api('POST', "/api/inbox/conversations/{$list[0]['id']}/reply", ['htmlBody' => '<p>Merci</p>'], 'Bearer '.$token);
        $this->assertStatus(202);
        self::assertSame('booking-7', $reply['externalRef']);

        // Too long: refused.
        $this->api('POST', "/api/inbox/conversations/{$list[0]['id']}/reply", ['htmlBody' => '<p>x</p>', 'externalRef' => str_repeat('x', 191)], 'Bearer '.$token);
        $this->assertStatus(422);
    }
}
