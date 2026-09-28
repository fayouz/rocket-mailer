<?php

namespace App\Mailbox;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mailer\Transport;
use Symfony\Component\Mailer\Transport\TransportInterface;

/**
 * Opens the connections to the mail servers: IMAP clients and SMTP (or provider) transports.
 * The single place that reaches the network, replaced by scripted servers in the tests (Tests\Support\FakeMailServers).
 */
class MailServers
{
    public function __construct(
        #[Autowire(service: 'mailer.transport_factory')] private readonly Transport $transports,
    ) {
    }

    public function imap(float $timeout): ImapClient
    {
        return new ImapClient($timeout);
    }

    public function transport(#[\SensitiveParameter] string $dsn): TransportInterface
    {
        return $this->transports->fromString($dsn);
    }
}
