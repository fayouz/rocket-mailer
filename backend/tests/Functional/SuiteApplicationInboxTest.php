<?php

namespace App\Tests\Functional;

use App\Tests\ApiTestTrait;
use App\Tests\Support\HttpMock;
use Rocket\Core\Oidc\Jwt;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Suite mode: a Rocket Auth access token obtained by another brick (client credentials, audience "rocket-mailer")
 * is mapped by rocket-core to the application linked to that OAuth client, exactly like its "rma_" token: it reaches
 * the shared inboxes attached to it. Rocket Auth is simulated (signing key, discovery and JWKS served by a mock).
 */
final class SuiteApplicationInboxTest extends WebTestCase
{
    use ApiTestTrait;

    private const ISSUER = 'https://auth.example.test';
    private const SUITE_ENV = ['ROCKET_AUTH_URL' => self::ISSUER, 'ROCKET_AUTH_CLIENT_ID' => 'rocket-mailer', 'ROCKET_AUTH_CLIENT_SECRET' => 'secret'];

    protected function tearDown(): void
    {
        foreach (array_keys(self::SUITE_ENV) as $name) {
            unset($_ENV[$name], $_SERVER[$name]);
        }
        HttpMock::reset();
        parent::tearDown();
    }

    public function testClientCredentialsTokenActsAsTheLinkedApplication(): void
    {
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => \OPENSSL_KEYTYPE_RSA]);
        HttpMock::reset();
        HttpMock::json(self::ISSUER.'/.well-known/openid-configuration', ['issuer' => self::ISSUER, 'authorization_endpoint' => self::ISSUER.'/authorize', 'token_endpoint' => self::ISSUER.'/token', 'jwks_uri' => self::ISSUER.'/jwks']);
        HttpMock::json(self::ISSUER.'/jwks', ['keys' => [['kid' => 'k1'] + Jwt::publicJwk($key)]]);
        HttpMock::json(self::ISSUER, []); // Registration of the brick in Rocket Auth.
        foreach (self::SUITE_ENV as $name => $value) {
            $_ENV[$name] = $_SERVER[$name] = $value;
        }
        self::ensureKernelShutdown();
        $this->client = static::createClient();

        $admin = 'Bearer '.$this->jwtFor($this->createUser('admin@example.org', ['ROLE_ADMIN']));
        [$pms] = $this->createApplication(canImpersonate: false, name: 'Rocket PMS');
        $pms->setOauthClientId('rocket-pms');
        $this->em()->flush();

        $mailbox = $this->api('POST', '/api/mailboxes', [
            'name' => 'Réservations', 'email' => 'contact@example.org', 'transport' => 'dsn', 'dsn' => 'null://null',
            'imapHost' => 'imap.example.test', 'inboxEnabled' => true,
        ], $admin);
        $this->assertStatus(201);

        $token = static fn (array $claims) => 'Bearer '.Jwt::sign($claims + ['iss' => self::ISSUER, 'sub' => 'rocket-pms', 'azp' => 'rocket-pms', 'aud' => ['rocket-mailer'], 'exp' => time() + 300], $key, 'k1');


        $this->api('GET', "/api/inbox/mailboxes/{$mailbox['id']}/conversations", authorization: $token([]));
        $this->assertStatus(403); // Not attached yet.
        $this->api('PATCH', "/api/mailboxes/{$mailbox['id']}", ['applications' => ['/api/applications/'.$pms->getId()]], $admin);
        $this->assertStatus(200);
        self::assertSame([], $this->api('GET', "/api/inbox/mailboxes/{$mailbox['id']}/conversations", authorization: $token([])));
        $this->assertStatus(200);
        self::assertSame(['application'], array_column($this->api('GET', '/api/inbox/mailboxes', authorization: $token([])), 'role'));

        // Another audience, or a user's token: refused.
        $this->api('GET', '/api/inbox/mailboxes', authorization: $token(['aud' => ['rocket-print']]));
        $this->assertStatus(401);
        $this->api('GET', '/api/inbox/mailboxes', authorization: $token(['sub' => 'some-user']));
        $this->assertStatus(401);
    }
}
