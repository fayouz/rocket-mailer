<?php

namespace App\Mailbox;

use App\Entity\Mailbox;
use App\Mailbox\OAuth\OAuthTokens;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mailer\Transport\Smtp\Auth\XOAuth2Authenticator;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;
use Symfony\Component\Mailer\Transport\Smtp\SmtpTransport;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\Email;

/**
 * Connections of a sending mailbox: its SMTP server (or provider) and its IMAP server.
 * Password mailboxes log in with their password; OAuth mailboxes (Google, Microsoft) with SASL XOAUTH2 and an access
 * token refreshed from the stored refresh token (OAuthTokens).
 */
class MailboxConnector
{
    public function __construct(
        private readonly MailServers $servers,
        private readonly SecretBox $secrets,
        private readonly OAuthTokens $oauth,
        #[Autowire(env: 'float:MAILBOX_TIMEOUT')] private readonly float $timeout = 15.0,
    ) {
    }

    /** Symfony Mailer transport of the mailbox. */
    public function transport(Mailbox $mailbox): TransportInterface
    {
        if (Mailbox::TRANSPORT_DSN === $mailbox->getTransport()) {
            $dsn = $mailbox->getEncryptedDsn();
            if (null === $dsn) {
                throw new \RuntimeException('The provider DSN of this mailbox is missing.');
            }

            return $this->servers->transport($this->secrets->decrypt($dsn));
        }

        $scheme = 'ssl' === $mailbox->getSmtpEncryption() ? 'smtps' : 'smtp';
        $credentials = '';
        if ($mailbox->isOAuth()) {
            $credentials = rawurlencode($mailbox->getSmtpUsername() ?? $mailbox->getEmail()).':'.rawurlencode($this->oauth->accessToken($mailbox)).'@';
        } elseif (null !== $mailbox->getSmtpUsername()) {
            $password = null === $mailbox->getEncryptedSmtpPassword() ? '' : $this->secrets->decrypt($mailbox->getEncryptedSmtpPassword());
            $credentials = rawurlencode($mailbox->getSmtpUsername()).':'.rawurlencode($password).'@';
        }
        $options = match ($mailbox->getSmtpEncryption()) {
            'starttls' => '?require_tls=true',
            'none' => '?auto_tls=false',
            default => '',
        };

        $transport = $this->servers->transport(\sprintf('%s://%s%s:%d%s', $scheme, $credentials, $mailbox->getSmtpHost(), $mailbox->getSmtpPort() ?? 587, $options));
        if ($mailbox->isOAuth() && $transport instanceof EsmtpTransport) {
            // Never fall back to a password mechanism with the access token.
            $transport->setAuthenticators([new XOAuth2Authenticator()]);
        }

        return $transport;
    }

    /**
     * Stores a copy of a sent message in the mailbox's "Sent" folder.
     *
     * @return string the folder (decoded name)
     */
    public function archive(Mailbox $mailbox, string $rawMessage): string
    {
        $client = $this->openImap($mailbox);
        try {
            $folder = $client->resolveSentFolder($mailbox->getImapSentFolder());
            $client->append($folder, $rawMessage);

            return ImapClient::decodeName($folder);
        } finally {
            $client->logout();
        }
    }

    /**
     * Checks the connections: SMTP (EHLO and authentication; for a provider, nothing without sending), IMAP login and
     * "Sent" folder (when IMAP is used: copies or inbox). With $sendTo, also sends a test email (and archives it).
     * Messages are French and meant for the user; "detail" is the server's answer.
     *
     * @return array{ok: bool, smtp: array{ok: bool, message: string, detail?: string}, imap: array{ok: bool, message: string, detail?: string, sentFolder?: string}|null}
     */
    public function test(Mailbox $mailbox, ?string $sendTo = null): array
    {
        $result = ['ok' => true, 'smtp' => ['ok' => true, 'message' => ''], 'imap' => null];

        try {
            $transport = $this->transport($mailbox);
            if (null !== $sendTo) {
                $sent = $transport->send((new Email())
                    ->from($mailbox->toAddress())
                    ->to($sendTo)
                    ->subject(\sprintf('Test de la boîte d’envoi « %s »', $mailbox->getName()))
                    ->text("Ce message confirme que Rocket Mailer peut envoyer depuis {$mailbox->getEmail()}."));
                $result['smtp']['message'] = \sprintf('Email de test envoyé à %s.', $sendTo);
            } else {
                $result['smtp']['message'] = self::checkTransport($transport);
            }
        } catch (\Throwable $e) {
            $result['smtp'] = ['ok' => false, 'message' => self::explain('smtp', $mailbox, $e), 'detail' => $e->getMessage()];
        }

        if ($mailbox->isImapEnabled() || $mailbox->isInboxEnabled()) {
            try {
                $client = $this->openImap($mailbox);
                try {
                    $folder = $client->resolveSentFolder($mailbox->getImapSentFolder());
                    if (isset($sent)) {
                        $client->append($folder, $sent->toString());
                    }
                } finally {
                    $client->logout();
                }
                $result['imap'] = ['ok' => true, 'message' => 'Connexion IMAP réussie.', 'sentFolder' => ImapClient::decodeName($folder)];
            } catch (\Throwable $e) {
                $result['imap'] = ['ok' => false, 'message' => self::explain('imap', $mailbox, $e), 'detail' => $e->getMessage()];
            }
        }
        $result['ok'] = $result['smtp']['ok'] && (null === $result['imap'] || $result['imap']['ok']);

        return $result;
    }

    /** A French explanation of a connection failure, from the exception. */
    public static function explain(string $protocol, Mailbox $mailbox, \Throwable $e): string
    {
        $server = 'imap' === $protocol ? 'IMAP' : 'SMTP';
        $host = 'imap' === $protocol ? $mailbox->getImapHost() : $mailbox->getSmtpHost();
        $message = $e->getMessage();

        return match (true) {
            $e instanceof OAuth\OAuthException => $message,
            (bool) preg_match('/authenticat|login|credentials|\b535\b|\b534\b|AUTHENTICATIONFAILED|Invalid credentials|password/i', $message) => $mailbox->isOAuth()
                ? \sprintf('Le serveur %s a refusé l’autorisation OAuth : reconnectez la boîte (et vérifiez que l’accès %s est activé sur le compte).', $server, $server)
                : \sprintf('Identifiant ou mot de passe refusé par le serveur %s. Vérifiez-les ; certains fournisseurs (Gmail, iCloud, Yahoo…) exigent un mot de passe d’application.', $server),
            (bool) preg_match('/timed out|did not answer in time/i', $message) => \sprintf('Le serveur %s %s ne répond pas (délai dépassé) : vérifiez l’adresse et le port.', $server, $host ?? ''),
            (bool) preg_match('/connect|resolve|getaddrinfo|Connection refused|closed/i', $message) => \sprintf('Impossible de joindre le serveur %s %s : vérifiez l’adresse, le port et le chiffrement.', $server, $host ?? ''),
            (bool) preg_match('/TLS|SSL|certificate|crypto/i', $message) => \sprintf('La connexion chiffrée au serveur %s a échoué : vérifiez le chiffrement (SSL/TLS ou STARTTLS) et le port.', $server),
            (bool) preg_match('/decrypted/i', $message) => 'Les identifiants enregistrés ne sont plus lisibles (clé de chiffrement changée) : saisissez-les à nouveau.',
            default => \sprintf('Échec de la connexion %s : %s', $server, $message),
        };
    }

    /**
     * SMTP login of the mailbox (or, for a provider, nothing without sending): the health check of the worker.
     *
     * @return string what was checked; throws on failure
     */
    public function checkSmtp(Mailbox $mailbox): string
    {
        return self::checkTransport($this->transport($mailbox));
    }

    /**
     * IMAP login of the mailbox and its "Sent" folder: the health check of the worker.
     *
     * @return string what was checked; throws on failure
     */
    public function checkImap(Mailbox $mailbox): string
    {
        $client = $this->openImap($mailbox);
        try {
            return \sprintf('Connexion IMAP réussie, dossier « %s ».', ImapClient::decodeName($client->resolveSentFolder($mailbox->getImapSentFolder())));
        } finally {
            $client->logout();
        }
    }

    private static function checkTransport(TransportInterface $transport): string
    {
        if (!$transport instanceof SmtpTransport) {
            return 'Configuration lue. Un fournisseur ne peut être vérifié qu’en envoyant un email de test.';
        }
        $transport->start();
        $transport->stop();

        return 'Connexion et authentification SMTP réussies.';
    }

    /** Connected and logged-in IMAP client of the mailbox; the caller logs out. */
    public function openImap(Mailbox $mailbox): ImapClient
    {
        $username = $mailbox->getImapUsername() ?? $mailbox->getSmtpUsername() ?? $mailbox->getEmail();
        if ($mailbox->isOAuth()) {
            $accessToken = $this->oauth->accessToken($mailbox);
        } else {
            $encrypted = $mailbox->getEncryptedImapPassword() ?? $mailbox->getEncryptedSmtpPassword();
            if (null === $encrypted) {
                throw new ImapException('No IMAP password is configured.');
            }
        }

        $client = $this->servers->imap($this->timeout);
        $client->connect((string) $mailbox->getImapHost(), $mailbox->getImapPort() ?? 993, $mailbox->getImapEncryption());
        if (isset($accessToken)) {
            $client->authenticateXOAuth2($username, $accessToken);
        } else {
            $client->login($username, $this->secrets->decrypt((string) ($encrypted ?? '')));
        }

        return $client;
    }
}
