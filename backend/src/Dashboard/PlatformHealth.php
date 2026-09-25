<?php

namespace App\Dashboard;

use App\Ldap\LdapSettings;
use Doctrine\DBAL\Connection;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Status of the services Rocket Mailer depends on, for the dashboard.
 * Only cheap checks: nothing here opens a network connection besides the database query.
 */
final class PlatformHealth
{
    public const OPERATIONAL = 'operational';
    public const DEGRADED = 'degraded';
    public const DOWN = 'down';

    /** A queued email older than this means the worker is stopped or the SMTP relay is stuck. */
    private const QUEUE_DELAY_WARNING = 300;

    public function __construct(
        private readonly Connection $db,
        private readonly ClockInterface $clock,
        #[Autowire(env: 'MAILER_DSN')] private readonly string $mailerDsn,
        private readonly LdapSettings $ldapSettings,
        #[Autowire(env: 'resolve:ATTACHMENTS_DIR')] private readonly string $attachmentsDir,
    ) {
    }

    /** @return array{status: string, services: list<array<string, mixed>>} */
    public function check(): array
    {
        $services = [$this->database()];
        if (self::DOWN !== $services[0]['status']) {
            $services[] = $this->queue();
        }
        $services[] = $this->mailer();
        $services[] = $this->ldap(self::DOWN !== $services[0]['status']);
        $services[] = $this->storage();

        $statuses = array_column($services, 'status');

        return [
            'status' => match (true) {
                \in_array(self::DOWN, $statuses, true) => self::DOWN,
                \in_array(self::DEGRADED, $statuses, true) => self::DEGRADED,
                default => self::OPERATIONAL,
            },
            'services' => $services,
        ];
    }

    /** @return array<string, mixed> */
    private function database(): array
    {
        $start = hrtime(true);
        try {
            $this->db->fetchOne('SELECT 1');
            $version = $this->db->fetchOne('SHOW server_version');
        } catch (\Throwable) {
            return ['id' => 'database', 'label' => 'Base de données', 'status' => self::DOWN, 'detail' => 'Injoignable'];
        }

        return [
            'id' => 'database',
            'label' => 'Base de données',
            'status' => self::OPERATIONAL,
            'detail' => 'PostgreSQL '.explode(' ', (string) $version)[0],
            'latencyMs' => round((hrtime(true) - $start) / 1e6, 1),
        ];
    }

    /** @return array<string, mixed> */
    private function queue(): array
    {
        $row = $this->db->fetchAssociative("SELECT COUNT(*) AS queued, MIN(created_at) AS oldest FROM email WHERE status = 'queued'");
        $retrying = (int) $this->db->fetchOne("SELECT COUNT(*) FROM messenger_messages WHERE queue_name = 'failed'");
        $delay = null === $row['oldest'] ? 0 : $this->clock->now()->getTimestamp() - (new \DateTimeImmutable($row['oldest']))->getTimestamp();

        return [
            'id' => 'queue',
            'label' => 'File d\'envoi',
            'status' => $delay > self::QUEUE_DELAY_WARNING ? self::DEGRADED : self::OPERATIONAL,
            'detail' => match (true) {
                $delay > self::QUEUE_DELAY_WARNING => 'Emails en attente depuis plus de 5 minutes : le worker est-il démarré ?',
                (int) $row['queued'] > 0 => \sprintf('%d email(s) en cours d\'envoi', $row['queued']),
                default => 'Aucun email en attente',
            },
            'queued' => (int) $row['queued'],
            'failedMessages' => $retrying,
        ];
    }

    /** @return array<string, mixed> */
    private function mailer(): array
    {
        $scheme = parse_url($this->mailerDsn, \PHP_URL_SCHEME) ?: 'inconnu';
        $host = parse_url($this->mailerDsn, \PHP_URL_HOST);
        $port = parse_url($this->mailerDsn, \PHP_URL_PORT);

        return [
            'id' => 'mailer',
            'label' => 'Relais SMTP',
            // The "null" transport silently drops every email.
            'status' => 'null' === $scheme ? self::DEGRADED : self::OPERATIONAL,
            // Never expose credentials: scheme, host and port only.
            'detail' => 'null' === $scheme ? 'Transport « null » : les emails ne partent pas' : $scheme.'://'.$host.($port ? ':'.$port : ''),
        ];
    }

    /** @return array<string, mixed> */
    private function ldap(bool $databaseUp): array
    {
        $config = $this->ldapSettings->get();
        if (!$config->enabled) {
            return ['id' => 'ldap', 'label' => 'Annuaire LDAP', 'status' => 'disabled', 'detail' => 'Non configuré'];
        }

        $row = $databaseUp
            ? $this->db->fetchAssociative("SELECT COUNT(*) AS users, MAX(ldap_synced_at) AS synced FROM \"user\" WHERE source = 'ldap'")
            : ['users' => 0, 'synced' => null];

        return [
            'id' => 'ldap',
            'label' => 'Annuaire LDAP',
            'status' => self::OPERATIONAL,
            'detail' => preg_replace('#//[^@/]*@#', '//', $config->url),
            'users' => (int) $row['users'],
            'lastSyncAt' => null === $row['synced'] ? null : (new \DateTimeImmutable($row['synced']))->format(\DATE_ATOM),
        ];
    }

    /** @return array<string, mixed> */
    private function storage(): array
    {
        $directory = is_dir($this->attachmentsDir) ? $this->attachmentsDir : \dirname($this->attachmentsDir);
        $total = @disk_total_space($directory) ?: null;
        $free = @disk_free_space($directory) ?: null;
        $usage = $total && null !== $free ? round(100 * ($total - $free) / $total, 1) : null;
        $writable = is_writable($directory);

        return [
            'id' => 'storage',
            'label' => 'Stockage des pièces jointes',
            'status' => match (true) {
                !$writable => self::DOWN,
                null !== $usage && $usage >= 90 => self::DEGRADED,
                default => self::OPERATIONAL,
            },
            'detail' => $writable ? 'Accessible en écriture' : 'Dossier non accessible en écriture',
            'usagePercent' => $usage,
            'freeBytes' => $free ? (int) $free : null,
        ];
    }
}
