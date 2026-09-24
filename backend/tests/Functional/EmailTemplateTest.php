<?php

namespace App\Tests\Functional;

use App\Tests\ApiTestTrait;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class EmailTemplateTest extends WebTestCase
{
    use ApiTestTrait;

    public function testTemplateIsOwnedTrackedAndVersioned(): void
    {
        $alice = $this->createUser('alice@example.org');
        $jwt = 'Bearer '.$this->jwtFor($alice);

        $created = $this->api('POST', '/api/email_templates', [
            'name' => 'Welcome',
            'html' => '<table><tr><td style="color:red">Welcome</td></tr></table>',
            'projectData' => ['pages' => []],
        ], $jwt);
        $this->assertStatus(201);
        self::assertSame('alice@example.org', $created['owner']['email']);
        self::assertSame('alice@example.org', $created['createdBy']);
        self::assertNotNull($created['createdAt']);

        $this->api('PATCH', '/api/email_templates/'.$created['id'], ['name' => 'Welcome v2'], $jwt);
        $this->assertStatus(200);

        $versions = $this->api('GET', '/api/email_templates/'.$created['id'].'/versions', authorization: $jwt);
        $this->assertStatus(200);
        self::assertSame([2, 1], array_column($versions, 'version'));
        self::assertSame(['name'], $versions[0]['changedFields']);

        $restored = $this->api('POST', '/api/email_templates/'.$created['id'].'/versions/1/restore', authorization: $jwt);
        $this->assertStatus(200);
        self::assertSame('Welcome', $restored['name']);
        self::assertCount(3, $this->api('GET', '/api/email_templates/'.$created['id'].'/versions', authorization: $jwt));
    }

    public function testPrivateTemplatesAreHiddenFromOthers(): void
    {
        $alice = $this->createUser('alice@example.org');
        $bob = $this->createUser('bob@example.org');
        $aliceJwt = 'Bearer '.$this->jwtFor($alice);
        $bobJwt = 'Bearer '.$this->jwtFor($bob);

        $private = $this->api('POST', '/api/email_templates', ['name' => 'Private', 'html' => '<p>p</p>'], $aliceJwt);
        $this->api('POST', '/api/email_templates', ['name' => 'Shared', 'html' => '<p>s</p>', 'shared' => true], $aliceJwt);

        $list = $this->api('GET', '/api/email_templates', authorization: $bobJwt);
        self::assertSame(['Shared'], array_column($list, 'name'));

        $this->api('GET', '/api/email_templates/'.$private['id'], authorization: $bobJwt);
        $this->assertStatus(403);
        $this->api('PATCH', '/api/email_templates/'.$private['id'], ['name' => 'hacked'], $bobJwt);
        $this->assertStatus(403);
        $this->api('GET', '/api/email_templates/'.$private['id'].'/versions', authorization: $bobJwt);
        $this->assertStatus(403);

        $this->api('POST', '/api/emails', [
            'to' => ['x@example.com'], 'subject' => 's', 'htmlBody' => '<p/>',
            'template' => '/api/email_templates/'.$private['id'],
        ], $bobJwt);
        $this->assertStatus(403);
    }

    public function testEmailValidation(): void
    {
        $alice = $this->createUser('alice@example.org');

        $this->api('POST', '/api/emails', ['to' => ['not-an-email'], 'subject' => '', 'htmlBody' => ''], 'Bearer '.$this->jwtFor($alice));
        $this->assertStatus(422);
    }
}
