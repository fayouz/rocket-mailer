<?php

namespace App\Dashboard;

use App\Entity\EmailTemplate;
use App\Entity\User;
use Doctrine\DBAL\Connection;
use Symfony\Component\Clock\ClockInterface;

/**
 * Figures for the dashboard, computed with plain SQL.
 * Admins see the whole platform; other users only their own emails, and their own or shared templates.
 */
final class DashboardStats
{
    public const DAYS = 30;

    public function __construct(
        private readonly Connection $db,
        private readonly ClockInterface $clock,
    ) {
    }

    /** @return array<string, mixed> */
    public function forUser(User $user, bool $admin): array
    {
        $now = $this->clock->now();
        $today = $now->setTime(0, 0);
        $from = $today->modify(\sprintf('-%d days', self::DAYS - 1));
        $previousFrom = $from->modify(\sprintf('-%d days', self::DAYS));

        [$scope, $params] = $admin ? ['TRUE', []] : ['e.sender_id = :user', ['user' => $user->getId()->toRfc4122()]];

        $daily = $this->daily($scope, $params, $from, self::DAYS);
        $current = $this->totals($daily);
        $previous = $this->db->fetchAssociative(
            "SELECT COUNT(*) FILTER (WHERE status = 'sent') AS sent, COUNT(*) FILTER (WHERE status = 'failed') AS failed
             FROM email e WHERE $scope AND e.created_at >= :from AND e.created_at < :to",
            $params + ['from' => $this->sql($previousFrom), 'to' => $this->sql($from)],
        );
        $queue = $this->db->fetchAssociative(
            "SELECT COUNT(*) AS queued, MIN(created_at) AS oldest FROM email e WHERE $scope AND status = 'queued'",
            $params,
        );

        $templates = $this->db->fetchAssociative(
            'SELECT COUNT(*) AS total, COUNT(*) FILTER (WHERE shared) AS shared FROM email_template t'
            .($admin ? '' : ' WHERE t.owner_id = :user OR t.shared'),
            $admin ? [] : $params,
        );

        $stats = [
            'scope' => $admin ? 'platform' : 'user',
            'generatedAt' => $now->format(\DATE_ATOM),
            'days' => self::DAYS,
            'emails' => [
                'sent' => $current['sent'],
                'failed' => $current['failed'],
                'queued' => (int) $queue['queued'],
                'oldestQueuedAt' => $this->atom($queue['oldest']),
                'previousSent' => (int) $previous['sent'],
                'previousFailed' => (int) $previous['failed'],
                'deliveryRate' => $this->rate($current['sent'], $current['failed']),
                'withAttachments' => (int) $this->db->fetchOne(
                    "SELECT COUNT(DISTINCT e.id) FROM email e JOIN attachment a ON a.email_id = e.id WHERE $scope AND e.created_at >= :from",
                    $params + ['from' => $this->sql($from)],
                ),
            ],
            'templates' => ['total' => (int) $templates['total'], 'shared' => (int) $templates['shared']],
            'daily' => $daily,
            'recentEmails' => $this->recentEmails($scope, $params),
            'activity' => $this->activity($user, $admin, $scope, $params),
        ];

        if ($admin) {
            $stats['users'] = array_map('intval', $this->db->fetchAssociative(
                "SELECT COUNT(*) AS total, COUNT(*) FILTER (WHERE enabled) AS enabled,
                        COUNT(*) FILTER (WHERE source = 'ldap') AS ldap, COUNT(*) FILTER (WHERE source = 'local') AS local
                 FROM \"user\"",
            ));
            $stats['applications'] = $this->applications($from);
        }

        return $stats;
    }

    /**
     * One entry per day, oldest first, including days without any email.
     *
     * @param array<string, mixed> $params
     *
     * @return list<array{date: string, sent: int, failed: int, queued: int}>
     */
    private function daily(string $scope, array $params, \DateTimeImmutable $from, int $days): array
    {
        $rows = $this->db->fetchAllAssociative(
            "SELECT to_char(date_trunc('day', e.created_at), 'YYYY-MM-DD') AS day,
                    COUNT(*) FILTER (WHERE status = 'sent') AS sent,
                    COUNT(*) FILTER (WHERE status = 'failed') AS failed,
                    COUNT(*) FILTER (WHERE status = 'queued') AS queued
             FROM email e WHERE $scope AND e.created_at >= :from GROUP BY 1",
            $params + ['from' => $this->sql($from)],
        );
        $byDay = array_column($rows, null, 'day');

        $daily = [];
        for ($i = 0; $i < $days; ++$i) {
            $day = $from->modify("+$i days")->format('Y-m-d');
            $row = $byDay[$day] ?? [];
            $daily[] = [
                'date' => $day,
                'sent' => (int) ($row['sent'] ?? 0),
                'failed' => (int) ($row['failed'] ?? 0),
                'queued' => (int) ($row['queued'] ?? 0),
            ];
        }

        return $daily;
    }

    /**
     * @param list<array{sent: int, failed: int}> $daily
     *
     * @return array{sent: int, failed: int}
     */
    private function totals(array $daily): array
    {
        return [
            'sent' => array_sum(array_column($daily, 'sent')),
            'failed' => array_sum(array_column($daily, 'failed')),
        ];
    }

    /** Share of delivered emails among processed ones, as a percentage; null when nothing was processed. */
    private function rate(int $sent, int $failed): ?float
    {
        return 0 === $sent + $failed ? null : round(100 * $sent / ($sent + $failed), 2);
    }

    /**
     * @param array<string, mixed> $params
     *
     * @return list<array<string, mixed>>
     */
    private function recentEmails(string $scope, array $params): array
    {
        $rows = $this->db->fetchAllAssociative(
            "SELECT e.id, e.subject, e.recipients_to, e.status, e.created_at, e.from_address,
                    u.email AS sender_email, TRIM(CONCAT(u.first_name, ' ', u.last_name)) AS sender_name, a.name AS application_name
             FROM email e JOIN \"user\" u ON u.id = e.sender_id LEFT JOIN application a ON a.id = e.application_id
             WHERE $scope ORDER BY e.created_at DESC, e.id DESC LIMIT 6",
            $params,
        );

        return array_map(fn (array $row) => [
            'id' => $row['id'],
            'subject' => $row['subject'],
            'to' => json_decode($row['recipients_to'], true),
            'status' => $row['status'],
            'createdAt' => $this->atom($row['created_at']),
            'from' => $row['from_address'],
            'sender' => '' !== $row['sender_name'] ? $row['sender_name'] : $row['sender_email'],
            'applicationName' => $row['application_name'],
        ], $rows);
    }

    /**
     * Latest events, newest first: emails, template changes and, for admins, new users and applications.
     *
     * @param array<string, mixed> $params
     *
     * @return list<array{type: string, at: string, title: string, actor: ?string, link: ?string}>
     */
    private function activity(User $user, bool $admin, string $scope, array $params): array
    {
        $limit = 12;
        $events = [];

        foreach ($this->db->fetchAllAssociative(
            "SELECT e.id, e.subject, e.status, COALESCE(e.sent_at, e.updated_at) AS at, u.email AS actor, a.name AS application_name
             FROM email e JOIN \"user\" u ON u.id = e.sender_id LEFT JOIN application a ON a.id = e.application_id
             WHERE $scope ORDER BY at DESC LIMIT 8",
            $params,
        ) as $row) {
            $events[] = [
                'type' => 'email.'.$row['status'],
                'at' => $this->atom($row['at']),
                'title' => $row['subject'],
                'actor' => $row['actor'].($row['application_name'] ? ' via '.$row['application_name'] : ''),
                'link' => null,
            ];
        }

        $templateScope = $admin ? '' : 'AND (t.owner_id = :user OR t.shared)';
        foreach ($this->db->fetchAllAssociative(
            "SELECT v.action, v.logged_at, v.username, v.version, t.id, t.name
             FROM email_template_version v JOIN email_template t ON t.id::text = v.object_id
             WHERE v.object_class = :class $templateScope ORDER BY v.logged_at DESC LIMIT $limit",
            ['class' => EmailTemplate::class] + ($admin ? [] : $params),
        ) as $row) {
            $events[] = [
                'type' => 'template.'.$row['action'],
                'at' => $this->atom($row['logged_at']),
                'title' => \sprintf('%s (version %d)', $row['name'], $row['version']),
                'actor' => $row['username'],
                'link' => '/templates/'.$row['id'],
            ];
        }

        if ($admin) {
            foreach ($this->db->fetchAllAssociative(
                "SELECT email, source, created_at, created_by FROM \"user\" ORDER BY created_at DESC LIMIT $limit",
            ) as $row) {
                $events[] = [
                    'type' => 'user.created',
                    'at' => $this->atom($row['created_at']),
                    'title' => $row['email'].('ldap' === $row['source'] ? ' (LDAP)' : ''),
                    'actor' => $row['created_by'],
                    'link' => '/users',
                ];
            }
            foreach ($this->db->fetchAllAssociative(
                "SELECT name, created_at, created_by FROM application ORDER BY created_at DESC LIMIT $limit",
            ) as $row) {
                $events[] = [
                    'type' => 'application.created',
                    'at' => $this->atom($row['created_at']),
                    'title' => $row['name'],
                    'actor' => $row['created_by'],
                    'link' => '/applications',
                ];
            }
        }

        usort($events, static fn (array $a, array $b) => strcmp($b['at'], $a['at']));

        return \array_slice($events, 0, $limit);
    }

    /** @return list<array<string, mixed>> */
    private function applications(\DateTimeImmutable $from): array
    {
        $rows = $this->db->fetchAllAssociative(
            "SELECT a.id, a.name, a.enabled, a.can_impersonate, a.last_used_at,
                    COUNT(e.id) FILTER (WHERE e.status = 'sent') AS sent,
                    COUNT(e.id) FILTER (WHERE e.status = 'failed') AS failed
             FROM application a LEFT JOIN email e ON e.application_id = a.id AND e.created_at >= :from
             GROUP BY a.id ORDER BY a.last_used_at DESC NULLS LAST, a.name",
            ['from' => $this->sql($from)],
        );

        return array_map(fn (array $row) => [
            'id' => $row['id'],
            'name' => $row['name'],
            'enabled' => (bool) $row['enabled'],
            'canImpersonate' => (bool) $row['can_impersonate'],
            'lastUsedAt' => $this->atom($row['last_used_at']),
            'sent' => (int) $row['sent'],
            'failed' => (int) $row['failed'],
        ], $rows);
    }

    private function sql(\DateTimeImmutable $date): string
    {
        return $date->format('Y-m-d H:i:s');
    }

    private function atom(?string $value): ?string
    {
        return null === $value ? null : (new \DateTimeImmutable($value))->format(\DATE_ATOM);
    }
}
