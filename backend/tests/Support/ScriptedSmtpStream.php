<?php

namespace App\Tests\Support;

use Symfony\Component\Mailer\Transport\Smtp\Stream\AbstractStream;

/** An SMTP connection to a scripted server (a socket pair holding its answers). See FakeMailServers. */
final class ScriptedSmtpStream extends AbstractStream
{
    /** @var resource|null */
    private $server;
    private string $host = 'fake.test';
    private int $port = 25;

    public function __construct(private readonly string $script)
    {
    }

    public function initialize(): void
    {
        [$client, $server] = stream_socket_pair(\STREAM_PF_UNIX, \STREAM_SOCK_STREAM, \STREAM_IPPROTO_IP) ?: throw new \RuntimeException('socket pair');
        fwrite($server, $this->script);
        stream_set_timeout($client, 2);
        $this->server = $server;
        $this->stream = $this->in = $this->out = $client;
    }

    // What EsmtpTransport expects from a SocketStream.
    public function disableTls(): static
    {
        return $this;
    }

    public function isTLS(): bool
    {
        return false;
    }

    public function setHost(string $host): static
    {
        $this->host = $host;

        return $this;
    }

    public function getHost(): string
    {
        return $this->host;
    }

    public function setPort(int $port): static
    {
        $this->port = $port;

        return $this;
    }

    public function getPort(): int
    {
        return $this->port;
    }

    public function received(): string
    {
        if (null === $this->server) {
            return '';
        }
        stream_set_blocking($this->server, false);

        return (string) stream_get_contents($this->server);
    }

    protected function getReadConnectionDescription(): string
    {
        return 'fake SMTP server';
    }
}
