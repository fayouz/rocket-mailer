<?php

namespace App\Inbox;

/**
 * Small RFC 5322 / MIME parser for the received messages (symfony/mime only builds messages): headers
 * (RFC 2047 encoded words), multipart bodies, base64 / quoted-printable, charsets, attachments (RFC 2231 names).
 */
final class MimeParser
{
    private const MAX_DEPTH = 8;

    public function parse(string $raw): ParsedMessage
    {
        $raw = str_replace("\r\n", "\n", $raw);
        [$headers, $body] = self::split($raw);
        $message = new ParsedMessage();

        $message->messageId = self::firstId($headers['message-id'][0] ?? '');
        $message->inReplyTo = self::firstId($headers['in-reply-to'][0] ?? '');
        $message->references = self::ids($headers['references'][0] ?? '');
        $from = self::addresses(self::decodeHeader($headers['from'][0] ?? ''));
        if ([] !== $from) {
            $message->fromAddress = $from[0]['email'];
            $message->fromName = $from[0]['name'];
        }
        $replyTo = self::addresses(self::decodeHeader($headers['reply-to'][0] ?? ''));
        $message->replyTo = $replyTo[0]['email'] ?? null;
        $message->to = array_column(self::addresses(self::decodeHeader(implode(', ', $headers['to'] ?? []))), 'email');
        $message->cc = array_column(self::addresses(self::decodeHeader(implode(', ', $headers['cc'] ?? []))), 'email');
        $message->subject = trim(self::decodeHeader($headers['subject'][0] ?? ''));
        try {
            $message->date = isset($headers['date'][0]) ? new \DateTimeImmutable(preg_replace('/\s*\([^)]*\)\s*$/', '', $headers['date'][0]) ?? '') : null;
        } catch (\Exception) {
            $message->date = null;
        }
        $auto = strtolower($headers['auto-submitted'][0] ?? 'no');
        $message->automated = 'no' !== $auto || isset($headers['list-id']) || \in_array(strtolower($headers['precedence'][0] ?? ''), ['bulk', 'list', 'junk'], true);

        $this->walk($headers, $body, $message, 0);

        if ('' === trim($message->text) && null !== $message->html) {
            $message->text = self::htmlToText($message->html);
        }

        return $message;
    }

    /** @param array<string, list<string>> $headers */
    private function walk(array $headers, string $body, ParsedMessage $message, int $depth): void
    {
        [$type, $params] = self::parameters($headers['content-type'][0] ?? 'text/plain; charset=us-ascii');
        $type = strtolower($type);
        [$disposition, $dispositionParams] = self::parameters($headers['content-disposition'][0] ?? '');
        $filename = $dispositionParams['filename'] ?? $params['name'] ?? null;

        if (str_starts_with($type, 'multipart/') && isset($params['boundary']) && $depth < self::MAX_DEPTH) {
            foreach (self::parts($body, $params['boundary']) as $part) {
                [$partHeaders, $partBody] = self::split($part);
                $this->walk($partHeaders, $partBody, $message, $depth + 1);
            }

            return;
        }

        $content = self::decodeBody($body, strtolower(trim($headers['content-transfer-encoding'][0] ?? '7bit')));
        $isAttachment = 'attachment' === strtolower($disposition) || null !== $filename;

        if (!$isAttachment && 'text/plain' === $type && '' === $message->text) {
            $message->text = self::toUtf8($content, $params['charset'] ?? null);

            return;
        }
        if (!$isAttachment && 'text/html' === $type && null === $message->html) {
            $message->html = self::toUtf8($content, $params['charset'] ?? null);

            return;
        }
        if (!$isAttachment && str_starts_with($type, 'text/')) {
            return; // A second text alternative (e.g. text/enriched): ignored.
        }

        $message->attachments[] = [
            'filename' => self::safeFilename($filename ?? ('message/rfc822' === $type ? 'message.eml' : 'piece-jointe')),
            'mimeType' => preg_match('#^[a-z0-9.+-]+/[a-z0-9.+-]+$#', $type) ? $type : 'application/octet-stream',
            'content' => $content,
        ];
    }

    /** @return array{0: array<string, list<string>>, 1: string} */
    private static function split(string $raw): array
    {
        $raw = ltrim($raw, "\n");
        $pos = strpos($raw, "\n\n");
        $head = false === $pos ? $raw : substr($raw, 0, $pos);
        $body = false === $pos ? '' : substr($raw, $pos + 2);

        $headers = [];
        $head = (string) preg_replace("/\n[ \t]+/", ' ', $head); // Unfolding.
        foreach (explode("\n", $head) as $line) {
            if (preg_match('/^([!-9;-~]+):\s*(.*)$/', $line, $m)) {
                $headers[strtolower($m[1])][] = trim($m[2]);
            }
        }

        return [$headers, $body];
    }

    /** @return list<string> */
    private static function parts(string $body, string $boundary): array
    {
        $parts = [];
        $delimiter = '--'.$boundary;
        $current = null;
        foreach (explode("\n", $body) as $line) {
            $trimmed = rtrim($line);
            if ($trimmed === $delimiter.'--') {
                break;
            }
            if ($trimmed === $delimiter) {
                if (null !== $current) {
                    $parts[] = $current;
                }
                $current = '';
                continue;
            }
            if (null !== $current) {
                $current .= $line."\n";
            }
        }
        if (null !== $current && '' !== $current) {
            $parts[] = $current;
        }

        return array_map(static fn (string $p) => str_ends_with($p, "\n") ? substr($p, 0, -1) : $p, $parts);
    }

    /** @return array{0: string, 1: array<string, string>} value and lowercased parameters (RFC 2231 decoded) */
    private static function parameters(string $header): array
    {
        $segments = [];
        $current = '';
        $quoted = false;
        for ($i = 0, $n = \strlen($header); $i < $n; ++$i) {
            $char = $header[$i];
            if ('"' === $char && ($i === 0 || '\\' !== $header[$i - 1])) {
                $quoted = !$quoted;
            }
            if (';' === $char && !$quoted) {
                $segments[] = $current;
                $current = '';
                continue;
            }
            $current .= $char;
        }
        $segments[] = $current;

        $value = trim((string) array_shift($segments));
        $params = [];
        $continued = [];
        foreach ($segments as $segment) {
            if (!preg_match('/^\s*([^=\s*]+)(\*\d+)?(\*)?\s*=\s*(.*)$/s', $segment, $m)) {
                continue;
            }
            $name = strtolower($m[1]);
            $raw = trim($m[4]);
            if (str_starts_with($raw, '"') && str_ends_with($raw, '"') && \strlen($raw) >= 2) {
                $raw = stripcslashes(substr($raw, 1, -1));
            }
            if ('' !== $m[2]) {
                $continued[$name][(int) substr($m[2], 1)] = [$raw, '' !== $m[3]];
                continue;
            }
            $params[$name] = '' !== $m[3] ? self::decode2231($raw) : self::decodeHeader($raw);
        }
        foreach ($continued as $name => $pieces) {
            ksort($pieces);
            $encoded = $pieces[array_key_first($pieces)][1];
            $joined = implode('', array_column($pieces, 0));
            $params[$name] = $encoded ? self::decode2231($joined) : self::decodeHeader($joined);
        }

        return [$value, $params];
    }

    /** charset'lang'percent-encoded (RFC 2231). */
    private static function decode2231(string $value): string
    {
        $parts = explode("'", $value, 3);
        if (3 !== \count($parts)) {
            return rawurldecode($value);
        }

        return self::toUtf8(rawurldecode($parts[2]), '' !== $parts[0] ? $parts[0] : null);
    }

    public static function decodeHeader(string $value): string
    {
        if (!str_contains($value, '=?')) {
            return self::toUtf8($value, null);
        }
        // Whitespace between two encoded words is not significant (RFC 2047, 6.2).
        $value = (string) preg_replace('/(\?=)\s+(=\?)/', '$1$2', $value);

        return (string) preg_replace_callback('/=\?([^?]+)\?([bBqQ])\?([^?]*)\?=/', static function (array $m): string {
            $charset = preg_replace('/\*.*$/', '', $m[1]) ?? $m[1];
            $data = 'b' === strtolower($m[2]) ? (string) base64_decode($m[3]) : quoted_printable_decode(str_replace('_', ' ', $m[3]));

            return self::toUtf8($data, $charset);
        }, $value);
    }

    private static function decodeBody(string $body, string $encoding): string
    {
        return match ($encoding) {
            'base64' => (string) base64_decode((string) preg_replace('/[^A-Za-z0-9+\/=]/', '', $body)),
            'quoted-printable' => quoted_printable_decode(str_replace("\n", "\r\n", $body)),
            default => $body,
        };
    }

    private static function toUtf8(string $value, ?string $charset): string
    {
        $charset = strtolower(trim((string) $charset, " \"'"));
        if ('' === $charset || \in_array($charset, ['utf-8', 'utf8', 'us-ascii', 'ascii'], true)) {
            return mb_check_encoding($value, 'UTF-8') ? $value : mb_convert_encoding($value, 'UTF-8', 'Windows-1252');
        }
        try {
            $converted = @mb_convert_encoding($value, 'UTF-8', $charset);
        } catch (\ValueError) {
            $converted = false;
        }
        if (false === $converted) {
            $converted = @iconv($charset, 'UTF-8//IGNORE', $value);
        }

        return mb_scrub(false === $converted ? $value : $converted, 'UTF-8');
    }

    private static function firstId(string $value): ?string
    {
        return self::ids($value)[0] ?? null;
    }

    /** @return list<string> Message-IDs with their angle brackets */
    private static function ids(string $value): array
    {
        preg_match_all('/<[^<>\s]+>/', $value, $m);

        return array_values(array_unique(array_map(static fn (string $id) => mb_substr($id, 0, 255), $m[0])));
    }

    /** @return list<array{email: string, name: ?string}> */
    public static function addresses(string $value): array
    {
        $list = [];
        $current = '';
        $quoted = false;
        $angle = 0;
        for ($i = 0, $n = \strlen($value); $i < $n; ++$i) {
            $char = $value[$i];
            if ('"' === $char && ($i === 0 || '\\' !== $value[$i - 1])) {
                $quoted = !$quoted;
            } elseif (!$quoted && '<' === $char) {
                ++$angle;
            } elseif (!$quoted && '>' === $char) {
                $angle = max(0, $angle - 1);
            }
            if (!$quoted && 0 === $angle && (',' === $char || ';' === $char)) {
                $list[] = $current;
                $current = '';
                continue;
            }
            $current .= $char;
        }
        $list[] = $current;

        $addresses = [];
        foreach ($list as $item) {
            $item = trim($item);
            if (preg_match('/^(.*)<([^<>]+)>\s*$/s', $item, $m)) {
                $name = trim($m[1], " \t\"'");
                $email = trim($m[2]);
            } else {
                $name = null;
                $email = trim((string) preg_replace('/\([^)]*\)/', '', $item));
            }
            if (!filter_var($email, \FILTER_VALIDATE_EMAIL) && !preg_match('/^[^@\s]+@[^@\s]+$/u', $email)) {
                continue;
            }
            $addresses[] = ['email' => mb_strtolower($email), 'name' => '' === (string) $name ? null : stripslashes((string) $name)];
        }

        return $addresses;
    }

    private static function safeFilename(string $name): string
    {
        $name = basename(str_replace('\\', '/', $name));
        $name = trim((string) preg_replace('/[\x00-\x1f\x7f]/u', '', $name));

        return '' === $name || '.' === $name || '..' === $name ? 'piece-jointe' : mb_substr($name, 0, 200);
    }

    public static function htmlToText(string $html): string
    {
        $html = (string) preg_replace('#<(script|style|head)[^>]*>.*?</\1>#is', '', $html);
        $html = (string) preg_replace('#<(br|/p|/div|/tr|/h[1-6]|/li)[^>]*>#i', "\n", $html);
        $text = html_entity_decode(strip_tags($html), \ENT_QUOTES | \ENT_HTML5, 'UTF-8');

        return trim((string) preg_replace("/\n{3,}/", "\n\n", (string) preg_replace('/[ \t]+/', ' ', $text)));
    }
}
