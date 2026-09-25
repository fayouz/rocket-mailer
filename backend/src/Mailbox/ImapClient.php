<?php

namespace App\Mailbox;

/**
 * Minimal IMAP4rev1 client (RFC 3501): what a sending mailbox needs, i.e. log in, find or create the
 * "Sent" folder and APPEND a copy of each email. Plain sockets, no ext-imap (removed from PHP 8.4).
 */
final class ImapClient
{
    /** Folder names tried, in order, when the server does not flag its "Sent" folder (RFC 6154). */
    public const SENT_CANDIDATES = ['Sent', 'Sent Items', 'Sent Messages', 'Sent Mail', '[Gmail]/Sent Mail', 'INBOX.Sent', 'INBOX/Sent', 'Envoyés', 'Éléments envoyés', 'INBOX.Envoyés'];

    /** @var resource|null */
    private $stream;
    private int $tag = 0;

    public function __construct(private readonly float $timeout = 15.0)
    {
    }

    /** @param 'ssl'|'starttls'|'none' $encryption */
    public function connect(string $host, int $port, string $encryption): void
    {
        $scheme = 'ssl' === $encryption ? 'ssl' : 'tcp';
        $context = stream_context_create(['ssl' => ['peer_name' => $host, 'SNI_enabled' => true]]);
        $stream = @stream_socket_client(\sprintf('%s://%s:%d', $scheme, $host, $port), $errno, $error, $this->timeout, \STREAM_CLIENT_CONNECT, $context);
        if (false === $stream) {
            throw new ImapException(\sprintf('Cannot connect to %s:%d: %s', $host, $port, $error ?: 'connection failed'));
        }
        stream_set_timeout($stream, (int) ceil($this->timeout));
        $this->stream = $stream;

        $greeting = $this->readLine();
        if (!preg_match('/^\* (OK|PREAUTH)/i', $greeting)) {
            throw new ImapException('Unexpected IMAP greeting: '.$greeting);
        }

        if ('starttls' === $encryption) {
            $this->command('STARTTLS');
            if (true !== @stream_socket_enable_crypto($this->stream, true, \STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | \STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT)) {
                throw new ImapException('STARTTLS negotiation failed.');
            }
        }
    }

    public function login(string $username, #[\SensitiveParameter] string $password): void
    {
        try {
            $this->command('LOGIN '.self::quote($username).' '.self::quote($password));
        } catch (ImapException $e) {
            throw new ImapException('IMAP authentication failed: '.$e->getMessage(), 0, $e);
        }
    }

    /**
     * Folder names, with the "Sent" folder flagged by the server if it supports SPECIAL-USE.
     *
     * @return array{folders: list<string>, sent: ?string}
     */
    public function folders(): array
    {
        $folders = [];
        $sent = null;
        foreach ($this->command('LIST "" "*"') as $line) {
            if (!preg_match('/^\* LIST \(([^)]*)\) (?:"[^"]*"|NIL) (.+)$/i', $line, $m)) {
                continue;
            }
            $name = self::unquote($m[2]);
            $folders[] = $name;
            if (null === $sent && preg_match('/\\\\Sent\b/i', $m[1])) {
                $sent = $name;
            }
        }

        return ['folders' => $folders, 'sent' => $sent];
    }

    /**
     * The folder where copies go: the configured one (created if missing), else the server's "Sent" folder,
     * else a usual name, else a new "Sent" folder. Returns its IMAP (modified UTF-7) name.
     */
    public function resolveSentFolder(?string $configured): string
    {
        $list = $this->folders();
        if (null !== $configured && '' !== trim($configured)) {
            $name = self::encodeName(trim($configured));
            if (!\in_array($name, $list['folders'], true)) {
                $this->command('CREATE '.self::quote($name));
            }

            return $name;
        }
        if (null !== $list['sent']) {
            return $list['sent'];
        }
        foreach (self::SENT_CANDIDATES as $candidate) {
            if (\in_array(self::encodeName($candidate), $list['folders'], true)) {
                return self::encodeName($candidate);
            }
        }
        $this->command('CREATE "Sent"');

        return 'Sent';
    }

    /** Stores a raw RFC 5322 message in the folder, marked as read. */
    public function append(string $folder, string $message): void
    {
        $message = preg_replace("/\r?\n/", "\r\n", $message) ?? $message;
        $tag = $this->nextTag();
        $this->write(\sprintf("%s APPEND %s (\\Seen) {%d}\r\n", $tag, self::quote($folder), \strlen($message)));
        $continuation = $this->readLine();
        if (!str_starts_with($continuation, '+')) {
            throw new ImapException('APPEND refused: '.$continuation);
        }
        $this->write($message."\r\n");
        $this->readUntilTagged($tag);
    }

    public function logout(): void
    {
        if (null === $this->stream) {
            return;
        }
        try {
            $this->command('LOGOUT');
        } catch (ImapException) {
            // Closing anyway.
        }
        fclose($this->stream);
        $this->stream = null;
    }

    public function __destruct()
    {
        if (null !== $this->stream) {
            @fclose($this->stream);
        }
    }

    /** IMAP folder names are "modified UTF-7" (RFC 3501, 5.1.3). */
    public static function encodeName(string $utf8): string
    {
        return preg_match('/[^\x20-\x7e]|&/', $utf8) ? mb_convert_encoding($utf8, 'UTF7-IMAP', 'UTF-8') : $utf8;
    }

    public static function decodeName(string $imap): string
    {
        return str_contains($imap, '&') ? mb_convert_encoding($imap, 'UTF-8', 'UTF7-IMAP') : $imap;
    }

    /** @return list<string> untagged lines */
    private function command(string $command): array
    {
        $tag = $this->nextTag();
        $this->write($tag.' '.$command."\r\n");

        return $this->readUntilTagged($tag);
    }

    /** @return list<string> */
    private function readUntilTagged(string $tag): array
    {
        $lines = [];
        while (true) {
            $line = $this->readLine();
            if (str_starts_with($line, $tag.' ')) {
                if (!preg_match('/^'.preg_quote($tag, '/').' OK/i', $line)) {
                    throw new ImapException(trim(substr($line, \strlen($tag) + 1)));
                }

                return $lines;
            }
            // Literal in a response ({n}): read it and fold it into the line.
            if (preg_match('/\{(\d+)\}$/', $line, $m)) {
                $line = substr($line, 0, -\strlen($m[0])).'"'.addcslashes($this->readBytes((int) $m[1]), '"\\').'"'.$this->readLine();
            }
            $lines[] = $line;
        }
    }

    private function nextTag(): string
    {
        return 'rm'.++$this->tag;
    }

    private function write(string $data): void
    {
        if (null === $this->stream || false === @fwrite($this->stream, $data)) {
            throw new ImapException('The IMAP connection was closed.');
        }
    }

    private function readLine(): string
    {
        $line = null === $this->stream ? false : fgets($this->stream);
        if (false === $line) {
            $timedOut = null !== $this->stream && (stream_get_meta_data($this->stream)['timed_out'] ?? false);
            throw new ImapException($timedOut ? 'The IMAP server did not answer in time.' : 'The IMAP connection was closed.');
        }

        return rtrim($line, "\r\n");
    }

    private function readBytes(int $length): string
    {
        $data = '';
        while (\strlen($data) < $length) {
            $chunk = null === $this->stream ? false : fread($this->stream, $length - \strlen($data));
            if (false === $chunk || '' === $chunk) {
                throw new ImapException('The IMAP connection was closed.');
            }
            $data .= $chunk;
        }

        return $data;
    }

    private static function quote(string $value): string
    {
        return '"'.addcslashes($value, '"\\').'"';
    }

    private static function unquote(string $value): string
    {
        $value = trim($value);

        return str_starts_with($value, '"') ? stripcslashes(substr($value, 1, -1)) : $value;
    }
}
