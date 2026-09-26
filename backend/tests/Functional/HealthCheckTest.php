<?php

namespace App\Tests\Functional;

use App\Tests\ApiTestTrait;
use Rocket\Core\Message\CheckServicesHealth;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Network checks of the sending mailboxes (App\Health\MailboxesProbe), shown on the dashboard with those of rocket-core.
 * The successful mailbox check needs GreenMail (GREENMAIL_HOST); failures use a closed port.
 */
final class HealthCheckTest extends WebTestCase
{
    use ApiTestTrait;

    private function admin(): string
    {
        return 'Bearer '.$this->jwtFor($this->createUser('admin@example.org', ['ROLE_ADMIN']));
    }

    /** @return array<string, mixed> */
    private function service(array $health, string $id): array
    {
        foreach ($health['services'] as $service) {
            if ($id === $service['id']) {
                return $service;
            }
        }
        self::fail("No $id service");
    }

    /** @return array<string, mixed> */
    private function createMailbox(string $admin, string $name, string $host, int $smtpPort, int $imapPort): array
    {
        $mailbox = $this->api('POST', '/api/mailboxes', [
            'name' => $name,
            'email' => 'commercial@crm.example.org',
            'transport' => 'smtp',
            'smtpHost' => $host,
            'smtpPort' => $smtpPort,
            'smtpEncryption' => 'none',
            'smtpUsername' => 'commercial',
            'smtpPassword' => 'secret-pass',
            'imapEnabled' => true,
            'imapHost' => $host,
            'imapPort' => $imapPort,
            'imapEncryption' => 'none',
        ], $admin);
        $this->assertStatus(201);

        return $mailbox;
    }

    public function testMailboxFailuresShowOnTheDashboard(): void
    {
        $admin = $this->admin();

        $dashboard = $this->api('GET', '/api/dashboard', authorization: $admin);
        self::assertSame('disabled', $this->service($dashboard['health'], 'mailboxes')['status']);

        // A mailbox whose servers refuse connections: one check for sending, one for the IMAP copy.
        $mailbox = $this->createMailbox($admin, 'Boîte en panne', '127.0.0.1', 1, 1);
        self::assertSame('unknown', $this->service($this->api('GET', '/api/dashboard', authorization: $admin)['health'], 'mailboxes')['status']);

        $health = $this->api('POST', '/api/health/check', authorization: $admin);
        $this->assertStatus(200);
        $mailboxes = $this->service($health, 'mailboxes');
        self::assertSame('down', $mailboxes['status']);
        self::assertStringContainsString('2 sur 2 en échec : Boîte en panne (envoi)', $mailboxes['detail']);
        self::assertSame([$mailbox['id'].':smtp', $mailbox['id'].':imap'], array_column($mailboxes['items'], 'id'));
        self::assertSame(['down', 'down'], array_column($mailboxes['items'], 'status'));
        self::assertSame('down', $health['status']);

        // Users only see the overall status.
        $user = 'Bearer '.$this->jwtFor($this->createUser('user@example.org'));
        self::assertArrayNotHasKey('services', $this->api('GET', '/api/dashboard', authorization: $user)['health']);
    }

    public function testReachableMailboxAndScheduledCheck(): void
    {
        $host = getenv('GREENMAIL_HOST') ?: ($_SERVER['GREENMAIL_HOST'] ?? '');
        if ('' === $host || !@fsockopen($host, 3143, $errno, $error, 1)) {
            self::markTestSkipped('GreenMail is not available (GREENMAIL_HOST).');
        }
        $admin = $this->admin();
        $mailbox = $this->createMailbox($admin, 'Boîte commerciale', $host, 3025, 3143);

        // The scheduler sends CheckServicesHealth every 5 minutes (handled synchronously in tests).
        static::getContainer()->get(MessageBusInterface::class)->dispatch(new CheckServicesHealth());

        $mailboxes = $this->service($this->api('GET', '/api/dashboard', authorization: $admin)['health'], 'mailboxes');
        self::assertSame('operational', $mailboxes['status']);
        self::assertSame([$mailbox['id'].':smtp', $mailbox['id'].':imap'], array_column($mailboxes['items'], 'id'));
        self::assertSame(['operational', 'operational'], array_column($mailboxes['items'], 'status'));
        self::assertStringContainsString('Sent', $mailboxes['items'][1]['check']['detail']);

        // Disabled mailbox: its results are dropped at the next check.
        $this->api('PATCH', '/api/mailboxes/'.$mailbox['id'], ['enabled' => false], $admin);
        $tester = new CommandTester((new Application(static::$kernel))->find('app:health:check'));
        $tester->execute([]);
        $tester->assertCommandIsSuccessful();
        self::assertSame(0, (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM service_check'));
    }
}
