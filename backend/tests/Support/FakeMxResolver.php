<?php

namespace App\Tests\Support;

use App\Mailbox\MxResolver;

/** MX records of the test environment (no DNS). */
final class FakeMxResolver extends MxResolver
{
    /** @var array<string, list<string>> */
    public const RECORDS = [
        'loussa-housing.fr' => ['mx1.mail.ovh.net', 'mx2.mail.ovh.net'],
        'workspace.example' => ['aspmx.l.google.com'],
        'contoso.example' => ['contoso-example.mail.protection.outlook.com'],
    ];

    public function lookup(string $domain): array
    {
        return self::RECORDS[$domain] ?? [];
    }
}
