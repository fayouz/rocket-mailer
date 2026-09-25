<?php

namespace App\Tests\Functional;

use App\Entity\Email;
use App\Entity\EmailTemplate;
use App\Entity\User;
use App\Tests\ApiTestTrait;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class DashboardTest extends WebTestCase
{
    use ApiTestTrait;

    private function email(User $sender, string $subject, string $status = 'sent', array $to = ['client@example.com']): Email
    {
        $email = (new Email())->setSender($sender)->setSubject($subject)->setHtmlBody('<p>Hi</p>')->setTo($to);
        match ($status) {
            'sent' => $email->markSent(),
            'failed' => $email->markFailed('Relay refused'),
            default => null,
        };
        $this->em()->persist($email);

        return $email;
    }

    public function testUsersOnlySeeTheirOwnActivity(): void
    {
        $alice = $this->createUser('alice@example.org');
        $bob = $this->createUser('bob@example.org');
        $this->email($alice, 'Alice 1');
        $this->email($alice, 'Alice 2', 'failed');
        $this->email($alice, 'Alice 3', 'queued');
        $this->email($bob, 'Bob 1');
        $this->em()->persist((new EmailTemplate())->setName('Private to Bob')->setHtml('<p/>')->setOwner($bob));
        $this->em()->persist((new EmailTemplate())->setName('Shared by Bob')->setHtml('<p/>')->setOwner($bob)->setShared(true));
        $this->em()->flush();

        $stats = $this->api('GET', '/api/dashboard', authorization: 'Bearer '.$this->jwtFor($alice));
        $this->assertStatus(200);

        self::assertSame('user', $stats['scope']);
        self::assertSame(1, $stats['emails']['sent']);
        self::assertSame(1, $stats['emails']['failed']);
        self::assertSame(1, $stats['emails']['queued']);
        self::assertEquals(50, $stats['emails']['deliveryRate']);
        self::assertSame(['total' => 1, 'shared' => 1], $stats['templates']);
        self::assertCount(30, $stats['daily']);
        self::assertSame(['sent' => 1, 'failed' => 1, 'queued' => 1], array_intersect_key(end($stats['daily']), ['sent' => 0, 'failed' => 0, 'queued' => 0]));
        self::assertSame(['Alice 3', 'Alice 2', 'Alice 1'], array_column($stats['recentEmails'], 'subject'));
        self::assertNotContains('Bob 1', array_column($stats['activity'], 'title'));
        self::assertNotContains('Private to Bob (version 1)', array_column($stats['activity'], 'title'));
        self::assertContains('Shared by Bob (version 1)', array_column($stats['activity'], 'title'));

        // No admin-only data: users, applications, service details.
        self::assertArrayNotHasKey('users', $stats);
        self::assertArrayNotHasKey('applications', $stats);
        self::assertSame(['status'], array_keys($stats['health']));
    }

    public function testAdminsSeeThePlatformAndServiceDetails(): void
    {
        $admin = $this->createUser('admin@example.org', ['ROLE_ADMIN']);
        [$application] = $this->createApplication();
        $this->email($this->createUser('alice@example.org'), 'Via CRM')->setApplication($application);
        $this->email($admin, 'Admin');
        $this->em()->flush();

        $stats = $this->api('GET', '/api/dashboard', authorization: 'Bearer '.$this->jwtFor($admin));
        $this->assertStatus(200);

        self::assertSame('platform', $stats['scope']);
        self::assertSame(2, $stats['emails']['sent']);
        self::assertSame(['total' => 2, 'enabled' => 2, 'ldap' => 0, 'local' => 2], $stats['users']);
        self::assertSame('Partner CRM', $stats['applications'][0]['name']);
        self::assertSame(1, $stats['applications'][0]['sent']);
        self::assertContains('application.created', array_column($stats['activity'], 'type'));

        $services = array_column($stats['health']['services'], null, 'id');
        self::assertSame(['database', 'queue', 'mailer', 'mailboxes', 'ldap', 'sso', 'storage'], array_keys($services));
        self::assertSame('operational', $services['database']['status']);
        // MAILER_DSN=null://null in tests: nothing is actually delivered.
        self::assertSame('degraded', $services['mailer']['status']);
        self::assertSame('disabled', $services['ldap']['status']);
        self::assertNotSame('operational', $stats['health']['status']);
    }

    public function testDelegatedSessionsCannotReadTheDashboard(): void
    {
        $this->createUser('alice@example.org');
        [, $token] = $this->createApplication();

        $this->api('GET', '/api/dashboard', authorization: 'Bearer '.$token);
        $this->assertStatus(403);

        $embed = $this->api('POST', '/api/embed/token', authorization: 'Bearer '.$token, headers: ['X-Impersonate-User' => 'alice@example.org']);
        $this->api('GET', '/api/dashboard', authorization: 'Embed '.$embed['token']);
        $this->assertStatus(403);
    }

    public function testSentEmailsCanBeFiltered(): void
    {
        $admin = $this->createUser('admin@example.org', ['ROLE_ADMIN']);
        $alice = $this->createUser('alice@example.org');
        [$application] = $this->createApplication();
        $this->email($alice, 'Votre devis', 'sent', ['jean@client.example'])->setApplication($application);
        $this->email($alice, 'Relance', 'failed');
        $this->email($admin, 'Newsletter', 'sent', ['marie@client.example'])->setCc(['DEVIS@partner.example']);
        $this->em()->flush();
        $jwt = 'Bearer '.$this->jwtFor($admin);

        $subjects = fn (string $query) => array_column($this->api('GET', '/api/emails?'.$query, authorization: $jwt) ?? [], 'subject');

        self::assertEqualsCanonicalizing(['Votre devis', 'Newsletter'], $subjects('q=devis'), 'subject or recipients, case-insensitive');
        self::assertSame(['Votre devis'], $subjects('q=JEAN@'));
        self::assertSame(['Relance'], $subjects('status=failed'));
        self::assertEqualsCanonicalizing(['Votre devis', 'Relance'], $subjects('sender='.$alice->getId()));
        self::assertSame(['Votre devis'], $subjects('application='.$application->getId()));
        self::assertCount(3, $subjects('createdAt[after]='.date('Y-m-d')));
        self::assertCount(0, $subjects('createdAt[before]=2000-01-01'));

        $this->api('GET', '/api/emails?sender=not-a-uuid', authorization: $jwt);
        $this->assertStatus(422);

        // Filters never widen what a user can see.
        self::assertSame([], array_column($this->api('GET', '/api/emails?sender='.$admin->getId(), authorization: 'Bearer '.$this->jwtFor($alice)), 'subject'));
    }
}
