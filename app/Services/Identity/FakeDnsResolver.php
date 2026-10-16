<?php

namespace App\Services\Identity;

/**
 * Test double for DNS TXT lookups. Records are set/cleared explicitly so
 * tests can exercise "record present" and "record removed" without any
 * network access.
 */
final class FakeDnsResolver implements DnsResolverContract
{
    /** @var array<string, array<int, string>> */
    private array $records = [];

    /**
     * Make the given name resolve to the given TXT value.
     */
    public function seed(string $name, string $value): self
    {
        $this->records[$name][] = $value;

        return $this;
    }

    /**
     * Remove every TXT record for the given name.
     */
    public function clear(string $name): self
    {
        unset($this->records[$name]);

        return $this;
    }

    /**
     * @return array<int, string>
     */
    public function txtRecords(string $name): array
    {
        return $this->records[$name] ?? [];
    }
}
