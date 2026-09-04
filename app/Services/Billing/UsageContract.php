<?php

namespace App\Services\Billing;

/**
 * Usage figures sourced from Analytics Engine (spec 9/14). Read-only —
 * quota decisions are computed from what this returns, never written back
 * here.
 */
interface UsageContract
{
    /**
     * @return array{storage_bytes: int, artifacts_this_period: int, render_minutes_this_period: int}
     */
    public function currentUsage(string $orgSlug): array;
}
