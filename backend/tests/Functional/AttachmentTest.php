<?php

namespace App\Tests\Functional;

use App\Entity\Attachment;
use App\Tests\ApiTestTrait;
use Symfony\Bundle\FrameworkBundle\Console\Application as ConsoleApplication;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpFoundation\File\UploadedFile;

final class AttachmentTest extends WebTestCase
{
    use ApiTestTrait;

    /** @return array<string, mixed>|null */
    private function upload(string $authorization, string $name = 'devis.pdf', string $content = "%PDF-1.4\nfake"): ?array
    {
        $path = tempnam(sys_get_temp_dir(), 'att');
        file_put_contents($path, $content);

        $this->client->request('POST', '/api/attachments', files: ['file' => new UploadedFile($path, $name, null, null, true)], server: [
            'HTTP_AUTHORIZATION' => $authorization,
            'HTTP_ACCEPT' => 'application/json',
        ]);

        return json_decode((string) $this->client->getResponse()->getContent(), true);
    }

    private function send(string $authorization, array $attachmentIds): ?array
    {
        return $this->api('POST', '/api/emails', [
            'to' => ['client@example.com'],
            'subject' => 'Votre devis',
            'htmlBody' => '<p>Ci-joint.</p>',
            'attachments' => array_map(static fn (string $id) => '/api/attachments/'.$id, $attachmentIds),
        ], $authorization);
    }

    public function testUploadAndSendWithAttachments(): void
    {
        $jwt = 'Bearer '.$this->jwtFor($this->createUser('alice@example.org'));

        $uploaded = $this->upload($jwt);
        $this->assertStatus(201);
        self::assertSame('devis.pdf', $uploaded['filename']);
        self::assertSame('application/pdf', $uploaded['mimeType']);
        self::assertSame(\strlen("%PDF-1.4\nfake"), $uploaded['size']);

        $email = $this->send($jwt, [$uploaded['id']]);
        $this->assertStatus(202);
        self::assertSame('sent', $email['status']);
        self::assertSame('devis.pdf', $email['attachments'][0]['filename']);

        $message = self::getMailerMessage();
        self::assertCount(1, $message->getAttachments());
        self::assertSame('devis.pdf', $message->getAttachments()[0]->getFilename());
        self::assertSame("%PDF-1.4\nfake", $message->getAttachments()[0]->getBody());

        $list = $this->api('GET', '/api/emails', authorization: $jwt);
        self::assertSame(1, $list[0]['attachmentCount']);
    }

    public function testCannotAttachSomeoneElsesFile(): void
    {
        $alice = 'Bearer '.$this->jwtFor($this->createUser('alice@example.org'));
        $bob = 'Bearer '.$this->jwtFor($this->createUser('bob@example.org'));

        $uploaded = $this->upload($alice);

        $this->send($bob, [$uploaded['id']]);
        $this->assertStatus(403);
        $this->api('GET', '/api/attachments/'.$uploaded['id'], authorization: $bob);
        $this->assertStatus(403);
        $this->client->request('GET', '/api/attachments/'.$uploaded['id'].'/download', server: ['HTTP_AUTHORIZATION' => $bob]);
        $this->assertStatus(403);
    }

    public function testAnAttachmentIsSentOnlyOnce(): void
    {
        $jwt = 'Bearer '.$this->jwtFor($this->createUser('alice@example.org'));
        $uploaded = $this->upload($jwt);

        $this->send($jwt, [$uploaded['id']]);
        $this->assertStatus(202);
        $this->send($jwt, [$uploaded['id']]);
        $this->assertStatus(422);

        $this->api('DELETE', '/api/attachments/'.$uploaded['id'], authorization: $jwt);
        $this->assertStatus(403);
    }

    public function testRejectsBlockedTypesAndOversizedFiles(): void
    {
        $jwt = 'Bearer '.$this->jwtFor($this->createUser('alice@example.org'));

        $this->upload($jwt, 'setup.exe', 'MZ');
        $this->assertStatus(422);

        $this->upload($jwt, 'huge.bin', str_repeat('a', 10 * 1024 * 1024 + 1));
        $this->assertStatus(413);

        $this->upload($jwt, 'empty.txt', '');
        $this->assertStatus(422);
    }

    public function testFilenamesAreSanitized(): void
    {
        $jwt = 'Bearer '.$this->jwtFor($this->createUser('alice@example.org'));

        self::assertSame('passwd', $this->upload($jwt, '../../etc/passwd', 'x')['filename']);
        self::assertSame('a_b.txt', $this->upload($jwt, "a\x00\"b.txt", 'x')['filename']);
    }

    public function testDownloadAndDeleteBeforeSending(): void
    {
        $jwt = 'Bearer '.$this->jwtFor($this->createUser('alice@example.org'));
        $uploaded = $this->upload($jwt, 'note.txt', 'hello');

        $this->client->request('GET', '/api/attachments/'.$uploaded['id'].'/download', server: ['HTTP_AUTHORIZATION' => $jwt]);
        $this->assertStatus(200);
        $response = $this->client->getInternalResponse();
        self::assertStringContainsString('attachment; filename=note.txt', $response->getHeader('Content-Disposition'));
        self::assertSame('application/octet-stream', $response->getHeader('Content-Type'));

        $this->api('DELETE', '/api/attachments/'.$uploaded['id'], authorization: $jwt);
        $this->assertStatus(204);
        self::assertNull($this->em()->find(Attachment::class, $uploaded['id']));
    }

    public function testEmbedSessionCanAttachButNotDownload(): void
    {
        $this->createUser('alice@example.org');
        [, $appToken] = $this->createApplication();
        $embed = 'Embed '.$this->api('POST', '/api/embed/token', authorization: 'Bearer '.$appToken, headers: ['X-Impersonate-User' => 'alice@example.org'])['token'];

        $uploaded = $this->upload($embed);
        $this->assertStatus(201);
        $this->api('GET', '/api/attachments/'.$uploaded['id'], authorization: $embed);
        $this->assertStatus(200);
        $this->client->request('GET', '/api/attachments/'.$uploaded['id'].'/download', server: ['HTTP_AUTHORIZATION' => $embed]);
        $this->assertStatus(403);

        $this->send($embed, [$uploaded['id']]);
        $this->assertStatus(202);
    }

    public function testPurgeRemovesOnlyOldUnsentUploads(): void
    {
        $jwt = 'Bearer '.$this->jwtFor($this->createUser('alice@example.org'));
        $sent = $this->upload($jwt, 'sent.txt', 'a');
        $this->send($jwt, [$sent['id']]);
        $unsent = $this->upload($jwt, 'unsent.txt', 'b');

        $tester = new CommandTester((new ConsoleApplication(static::$kernel))->find('app:attachments:purge'));
        $tester->execute(['--older-than' => '-1 minute']);
        $tester->assertCommandIsSuccessful();

        $this->em()->clear();
        self::assertNotNull($this->em()->find(Attachment::class, $sent['id']));
        self::assertNull($this->em()->find(Attachment::class, $unsent['id']));
    }
}
