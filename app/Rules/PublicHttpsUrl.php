<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * HTTPS URLs whose host resolves only to public addresses. Used for
 * IdP metadata URLs that Polis will fetch server-side, so a tenant admin
 * cannot point Polis at loopback, link-local, private, or reserved targets
 * (RUB-461).
 */
class PublicHttpsUrl implements ValidationRule
{
    /**
     * Host suffixes that are never safe IdP targets, even before DNS.
     *
     * @var list<string>
     */
    private const BLOCKED_HOST_SUFFIXES = [
        '.local',
        '.localhost',
        '.internal',
        '.intranet',
        '.lan',
        '.home',
        '.corp',
    ];

    /**
     * @param  (Closure(string): list<string>)|null  $resolveHost
     *                                                             Optional host → IP
     *                                                             list resolver for
     *                                                             tests. Defaults to
     *                                                             DNS A/AAAA lookup.
     */
    public function __construct(private readonly ?Closure $resolveHost = null) {}

    /**
     * @param  Closure(string, string|null=): mixed  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || $value === '') {
            $fail('The :attribute must be a public https URL.');

            return;
        }

        $parts = parse_url($value);
        $hostPart = is_array($parts) ? ($parts['host'] ?? null) : null;

        if (! is_array($parts)
            || ($parts['scheme'] ?? null) !== 'https'
            || ! is_string($hostPart)
            || $hostPart === ''
            || isset($parts['user'])
            || isset($parts['pass'])) {
            $fail('The :attribute must be a public https URL.');

            return;
        }

        $host = strtolower($hostPart);

        // IPv6 literals arrive as "[::1]"; strip brackets for IP checks.
        if (str_starts_with($host, '[') && str_ends_with($host, ']')) {
            $host = substr($host, 1, -1);
        }

        if ($host === 'localhost' || $this->hasBlockedSuffix($host)) {
            $fail('The :attribute host is not a public address.');

            return;
        }

        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            if (! $this->isPublicIp($host)) {
                $fail('The :attribute host is not a public address.');
            }

            return;
        }

        $addresses = $this->addressesFor($host);

        if ($addresses === []) {
            $fail('The :attribute host could not be resolved to a public address.');

            return;
        }

        foreach ($addresses as $address) {
            if (! $this->isPublicIp($address)) {
                $fail('The :attribute host is not a public address.');

                return;
            }
        }
    }

    private function hasBlockedSuffix(string $host): bool
    {
        foreach (self::BLOCKED_HOST_SUFFIXES as $suffix) {
            if ($host === ltrim($suffix, '.') || str_ends_with($host, $suffix)) {
                return true;
            }
        }

        return false;
    }

    private function isPublicIp(string $ip): bool
    {
        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
    }

    /**
     * @return list<string>
     */
    private function addressesFor(string $host): array
    {
        if ($this->resolveHost !== null) {
            /** @var list<string> $addresses */
            $addresses = ($this->resolveHost)($host);

            return array_values(array_unique(array_filter($addresses, is_string(...))));
        }

        $addresses = [];

        foreach ([DNS_A => 'ip', DNS_AAAA => 'ipv6'] as $type => $key) {
            $records = @dns_get_record($host, $type);

            if (! is_array($records)) {
                continue;
            }

            foreach ($records as $record) {
                if (isset($record[$key]) && is_string($record[$key]) && $record[$key] !== '') {
                    $addresses[] = $record[$key];
                }
            }
        }

        return array_values(array_unique($addresses));
    }
}
