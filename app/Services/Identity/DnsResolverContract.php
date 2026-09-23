<?php

namespace App\Services\Identity;

/**
 * Injectable seam over DNS TXT lookups, so domain verification is fully
 * testable without real DNS resolution. {@see RealDnsResolver} performs
 * the actual lookup; {@see FakeDnsResolver} is bound in tests.
 *
 * NOTE: only the fake resolver is exercised by this spec's test suite —
 * real end-to-end DNS resolution is not covered by an automated test.
 */
interface DnsResolverContract
{
    /**
     * Return every TXT record value found for the given DNS name.
     *
     * @return array<int, string>
     */
    public function txtRecords(string $name): array;
}
