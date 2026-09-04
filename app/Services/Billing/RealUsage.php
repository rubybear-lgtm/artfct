<?php

namespace App\Services\Billing;

use RuntimeException;

/**
 * Real Cloudflare Analytics Engine usage source. Fails closed — no live
 * Analytics Engine account in this environment.
 */
final class RealUsage implements UsageContract
{
    public function currentUsage(string $orgSlug): array
    {
        if (! config('services.cloudflare.analytics_engine_dataset')) {
            throw new RuntimeException('services.cloudflare.analytics_engine_dataset must be configured to read usage.');
        }

        throw new RuntimeException('RealUsage::currentUsage is not implemented — no live Analytics Engine account in this environment.');
    }
}
