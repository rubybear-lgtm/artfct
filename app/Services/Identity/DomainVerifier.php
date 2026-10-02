<?php

namespace App\Services\Identity;

use App\Models\TeamDomain;

final class DomainVerifier
{
    public function __construct(private readonly DnsResolverContract $resolver) {}

    /**
     * Attempt verification. Succeeds (and sets `verified_at`) only when the
     * domain's TXT record contains the expected token; otherwise
     * `verified_at` is left/reset to null so re-verification after the
     * record is removed and re-added works without extra bookkeeping.
     */
    public function verify(TeamDomain $domain): bool
    {
        $records = $this->resolver->txtRecords($domain->txtRecordName());

        if (! in_array($domain->verification_token, $records, true)) {
            $domain->forceFill(['verified_at' => null])->save();

            return false;
        }

        $domain->forceFill(['verified_at' => now()])->save();

        return true;
    }
}
