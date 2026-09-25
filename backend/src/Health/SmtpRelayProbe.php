<?php

namespace App\Health;

use Rocket\Core\Health\ServiceProbeInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mailer\Transport;
use Symfony\Component\Mailer\Transport\Smtp\SmtpTransport;

/** The SMTP relay of the platform (MAILER_DSN) on the dashboard, checked every 5 minutes by the worker. */
final class SmtpRelayProbe implements ServiceProbeInterface
{
    public function __construct(
        #[Autowire(service: 'mailer.transport_factory')] private readonly Transport $transports,
        #[Autowire(env: 'MAILER_DSN')] #[\SensitiveParameter] private readonly string $mailerDsn,
    ) {
    }

    public function id(): string
    {
        return 'mailer';
    }

    public function label(): string
    {
        return 'Relais SMTP';
    }

    public function targets(): iterable
    {
        // The "null" transport (development, tests) sends nothing: shown as not configured.
        if ('null' !== parse_url($this->mailerDsn, \PHP_URL_SCHEME)) {
            yield 'relay' => ['name' => $this->describe(), 'check' => $this->check(...)];
        }
    }

    private function check(): string
    {
        $transport = $this->transports->fromString($this->mailerDsn);
        if ($transport instanceof SmtpTransport) {
            $transport->start();
            $transport->stop();
        }

        return $this->describe();
    }

    /** Never exposes credentials: scheme, host and port only. */
    private function describe(): string
    {
        $port = parse_url($this->mailerDsn, \PHP_URL_PORT);

        return (parse_url($this->mailerDsn, \PHP_URL_SCHEME) ?: 'inconnu').'://'.parse_url($this->mailerDsn, \PHP_URL_HOST).($port ? ':'.$port : '');
    }
}
