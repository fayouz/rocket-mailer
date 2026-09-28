<?php

namespace App\Tests\Functional;

use App\Mailbox\ImapClient;
use App\Mailbox\ImapException;
use PHPUnit\Framework\TestCase;

/** SASL XOAUTH2 of the IMAP client against a scripted server (socket pair, no network). */
final class ImapXOAuth2Test extends TestCase
{
    public function testErrorChallengeIsAnsweredAndReported(): void
    {
        $server = null;
        $client = new ImapClient(2.0, static function () use (&$server) {
            [$client, $server] = stream_socket_pair(\STREAM_PF_UNIX, \STREAM_SOCK_STREAM, \STREAM_IPPROTO_IP);
            fwrite($server, "* OK ready\r\n+ ".base64_encode('{"status":"400","schemes":"Bearer"}')."\r\nrm1 NO [AUTHENTICATIONFAILED] Invalid credentials\r\n");

            return $client;
        });
        $client->connect('imap.gmail.com', 993, 'ssl');
        try {
            $client->authenticateXOAuth2('alice@gmail.com', 'expired');
            self::fail('Expected a failure.');
        } catch (ImapException $e) {
            self::assertStringContainsString('IMAP authentication failed', $e->getMessage());
            self::assertStringContainsString('"status":"400"', $e->getMessage());
        }
        stream_set_blocking($server, false);
        $sent = (string) stream_get_contents($server);
        // The client answered the challenge with an empty line.
        self::assertSame('rm1 AUTHENTICATE XOAUTH2 '.base64_encode("user=alice@gmail.com\1auth=Bearer expired\1\1")."\r\n\r\n", $sent);
    }
}
