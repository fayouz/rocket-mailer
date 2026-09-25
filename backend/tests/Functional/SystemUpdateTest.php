<?php

namespace App\Tests\Functional;

use App\Tests\ApiTestTrait;
use App\Tests\Support\HttpMock;
use App\Update\AppVersion;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\Cache\CacheInterface;

final class SystemUpdateTest extends WebTestCase
{
    use ApiTestTrait {
        setUp as private apiSetUp;
    }

    private const GITHUB = 'https://api.github.com/repos/acme/rocket-mailer/';

    protected function setUp(): void
    {
        $this->apiSetUp();
        HttpMock::reset();
        static::getContainer()->get(CacheInterface::class)->delete('app.update.latest_release');
    }

    private function admin(): string
    {
        return 'Bearer '.$this->jwtFor($this->createUser('admin@example.org', ['ROLE_ADMIN']));
    }

    public function testEveryUserSeesTheVersionButOnlyAdminsTheUpdates(): void
    {
        $user = 'Bearer '.$this->jwtFor($this->createUser('user@example.org'));

        self::assertSame(['version' => '0.6.0+2 (abc1234)', 'release' => '0.6.0'], $this->api('GET', '/api/system/version', authorization: $user));
        $this->api('GET', '/api/system/update', authorization: $user);
        $this->assertStatus(403);
        $this->api('POST', '/api/system/update', authorization: $user);
        $this->assertStatus(403);
        $this->api('GET', '/api/system/version');
        $this->assertStatus(401);
    }

    public function testFindsTheLatestReleaseAndCachesIt(): void
    {
        HttpMock::json(self::GITHUB.'releases', [
            ['tag_name' => 'v0.6.0', 'name' => 'v0.6.0', 'html_url' => 'https://github.com/acme/rocket-mailer/releases/tag/v0.6.0', 'draft' => false, 'prerelease' => false, 'published_at' => '2026-09-24T10:00:00Z', 'body' => 'Tableau de bord'],
            ['tag_name' => 'v0.8.0-rc.1', 'name' => 'RC', 'html_url' => 'x', 'draft' => false, 'prerelease' => true],
            ['tag_name' => 'v0.7.0', 'name' => '0.7.0 — Boîtes d’envoi', 'html_url' => 'https://github.com/acme/rocket-mailer/releases/tag/v0.7.0', 'draft' => false, 'prerelease' => false, 'published_at' => '2026-09-25T10:00:00Z', 'body' => '- Boîtes d’envoi'],
        ]);

        $status = $this->api('GET', '/api/system/update', authorization: $admin = $this->admin());
        $this->assertStatus(200);
        self::assertSame('0.6.0+2 (abc1234)', $status['current']['version']);
        self::assertSame('0.7.0', $status['latest']['version']);
        self::assertSame('0.7.0 — Boîtes d’envoi', $status['latest']['name']);
        self::assertSame('- Boîtes d’envoi', $status['latest']['notes']);
        self::assertTrue($status['updateAvailable']);
        self::assertTrue($status['updater']['configured']);
        self::assertNull($status['error']);
        self::assertContains('User-Agent: Rocket-Mailer', HttpMock::$requests[0]['headers']);

        // Cached: GitHub is asked once an hour, unless the administrator asks for a new check.
        $this->api('GET', '/api/system/update', authorization: $admin);
        self::assertCount(1, HttpMock::$requests);
        $this->api('GET', '/api/system/update?refresh=1', authorization: $admin);
        self::assertCount(2, HttpMock::$requests);
    }

    public function testFallsBackToTagsAndReportsGithubErrors(): void
    {
        HttpMock::json(self::GITHUB.'releases', []);
        HttpMock::json(self::GITHUB.'tags', [['name' => 'v0.5.0'], ['name' => 'v0.6.0'], ['name' => 'nightly'], ['name' => 'v0.7.0-beta']]);

        $status = $this->api('GET', '/api/system/update', authorization: $admin = $this->admin());
        self::assertSame('0.6.0', $status['latest']['version']);
        self::assertSame('https://github.com/acme/rocket-mailer/tree/v0.6.0', $status['latest']['url']);
        // Two commits after 0.6.0: up to date.
        self::assertFalse($status['updateAvailable']);

        HttpMock::json(self::GITHUB.'releases', ['message' => 'API rate limit exceeded'], 403);
        $status = $this->api('GET', '/api/system/update?refresh=1', authorization: $admin);
        $this->assertStatus(200);
        self::assertNull($status['latest']);
        self::assertStringContainsString('réessayez dans une heure', $status['error']);
    }

    public function testStartsTheUpdateThroughTheUpdater(): void
    {
        HttpMock::on('http://updater.test:8080/v1/update', static fn () => new MockResponse('', ['http_code' => 202]));

        $response = $this->api('POST', '/api/system/update', authorization: $admin = $this->admin());
        $this->assertStatus(202);
        self::assertTrue($response['started']);
        $request = end(HttpMock::$requests);
        self::assertSame(['POST', 'http://updater.test:8080/v1/update?async=true'], [$request['method'], $request['url']]);
        self::assertContains('Authorization: Bearer updater-test-token', $request['headers']);

        HttpMock::on('http://updater.test:8080/v1/update', static fn () => new MockResponse('', ['http_code' => 429]));
        $response = $this->api('POST', '/api/system/update', authorization: $admin);
        $this->assertStatus(422);
        self::assertStringContainsString('déjà en cours', $response['detail'] ?? $response['message'] ?? '');
    }

    public function testParsesBuildVersions(): void
    {
        self::assertSame('0.7.0', AppVersion::parse('v0.7.0')->label());
        self::assertFalse(AppVersion::parse('v0.7.0')->isOlderThan('0.7.0'));
        self::assertTrue(AppVersion::parse('v0.7.0')->isOlderThan('0.10.0'));
        self::assertSame('0.7.0-rc.1', AppVersion::parse('v0.7.0-rc.1')->release);
        self::assertSame(3, AppVersion::parse('0.7.0-rc.1-3-gdeadbeef')->ahead);
        self::assertNull(AppVersion::parse('develop')->isOlderThan('1.0.0'));
        self::assertSame('dev', AppVersion::parse('')->label());
    }
}
