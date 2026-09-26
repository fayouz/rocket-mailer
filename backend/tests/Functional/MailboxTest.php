<?php

namespace App\Tests\Functional;

use App\Mailbox\ImapClient;
use App\Tests\ApiTestTrait;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Sending mailboxes. The sending tests need a real SMTP/IMAP server: GreenMail
 * (GREENMAIL_HOST, test ports 3025/3143, user commercial / secret-pass). They are skipped without it.
 */
final class MailboxTest extends WebTestCase
{
    use ApiTestTrait;

    private function admin(): string
    {
        return 'Bearer '.$this->jwtFor($this->createUser('admin@example.org', ['ROLE_ADMIN']));
    }

    private function greenmail(): string
    {
        $host = getenv('GREENMAIL_HOST') ?: ($_SERVER['GREENMAIL_HOST'] ?? '');
        if ('' === $host || !@fsockopen($host, 3143, $errno, $error, 1)) {
            self::markTestSkipped('GreenMail is not available (GREENMAIL_HOST).');
        }

        return $host;
    }

    /** @return array<string, mixed> */
    private function createMailbox(string $admin, array $overrides = []): array
    {
        $host = $overrides['host'] ?? '127.0.0.1';
        unset($overrides['host']);

        return $this->api('POST', '/api/mailboxes', $overrides + [
            'name' => 'Service commercial',
            'email' => 'Commercial@CRM.example.org',
            'displayName' => 'Service commercial',
            'transport' => 'smtp',
            'smtpHost' => $host,
            'smtpPort' => 3025,
            'smtpEncryption' => 'none',
            'smtpUsername' => 'commercial',
            'smtpPassword' => 'secret-pass',
            'imapEnabled' => true,
            'imapHost' => $host,
            'imapPort' => 3143,
            'imapEncryption' => 'none',
        ], $admin);
    }

    public function testAdminsManageMailboxesAndSecretsNeverLeak(): void
    {
        $admin = $this->admin();
        $mailbox = $this->createMailbox($admin);
        $this->assertStatus(201);

        self::assertSame('commercial@crm.example.org', $mailbox['email']);
        self::assertTrue($mailbox['hasSmtpPassword']);
        self::assertFalse($mailbox['hasImapPassword'], 'IMAP reuses the SMTP password');
        $json = json_encode($mailbox);
        self::assertStringNotContainsString('secret-pass', $json);
        self::assertArrayNotHasKey('smtpPassword', $mailbox);

        $stored = $this->em()->getConnection()->fetchOne('SELECT smtp_password FROM mailbox');
        self::assertStringStartsWith('v1:', $stored);
        self::assertStringNotContainsString('secret-pass', $stored);

        // Omitting the password keeps it.
        $patched = $this->api('PATCH', '/api/mailboxes/'.$mailbox['id'], ['name' => 'Commercial'], $admin);
        $this->assertStatus(200);
        self::assertTrue($patched['hasSmtpPassword']);
        self::assertSame($stored, $this->em()->getConnection()->fetchOne('SELECT smtp_password FROM mailbox'));

        // Provider DSN: stored encrypted, only its scheme and host are shown.
        $provider = $this->api('POST', '/api/mailboxes', [
            'name' => 'Brevo', 'email' => 'news@example.org', 'transport' => 'dsn', 'dsn' => 'smtp://apikey:SECRET-KEY@smtp-relay.example.org:587',
        ], $admin);
        $this->assertStatus(201);
        self::assertSame('smtp://smtp-relay.example.org', $provider['dsnHint']);
        self::assertStringNotContainsString('SECRET-KEY', json_encode($provider));

        $this->api('POST', '/api/mailboxes', ['name' => 'x', 'email' => 'x@example.org', 'transport' => 'smtp'], $admin);
        $this->assertStatus(422);

        $alice = 'Bearer '.$this->jwtFor($this->createUser('alice@example.org'));
        $this->api('GET', '/api/mailboxes', authorization: $alice);
        $this->assertStatus(403);
    }

    public function testMailboxesAreOfferedToTheirApplicationsOnly(): void
    {
        $admin = $this->admin();
        $this->createUser('alice@example.org');
        [$crm, $crmToken] = $this->createApplication(name: 'CRM');
        [, $erpToken] = $this->createApplication(name: 'ERP');
        $mailbox = $this->createMailbox($admin, ['applications' => ['/api/applications/'.$crm->getId()]]);
        self::assertSame(['/api/applications/'.$crm->getId()], $mailbox['applications']);
        $this->createMailbox($admin, ['name' => 'Support', 'email' => 'support@example.org', 'availableToUsers' => true]);

        $sources = fn (array $options) => array_map(static fn (array $o) => $o['source'].':'.$o['email'], $options);
        $asAlice = ['X-Impersonate-User' => 'alice@example.org'];

        // Through an application: its sender and its own mailboxes, not the ones shared with Rocket Mailer's users.
        $crmOptions = $sources($this->api('GET', '/api/senders', authorization: 'Bearer '.$crmToken, headers: $asAlice));
        self::assertSame(['application:crm@partner.example', 'mailbox:commercial@crm.example.org'], $crmOptions);

        $erpOptions = $sources($this->api('GET', '/api/senders', authorization: 'Bearer '.$erpToken, headers: $asAlice));
        self::assertSame(['application:crm@partner.example'], $erpOptions);

        $appOptions = $sources($this->api('GET', '/api/senders', authorization: 'Bearer '.$this->jwtFor($this->em()->getRepository(\Rocket\Core\Entity\User::class)->findOneBy(['email' => 'alice@example.org']))));
        self::assertSame(['mailbox:support@example.org'], array_values(array_filter($appOptions, static fn (string $s) => str_starts_with($s, 'mailbox:'))));

        // The ERP cannot send through the CRM's mailbox, nor through the shared one.
        $this->api('POST', '/api/emails', [
            'to' => ['client@example.com'], 'subject' => 'Hi', 'htmlBody' => '<p>Hi</p>', 'mailbox' => '/api/mailboxes/'.$mailbox['id'],
        ], 'Bearer '.$erpToken, $asAlice);
        $this->assertStatus(422);
        $this->api('POST', '/api/emails', [
            'to' => ['client@example.com'], 'subject' => 'Hi', 'htmlBody' => '<p>Hi</p>', 'from' => 'support@example.org',
        ], 'Bearer '.$erpToken, $asAlice);
        $this->assertStatus(422);
    }

    public function testSendingThroughAMailboxKeepsACopyInItsSentFolder(): void
    {
        $host = $this->greenmail();
        $admin = $this->admin();
        $this->createUser('alice@example.org');
        [$crm, $crmToken] = $this->createApplication(name: 'CRM');
        $mailbox = $this->createMailbox($admin, ['host' => $host, 'applications' => ['/api/applications/'.$crm->getId()]]);

        $test = $this->api('POST', '/api/mailboxes/'.$mailbox['id'].'/test', [], $admin);
        $this->assertStatus(200);
        self::assertTrue($test['smtp']['ok'], $test['smtp']['message']);
        self::assertTrue($test['imap']['ok'], $test['imap']['message']);
        self::assertSame('Sent', $test['imap']['sentFolder']);

        $subject = 'Devis '.bin2hex(random_bytes(4));
        $email = $this->api('POST', '/api/emails', [
            'to' => ['client@example.com'], 'subject' => $subject, 'htmlBody' => '<p>Bonjour</p>', 'mailbox' => '/api/mailboxes/'.$mailbox['id'],
        ], 'Bearer '.$crmToken, ['X-Impersonate-User' => 'alice@example.org']);
        $this->assertStatus(202);
        self::assertSame('Service commercial <commercial@crm.example.org>', $email['from']);
        self::assertSame('Service commercial', $email['mailboxName']);

        $sent = $this->api('GET', '/api/emails/'.$email['id'], authorization: $admin);
        self::assertSame('sent', $sent['status'], (string) ($sent['errorMessage'] ?? ''));
        self::assertSame('Sent', $sent['archivedIn'], (string) ($sent['archiveError'] ?? ''));

        // The copy is in the mailbox, as read.
        $imap = new ImapClient(5);
        $imap->connect($host, 3143, 'none');
        $imap->login('commercial', 'secret-pass');
        self::assertContains('Sent', $imap->folders()['folders']);
        $imap->logout();
        $raw = $this->searchSent($host, $subject);
        self::assertStringContainsString('From: Service commercial <commercial@crm.example.org>', $raw);
        self::assertStringNotContainsString('Reply-To', $raw, 'a mailbox receives its own replies');
    }

    public function testFromMayDesignateAMailboxAndArchiveFailuresDoNotFailTheEmail(): void
    {
        $host = $this->greenmail();
        $admin = $this->admin();
        $alice = 'Bearer '.$this->jwtFor($this->createUser('alice@example.org'));
        $this->createMailbox($admin, [
            'host' => $host, 'availableToUsers' => true, 'imapUsername' => 'commercial', 'imapPassword' => 'wrong-password',
        ]);

        $email = $this->api('POST', '/api/emails', [
            'to' => ['client@example.com'], 'subject' => 'Hi', 'htmlBody' => '<p>Hi</p>', 'from' => 'commercial@crm.example.org',
        ], $alice);
        $this->assertStatus(202);
        self::assertSame('Service commercial', $email['mailboxName']);

        $sent = $this->api('GET', '/api/emails/'.$email['id'], authorization: $alice);
        self::assertSame('sent', $sent['status']);
        self::assertNull($sent['archivedIn']);
        self::assertStringContainsString('IMAP authentication failed', $sent['archiveError']);
    }

    /** Raw message of the "Sent" folder whose subject matches (via a plain IMAP session). */
    private function searchSent(string $host, string $subject): string
    {
        $socket = fsockopen($host, 3143, $errno, $error, 5);
        $read = static function () use ($socket, &$tag): string {
            $out = '';
            while (false !== ($line = fgets($socket))) {
                $out .= $line;
                if (str_starts_with($line, $tag.' ')) {
                    break;
                }
            }

            return $out;
        };
        fgets($socket);
        foreach (['LOGIN commercial secret-pass', 'SELECT "Sent"', 'SEARCH SUBJECT "'.$subject.'"'] as $i => $command) {
            $tag = 't'.$i;
            fwrite($socket, "$tag $command\r\n");
            $response = $read();
        }
        preg_match('/\* SEARCH (\d+)/', $response, $m);
        $tag = 'f';
        fwrite($socket, 'f FETCH '.($m[1] ?? '1')." BODY.PEEK[]\r\n");
        $message = $read();
        fclose($socket);

        return $message;
    }
}
