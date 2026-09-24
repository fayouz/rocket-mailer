<?php

namespace App\Tests\Functional;

use App\Tests\ApiTestTrait;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class EmbedTest extends WebTestCase
{
    use ApiTestTrait;

    private function embedToken(string $appToken, string $user = 'alice@example.org'): string
    {
        $response = $this->api('POST', '/api/embed/token', authorization: 'Bearer '.$appToken, headers: ['X-Impersonate-User' => $user]);
        $this->assertStatus(201);

        return $response['token'];
    }

    public function testEmbedSessionCanComposeAndSend(): void
    {
        $this->createUser('alice@example.org');
        [$application, $appToken] = $this->createApplication();
        $embed = 'Embed '.$this->embedToken($appToken);

        $context = $this->api('GET', '/api/embed/context', authorization: $embed);
        $this->assertStatus(200);
        self::assertSame(['https://partner.example'], $context['application']['allowedOrigins']);
        self::assertSame('alice@example.org', $context['user']['email']);

        $this->api('GET', '/api/email_templates', authorization: $embed);
        $this->assertStatus(200);

        $email = $this->api('POST', '/api/emails', ['to' => ['x@example.com'], 'subject' => 'From embed', 'htmlBody' => '<p>ok</p>'], $embed);
        $this->assertStatus(202);
        self::assertSame((string) $application->getId(), $this->em()->find(\App\Entity\Email::class, $email['id'])->getApplication()->getId()->toRfc4122());
    }

    public function testEmbedSessionIsRestrictedToCompose(): void
    {
        $this->createUser('alice@example.org');
        [, $appToken] = $this->createApplication();
        $embed = 'Embed '.$this->embedToken($appToken);

        $this->api('POST', '/api/email_templates', ['name' => 'x', 'html' => '<p/>'], $embed);
        $this->assertStatus(403);
        $this->api('GET', '/api/applications', authorization: $embed);
        $this->assertStatus(403);
        $this->api('POST', '/api/embed/token', authorization: $embed);
        $this->assertStatus(403);
    }

    public function testEmbedTokenIsNotAUserSession(): void
    {
        $this->createUser('alice@example.org');
        [, $appToken] = $this->createApplication();

        $this->api('GET', '/api/me', authorization: 'Bearer '.$this->embedToken($appToken));
        $this->assertStatus(401);
    }

    public function testDisablingTheApplicationRevokesEmbedSessions(): void
    {
        $this->createUser('alice@example.org');
        [$application, $appToken] = $this->createApplication();
        $embed = 'Embed '.$this->embedToken($appToken);

        $application->setEnabled(false);
        $this->em()->flush();

        $this->api('GET', '/api/me', authorization: $embed);
        $this->assertStatus(401);
    }

    public function testUsersCannotMintEmbedTokens(): void
    {
        $alice = $this->createUser('alice@example.org');

        $this->api('POST', '/api/embed/token', authorization: 'Bearer '.$this->jwtFor($alice));
        $this->assertStatus(403);
    }

    public function testFramePolicyIsPublicAndOnlyForImpersonatingApps(): void
    {
        [$application] = $this->createApplication(origins: ['https://crm.example']);
        [$other] = $this->createApplication(canImpersonate: false, origins: ['https://nope.example'], name: 'Other');

        $policy = $this->api('GET', '/api/embed/frame-policy?app='.$application->getId());
        $this->assertStatus(200);
        self::assertSame(['https://crm.example'], $policy['frameAncestors']);

        self::assertSame([], $this->api('GET', '/api/embed/frame-policy?app='.$other->getId())['frameAncestors']);
        self::assertSame([], $this->api('GET', '/api/embed/frame-policy?app=garbage')['frameAncestors']);
    }
}
