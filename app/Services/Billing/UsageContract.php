<?php

namespace App\Services\Billing;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;

/**
 * Usage figures sourced from Analytics Engine (spec 9/14). Read-only —
 * quota decisions are computed from what this returns, never written back
 * here.
 */
interface UsageContract
{
    /**
     * @return array{storage_bytes: int, artifacts_this_period: int, render_minutes_this_period: int, period_start?: string, period_end?: string, limits?: array{storage_bytes: int, artifacts_per_month: int}|null}
     *
     * @throws ConnectionException when the Worker cannot be reached.
     * @throws RequestException when the Worker refuses the read.
     * @throws \RuntimeException when the Worker base URL is not configured.
     */
    public function currentUsage(string $orgSlug): array;
}
