<?php

namespace App\Mailbox;

use App\Entity\Mailbox;

/**
 * Guesses the provider of an address and its IMAP/SMTP settings: a table of the usual domains, else the MX records
 * of the domain (Google Workspace, Microsoft 365, OVH…). "other": mail.<domain>, to be checked by the user.
 */
final class MailProviderDetector
{
    /**
     * @var array<string, array{label: string, imap: array{host: string, port: int, encryption: string}, smtp: array{host: string, port: int, encryption: string}, auth: list<string>, note?: string}>
     */
    public const PROVIDERS = [
        'ovh' => ['label' => 'OVHcloud', 'imap' => ['host' => 'ssl0.ovh.net', 'port' => 993, 'encryption' => 'ssl'], 'smtp' => ['host' => 'ssl0.ovh.net', 'port' => 465, 'encryption' => 'ssl'], 'auth' => [Mailbox::AUTH_PASSWORD], 'note' => 'Offre MX Plan / Email Pro. Pour Exchange OVHcloud, renseignez le serveur indiqué dans l’espace client.'],
        'gmail' => ['label' => 'Gmail / Google Workspace', 'imap' => ['host' => 'imap.gmail.com', 'port' => 993, 'encryption' => 'ssl'], 'smtp' => ['host' => 'smtp.gmail.com', 'port' => 465, 'encryption' => 'ssl'], 'auth' => [Mailbox::AUTH_OAUTH_GOOGLE, Mailbox::AUTH_PASSWORD], 'note' => 'Connexion Google recommandée. Sinon, un mot de passe d’application (validation en deux étapes requise).'],
        'microsoft' => ['label' => 'Outlook / Microsoft 365', 'imap' => ['host' => 'outlook.office365.com', 'port' => 993, 'encryption' => 'ssl'], 'smtp' => ['host' => 'smtp.office365.com', 'port' => 587, 'encryption' => 'starttls'], 'auth' => [Mailbox::AUTH_OAUTH_MICROSOFT], 'note' => 'Microsoft n’accepte plus les mots de passe en IMAP/SMTP : utilisez la connexion Microsoft.'],
        'icloud' => ['label' => 'iCloud Mail', 'imap' => ['host' => 'imap.mail.me.com', 'port' => 993, 'encryption' => 'ssl'], 'smtp' => ['host' => 'smtp.mail.me.com', 'port' => 587, 'encryption' => 'starttls'], 'auth' => [Mailbox::AUTH_PASSWORD], 'note' => 'Mot de passe d’application requis (appleid.apple.com > Connexion et sécurité).'],
        'yahoo' => ['label' => 'Yahoo Mail', 'imap' => ['host' => 'imap.mail.yahoo.com', 'port' => 993, 'encryption' => 'ssl'], 'smtp' => ['host' => 'smtp.mail.yahoo.com', 'port' => 465, 'encryption' => 'ssl'], 'auth' => [Mailbox::AUTH_PASSWORD], 'note' => 'Mot de passe d’application requis (Sécurité du compte Yahoo).'],
        'infomaniak' => ['label' => 'Infomaniak', 'imap' => ['host' => 'mail.infomaniak.com', 'port' => 993, 'encryption' => 'ssl'], 'smtp' => ['host' => 'mail.infomaniak.com', 'port' => 465, 'encryption' => 'ssl'], 'auth' => [Mailbox::AUTH_PASSWORD]],
        'ionos' => ['label' => 'IONOS', 'imap' => ['host' => 'imap.ionos.fr', 'port' => 993, 'encryption' => 'ssl'], 'smtp' => ['host' => 'smtp.ionos.fr', 'port' => 465, 'encryption' => 'ssl'], 'auth' => [Mailbox::AUTH_PASSWORD]],
        'gandi' => ['label' => 'Gandi', 'imap' => ['host' => 'mail.gandi.net', 'port' => 993, 'encryption' => 'ssl'], 'smtp' => ['host' => 'mail.gandi.net', 'port' => 465, 'encryption' => 'ssl'], 'auth' => [Mailbox::AUTH_PASSWORD]],
        'zoho' => ['label' => 'Zoho Mail', 'imap' => ['host' => 'imap.zoho.eu', 'port' => 993, 'encryption' => 'ssl'], 'smtp' => ['host' => 'smtp.zoho.eu', 'port' => 465, 'encryption' => 'ssl'], 'auth' => [Mailbox::AUTH_PASSWORD], 'note' => 'Comptes hors Europe : imap.zoho.com et smtp.zoho.com. Activez l’accès IMAP dans les réglages Zoho.'],
        'orange' => ['label' => 'Orange', 'imap' => ['host' => 'imap.orange.fr', 'port' => 993, 'encryption' => 'ssl'], 'smtp' => ['host' => 'smtp.orange.fr', 'port' => 465, 'encryption' => 'ssl'], 'auth' => [Mailbox::AUTH_PASSWORD]],
        'free' => ['label' => 'Free', 'imap' => ['host' => 'imap.free.fr', 'port' => 993, 'encryption' => 'ssl'], 'smtp' => ['host' => 'smtp.free.fr', 'port' => 465, 'encryption' => 'ssl'], 'auth' => [Mailbox::AUTH_PASSWORD], 'note' => 'Activez l’accès IMAP dans les options de votre compte Free.'],
        'sfr' => ['label' => 'SFR', 'imap' => ['host' => 'imap.sfr.fr', 'port' => 993, 'encryption' => 'ssl'], 'smtp' => ['host' => 'smtp.sfr.fr', 'port' => 465, 'encryption' => 'ssl'], 'auth' => [Mailbox::AUTH_PASSWORD]],
    ];

    /** Domains of consumer mailboxes. */
    private const DOMAINS = [
        'gmail' => ['gmail.com', 'googlemail.com'],
        'microsoft' => ['outlook.com', 'outlook.fr', 'hotmail.com', 'hotmail.fr', 'live.com', 'live.fr', 'msn.com'],
        'icloud' => ['icloud.com', 'me.com', 'mac.com'],
        'yahoo' => ['yahoo.com', 'yahoo.fr', 'ymail.com', 'rocketmail.com'],
        'orange' => ['orange.fr', 'wanadoo.fr'],
        'free' => ['free.fr', 'aliceadsl.fr'],
        'sfr' => ['sfr.fr', 'neuf.fr', 'cegetel.net', 'club-internet.fr'],
        'zoho' => ['zoho.com', 'zohomail.eu', 'zohomail.com'],
        'gandi' => ['gandi.net'],
        'infomaniak' => ['ik.me', 'etik.com'],
    ];

    /** MX host suffixes of hosted domains. */
    private const MX = [
        'google.com' => 'gmail', 'googlemail.com' => 'gmail',
        'outlook.com' => 'microsoft', 'office365.us' => 'microsoft',
        'ovh.net' => 'ovh',
        'icloud.com' => 'icloud',
        'yahoodns.net' => 'yahoo',
        'infomaniak.ch' => 'infomaniak', 'infomaniak.com' => 'infomaniak',
        'ionos.fr' => 'ionos', 'ionos.com' => 'ionos', 'ionos.de' => 'ionos', 'kundenserver.de' => 'ionos', '1and1.fr' => 'ionos', '1and1.com' => 'ionos',
        'gandi.net' => 'gandi',
        'zoho.com' => 'zoho', 'zoho.eu' => 'zoho', 'zohomail.com' => 'zoho',
        'orange.fr' => 'orange',
        'free.fr' => 'free',
        'sfr.fr' => 'sfr',
    ];

    public function __construct(private readonly MxResolver $mx)
    {
    }

    /**
     * @return array{email: string, domain: string, provider: string, label: string, detectedBy: string, mx: list<string>,
     *     imap: array{host: string, port: int, encryption: string}, smtp: array{host: string, port: int, encryption: string},
     *     username: string, auth: list<string>, note: ?string}
     */
    public function detect(string $email): array
    {
        $email = mb_strtolower(trim($email));
        $domain = (string) substr((string) strrchr($email, '@'), 1);
        if ('' === $domain || !filter_var($email, \FILTER_VALIDATE_EMAIL)) {
            throw new \InvalidArgumentException('Adresse email invalide.');
        }

        $provider = null;
        $detectedBy = 'domain';
        foreach (self::DOMAINS as $id => $domains) {
            if (\in_array($domain, $domains, true)) {
                $provider = $id;
            }
        }
        $mx = [];
        if (null === $provider) {
            $detectedBy = 'mx';
            $mx = $this->mx->lookup($domain);
            foreach ($mx as $host) {
                foreach (self::MX as $suffix => $id) {
                    if ($host === $suffix || str_ends_with($host, '.'.$suffix)) {
                        $provider = $id;
                        break 2;
                    }
                }
            }
        }

        if (null === $provider) {
            return [
                'email' => $email, 'domain' => $domain, 'provider' => 'other', 'label' => 'Autre fournisseur', 'detectedBy' => 'guess', 'mx' => $mx,
                'imap' => ['host' => 'mail.'.$domain, 'port' => 993, 'encryption' => 'ssl'],
                'smtp' => ['host' => 'mail.'.$domain, 'port' => 465, 'encryption' => 'ssl'],
                'username' => $email, 'auth' => [Mailbox::AUTH_PASSWORD],
                'note' => 'Fournisseur non reconnu : serveurs supposés, à vérifier auprès de votre hébergeur.',
            ];
        }
        $settings = self::PROVIDERS[$provider];

        return [
            'email' => $email, 'domain' => $domain, 'provider' => $provider, 'label' => $settings['label'], 'detectedBy' => $detectedBy, 'mx' => $mx,
            'imap' => $settings['imap'], 'smtp' => $settings['smtp'],
            'username' => $email, 'auth' => $settings['auth'], 'note' => $settings['note'] ?? null,
        ];
    }
}
