<?php

namespace App\Services\Billing;

/**
 * Per-tenant quota ceilings (spec 14). Same numeric values for every
 * tenant in this environment — a per-team override column is a natural
 * extension but wasn't asked for by the DoD, which only requires each
 * tenant to *have* a storage/artifact/bundle-size/render-minute ceiling,
 * not that the ceiling vary per tenant.
 */
final readonly class QuotaLimits
{
    public function __construct(
        public int $storageBytes,
        public int $artifactsPerMonth,
        public int $bundleSizeCeilingBytes,
        public int $renderMinutesPerMonth,
    ) {}

    /**
     * Defaults: 5 GB storage, 1,000 artifacts/month, a 10 MB per-artifact
     * ceiling (below spec 4's absolute 50 MB bundle ceiling — DoD:
     * "below spec 4's absolute ceiling"), 100 browser-render minutes.
     */
    public static function default(): self
    {
        return new self(
            storageBytes: (int) config('billing.quota.storage_bytes', 5 * 1024 * 1024 * 1024),
            artifactsPerMonth: (int) config('billing.quota.artifacts_per_month', 1000),
            bundleSizeCeilingBytes: (int) config('billing.quota.bundle_size_ceiling_bytes', 10 * 1024 * 1024),
            renderMinutesPerMonth: (int) config('billing.quota.render_minutes_per_month', 100),
        );
    }
}
