<?php

namespace App\Command;

use App\Entity\ApplicationSender;
use App\Entity\EmailLayout;
use App\Entity\EmailTemplate;
use App\Entity\Mailbox;
use App\Mailbox\SecretBox;
use App\Repository\ApplicationSenderRepository;
use App\Repository\EmailLayoutRepository;
use App\Repository\EmailTemplateRepository;
use App\Repository\MailboxRepository;
use Doctrine\ORM\EntityManagerInterface;
use Rocket\Core\Command\DemoSeederInterface;
use Rocket\Core\Entity\Application;
use Rocket\Core\Entity\ColorPalette;
use Rocket\Core\Repository\ApplicationRepository;
use Rocket\Core\Repository\ColorPaletteRepository;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Demo data of Rocket Mailer: the demo application (holding DEMO_APP_TOKEN) becomes "Démo CRM", the third-party
 * application of demo/host that embeds the composer, with its own sender and sending mailbox; "Rocket Cloud", linked
 * to the Rocket Auth client rocket-cloud (suite); shared templates and a layout.
 */
final class MailerDemoSeeder implements DemoSeederInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ApplicationRepository $applications,
        private readonly ApplicationSenderRepository $applicationSenders,
        private readonly EmailTemplateRepository $templates,
        private readonly MailboxRepository $mailboxes,
        private readonly EmailLayoutRepository $layouts,
        private readonly SecretBox $secrets,
        private readonly ColorPaletteRepository $palettes,
        #[Autowire(env: 'DEMO_APP_TOKEN')] private readonly string $demoAppToken,
        #[Autowire(env: 'DEMO_HOST_ORIGIN')] private readonly string $demoHostOrigin,
        #[Autowire(env: 'DEMO_MAILBOX_SMTP')] private readonly string $demoMailboxSmtp,
        #[Autowire(env: 'DEMO_MAILBOX_IMAP')] private readonly string $demoMailboxImap,
    ) {
    }

    public function seed(array $users, SymfonyStyle $io): void
    {
        $admin = $users['admin@example.org'];

        // The core's demo application, seeded just before with DEMO_APP_TOKEN.
        $application = $this->applications->findOneByToken($this->demoAppToken);
        if (null !== $application) {
            $application->setDescription('Démo CRM : application tierce de démonstration (demo/host) qui embarque le composeur.')
                ->setAllowedOrigins([$this->demoHostOrigin]);
            // Its own sender: an application never sends from the platform's addresses.
            $this->em->persist($this->applicationSenders->forApplication($application)
                ->setSenderEmail('contact@crm.example.org')
                ->setSenderName('Démo CRM')
                ->setAllowedSenders(['*@crm.example.org']));
            // Its embedded composer matches the Demo CRM's indigo header; Rocket Mailer keeps its default colors.
            $palette = $this->palettes->findOneBy(['name' => 'Démo CRM']) ?? (new ColorPalette())->setName('Démo CRM');
            $palette->setPrimary('#4338ca')->setNeutral('slate');
            $this->em->persist($palette);
            $application->setPalette($palette);
        }

        // Rocket Cloud, in the suite: it sends its share notifications with a token of Rocket Auth (client
        // credentials of the client "rocket-cloud"), mapped to this application, on behalf of its users.
        if (null === $this->applications->findOneBy(['oauthClientId' => 'rocket-cloud'])) {
            $cloud = (new Application())
                ->setName('Rocket Cloud')
                ->setDescription('Rocket Cloud : notifications de partage, envoyées au nom de ses utilisateurs (client Rocket Auth rocket-cloud).')
                ->setCanImpersonate(true)
                ->setOauthClientId('rocket-cloud');
            // Its static token is never shown: Rocket Cloud authenticates with the tokens of Rocket Auth.
            $cloud->rotateToken();
            $this->em->persist($cloud);
            // Its own sender: required to send without "from".
            $this->em->persist($this->applicationSenders->forApplication($cloud)
                ->setSenderEmail('partage@cloud.example.org')
                ->setSenderName('Rocket Cloud'));
        }

        // Sending mailbox of the CRM: SMTP through Mailpit (to see the emails), copy in GreenMail's IMAP "Sent" folder.
        if ('' !== $this->demoMailboxSmtp && null !== $application && null === $this->mailboxes->findOneBy(['email' => 'commercial@crm.example.org'])) {
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

        if (null !== $application) {
            $io->text(\sprintf('Application « %s » (%s) : Démo CRM, origine autorisée %s', $application->getName(), $application->getId(), $this->demoHostOrigin));
        }
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
