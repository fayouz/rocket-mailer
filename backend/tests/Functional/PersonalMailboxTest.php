<?php

namespace App\Tests\Functional;

use App\Entity\Mailbox;
use App\Tests\ApiTestTrait;
use App\Tests\Support\FakeMailServers;
use App\Tests\Support\HttpMock;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Personal mailboxes (password and OAuth), detection of the servers, connection test. No network: scripted IMAP/SMTP
 * servers (FakeMailServers), MX records (FakeMxResolver) and OAuth token endpoints (HttpMock).
 */
final class PersonalMailboxTest extends WebTestCase
{
    use ApiTestTrait {
        setUp as apiSetUp;
    }

    protected function setUp(): void
    {
        $this->apiSetUp();
        FakeMailServers::reset();
        HttpMock::reset();
    }

    public function testDetectsTheProvider(): void
    {
        $user = 'Bearer '.$this->jwtFor($this->createUser('alice@example.org'));

        $gmail = $this->api('GET', '/api/mailboxes/detect?email=Faez@gmail.com', authorization: $user);
        $this->assertStatus(200);
        self::assertSame('gmail', $gmail['provider']);
        self::assertSame(['host' => 'imap.gmail.com', 'port' => 993, 'encryption' => 'ssl'], $gmail['imap']);
        self::assertSame(['oauth_google', 'password'], $gmail['auth']);

        $ovh = $this->api('GET', '/api/mailboxes/detect?email=contact@loussa-housing.fr', authorization: $user);
        self::assertSame(['ovh', 'mx', 'ssl0.ovh.net'], [$ovh['provider'], $ovh['detectedBy'], $ovh['smtp']['host']]);
        self::assertSame('gmail', $this->api('GET', '/api/mailboxes/detect?email=a@workspace.example', authorization: $user)['provider']);
        self::assertSame('microsoft', $this->api('GET', '/api/mailboxes/detect?email=a@contoso.example', authorization: $user)['provider']);
        self::assertSame('orange', $this->api('GET', '/api/mailboxes/detect?email=a@wanadoo.fr', authorization: $user)['provider']);

        $other = $this->api('GET', '/api/mailboxes/detect?email=a@unknown.example', authorization: $user);
        self::assertSame(['other', 'mail.unknown.example'], [$other['provider'], $other['imap']['host']]);

        $this->api('GET', '/api/mailboxes/detect?email=nope', authorization: $user);
        $this->assertStatus(422);
    }

    public function testPersonalMailboxIsVisibleToItsOwnerOnly(): void
    {
        $alice = $this->createUser('alice@example.org');
        $asAlice = 'Bearer '.$this->jwtFor($alice);
        $asBob = 'Bearer '.$this->jwtFor($this->createUser('bob@example.org'));
        $asAdmin = 'Bearer '.$this->jwtFor($this->createUser('admin@example.org', ['ROLE_ADMIN']));

        $created = $this->api('POST', '/api/mailboxes/personal', ['email' => 'Contact@Loussa-Housing.fr', 'password' => 's3cret', 'displayName' => 'Alice'], $asAlice);
        $this->assertStatus(201);
        self::assertSame(['personal', 'ovh', 'password', 'contact@loussa-housing.fr', 'ssl0.ovh.net', true, true], [
            $created['kind'], $created['provider'], $created['authType'], $created['username'], $created['imap']['host'], $created['inboxEnabled'], $created['hasPassword'],
        ]);
        $id = $created['id'];

        $this->api('POST', '/api/mailboxes/personal', ['email' => 'contact@loussa-housing.fr', 'password' => 'x'], $asAlice);
        $this->assertStatus(409);
        $this->api('POST', '/api/mailboxes/personal', ['email' => 'contact@loussa-housing.fr'], $asAlice);
        $this->assertStatus(422);

        self::assertSame([$id], array_column($this->api('GET', '/api/mailboxes/personal', authorization: $asAlice), 'id'));
        self::assertSame([], $this->api('GET', '/api/mailboxes/personal', authorization: $asBob));
        $this->api('GET', '/api/mailboxes/personal/'.$id, authorization: $asBob);
        $this->assertStatus(404);
        $this->api('PATCH', '/api/mailboxes/personal/'.$id, ['imapHost' => 'evil.example'], $asBob);
        $this->assertStatus(404);
        $this->api('POST', '/api/mailboxes/'.$id.'/test', [], $asBob);
        $this->assertStatus(404);

        // The owner's inbox: sole manager, but nobody manages its members.
        $inboxes = $this->api('GET', '/api/inbox/mailboxes', authorization: $asAlice);
        self::assertSame([[$id, 'manager']], array_map(static fn (array $m) => [$m['id'], $m['role']], $inboxes));
        $this->api('GET', '/api/inbox/mailboxes/'.$id.'/conversations', authorization: $asAlice);
        $this->assertStatus(200);
        $this->api('GET', '/api/inbox/mailboxes/'.$id.'/members', authorization: $asAlice);
        $this->assertStatus(403);

        // Administrators: that it exists (metadata), never its content, no change of its servers.
        $list = $this->api('GET', '/api/mailboxes', authorization: $asAdmin);
        $row = array_values(array_filter($list, static fn (array $m) => $m['id'] === $id))[0];
        self::assertSame(['personal', 'alice@example.org'], [$row['kind'], $row['ownerEmail']]);
        self::assertArrayNotHasKey('smtpPassword', $row);
        $this->api('PATCH', '/api/mailboxes/'.$id, ['imapHost' => 'evil.example'], $asAdmin);
        $this->assertStatus(403);
        $this->api('GET', '/api/inbox/mailboxes/'.$id.'/conversations', authorization: $asAdmin);
        $this->assertStatus(403);
        $this->api('GET', '/api/inbox/mailboxes/'.$id.'/members', authorization: $asAdmin);
        $this->assertStatus(403);
        $this->api('POST', '/api/inbox/mailboxes/'.$id.'/members', ['email' => 'admin@example.org'], $asAdmin);
        $this->assertStatus(403);
        $this->api('POST', '/api/mailboxes/'.$id.'/test', [], $asAdmin);
        $this->assertStatus(404);

        $updated = $this->api('PATCH', '/api/mailboxes/personal/'.$id, ['name' => 'Pro', 'inboxEnabled' => false], $asAlice);
        $this->assertStatus(200);
        self::assertSame(['Pro', false], [$updated['name'], $updated['inboxEnabled']]);

        $this->api('DELETE', '/api/mailboxes/personal/'.$id, authorization: $asAlice);
        $this->assertStatus(204);
        self::assertSame([], $this->api('GET', '/api/mailboxes/personal', authorization: $asAlice));
    }

    public function testApplicationImpersonatingTheOwner(): void
    {
        $this->createUser('alice@example.org');
        [, $token] = $this->createApplication(canImpersonate: true, name: 'Rocket Host');
        $asAlice = ['X-Impersonate-User' => 'alice@example.org'];

        $created = $this->api('POST', '/api/mailboxes/personal', ['email' => 'alice@icloud.com', 'password' => 'app-password'], 'Bearer '.$token, $asAlice);
        $this->assertStatus(201);
        self::assertSame(['icloud', 'imap.mail.me.com', 587, 'starttls'], [$created['provider'], $created['imap']['host'], $created['smtp']['port'], $created['smtp']['encryption']]);
        self::assertCount(1, $this->api('GET', '/api/mailboxes/personal', authorization: 'Bearer '.$token, headers: $asAlice));
        $test = $this->api('POST', '/api/mailboxes/'.$created['id'].'/test', [], 'Bearer '.$token, $asAlice);
        $this->assertStatus(200);
        self::assertTrue($test['ok'], json_encode($test, \JSON_THROW_ON_ERROR));

        // Without impersonation, the application has no personal mailbox.
        $this->api('GET', '/api/mailboxes/personal', authorization: 'Bearer '.$token);
        $this->assertStatus(403);
        $this->api('GET', '/api/inbox/mailboxes/'.$created['id'].'/conversations', authorization: 'Bearer '.$token);
        $this->assertStatus(403);
    }

    public function testConnectionTestWithAPassword(): void
    {
        $asAlice = 'Bearer '.$this->jwtFor($this->createUser('alice@example.org'));
        $id = $this->api('POST', '/api/mailboxes/personal', ['email' => 'alice@free.fr', 'password' => 'pa"ss'], $asAlice)['id'];

        $test = $this->api('POST', '/api/mailboxes/'.$id.'/test', [], $asAlice);
        $this->assertStatus(200);
        self::assertTrue($test['ok'], json_encode($test, \JSON_THROW_ON_ERROR));
        self::assertSame('Connexion et authentification SMTP réussies.', $test['smtp']['message']);
        self::assertSame(['Connexion IMAP réussie.', 'Sent'], [$test['imap']['message'], $test['imap']['sentFolder']]);
        self::assertStringContainsString('LOGIN "alice@free.fr" "pa\\"ss"', FakeMailServers::imapReceived());
        self::assertStringContainsString('AUTH PLAIN', FakeMailServers::smtpReceived());

        FakeMailServers::$imapAccepts = FakeMailServers::$smtpAccepts = false;
        $test = $this->api('POST', '/api/mailboxes/'.$id.'/test', [], $asAlice);
        self::assertFalse($test['ok']);
        self::assertStringStartsWith('Identifiant ou mot de passe refusé par le serveur SMTP', $test['smtp']['message']);
        self::assertStringStartsWith('Identifiant ou mot de passe refusé par le serveur IMAP', $test['imap']['message']);
        self::assertStringContainsString('Invalid credentials', $test['imap']['detail']);
    }

    public function testOAuthMailbox(): void
    {
        $alice = $this->createUser('alice@example.org');
        $asAlice = 'Bearer '.$this->jwtFor($alice);
        $asAdmin = 'Bearer '.$this->jwtFor($this->createUser('admin@example.org', ['ROLE_ADMIN']));

        $refused = $this->api('POST', '/api/mailboxes/oauth/google/start', ['email' => 'alice@gmail.com'], $asAlice);
        $this->assertStatus(422);
        self::assertStringContainsString('n’est pas configurée', $refused['detail']);

        // The administrator registers the Google application: the secret is never returned.
        $this->api('PUT', '/api/mailboxes/oauth/apps/google', ['clientId' => 'client-123', 'clientSecret' => 'secret-456'], $asAlice);
        $this->assertStatus(403);
        $app = $this->api('PUT', '/api/mailboxes/oauth/apps/google', ['clientId' => 'client-123', 'clientSecret' => 'secret-456'], $asAdmin);
        $this->assertStatus(200);
        self::assertSame([true, true, 'https://mail.google.com/'], [$app['configured'], $app['hasClientSecret'], $app['scope']]);
        self::assertStringNotContainsString('secret-456', (string) $this->client->getResponse()->getContent());
        $apps = $this->api('GET', '/api/mailboxes/oauth/apps', authorization: $asAdmin);
        self::assertSame(['google', 'microsoft'], array_column($apps, 'provider'));
        self::assertStringEndsWith('/api/mailboxes/oauth/callback', $apps[0]['effectiveRedirectUri']);

        $this->api('POST', '/api/mailboxes/oauth/google/start', ['email' => 'alice@gmail.com', 'returnUrl' => 'https://evil.example/'], $asAlice);
        $this->assertStatus(422);
        $start = $this->api('POST', '/api/mailboxes/oauth/google/start', ['email' => 'alice@gmail.com'], $asAlice);
        $this->assertStatus(200);
        parse_str((string) parse_url($start['authorizationUrl'], \PHP_URL_QUERY), $query);
        self::assertStringStartsWith('https://accounts.google.com/o/oauth2/v2/auth?', $start['authorizationUrl']);
        self::assertSame(['client-123', 'https://mail.google.com/', 'S256', 'offline', 'alice@gmail.com'], [$query['client_id'], $query['scope'], $query['code_challenge_method'], $query['access_type'], $query['login_hint']]);

        $tokenRequests = [];
        HttpMock::on('https://oauth2.googleapis.com/token', static function (string $method, string $url, array $options) use (&$tokenRequests) {
            parse_str(\is_string($options['body'] ?? null) ? $options['body'] : '', $body);
            $tokenRequests[] = $body;

            return new MockResponse(json_encode('authorization_code' === $body['grant_type']
                ? ['access_token' => 'access-1', 'refresh_token' => 'refresh-1', 'expires_in' => 30]
                : ['access_token' => 'access-2', 'expires_in' => 3600]), ['response_headers' => ['content-type' => 'application/json']]);
        });

        // Tampered state: back to the interface with an error.
        $this->client->request('GET', '/api/mailboxes/oauth/callback?code=abc&state=forged');
        self::assertStringContainsString('status=error', (string) $this->client->getResponse()->headers->get('Location'));

        // The provider calls back (no authentication): the mailbox is created, its refresh token stored encrypted.
        $this->client->request('GET', '/api/mailboxes/oauth/callback?code=the-code&state='.urlencode($query['state']));
        $this->assertStatus(302);
        $location = (string) $this->client->getResponse()->headers->get('Location');
        self::assertStringStartsWith('http://localhost:3000/mailboxes/mine?status=connected&mailbox=', $location);
        self::assertSame(['authorization_code', 'the-code', 'client-123', 'secret-456'], [$tokenRequests[0]['grant_type'], $tokenRequests[0]['code'], $tokenRequests[0]['client_id'], $tokenRequests[0]['client_secret']]);
        self::assertNotEmpty($tokenRequests[0]['code_verifier']);

        $mailbox = $this->em()->getRepository(Mailbox::class)->findOneBy(['email' => 'alice@gmail.com']);
        self::assertNotNull($mailbox);
        self::assertSame([Mailbox::KIND_PERSONAL, Mailbox::AUTH_OAUTH_GOOGLE, 'imap.gmail.com', true], [$mailbox->getKind(), $mailbox->getAuthType(), $mailbox->getImapHost(), $mailbox->getOauthConnected()]);
        self::assertStringNotContainsString('refresh-1', (string) $mailbox->getEncryptedOAuthRefreshToken());
        $present = $this->api('GET', '/api/mailboxes/personal/'.$mailbox->getId(), authorization: $asAlice);
        self::assertSame(['oauth_google', true, false], [$present['authType'], $present['oauthConnected'], $present['hasPassword']]);

        // The access token expires within the margin: refreshed, then IMAP and SMTP log in with XOAUTH2.
        $test = $this->api('POST', '/api/mailboxes/'.$mailbox->getId().'/test', [], $asAlice);
        self::assertTrue($test['ok'], json_encode($test, \JSON_THROW_ON_ERROR));
        self::assertSame(['refresh_token', 'refresh-1'], [$tokenRequests[1]['grant_type'], $tokenRequests[1]['refresh_token']]);
        $sasl = base64_encode("user=alice@gmail.com\1auth=Bearer access-2\1\1");
        self::assertStringContainsString('AUTHENTICATE XOAUTH2 '.$sasl, FakeMailServers::imapReceived());
        self::assertStringContainsString('AUTH XOAUTH2 '.$sasl, FakeMailServers::smtpReceived());
        self::assertStringNotContainsString('AUTH PLAIN', FakeMailServers::smtpReceived());

        // Cached until it expires: no new refresh.
        $this->api('POST', '/api/mailboxes/'.$mailbox->getId().'/test', [], $asAlice);
        self::assertCount(2, $tokenRequests);

        // Rejected by the server: French advice to reconnect.
        FakeMailServers::$imapAccepts = false;
        $test = $this->api('POST', '/api/mailboxes/'.$mailbox->getId().'/test', [], $asAlice);
        self::assertStringStartsWith('Le serveur IMAP a refusé l’autorisation OAuth', $test['imap']['message']);
    }

    public function testRevokedOAuthGrant(): void
    {
        $asAdmin = 'Bearer '.$this->jwtFor($this->createUser('admin@example.org', ['ROLE_ADMIN']));
        $asAlice = 'Bearer '.$this->jwtFor($this->createUser('alice@example.org'));
        $this->api('PUT', '/api/mailboxes/oauth/apps/microsoft', ['clientId' => 'ms-client', 'clientSecret' => 'ms-secret', 'tenant' => 'organizations'], $asAdmin);
        $start = $this->api('POST', '/api/mailboxes/oauth/microsoft/start', ['email' => 'alice@contoso.example', 'returnUrl' => 'http://localhost:3000/wizard?step=2'], $asAlice);
        self::assertStringStartsWith('https://login.microsoftonline.com/organizations/oauth2/v2.0/authorize?', $start['authorizationUrl']);
        parse_str((string) parse_url($start['authorizationUrl'], \PHP_URL_QUERY), $query);
        self::assertSame('offline_access https://outlook.office.com/IMAP.AccessAsUser.All https://outlook.office.com/SMTP.Send', $query['scope']);

        // Consent refused.
        $this->client->request('GET', '/api/mailboxes/oauth/callback?error=access_denied&state='.urlencode($query['state']));
        self::assertStringStartsWith('http://localhost:3000/wizard?step=2&status=error&message=Connexion+annul', (string) $this->client->getResponse()->headers->get('Location'));

        HttpMock::json('https://login.microsoftonline.com/organizations/oauth2/v2.0/token', ['access_token' => 'a', 'refresh_token' => 'r', 'expires_in' => 3600]);
        $this->client->request('GET', '/api/mailboxes/oauth/callback?code=c&state='.urlencode($query['state']));
        $mailbox = $this->em()->getRepository(Mailbox::class)->findOneBy(['email' => 'alice@contoso.example']);
        self::assertSame(['microsoft', 'outlook.office365.com', 'smtp.office365.com'], [$mailbox->getProvider(), $mailbox->getImapHost(), $mailbox->getSmtpHost()]);

        // Expired: the refresh is refused (revoked grant).
        $this->em()->getConnection()->executeStatement('UPDATE mailbox SET oauth_expires_at = NOW() - INTERVAL \'1 hour\' WHERE id = ?', [$mailbox->getId()->toRfc4122()]);
        HttpMock::json('https://login.microsoftonline.com/organizations/oauth2/v2.0/token', ['error' => 'invalid_grant', 'error_description' => 'AADSTS70008'], 400);
        $test = $this->api('POST', '/api/mailboxes/'.$mailbox->getId().'/test', [], $asAlice);
        self::assertFalse($test['ok']);
        self::assertSame('L’autorisation Microsoft a expiré ou a été révoquée : reconnectez la boîte.', $test['smtp']['message']);
    }
}
