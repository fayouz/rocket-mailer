<?php

namespace App\Command;

use App\Entity\Application;
use App\Entity\EmailTemplate;
use App\Entity\User;
use App\Repository\ApplicationRepository;
use App\Repository\EmailTemplateRepository;
use App\Repository\UserRepository;
use App\Security\Roles;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Http\Authenticator\Token\PostAuthenticationToken;

/**
 * Idempotent demo data. Refuses to run unless DEMO_MODE=1, so it can never touch a real instance.
 */
#[AsCommand(name: 'app:demo:seed', description: 'Load demo users, templates and an external application (DEMO_MODE=1 only).')]
final class DemoSeedCommand
{
    public const USERS = [
        ['admin@example.org', 'demo-admin-password', 'Ada', 'Admin', true],
        ['alice@example.org', 'demo-alice-password', 'Alice', 'Durand', false],
    ];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly UserRepository $users,
        private readonly ApplicationRepository $applications,
        private readonly EmailTemplateRepository $templates,
        private readonly UserPasswordHasherInterface $hasher,
        private readonly TokenStorageInterface $tokenStorage,
        #[Autowire(env: 'bool:DEMO_MODE')] private readonly bool $demoMode,
        #[Autowire(env: 'DEMO_APP_TOKEN')] private readonly string $demoAppToken,
        #[Autowire(env: 'DEMO_HOST_ORIGIN')] private readonly string $demoHostOrigin,
    ) {
    }

    public function __invoke(SymfonyStyle $io): int
    {
        if (!$this->demoMode) {
            $io->error('Demo data can only be loaded with DEMO_MODE=1.');

            return Command::FAILURE;
        }

        $admin = null;
        foreach (self::USERS as [$email, $password, $firstName, $lastName, $isAdmin]) {
            $user = $this->users->findOneBy(['email' => $email]) ?? new User();
            $user->setEmail($email)->setFirstName($firstName)->setLastName($lastName)
                ->setRoles($isAdmin ? [Roles::ADMIN] : [])->setEnabled(true);
            $user->setPassword($this->hasher->hashPassword($user, $password));
            $this->em->persist($user);
            $admin ??= $user;
        }
        $this->em->flush();

        // Attribute the demo content to the admin (Blameable reads the security token).
        $this->tokenStorage->setToken(new PostAuthenticationToken($admin, 'api', $admin->getRoles()));

        $application = $this->applications->findOneBy(['name' => 'Démo CRM']) ?? (new Application())->setName('Démo CRM');
        $application->setDescription('Application tierce de démonstration (http://localhost:4000) qui embarque le composeur.')
            ->setCanImpersonate(true)
            ->setAllowedOrigins([$this->demoHostOrigin])
            ->setEnabled(true);
        $application->useToken($this->demoAppToken);
        $this->em->persist($application);

        foreach ($this->templateFixtures() as [$name, $description, $subject, $html]) {
            $template = $this->templates->findOneBy(['name' => $name]) ?? new EmailTemplate();
            if (null === $template->getOwner()) {
                $template->setName($name)->setDescription($description)->setDefaultSubject($subject)
                    ->setHtml($html)->setShared(true)->setOwner($admin);
                $this->em->persist($template);
            }
        }

        $this->em->flush();
        $this->tokenStorage->setToken(null);

        $io->success('Demo data loaded.');
        $io->table(['Compte', 'Mot de passe'], array_map(static fn (array $u) => [$u[0], $u[1]], self::USERS));
        $io->text(\sprintf('Application "Démo CRM" (%s) — origine autorisée : %s', $application->getId(), $this->demoHostOrigin));

        return Command::SUCCESS;
    }

    /** @return list<array{string, string, string, string}> */
    private function templateFixtures(): array
    {
        $layout = static fn (string $title, string $body, string $cta) => <<<HTML
            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f4f4f5;padding:24px 0;font-family:Arial,Helvetica,sans-serif;">
              <tr><td align="center">
                <table role="presentation" width="600" cellpadding="0" cellspacing="0" style="background:#ffffff;border-radius:8px;overflow:hidden;">
                  <tr><td style="background:#f97316;padding:20px 32px;color:#ffffff;font-size:20px;font-weight:bold;">🚀 Rocket Mailer</td></tr>
                  <tr><td style="padding:32px;color:#18181b;font-size:15px;line-height:1.6;">
                    <h1 style="font-size:22px;margin:0 0 16px;">{$title}</h1>
                    {$body}
                    <p style="margin:24px 0;"><a href="https://example.org" style="background:#f97316;color:#ffffff;padding:12px 20px;border-radius:6px;text-decoration:none;display:inline-block;">{$cta}</a></p>
                    <p style="margin:0;">Bien cordialement,<br>L'équipe</p>
                  </td></tr>
                  <tr><td style="padding:16px 32px;background:#fafafa;color:#71717a;font-size:12px;">Vous recevez ce message car vous êtes client de notre société.</td></tr>
                </table>
              </td></tr>
            </table>
            HTML;

        return [
            [
                'Bienvenue',
                'Message d\'accueil pour un nouveau client.',
                'Bienvenue chez nous !',
                $layout('Bienvenue !', '<p>Bonjour,</p><p>Nous sommes ravis de vous compter parmi nos clients. Votre espace est prêt : vous pouvez dès maintenant suivre vos commandes et vos factures.</p>', 'Accéder à mon espace'),
            ],
            [
                'Relance devis',
                'Relance d\'un devis envoyé et resté sans réponse.',
                'Votre devis est toujours disponible',
                $layout('Votre devis vous attend', '<p>Bonjour,</p><p>Nous revenons vers vous au sujet du devis que nous vous avons transmis. Il reste valable encore <strong>15 jours</strong>.</p><p>Avez-vous des questions ? Nous sommes à votre disposition.</p>', 'Consulter le devis'),
            ],
        ];
    }
}
