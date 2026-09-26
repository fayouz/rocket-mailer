<?php

namespace App\Tests\Functional;

use App\Entity\ApplicationSender;
use App\Entity\EmailLayout;
use App\Entity\EmailTemplate;
use App\Tests\ApiTestTrait;
use Rocket\Core\Command\DemoSeedCommand;
use Rocket\Core\Entity\Application;
use Symfony\Bundle\FrameworkBundle\Console\Application as ConsoleApplication;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Console\Tester\CommandTester;

/** The demo data of Rocket Mailer (App\Command\MailerDemoSeeder), loaded by app:demo:seed of rocket-core. */
final class DemoSeedCommandTest extends WebTestCase
{
    use ApiTestTrait;

    private const DEMO_TOKEN = 'rma_demo_0000000000000000000000000000000000';

    private function seed(): CommandTester
    {
        $tester = new CommandTester((new ConsoleApplication(static::$kernel))->find('app:demo:seed'));
        $tester->execute([]);

        return $tester;
    }

    public function testSeedIsIdempotentAndUsable(): void
    {
        $this->seed()->assertCommandIsSuccessful();
        $this->seed()->assertCommandIsSuccessful();

        $templates = $this->em()->getRepository(EmailTemplate::class)->findAll();
        self::assertCount(3, $templates);
        self::assertCount(1, $this->em()->getRepository(EmailLayout::class)->findAll());
        $appointment = $this->em()->getRepository(EmailTemplate::class)->findOneBy(['name' => 'Confirmation de rendez-vous']);
        self::assertSame('Charte Démo CRM', $appointment->getLayout()?->getName());
        self::assertSame('admin@example.org', $templates[0]->getCreatedBy());

        [$adminEmail, $adminPassword] = DemoSeedCommand::USERS[0];
        $login = $this->api('POST', '/api/auth/login', ['email' => $adminEmail, 'password' => $adminPassword]);
        $this->assertStatus(200);
        self::assertContains('ROLE_ADMIN', $this->api('GET', '/api/me', authorization: 'Bearer '.$login['token'])['roles']);

        // The demo application is Démo CRM: its origin, its own sender and its mailbox.
        $application = $this->em()->getRepository(Application::class)->findOneByToken(self::DEMO_TOKEN);
        self::assertSame(['http://localhost:4000'], $application->getAllowedOrigins());
        $sender = $this->em()->find(ApplicationSender::class, $application->getId());
        self::assertSame(['contact@crm.example.org', 'Démo CRM', ['*@crm.example.org']], [$sender->getSenderEmail(), $sender->getSenderName(), $sender->getAllowedSenders()]);
        self::assertSame('#4338ca', $application->getPalette()?->getPrimary());

        $asAlice = ['X-Impersonate-User' => 'alice@example.org'];
        $this->api('POST', '/api/embed/token', authorization: 'Bearer '.self::DEMO_TOKEN, headers: $asAlice);
        $this->assertStatus(201);
        $email = $this->api('POST', '/api/emails', ['to' => ['client@example.com'], 'subject' => 'Démo', 'htmlBody' => '<p>ok</p>'], 'Bearer '.self::DEMO_TOKEN, $asAlice);
        $this->assertStatus(202);
        self::assertSame('Démo CRM <contact@crm.example.org>', $email['from']);
    }
}
