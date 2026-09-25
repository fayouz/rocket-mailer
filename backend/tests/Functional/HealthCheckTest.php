<?php

namespace App\Tests\Functional;

use App\Ldap\LdapConfig;
use App\Ldap\UserDirectoryInterface;
use App\Message\CheckServicesHealth;
use App\Tests\ApiTestTrait;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Network checks of the LDAP server and of the sending mailboxes, shown on the dashboard.
 * The successful mailbox check needs GreenMail (GREENMAIL_HOST); failures use a closed port.
 */
final class HealthCheckTest extends WebTestCase
{
    use ApiTestTrait;

    private function admin(): string
    {
        return 'Bearer '.$this->jwtFor($this->createUser('admin@example.org', ['ROLE_ADMIN']));
    }

    /** A directory whose server answers, or not. */
    private function directory(bool $reachable): UserDirectoryInterface
    {
        return new class($reachable) implements UserDirectoryInterface {
            public int $pings = 0;

            public function __construct(public bool $reachable)
            {
            }

            public function isEnabled(): bool
            {
                return true;
            }

            public function checkCredentials(string $dn, string $password): bool
            {
                return false;
            }

            public function fetchUsers(): iterable
            {
                return [];
            }

            public function probe(LdapConfig $config, int $limit = 5): array
            {
                return ['count' => 0, 'sample' => []];
            }

            public function ping(LdapConfig $config): void
            {
                ++$this->pings;
                if (!$this->reachable) {
                    throw new \RuntimeException("Can't contact LDAP server");
                }
            }
        };
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

    private function enableLdap(string $admin): void
    {
        $this->api('PUT', '/api/ldap/config', [
            'enabled' => true,
            'url' => 'ldap://ldap.example.org:389',
            'baseDn' => 'ou=people,dc=example,dc=org',
            'bindDn' => 'cn=reader,dc=example,dc=org',
            'bindPassword' => 's3cret-bind',
            'userFilter' => '(objectClass=inetOrgPerson)',
        ], $admin);
        $this->assertStatus(200);
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

    public function testLdapAndMailboxFailuresShowOnTheDashboard(): void
    {
        $this->client->disableReboot();
        static::getContainer()->set(UserDirectoryInterface::class, $directory = $this->directory(false));
        $admin = $this->admin();
        $this->enableLdap($admin);

        // Not checked yet: unknown, the platform status ignores it.
        $dashboard = $this->api('GET', '/api/dashboard', authorization: $admin);
        self::assertSame('unknown', $this->service($dashboard['health'], 'ldap')['status']);
        self::assertSame('disabled', $this->service($dashboard['health'], 'mailboxes')['status']);

        // A mailbox whose servers refuse connections.
        $this->createMailbox($admin, 'Boîte en panne', '127.0.0.1', 1, 1);

        $health = $this->api('POST', '/api/health/check', authorization: $admin);
        $this->assertStatus(200);
        self::assertSame(1, $directory->pings);
        $ldap = $this->service($health, 'ldap');
        self::assertSame('down', $ldap['status']);
        self::assertStringContainsString("Can't contact LDAP server", $ldap['detail']);
        self::assertNotNull($ldap['check']['failingSince']);

        $mailboxes = $this->service($health, 'mailboxes');
        self::assertSame('down', $mailboxes['status']);
        self::assertStringContainsString('1 sur 1 en échec : Boîte en panne', $mailboxes['detail']);
        self::assertSame('down', $mailboxes['items'][0]['smtp']['status']);
        self::assertSame('down', $mailboxes['items'][0]['imap']['status']);
        self::assertSame('down', $health['status']);

        // The server is back: the failure streak ends.
        $directory->reachable = true;
        $ldap = $this->service($this->api('POST', '/api/health/check', authorization: $admin), 'ldap');
        self::assertSame('operational', $ldap['status']);
        self::assertNull($ldap['check']['failingSince']);
        self::assertNotNull($ldap['check']['lastOkAt']);

        // Users only see the overall status.
        $user = 'Bearer '.$this->jwtFor($this->createUser('user@example.org'));
        $this->api('POST', '/api/health/check', authorization: $user);
        $this->assertStatus(403);
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
        self::assertSame($mailbox['id'], $mailboxes['items'][0]['id']);
        self::assertSame(['operational', 'operational'], [$mailboxes['items'][0]['smtp']['status'], $mailboxes['items'][0]['imap']['status']]);

        // Disabled mailbox: its results are dropped at the next check.
        $this->api('PATCH', '/api/mailboxes/'.$mailbox['id'], ['enabled' => false], $admin);
        $tester = new CommandTester((new Application(static::$kernel))->find('app:health:check'));
        $tester->execute([]);
        $tester->assertCommandIsSuccessful();
        self::assertSame(0, (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM service_check'));
    }
}
