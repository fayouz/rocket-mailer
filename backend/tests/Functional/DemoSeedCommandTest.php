<?php

namespace App\Tests\Functional;

use App\Command\DemoSeedCommand;
use App\Entity\EmailTemplate;
use App\Tests\ApiTestTrait;
use Symfony\Bundle\FrameworkBundle\Console\Application as ConsoleApplication;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

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
        self::assertCount(1, $this->em()->getRepository(\App\Entity\EmailLayout::class)->findAll());
        $appointment = $this->em()->getRepository(EmailTemplate::class)->findOneBy(['name' => 'Confirmation de rendez-vous']);
        self::assertSame('Charte Démo CRM', $appointment->getLayout()?->getName());
        self::assertSame('admin@example.org', $templates[0]->getCreatedBy());

        [$adminEmail, $adminPassword] = DemoSeedCommand::USERS[0];
        $login = $this->api('POST', '/api/auth/login', ['email' => $adminEmail, 'password' => $adminPassword]);
        $this->assertStatus(200);
        self::assertContains('ROLE_ADMIN', $this->api('GET', '/api/me', authorization: 'Bearer '.$login['token'])['roles']);

        $this->api('POST', '/api/embed/token', authorization: 'Bearer '.self::DEMO_TOKEN, headers: ['X-Impersonate-User' => 'alice@example.org']);
        $this->assertStatus(201);
    }

    public function testRefusesOutsideDemoMode(): void
    {
        $container = static::getContainer();
        $command = new DemoSeedCommand(
            $container->get(\Doctrine\ORM\EntityManagerInterface::class),
            $container->get(\App\Repository\UserRepository::class),
            $container->get(\App\Repository\ApplicationRepository::class),
            $container->get(\App\Repository\EmailTemplateRepository::class),
            $container->get(\Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface::class),
            $container->get('security.token_storage'),
            false,
            self::DEMO_TOKEN,
            'http://localhost:4000',
            $container->get(\App\Repository\MailboxRepository::class),
            $container->get(\App\Repository\EmailLayoutRepository::class),
            $container->get(\App\Mailbox\SecretBox::class),
            '',
            '',
        );

        $io = new \Symfony\Component\Console\Style\SymfonyStyle(new \Symfony\Component\Console\Input\ArrayInput([]), new \Symfony\Component\Console\Output\NullOutput());
        self::assertSame(Command::FAILURE, $command($io));
        self::assertSame([], $this->em()->getRepository(EmailTemplate::class)->findAll());
    }
}
