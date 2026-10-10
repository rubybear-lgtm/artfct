<?php

namespace App\Services\Identity;

final class RealDnsResolver implements DnsResolverContract
{
    /**
     * @return array<int, string>
     */
    public function txtRecords(string $name): array
    {
        $records = @dns_get_record($name, DNS_TXT);

        if ($records === false) {
            return [];
        }

        return collect($records)
            ->pluck('txt')
            ->filter()
            ->values()
            ->all();
    }
}
