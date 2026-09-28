<?php

namespace App\Tests\Support;

use App\Mailbox\ImapClient;
use App\Mailbox\MailServers;
use Symfony\Component\Mailer\Transport\Dsn;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;
use Symfony\Component\Mailer\Transport\TransportInterface;

/**
 * Scripted IMAP and SMTP servers of the test environment (no network): each connection is a socket pair whose
 * server side already holds the answers of the scenario ("ok" or "reject"). What the client sent is kept.
 * Provider DSNs (null://…), local addresses and the GreenMail server of the CI (GREENMAIL_HOST) are reached for real.
 */
final class FakeMailServers extends MailServers
{
    public static bool $imapAccepts = true;
    public static bool $smtpAccepts = true;

    /** @var list<resource> server sides of the IMAP connections */
    private static array $imapServers = [];

    /** @var list<ScriptedSmtpStream> */
    public static array $smtpStreams = [];

    /** @var list<array{username: ?string, password: ?string, authenticators: list<string>}> */
    public static array $smtpLogins = [];

    public static function reset(): void
    {
        self::$imapAccepts = self::$smtpAccepts = true;
        self::$imapServers = self::$smtpStreams = self::$smtpLogins = [];
    }

    public function imap(float $timeout): ImapClient
    {
        return new ImapClient(2.0, static function (string $address, float $timeout, $context): mixed {
            if (self::isReal((string) parse_url($address, \PHP_URL_HOST))) {
                return @stream_socket_client($address, $errno, $error, $timeout, \STREAM_CLIENT_CONNECT, $context);
            }
            [$client, $server] = stream_socket_pair(\STREAM_PF_UNIX, \STREAM_SOCK_STREAM, \STREAM_IPPROTO_IP) ?: throw new \RuntimeException('socket pair');
            fwrite($server, self::$imapAccepts
                ? "* OK Fake IMAP ready\r\nrm1 OK Logged in\r\n* LIST (\\HasNoChildren \\Sent) \"/\" \"Sent\"\r\n* LIST (\\HasNoChildren) \"/\" \"INBOX\"\r\nrm2 OK LIST done\r\n* BYE\r\nrm3 OK Bye\r\n"
                : "* OK Fake IMAP ready\r\nrm1 NO [AUTHENTICATIONFAILED] Invalid credentials (Failure)\r\n");
            self::$imapServers[] = $server;

            return $client;
        });
    }

    public function transport(#[\SensitiveParameter] string $dsn): TransportInterface
    {
        $parsed = Dsn::fromString($dsn);
        if (!\in_array($parsed->getScheme(), ['smtp', 'smtps'], true) || self::isReal($parsed->getHost())) {
            return parent::transport($dsn);
        }
        $stream = new ScriptedSmtpStream(self::$smtpAccepts
            ? "220 fake.test ESMTP\r\n250-fake.test\r\n250-AUTH PLAIN XOAUTH2\r\n250 8BITMIME\r\n235 2.7.0 Accepted\r\n221 Bye\r\n"
            : "220 fake.test ESMTP\r\n250-fake.test\r\n250-AUTH PLAIN XOAUTH2\r\n250 8BITMIME\r\n535 5.7.8 Username and Password not accepted\r\n250 OK\r\n535 5.7.8 Username and Password not accepted\r\n250 OK\r\n535 5.7.8 Username and Password not accepted\r\n250 OK\r\n535 5.7.8 Username and Password not accepted\r\n250 OK\r\n221 Bye\r\n");
        self::$smtpStreams[] = $stream;
        $transport = new EsmtpTransport($parsed->getHost(), 25, false, null, null, $stream);
        $transport->setUsername((string) $parsed->getUser());
        $transport->setPassword((string) $parsed->getPassword());

        return $transport;
    }

    /** Reached for real: the GreenMail server of the CI, local addresses (closed ports of the failure tests). */
    private static function isReal(string $host): bool
    {
        $greenMail = $_SERVER['GREENMAIL_HOST'] ?? $_ENV['GREENMAIL_HOST'] ?? getenv('GREENMAIL_HOST');

        return \in_array($host, ['localhost', '127.0.0.1', '::1'], true) || (\is_string($greenMail) && '' !== $greenMail && $host === $greenMail);
    }

    /** Everything the clients sent to the IMAP servers so far. */
    public static function imapReceived(): string
    {
        $received = '';
        foreach (self::$imapServers as $server) {
            stream_set_blocking($server, false);
            $received .= stream_get_contents($server);
        }

        return $received;
    }

    public static function smtpReceived(): string
    {
        return implode('', array_map(static fn (ScriptedSmtpStream $s) => $s->received(), self::$smtpStreams));
    }
}
