<?php

namespace App\Tests\Functional;

use App\Entity\ApplicationSender;
use App\Tests\ApiTestTrait;
use Rocket\Core\Entity\Application;
use Rocket\Core\Entity\User;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Uid\Uuid;

/** MAILER_DEFAULT_FROM="Rocket Test <no-reply@example.test>" in .env.test */
final class SenderTest extends WebTestCase
{
    use ApiTestTrait;

    private function send(string $authorization, ?string $from = null, array $headers = []): ?array
    {
        return $this->api('POST', '/api/emails', array_filter([
            'to' => ['client@example.com'],
            'subject' => 'Hello',
            'htmlBody' => '<p>Hi</p>',
            'from' => $from,
        ], static fn ($v) => null !== $v), $authorization, $headers);
    }

    public function testDefaultComesFromInstallConfigurationAndRepliesGoToTheUser(): void
    {
        $alice = $this->createUser('alice@example.org');
        $alice->setFirstName('Alice')->setLastName('Durand');
        $this->em()->flush();
        $jwt = 'Bearer '.$this->jwtFor($alice);

        $options = $this->api('GET', '/api/senders', authorization: $jwt);
        $this->assertStatus(200);
        self::assertSame(['Rocket Test <no-reply@example.test>', 'Alice Durand <alice@example.org>'], array_column($options, 'from'));
        self::assertSame([true, false], array_column($options, 'default'));
        self::assertSame(['settings', 'personal'], array_column($options, 'source'));

        $email = $this->send($jwt);
        $this->assertStatus(202);
        self::assertSame('Rocket Test <no-reply@example.test>', $email['from']);
        $message = self::getMailerMessage();
        self::assertEmailAddressContains($message, 'From', 'no-reply@example.test');
        self::assertEmailAddressContains($message, 'Reply-To', 'alice@example.org');
    }

    public function testUserCanSendFromTheirOwnAddressUnlessDisabled(): void
    {
        $jwt = 'Bearer '.$this->jwtFor($this->createUser('alice@example.org'));
        $admin = 'Bearer '.$this->jwtFor($this->createUser('admin@example.org', ['ROLE_ADMIN']));

        $this->send($jwt, 'alice@example.org');
        $this->assertStatus(202);
        $message = self::getMailerMessage();
        self::assertEmailAddressContains($message, 'From', 'alice@example.org');
        self::assertFalse($message->getHeaders()->has('Reply-To'));

        $this->api('PATCH', '/api/settings', ['personalFromAllowed' => false], $admin);
        $this->assertStatus(200);

        self::assertSame(['settings'], array_column($this->api('GET', '/api/senders', authorization: $jwt), 'source'));
        $this->send($jwt, 'alice@example.org');
        $this->assertStatus(422);
    }

    public function testArbitraryAddressesAreRefused(): void
    {
        $jwt = 'Bearer '.$this->jwtFor($this->createUser('alice@example.org'));

        $this->send($jwt, 'ceo@example.test');
        $this->assertStatus(422);
        $this->send($jwt, 'not an address <<');
        $this->assertStatus(422);
    }

    public function testAdminsManageSenderAddressesAndTheDefault(): void
    {
        $admin = 'Bearer '.$this->jwtFor($this->createUser('admin@example.org', ['ROLE_ADMIN']));
        $jwt = 'Bearer '.$this->jwtFor($this->createUser('alice@example.org'));

        $this->api('GET', '/api/settings', authorization: $admin);
        $support = $this->api('POST', '/api/sender_addresses', ['email' => 'Support@Example.test', 'name' => 'Support', 'isDefault' => true], $admin);
        $this->assertStatus(201);
        self::assertSame('support@example.test', $support['email']);

        $list = $this->api('GET', '/api/sender_addresses', authorization: $admin);
        self::assertSame(['support@example.test', 'no-reply@example.test'], array_column($list, 'email'));
        self::assertSame([true, false], array_column($list, 'isDefault'));
        self::assertSame('Support <support@example.test>', $this->send($jwt)['from']);
        self::assertSame('Rocket Test <no-reply@example.test>', $this->send($jwt, 'no-reply@example.test')['from']);

        // Removing the default promotes another address.
        $this->api('DELETE', '/api/sender_addresses/'.$support['id'], authorization: $admin);
        $this->assertStatus(204);
        self::assertSame([true], array_column($this->api('GET', '/api/sender_addresses', authorization: $admin), 'isDefault'));
    }

    public function testInstallDefaultIsSeededOnlyOnce(): void
    {
        $admin = 'Bearer '.$this->jwtFor($this->createUser('admin@example.org', ['ROLE_ADMIN']));

        $this->api('GET', '/api/settings', authorization: $admin);
        $senders = $this->api('GET', '/api/sender_addresses', authorization: $admin);
        $this->api('DELETE', '/api/sender_addresses/'.$senders[0]['id'], authorization: $admin);

        $this->api('GET', '/api/settings', authorization: $admin);
        self::assertSame([], $this->api('GET', '/api/sender_addresses', authorization: $admin));
        // Without sender addresses, the user's own address becomes the default.
        self::assertSame([true], array_column($this->api('GET', '/api/senders', authorization: $admin), 'default'));
    }

    public function testOnlyAdminsChangeSettings(): void
    {
        $jwt = 'Bearer '.$this->jwtFor($this->createUser('alice@example.org'));

        $this->api('GET', '/api/sender_addresses', authorization: $jwt);
        $this->assertStatus(403);
        $this->api('PATCH', '/api/settings', ['personalFromAllowed' => false], $jwt);
        $this->assertStatus(403);
    }

    public function testApplicationsImposeAddressesOnTheirOwnDomains(): void
    {
        $this->createUser('alice@example.org');
        [$application, $token] = $this->createApplication();
        $this->em()->find(ApplicationSender::class, $application->getId())->setAllowedSenders(['*@crm.example.com', 'direction@example.com']);
        $this->em()->flush();
        $asAlice = ['X-Impersonate-User' => 'alice@example.org'];

        $email = $this->send('Bearer '.$token, 'Agence Lyon <lyon@crm.example.com>', $asAlice);
        $this->assertStatus(202);
        self::assertSame('Agence Lyon <lyon@crm.example.com>', $email['from']);
        self::assertEmailAddressContains(self::getMailerMessage(), 'Reply-To', 'alice@example.org');

        $this->send('Bearer '.$token, 'direction@example.com', $asAlice);
        $this->assertStatus(202);
        $this->send('Bearer '.$token, 'other@example.com', $asAlice);
        $this->assertStatus(422);

        // The embedded composer gets the same right.
        $embed = 'Embed '.$this->api('POST', '/api/embed/token', authorization: 'Bearer '.$token, headers: $asAlice)['token'];
        $this->api('GET', '/api/senders', authorization: $embed);
        $this->assertStatus(200);
        $this->send($embed, 'Agence Lyon <lyon@crm.example.com>');
        $this->assertStatus(202);

        // A user alone cannot use the application's domains.
        $this->send('Bearer '.$this->jwtFor($this->em()->getRepository(User::class)->findOneBy(['email' => 'alice@example.org'])), 'lyon@crm.example.com');
        $this->assertStatus(422);
    }

    public function testApplicationSenderSettings(): void
    {
        $admin = 'Bearer '.$this->jwtFor($this->createUser('admin@example.org', ['ROLE_ADMIN']));
        $application = $this->api('POST', '/api/applications', ['name' => 'CRM'], $admin);

        // Readable before anything is saved, then saved by the first PATCH.
        $sender = $this->api('GET', '/api/application_senders/'.$application['id'], authorization: $admin);
        $this->assertStatus(200);
        self::assertEquals(['id' => $application['id'], 'allowedSenders' => []], $sender);
        self::assertSame([], $this->api('GET', '/api/application_senders', authorization: $admin));

        $this->api('PATCH', '/api/application_senders/'.$application['id'], ['allowedSenders' => ['*@*']], $admin);
        $this->assertStatus(422);
        $sender = $this->api('PATCH', '/api/application_senders/'.$application['id'], ['senderEmail' => ' CRM@Example.com ', 'senderName' => 'CRM', 'allowedSenders' => ['*@CRM.example.com', 'x@example.com']], $admin);
        $this->assertStatus(200);
        self::assertSame(['crm@example.com', ['*@crm.example.com', 'x@example.com']], [$sender['senderEmail'], $sender['allowedSenders']]);
        self::assertSame([$application['id']], array_column($this->api('GET', '/api/application_senders', authorization: $admin), 'id'));

        $this->api('GET', '/api/application_senders/'.Uuid::v7(), authorization: $admin);
        $this->assertStatus(404);
        $this->api('GET', '/api/application_senders/'.$application['id'], authorization: 'Bearer '.$this->jwtFor($this->createUser('alice@example.org')));
        $this->assertStatus(403);

        // They go with their application.
        $this->api('DELETE', '/api/applications/'.$application['id'], authorization: $admin);
        $this->assertStatus(204);
        $this->em()->clear();
        self::assertSame([], $this->em()->getRepository(ApplicationSender::class)->findAll());
    }

    public function testApplicationsNeverSendAsThePlatform(): void
    {
        $admin = 'Bearer '.$this->jwtFor($this->createUser('admin@example.org', ['ROLE_ADMIN']));
        $this->createUser('alice@example.org');
        $asAlice = ['X-Impersonate-User' => 'alice@example.org'];

        // The sender is part of the application; the platform default is only a suggestion for it.
        $created = $this->api('POST', '/api/applications', ['name' => 'ERP', 'canImpersonate' => true], $admin);
        $sender = $this->api('PATCH', '/api/application_senders/'.$created['id'], ['senderEmail' => 'ERP@Example.com', 'senderName' => 'ERP Acme'], $admin);
        $this->assertStatus(200);
        self::assertSame(['erp@example.com', 'ERP Acme'], [$sender['senderEmail'], $sender['senderName']]);
        $this->api('PATCH', '/api/application_senders/'.$created['id'], ['senderEmail' => 'not an email'], $admin);
        $this->assertStatus(422);

        // No sender configured: nothing to offer, and sending is refused with an explanation.
        [$bare, $token] = $this->createApplication(name: 'Bare', senderEmail: null);
        self::assertSame([], $this->api('GET', '/api/senders', authorization: 'Bearer '.$token, headers: $asAlice));
        $response = $this->send('Bearer '.$token, null, $asAlice);
        $this->assertStatus(422);
        self::assertStringContainsString('has no sender configured', $response['detail']);

        // Neither the settings' addresses (the platform default included) nor the user's own address.
        $this->em()->persist((new ApplicationSender($this->em()->find(Application::class, $bare->getId())))->setSenderEmail('bare@partner.example')->setSenderName('Bare'));
        $this->em()->flush();
        foreach (['no-reply@example.test', 'alice@example.org'] as $platformAddress) {
            $this->send('Bearer '.$token, $platformAddress, $asAlice);
            $this->assertStatus(422);
        }
        $options = $this->api('GET', '/api/senders', authorization: 'Bearer '.$token, headers: $asAlice);
        self::assertSame([['from' => 'Bare <bare@partner.example>', 'default' => true, 'source' => 'application']], array_map(
            static fn (array $o) => array_intersect_key($o, array_flip(['from', 'default', 'source'])),
            $options,
        ));
        $email = $this->send('Bearer '.$token, null, $asAlice);
        $this->assertStatus(202);
        self::assertSame('Bare <bare@partner.example>', $email['from']);
    }
}
