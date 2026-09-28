<?php

namespace App\Tests\Functional;

use App\Entity\Email;
use App\Entity\MailboxMember;
use App\Inbox\InMemoryInboxSource;
use App\Inbox\MimeParser;
use App\MessageHandler\SendEmailHandler;
use App\Tests\ApiTestTrait;
use Rocket\Core\Entity\User;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Mime\Email as MimeEmail;

/**
 * Shared inboxes. Received messages come from the in-memory INBOX (DEMO_MODE=1 in the tests): never a real server.
 * Replies leave through a "null://null" provider DSN.
 */
final class SharedInboxTest extends WebTestCase
{
    use ApiTestTrait {
        setUp as apiSetUp;
    }

    private string $admin;
    private string $alice;
    private string $bob;
    private User $aliceUser;
    private User $bobUser;
    private string $mailboxId;

    protected function setUp(): void
    {
        $this->apiSetUp();
        $this->client->disableReboot(); // Keeps the in-memory INBOX between requests.
        $this->admin = 'Bearer '.$this->jwtFor($this->createUser('admin@example.org', ['ROLE_ADMIN']));
        $this->aliceUser = $this->createUser('alice@example.org');
        $this->bobUser = $this->createUser('bob@example.org');
        $this->alice = 'Bearer '.$this->jwtFor($this->aliceUser);
        $this->bob = 'Bearer '.$this->jwtFor($this->bobUser);

        $mailbox = $this->api('POST', '/api/mailboxes', [
            'name' => 'Contact',
            'email' => 'contact@example.org',
            'displayName' => 'Équipe contact',
            'transport' => 'dsn',
            'dsn' => 'null://null',
            'imapHost' => 'imap.example.test',
            'inboxEnabled' => true,
        ], $this->admin);
        $this->assertStatus(201);
        self::assertTrue($mailbox['inboxEnabled']);
        $this->mailboxId = $mailbox['id'];

        $this->api('POST', "/api/inbox/mailboxes/{$this->mailboxId}/members", ['email' => 'alice@example.org', 'role' => 'member'], $this->admin);
        $this->assertStatus(201);
    }

    private function deliver(string $raw): void
    {
        static::getContainer()->get(InMemoryInboxSource::class)->deliver('contact@example.org', $raw);
    }

    private static function message(string $id, string $subject, string $body, string $from = 'Client <client@example.com>', array $headers = [], string $type = 'text/plain'): string
    {
        $lines = ['From: '.$from, 'To: contact@example.org', 'Subject: '.$subject, 'Date: Mon, 28 Sep 2026 10:00:00 +0200', 'Message-ID: <'.$id.'>', 'MIME-Version: 1.0', 'Content-Type: '.$type.'; charset=utf-8'];

        return implode("\r\n", [...$lines, ...$headers])."\r\n\r\n".$body;
    }

    /** @return list<array<string, mixed>> */
    private function conversations(string $auth, string $query = ''): array
    {
        return $this->api('GET', "/api/inbox/mailboxes/{$this->mailboxId}/conversations".$query, authorization: $auth);
    }

    public function testOnlyMembersSeeAndUseTheSharedInbox(): void
    {
        $this->deliver(self::message('m1@example.com', 'Bonjour', 'Première demande'));
        self::assertSame(['fetched' => 1], $this->api('POST', "/api/inbox/mailboxes/{$this->mailboxId}/fetch", [], $this->alice));

        $mine = $this->api('GET', '/api/inbox/mailboxes', authorization: $this->alice);
        self::assertSame([['Contact', 'member', 1, 1]], array_map(static fn (array $m) => [$m['name'], $m['role'], $m['unread'], $m['open']], $mine));
        self::assertSame([], $this->api('GET', '/api/inbox/mailboxes', authorization: $this->bob));
        self::assertSame([], $this->api('GET', '/api/inbox/mailboxes', authorization: $this->admin), 'Administrators read a shared inbox only as members.');

        $conversationId = $this->conversations($this->alice)[0]['id'];
        foreach ([
            ['GET', "/api/inbox/mailboxes/{$this->mailboxId}/conversations"],
            ['POST', "/api/inbox/mailboxes/{$this->mailboxId}/fetch"],
            ['GET', "/api/inbox/conversations/{$conversationId}"],
            ['PATCH', "/api/inbox/conversations/{$conversationId}"],
            ['POST', "/api/inbox/conversations/{$conversationId}/reply"],
            ['POST', "/api/inbox/conversations/{$conversationId}/notes"],
        ] as [$method, $uri]) {
            $this->api($method, $uri, 'GET' === $method ? null : ['status' => 'closed', 'htmlBody' => '<p>x</p>', 'body' => 'x'], $this->bob);
            $this->assertStatus(403);
            $this->api($method, $uri, 'GET' === $method ? null : ['status' => 'closed', 'htmlBody' => '<p>x</p>', 'body' => 'x'], $this->admin);
            $this->assertStatus(403);
        }

        // Members do not manage the members; managers and administrators do.
        $this->api('GET', "/api/inbox/mailboxes/{$this->mailboxId}/members", authorization: $this->alice);
        $this->assertStatus(403);
        $this->api('POST', "/api/inbox/mailboxes/{$this->mailboxId}/members", ['email' => 'bob@example.org'], $this->alice);
        $this->assertStatus(403);
        $members = $this->api('GET', "/api/inbox/mailboxes/{$this->mailboxId}/members", authorization: $this->admin);
        $this->api('PATCH', '/api/inbox/members/'.$members[0]['id'], ['role' => 'manager'], $this->admin);
        $this->assertStatus(200);
        $bob = $this->api('POST', "/api/inbox/mailboxes/{$this->mailboxId}/members", ['email' => 'bob@example.org'], $this->alice);
        $this->assertStatus(201);
        $this->api('POST', "/api/inbox/mailboxes/{$this->mailboxId}/members", ['email' => 'bob@example.org'], $this->alice);
        $this->assertStatus(422);
        $this->api('GET', "/api/inbox/conversations/{$conversationId}", authorization: $this->bob);
        $this->assertStatus(200);
        $this->api('DELETE', '/api/inbox/members/'.$bob['id'], authorization: $this->alice);
        $this->assertStatus(204);
        $this->api('GET', "/api/inbox/conversations/{$conversationId}", authorization: $this->bob);
        $this->assertStatus(403);

        // Never through an application, even impersonating a member.
        [, $token] = $this->createApplication();
        $this->api('GET', "/api/inbox/conversations/{$conversationId}", authorization: 'Bearer '.$token, headers: ['X-Impersonate-User' => 'alice@example.org']);
        $this->assertStatus(403);

        // Members send from the mailbox; the others cannot.
        $senders = array_column($this->api('GET', '/api/senders', authorization: $this->alice), 'email');
        self::assertContains('contact@example.org', $senders);
        self::assertNotContains('contact@example.org', array_column($this->api('GET', '/api/senders', authorization: $this->bob), 'email'));
        $email = ['to' => ['someone@example.com'], 'subject' => 'Hi', 'htmlBody' => '<p>Hi</p>', 'mailbox' => '/api/mailboxes/'.$this->mailboxId];
        $sent = $this->api('POST', '/api/emails', $email, $this->alice);
        $this->assertStatus(202);
        self::assertSame('Contact', $sent['mailboxName']);
        $this->api('POST', '/api/emails', $email, $this->bob);
        $this->assertStatus(422);
        $this->api('POST', '/api/emails', ['to' => ['someone@example.com'], 'subject' => 'Hi', 'htmlBody' => '<p>Hi</p>', 'from' => 'contact@example.org'], $this->bob);
        $this->assertStatus(422);
    }

    public function testFetchedMessagesAreThreaded(): void
    {
        $this->deliver(self::message('a1@example.com', 'Commande 42', 'Où en est ma commande ?'));
        $this->deliver(self::message('a2@example.com', 'Re: Commande 42', 'Une précision', headers: ['In-Reply-To: <a1@example.com>', 'References: <a1@example.com>']));
        $this->deliver(self::message('a3@example.com', 'RE: TR: commande 42', 'Sans en-têtes de fil'));
        $this->deliver(self::message('a4@example.com', 'Commande 42', 'Même sujet, autre client', 'Autre <other@example.com>'));
        $this->deliver(self::message('b1@example.com', '=?UTF-8?B?RmFjdHVyZSDDqXTDqQ==?=', 'Nouvelle demande'));
        // Delivered twice (same Message-ID): imported once.
        $this->deliver(self::message('b1@example.com', 'Facture été', 'Doublon'));

        self::assertSame(['fetched' => 5], $this->api('POST', "/api/inbox/mailboxes/{$this->mailboxId}/fetch", [], $this->alice));
        self::assertSame(['fetched' => 0], $this->api('POST', "/api/inbox/mailboxes/{$this->mailboxId}/fetch", [], $this->alice));

        $list = $this->conversations($this->alice);
        $bySubject = [];
        foreach ($list as $c) {
            $bySubject[] = [$c['subject'], $c['messageCount'], $c['participants'], $c['unread']];
        }
        usort($bySubject, static fn ($a, $b) => [$a[0], $a[1]] <=> [$b[0], $b[1]]);
        self::assertSame([
            ['Commande 42', 1, ['other@example.com'], true],
            ['Commande 42', 3, ['client@example.com'], true],
            ['Facture été', 1, ['client@example.com'], true],
        ], $bySubject);

        $thread = array_values(array_filter($list, static fn ($c) => 3 === $c['messageCount']))[0];
        $detail = $this->api('GET', '/api/inbox/conversations/'.$thread['id'], authorization: $this->alice);
        self::assertSame(['inbound', 'inbound', 'inbound'], array_column($detail['items'], 'type'));
        self::assertSame('Où en est ma commande ?', $detail['items'][0]['text']);

        // Opening marks it as read, for alice only; it can be marked unread again.
        $unread = static fn (array $list) => array_sum(array_map(static fn ($c) => (int) $c['unread'], $list));
        self::assertSame(2, $unread($this->conversations($this->alice)));
        $this->api('POST', '/api/inbox/conversations/'.$thread['id'].'/unread', [], $this->alice);
        $this->assertStatus(204);
        self::assertSame(3, $unread($this->conversations($this->alice)));

        self::assertCount(1, $this->conversations($this->alice, '?q=autre%20client'));
    }

    public function testHtmlIsSanitizedAndRemoteImagesBlockedByDefault(): void
    {
        $boundary = 'b-123';
        $raw = implode("\r\n", [
            'From: Client <client@example.com>', 'To: contact@example.org', 'Subject: Rapport', 'Message-ID: <html@example.com>',
            'MIME-Version: 1.0', 'Content-Type: multipart/mixed; boundary="'.$boundary.'"', '',
            '--'.$boundary, 'Content-Type: text/html; charset=utf-8', 'Content-Transfer-Encoding: quoted-printable', '',
            '<p style=3D"color:red;background:url(https://t.example/x)" onclick=3D"steal()">Bonjour</p><script>alert(1)</script>',
            '<img src=3D"https://tracker.example/pixel.png"><form action=3D"https://evil.example"><input name=3D"pw"></form>',
            '<a href=3D"javascript:alert(1)">lien</a><style>body{display:none}</style>',
            '--'.$boundary, 'Content-Type: application/pdf; name="facture.pdf"', 'Content-Disposition: attachment; filename="../../facture.pdf"', 'Content-Transfer-Encoding: base64', '',
            base64_encode('%PDF-1.4 fake'),
            '--'.$boundary.'--', '',
        ]);
        $this->deliver($raw);
        $this->api('POST', "/api/inbox/mailboxes/{$this->mailboxId}/fetch", [], $this->alice);
        $id = $this->conversations($this->alice)[0]['id'];

        $message = $this->api('GET', '/api/inbox/conversations/'.$id, authorization: $this->alice)['items'][0];
        self::assertTrue($message['hasRemoteImages']);
        foreach (['<script', 'onclick', 'steal', 'url(', '<form', '<input', 'javascript:', '<style', 'tracker.example'] as $forbidden) {
            self::assertStringNotContainsStringIgnoringCase($forbidden, $message['html']);
        }
        self::assertStringContainsString('color:red', $message['html']);
        self::assertStringContainsString('Bonjour', $message['text']);

        $withImages = $this->api('GET', '/api/inbox/conversations/'.$id.'?images=1', authorization: $this->alice)['items'][0];
        self::assertStringContainsString('https://tracker.example/pixel.png', $withImages['html']);
        self::assertStringNotContainsString('<script', $withImages['html']);

        self::assertSame([['facture.pdf', 'application/pdf', 13]], array_map(static fn ($a) => [$a['filename'], $a['mimeType'], $a['size']], $message['attachments']));
        $this->client->request('GET', '/api/inbox/attachments/'.$message['attachments'][0]['id'], server: ['HTTP_AUTHORIZATION' => $this->alice]);
        $this->assertStatus(200);
        $response = $this->client->getResponse();
        self::assertStringContainsString('attachment', (string) $response->headers->get('Content-Disposition'));
        self::assertSame('application/octet-stream', $response->headers->get('Content-Type'));
        $this->api('GET', '/api/inbox/attachments/'.$message['attachments'][0]['id'], authorization: $this->bob);
        $this->assertStatus(403);
    }

    public function testRepliesAreThreadedAndSentFromTheMailbox(): void
    {
        $this->deliver(self::message('q1@example.com', 'Question', 'Bonjour', headers: ['Reply-To: Réponses <replies@example.com>', 'References: <q0@example.com>']));
        $this->api('POST', "/api/inbox/mailboxes/{$this->mailboxId}/fetch", [], $this->alice);
        $id = $this->conversations($this->alice)[0]['id'];

        $reply = $this->api('POST', "/api/inbox/conversations/{$id}/reply", ['htmlBody' => '<p>Voici la réponse</p>'], $this->alice);
        $this->assertStatus(202);
        self::assertSame(['replies@example.com'], $reply['to']);
        self::assertSame('Re: Question', $reply['subject']);

        $this->em()->clear();
        $email = $this->em()->getRepository(Email::class)->find($reply['id']);
        self::assertSame('sent', $email->getStatus()->value, (string) $email->getErrorMessage());
        self::assertSame('contact@example.org', $email->getFromAddress()->getAddress());
        self::assertSame('<q1@example.com>', $email->getInReplyTo());
        self::assertSame(['<q0@example.com>', '<q1@example.com>'], $email->getReferences());

        $mime = (new MimeEmail())->from('contact@example.org')->to('x@example.com')->text('x');
        SendEmailHandler::addThreadingHeaders($mime, $email);
        $headers = $mime->getHeaders();
        self::assertSame($email->getMessageId(), $headers->get('Message-ID')->getBodyAsString());
        self::assertSame('<q1@example.com>', $headers->get('In-Reply-To')->getBodyAsString());
        self::assertSame('<q0@example.com> <q1@example.com>', $headers->get('References')->getBodyAsString());

        // The customer answers our reply: same conversation, whatever the subject.
        $this->deliver(self::message('q2@example.com', 'Autre sujet', 'Merci', headers: ['In-Reply-To: '.$email->getMessageId()]));
        $this->api('POST', "/api/inbox/mailboxes/{$this->mailboxId}/fetch", [], $this->alice);
        $conversations = $this->conversations($this->alice);
        self::assertCount(1, $conversations);
        $types = array_column($this->api('GET', "/api/inbox/conversations/{$id}", authorization: $this->alice)['items'], 'type');
        self::assertSame(['inbound', 'reply', 'inbound'], $types);

        $this->api('POST', "/api/inbox/conversations/{$id}/reply", ['htmlBody' => '<p> </p>'], $this->alice);
        $this->assertStatus(422);
    }

    public function testAssignCloseAndNotes(): void
    {
        $this->deliver(self::message('c1@example.com', 'Support', 'Aide'));
        $this->api('POST', "/api/inbox/mailboxes/{$this->mailboxId}/fetch", [], $this->alice);
        $id = $this->conversations($this->alice)[0]['id'];

        $this->api('PATCH', "/api/inbox/conversations/{$id}", ['assignee' => $this->bobUser->getId()->toRfc4122()], $this->alice);
        $this->assertStatus(422);
        $updated = $this->api('PATCH', "/api/inbox/conversations/{$id}", ['assignee' => $this->aliceUser->getId()->toRfc4122(), 'status' => 'closed'], $this->alice);
        $this->assertStatus(200);
        self::assertSame(['alice@example.org', 'closed'], [$updated['assignee']['email'], $updated['status']]);

        self::assertCount(0, $this->conversations($this->alice, '?status=open'));
        self::assertCount(1, $this->conversations($this->alice, '?status=closed&mine=1'));

        $note = $this->api('POST', "/api/inbox/conversations/{$id}/notes", ['body' => 'Client rappelé, en attente.'], $this->alice);
        $this->assertStatus(201);
        self::assertSame('alice@example.org', $note['author']['email']);

        // A new message reopens it.
        $this->deliver(self::message('c2@example.com', 'Re: Support', 'Encore moi', headers: ['In-Reply-To: <c1@example.com>']));
        $this->api('POST', "/api/inbox/mailboxes/{$this->mailboxId}/fetch", [], $this->alice);
        $detail = $this->api('GET', "/api/inbox/conversations/{$id}", authorization: $this->alice);
        self::assertSame('open', $detail['status']);
        self::assertSame(['inbound', 'note', 'inbound'], array_column($detail['items'], 'type'));

        // Removing a member unassigns their conversations.
        $membership = $this->em()->getRepository(MailboxMember::class)->findOneBy(['user' => $this->aliceUser]);
        $this->api('DELETE', '/api/inbox/members/'.$membership->getId(), authorization: $this->admin);
        $this->assertStatus(204);
        $this->em()->clear();
        self::assertNull($this->em()->getConnection()->fetchOne('SELECT assignee_id FROM conversation'));
    }

    public function testMimeParserDecodesHeadersPartsAndAddresses(): void
    {
        $parsed = (new MimeParser())->parse(implode("\r\n", [
            'From: =?ISO-8859-1?Q?Ren=E9_Dupont?= <Rene@Example.COM>',
            'To: "Doe, John" <john@example.com>, jane@example.com',
            'Subject: =?UTF-8?Q?Caf=C3=A9?=',
            '  =?UTF-8?Q?_cr=C3=A8me?=',
            'Content-Type: multipart/alternative; boundary=xyz',
            '',
            '--xyz',
            'Content-Type: text/plain; charset=iso-8859-1',
            'Content-Transfer-Encoding: quoted-printable',
            '',
            'D=E9j=E0 vu',
            '--xyz',
            'Content-Type: text/html; charset=utf-8',
            '',
            '<p>Déjà vu</p>',
            '--xyz--',
        ]));
        self::assertSame(['rene@example.com', 'René Dupont'], [$parsed->fromAddress, $parsed->fromName]);
        self::assertSame(['john@example.com', 'jane@example.com'], $parsed->to);
        self::assertSame('Café crème', $parsed->subject);
        self::assertSame('Déjà vu', trim($parsed->text));
        self::assertSame('<p>Déjà vu</p>', trim((string) $parsed->html));
    }
}
