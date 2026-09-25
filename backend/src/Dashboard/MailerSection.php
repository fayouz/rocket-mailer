<?php

namespace App\Dashboard;

use App\Entity\EmailTemplate;
use Doctrine\DBAL\Connection;
use Rocket\Core\Dashboard\DashboardSectionInterface;
use Rocket\Core\Dashboard\DashboardStats;
use Rocket\Core\Entity\User;

/**
 * Rocket Mailer on the dashboard: emails over 30 days, deliverability, sending queue, templates.
 * Admins see the whole platform; other users only their own emails, and their own or shared templates.
 */
final class MailerSection implements DashboardSectionInterface
{
    private const STATUS = [
        'queued' => ['En file', 'neutral'],
        'sent' => ['Envoyé', 'success'],
        'failed' => ['Échec', 'error'],
    ];

    private const EVENTS = [
        'email.sent' => ['i-lucide-send', 'Email envoyé', 'text-success bg-success/10'],
        'email.failed' => ['i-lucide-circle-x', 'Échec d’envoi', 'text-error bg-error/10'],
        'email.queued' => ['i-lucide-clock', 'Email en file d’attente', 'text-muted bg-elevated'],
        'template.create' => ['i-lucide-layout-template', 'Template créé', 'text-sky-600 bg-sky-500/10 dark:text-sky-400'],
        'template.update' => ['i-lucide-pencil', 'Template modifié', 'text-sky-600 bg-sky-500/10 dark:text-sky-400'],
        'template.remove' => ['i-lucide-trash-2', 'Template supprimé', 'text-muted bg-elevated'],
    ];

    public function __construct(private readonly Connection $db)
    {
    }

    public function build(User $user, bool $admin, \DateTimeImmutable $from, \DateTimeImmutable $previousFrom): array
    {
        $params = ['user' => $user->getId()->toRfc4122()];
        [$scope, $scopeParams] = $admin ? ['TRUE', []] : ['e.sender_id = :user', $params];

        $daily = [];
        foreach ($this->db->fetchAllAssociative(
            "SELECT to_char(date_trunc('day', e.created_at), 'YYYY-MM-DD') AS day,
                    COUNT(*) FILTER (WHERE status = 'sent') AS sent,
                    COUNT(*) FILTER (WHERE status = 'failed') AS failed,
                    COUNT(*) FILTER (WHERE status = 'queued') AS queued
             FROM email e WHERE $scope AND e.created_at >= :from GROUP BY 1",
            $scopeParams + ['from' => DashboardStats::sql($from)],
        ) as $row) {
            $daily[$row['day']] = ['sent' => (int) $row['sent'], 'failed' => (int) $row['failed'], 'queued' => (int) $row['queued']];
        }
        $sent = array_sum(array_column($daily, 'sent'));
        $failed = array_sum(array_column($daily, 'failed'));
        $previous = $this->db->fetchAssociative(
            "SELECT COUNT(*) FILTER (WHERE status = 'sent') AS sent, COUNT(*) FILTER (WHERE status = 'failed') AS failed
             FROM email e WHERE $scope AND e.created_at >= :from AND e.created_at < :to",
            $scopeParams + ['from' => DashboardStats::sql($previousFrom), 'to' => DashboardStats::sql($from)],
        );
        $queue = $this->db->fetchAssociative(
            "SELECT COUNT(*) AS queued, MIN(created_at) AS oldest FROM email e WHERE $scope AND status = 'queued'",
            $scopeParams,
        );
        $withAttachments = (int) $this->db->fetchOne(
            "SELECT COUNT(DISTINCT e.id) FROM email e JOIN attachment a ON a.email_id = e.id WHERE $scope AND e.created_at >= :from",
            $scopeParams + ['from' => DashboardStats::sql($from)],
        );
        $templates = $this->db->fetchAssociative(
            'SELECT COUNT(*) AS total, COUNT(*) FILTER (WHERE shared) AS shared FROM email_template t'
            .($admin ? '' : ' WHERE t.owner_id = :user OR t.shared'),
            $admin ? [] : $params,
        );

        return [
            'kpis' => [
                [
                    'id' => 'sent',
                    'label' => $admin ? 'Emails envoyés (30 j)' : 'Mes emails envoyés (30 j)',
                    'value' => $sent,
                    'format' => 'number',
                    'icon' => 'i-lucide-send',
                    'tone' => 'bg-primary/10 text-primary',
                    'series' => 'sent',
                    'previous' => (int) $previous['sent'],
                    'detail' => \sprintf('%d sur les 30 jours précédents', $previous['sent']),
                ],
                [
                    'id' => 'rate',
                    'label' => 'Taux de délivrabilité',
                    'value' => 0 === $sent + $failed ? null : round(100 * $sent / ($sent + $failed), 2),
                    'format' => 'percent',
                    'icon' => 'i-lucide-badge-check',
                    'tone' => 'bg-success/10 text-success',
                    'legend' => [
                        ['label' => $sent.' délivrés', 'color' => 'bg-success'],
                        ['label' => $failed.' échecs', 'color' => 'bg-error'],
                    ],
                ],
                [
                    'id' => 'queue',
                    'label' => 'File d’envoi',
                    'value' => (int) $queue['queued'],
                    'format' => 'number',
                    'icon' => 'i-lucide-hourglass',
                    'tone' => 'bg-amber-500/10 text-amber-600 dark:text-amber-400',
                    'detail' => null === $queue['oldest']
                        ? 'Aucun email en attente'
                        : 'Plus ancien : '.(new \DateTimeImmutable($queue['oldest']))->format('d/m/Y H:i'),
                    'legend' => [['label' => $withAttachments.' avec pièces jointes (30 j)', 'color' => 'bg-primary']],
                ],
                [
                    'id' => 'templates',
                    'label' => 'Templates disponibles',
                    'value' => (int) $templates['total'],
                    'format' => 'number',
                    'icon' => 'i-lucide-layout-template',
                    'tone' => 'bg-sky-500/10 text-sky-600 dark:text-sky-400',
                    'legend' => [['label' => $templates['shared'].' partagés', 'color' => 'bg-sky-500']],
                ],
            ],
            'series' => [
                ['key' => 'sent', 'label' => 'Envoyés', 'color' => 'bg-success'],
                ['key' => 'failed', 'label' => 'Échecs', 'color' => 'bg-error'],
                ['key' => 'queued', 'label' => 'En file', 'color' => 'bg-accented'],
            ],
            'daily' => $daily,
            'recent' => [
                'title' => 'Derniers envois',
                'link' => $admin ? '/emails/all' : '/emails',
                'empty' => 'Aucun email envoyé pour le moment : écrivez un message depuis « Nouveau message ».',
                'items' => $this->recentEmails($scope, $scopeParams, $admin),
            ],
            'activity' => $this->activity($admin, $scope, $scopeParams, $params),
            'quickActions' => [
                ['label' => 'Nouveau message', 'icon' => 'i-lucide-send', 'to' => '/compose', 'tone' => 'bg-primary/10 text-primary'],
                ['label' => 'Nouveau template', 'icon' => 'i-lucide-layout-template', 'to' => '/templates/new', 'tone' => 'bg-sky-500/10 text-sky-600 dark:text-sky-400'],
                // Administrators get "Nouvelle application" and "Nouvel utilisateur" from the core instead.
                ...($admin ? [] : [['label' => 'Mes envois', 'icon' => 'i-lucide-inbox', 'to' => '/emails', 'tone' => 'bg-emerald-500/10 text-emerald-600 dark:text-emerald-400']]),
            ],
        ];
    }

    /**
     * @param array<string, mixed> $params
     *
     * @return list<array<string, mixed>>
     */
    private function recentEmails(string $scope, array $params, bool $admin): array
    {
        return array_map(static fn (array $row) => [
            'id' => $row['id'],
            'title' => $row['subject'],
            'subtitle' => 'À '.implode(', ', json_decode($row['recipients_to'], true))
                .($admin ? ' · par '.('' !== $row['sender_name'] ? $row['sender_name'] : $row['sender_email']) : '')
                .(null !== $row['application_name'] ? ' · via '.$row['application_name'] : ''),
            'at' => DashboardStats::atom($row['created_at']),
            'badge' => self::STATUS[$row['status']][0] ?? $row['status'],
            'badgeColor' => self::STATUS[$row['status']][1] ?? 'neutral',
        ], $this->db->fetchAllAssociative(
            "SELECT e.id, e.subject, e.recipients_to, e.status, e.created_at,
                    u.email AS sender_email, TRIM(CONCAT(u.first_name, ' ', u.last_name)) AS sender_name, a.name AS application_name
             FROM email e JOIN \"user\" u ON u.id = e.sender_id LEFT JOIN application a ON a.id = e.application_id
             WHERE $scope ORDER BY e.created_at DESC, e.id DESC LIMIT 6",
            $params,
        ));
    }

    /**
     * Latest emails and template changes (the core adds the new users and applications for admins).
     *
     * @param array<string, mixed> $scopeParams
     * @param array<string, mixed> $params
     *
     * @return list<array<string, mixed>>
     */
    private function activity(bool $admin, string $scope, array $scopeParams, array $params): array
    {
        $events = [];
        foreach ($this->db->fetchAllAssociative(
            "SELECT e.subject, e.status, COALESCE(e.sent_at, e.updated_at) AS at, u.email AS actor, a.name AS application_name
             FROM email e JOIN \"user\" u ON u.id = e.sender_id LEFT JOIN application a ON a.id = e.application_id
             WHERE $scope ORDER BY at DESC LIMIT 8",
            $scopeParams,
        ) as $row) {
            $events[] = self::event('email.'.$row['status'], $row['at'], $row['subject'], $row['actor'].($row['application_name'] ? ' via '.$row['application_name'] : ''), null);
        }

        $templateScope = $admin ? '' : 'AND (t.owner_id = :user OR t.shared)';
        foreach ($this->db->fetchAllAssociative(
            "SELECT v.action, v.logged_at, v.username, v.version, t.id, t.name
             FROM email_template_version v JOIN email_template t ON t.id::text = v.object_id
             WHERE v.object_class = :class $templateScope ORDER BY v.logged_at DESC LIMIT 12",
            ['class' => EmailTemplate::class] + ($admin ? [] : $params),
        ) as $row) {
            $events[] = self::event('template.'.$row['action'], $row['logged_at'], \sprintf('%s (version %d)', $row['name'], $row['version']), $row['username'], '/templates/'.$row['id']);
        }

        return $events;
    }

    /** @return array<string, mixed> */
    private static function event(string $type, string $at, string $title, ?string $actor, ?string $link): array
    {
        [$icon, $label, $color] = self::EVENTS[$type] ?? ['i-lucide-mail', $type, 'text-muted bg-elevated'];

        return ['type' => $type, 'at' => DashboardStats::atom($at), 'title' => $title, 'actor' => $actor, 'link' => $link, 'icon' => $icon, 'label' => $label, 'color' => $color];
    }
}
