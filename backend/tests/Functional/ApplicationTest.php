<?php

namespace App\Tests\Functional;

use App\Tests\ApiTestTrait;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * External applications (rocket-core) acting in Rocket Mailer; their tokens and impersonation are tested in rocket-core.
 */
final class ApplicationTest extends WebTestCase
{
    use ApiTestTrait;

    public function testApplicationWithoutImpersonationCannotReachResources(): void
    {
        [, $token] = $this->createApplication();

        $this->api('GET', '/api/emails', authorization: 'Bearer '.$token);
        $this->assertStatus(403);
    }

    public function testApplicationSendsEmailAsUser(): void
    {
        $alice = $this->createUser('alice@example.org');
        [, $token] = $this->createApplication();

        $email = $this->api('POST', '/api/emails', [
            'to' => ['client@example.com'],
            'subject' => 'Hello',
            'htmlBody' => '<p>Hi!</p>',
        ], 'Bearer '.$token, ['X-Impersonate-User' => 'alice@example.org']);

        $this->assertStatus(202);
        self::assertSame('alice@example.org', $email['sender']['email']);
        self::assertSame('Partner CRM', $email['applicationName']);
        self::assertSame('sent', $email['status']);
        self::assertEmailCount(1);
        $message = self::getMailerMessage();
        // Sent from the application's own sender, never the platform's; replies reach the user.
        self::assertEmailAddressContains($message, 'From', 'crm@partner.example');
        self::assertEmailAddressContains($message, 'Reply-To', 'alice@example.org');
        self::assertEmailAddressContains($message, 'To', 'client@example.com');

        $list = $this->api('GET', '/api/emails', authorization: 'Bearer '.$this->jwtFor($alice));
        self::assertCount(1, $list);
    }
}
