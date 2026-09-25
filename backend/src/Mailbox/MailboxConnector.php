<?php

namespace App\Mailbox;

use App\Entity\Mailbox;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mailer\Transport;
use Symfony\Component\Mailer\Transport\Smtp\SmtpTransport;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\Email;

/**
 * Connections of a sending mailbox: its SMTP server (or provider) and its IMAP server.
 */
class MailboxConnector
{
    public function __construct(
        #[Autowire(service: 'mailer.transport_factory')] private readonly Transport $transports,
        private readonly SecretBox $secrets,
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

            return $this->transports->fromString($this->secrets->decrypt($dsn));
        }

        $scheme = 'ssl' === $mailbox->getSmtpEncryption() ? 'smtps' : 'smtp';
        $credentials = '';
        if (null !== $mailbox->getSmtpUsername()) {
            $password = null === $mailbox->getEncryptedSmtpPassword() ? '' : $this->secrets->decrypt($mailbox->getEncryptedSmtpPassword());
            $credentials = rawurlencode($mailbox->getSmtpUsername()).':'.rawurlencode($password).'@';
        }
        $options = match ($mailbox->getSmtpEncryption()) {
            'starttls' => '?require_tls=true',
            'none' => '?auto_tls=false',
            default => '',
        };

        return $this->transports->fromString(\sprintf('%s://%s%s:%d%s', $scheme, $credentials, $mailbox->getSmtpHost(), $mailbox->getSmtpPort() ?? 587, $options));
    }

    /**
     * Stores a copy of a sent message in the mailbox's "Sent" folder.
     *
     * @return string the folder (decoded name)
     */
    public function archive(Mailbox $mailbox, string $rawMessage): string
    {
        $client = $this->imap($mailbox);
        try {
            $folder = $client->resolveSentFolder($mailbox->getImapSentFolder());
            $client->append($folder, $rawMessage);

            return ImapClient::decodeName($folder);
        } finally {
            $client->logout();
        }
    }

    /**
     * Checks the connections: SMTP login (or, for a provider, nothing without sending), IMAP login and folder.
     * With $sendTo, also sends a test email (and archives it).
     *
     * @return array{smtp: array{ok: bool, message: string}, imap: array{ok: bool, message: string, sentFolder?: string}|null}
     */
    public function test(Mailbox $mailbox, ?string $sendTo = null): array
    {
        $result = ['smtp' => ['ok' => true, 'message' => ''], 'imap' => null];

        try {
            $transport = $this->transport($mailbox);
            if (null !== $sendTo) {
                $sent = $transport->send((new Email())
                    ->from($mailbox->toAddress())
                    ->to($sendTo)
                    ->subject(\sprintf('Test de la boîte d’envoi « %s »', $mailbox->getName()))
                    ->text("Ce message confirme que Rocket Mailer peut envoyer depuis {$mailbox->getEmail()}."));
                $result['smtp']['message'] = \sprintf('Email de test envoyé à %s.', $sendTo);
            } elseif ($transport instanceof SmtpTransport) {
                $transport->start();
                $transport->stop();
                $result['smtp']['message'] = 'Connexion et authentification SMTP réussies.';
            } else {
                $result['smtp']['message'] = 'Configuration lue. Un fournisseur ne peut être vérifié qu’en envoyant un email de test.';
            }
        } catch (\Throwable $e) {
            $result['smtp'] = ['ok' => false, 'message' => $e->getMessage()];
        }

        if ($mailbox->isImapEnabled()) {
            try {
                $client = $this->imap($mailbox);
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
                $result['imap'] = ['ok' => false, 'message' => $e->getMessage()];
            }
        }

        return $result;
    }

    private function imap(Mailbox $mailbox): ImapClient
    {
        $username = $mailbox->getImapUsername() ?? $mailbox->getSmtpUsername() ?? $mailbox->getEmail();
        $encrypted = $mailbox->getEncryptedImapPassword() ?? $mailbox->getEncryptedSmtpPassword();
        if (null === $encrypted) {
            throw new ImapException('No IMAP password is configured.');
        }

        $client = new ImapClient($this->timeout);
        $client->connect((string) $mailbox->getImapHost(), $mailbox->getImapPort() ?? 993, $mailbox->getImapEncryption());
        $client->login($username, $this->secrets->decrypt($encrypted));

        return $client;
    }
}
