<?php

namespace App\Command;

use App\Entity\Application;
use App\Entity\EmailLayout;
use App\Entity\EmailTemplate;
use App\Entity\Mailbox;
use App\Mailbox\SecretBox;
use App\Repository\EmailLayoutRepository;
use App\Repository\MailboxRepository;
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
        private readonly MailboxRepository $mailboxes,
        private readonly EmailLayoutRepository $layouts,
        private readonly SecretBox $secrets,
        #[Autowire(env: 'DEMO_MAILBOX_SMTP')] private readonly string $demoMailboxSmtp,
        #[Autowire(env: 'DEMO_MAILBOX_IMAP')] private readonly string $demoMailboxImap,
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
            ->setAllowedSenders(['*@crm.example.org'])
            // Its own sender: an application never sends from the platform's addresses.
            ->setSenderEmail('contact@crm.example.org')
            ->setSenderName('Démo CRM')
            ->setEnabled(true);
        $application->useToken($this->demoAppToken);
        $this->em->persist($application);

        // Sending mailbox of the CRM: SMTP through Mailpit (to see the emails), copy in GreenMail's IMAP "Sent" folder.
        if ('' !== $this->demoMailboxSmtp && null === $this->mailboxes->findOneBy(['email' => 'commercial@crm.example.org'])) {
            [$smtpHost, $smtpPort] = explode(':', $this->demoMailboxSmtp) + [1 => '1025'];
            [$imapHost, $imapPort] = explode(':', $this->demoMailboxImap) + [1 => '3143'];
            $mailbox = (new Mailbox())
                ->setName('Boîte commerciale du CRM')
                ->setEmail('commercial@crm.example.org')
                ->setDisplayName('Service commercial')
                ->setSmtpHost($smtpHost)->setSmtpPort((int) $smtpPort)->setSmtpEncryption('none')
                ->setImapEnabled('' !== $imapHost)->setImapHost($imapHost ?: null)->setImapPort((int) $imapPort)->setImapEncryption('none')
                ->setImapUsername('commercial')->setImapPassword('secret-pass')
                ->addApplication($application);
            $mailbox->sealSecrets($this->secrets->encrypt(...));
            $this->em->persist($mailbox);
        }

        foreach ($this->templateFixtures() as [$name, $description, $subject, $html, $variables]) {
            $template = $this->templates->findOneBy(['name' => $name]) ?? new EmailTemplate();
            // New, or seeded before template variables existed: (re)load the fixture.
            $outdated = [] !== $variables && [] === array_filter($template->getVariables(), static fn (array $v) => null !== $v['label']);
            if (null === $template->getOwner() || $outdated) {
                $template->setName($name)->setDescription($description)->setDefaultSubject($subject)
                    ->setHtml($html)->setVariables($variables)->setShared(true)->setOwner($template->getOwner() ?? $admin);
                $this->em->persist($template);
            }
        }

        // A layout (the CRM's letterhead) and a template that only holds its content.
        $layout = $this->layouts->findOneBy(['name' => 'Charte Démo CRM']);
        if (null === $layout) {
            $layout = (new EmailLayout())->setName('Charte Démo CRM')->setDescription('En-tête et pied de page de Démo CRM.')->setHtml(<<<'HTML'
                <!doctype html>
                <html lang="fr"><head><meta charset="utf-8"></head>
                <body style="margin:0;background:#eef2ff;">
                  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#eef2ff;padding:32px 0;">
                    <tr><td align="center">
                      <table role="presentation" width="600" cellpadding="0" cellspacing="0" style="background:#ffffff;border-radius:12px;overflow:hidden;font-family:Arial,Helvetica,sans-serif;color:#1e1b4b;">
                        <tr><td style="background:#4338ca;padding:24px 32px;color:#ffffff;font-size:20px;font-weight:bold;">Démo CRM</td></tr>
                        <tr><td style="padding:32px;font-size:15px;line-height:1.6;">{{ content }}</td></tr>
                        <tr><td style="padding:16px 32px;border-top:1px solid #e0e7ff;color:#6366f1;font-size:12px;">Démo CRM · 1 rue de l'Exemple, 75000 Paris</td></tr>
                      </table>
                    </td></tr>
                  </table>
                </body></html>
                HTML);
            $this->em->persist($layout);
        }
        if (null === $this->templates->findOneBy(['name' => 'Confirmation de rendez-vous'])) {
            $this->em->persist((new EmailTemplate())
                ->setName('Confirmation de rendez-vous')
                ->setDescription('Utilise le layout « Charte Démo CRM ». Variables : client, date du rendez-vous.')
                ->setDefaultSubject('Votre rendez-vous du {{ rdv.date }}')
                ->setHtml('<p>Bonjour {{ client.prenom }},</p><p>Nous vous confirmons notre rendez-vous du <strong>{{ rdv.date }}</strong>.</p><p>À bientôt,<br>L’équipe commerciale</p>')
                ->setVariables([['name' => 'client.prenom', 'label' => 'Prénom du client'], ['name' => 'rdv.date', 'label' => 'Date du rendez-vous']])
                ->setLayout($layout)
                ->setShared(true)
                ->setOwner($admin));
        }

        $this->em->flush();
        $this->tokenStorage->setToken(null);

        $io->success('Demo data loaded.');
        $io->table(['Compte', 'Mot de passe'], array_map(static fn (array $u) => [$u[0], $u[1]], self::USERS));
        $io->text(\sprintf('Application "Démo CRM" (%s) — origine autorisée : %s', $application->getId(), $this->demoHostOrigin));

        return Command::SUCCESS;
    }

    /** @return list<array{string, string, string, string, list<array{name: string, label: string, defaultValue?: string}>}> */
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
                [],
            ],
            [
                'Relance devis',
                'Relance d\'un devis envoyé et resté sans réponse. Variables : client, numéro, montant et validité du devis.',
                'Votre devis n°{{ devis.numero }} est toujours disponible',
                $layout('Votre devis vous attend', '<p>Bonjour {{ client.prenom }},</p><p>Nous revenons vers vous au sujet du devis n°{{ devis.numero }} d\'un montant de <strong>{{ devis.montant }}</strong>, transmis à {{ client.societe }}. Il reste valable encore <strong>{{ devis.validite }}</strong>.</p><p>Avez-vous des questions ? Nous sommes à votre disposition.</p>', 'Consulter le devis'),
                [
                    ['name' => 'client.prenom', 'label' => 'Prénom du client'],
                    ['name' => 'client.societe', 'label' => 'Société'],
                    ['name' => 'devis.numero', 'label' => 'Numéro du devis'],
                    ['name' => 'devis.montant', 'label' => 'Montant du devis'],
                    ['name' => 'devis.validite', 'label' => 'Validité restante', 'defaultValue' => '15 jours'],
                ],
            ],
        ];
    }
}
