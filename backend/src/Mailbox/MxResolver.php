<?php

namespace App\Mailbox;

/** MX records of a domain (DNS), sorted by priority. Replaced in the tests (no network). */
class MxResolver
{
    /** @return list<string> lower-case hosts, best first; empty when unknown */
    public function lookup(string $domain): array
    {
        $records = @dns_get_record($domain, \DNS_MX);
        if (!\is_array($records)) {
            return [];
        }
        usort($records, static fn (array $a, array $b) => ($a['pri'] ?? 0) <=> ($b['pri'] ?? 0));

        return array_values(array_filter(array_map(static fn (array $r) => mb_strtolower(rtrim((string) ($r['target'] ?? ''), '.')), $records)));
    }
}
