<?php

namespace App\Services\Billing;

use App\Enums\PaymentStatus;
use App\Models\Team;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Pushes an org's quota limits and read-only (past due) state to the
 * Worker's `POST /v1/internal/org-limits`, authenticated with
 * `ARTFCT_LIMITS_WRITE_SECRET` (RUB-310). Same direction and shape as
 * `RevocationWriter`. A failed push is logged and returns false: the Worker
 * keeps enforcing its last pushed limits, so a dropped push fails open by
 * design and `billing:sync-limits` reconciles it.
 */
final class OrgLimitsWriter
{
    public function __construct(
        private readonly ?string $baseUrl = null,
        private readonly ?string $secret = null,
    ) {}

    public static function default(): self
    {
        return new self(
            config('services.worker.base_url') ?: null,
            config('services.worker.limits_write_secret') ?: null,
        );
    }

    public function push(Team $team, ?QuotaLimits $limits = null): bool
    {
        if (! $this->baseUrl || ! $this->secret) {
            return false;
        }

        $limits ??= QuotaLimits::default();

        try {
            $response = Http::withToken($this->secret)
                ->post(rtrim($this->baseUrl, '/').'/v1/internal/org-limits', [
                    'org' => $team->slug,
                    'storage_bytes' => $limits->storageBytes,
                    'artifacts_per_month' => $limits->artifactsPerMonth,
                    'bundle_ceiling_bytes' => $limits->bundleSizeCeilingBytes,
                    'read_only' => $team->payment_status === PaymentStatus::PastDue,
                ]);
        } catch (Throwable $exception) {
            Log::warning('Org limits push failed.', ['org' => $team->slug, 'error' => $exception->getMessage()]);

            return false;
        }

        if (! $response->successful()) {
            Log::warning('Org limits push rejected.', ['org' => $team->slug, 'status' => $response->status()]);
        }

        return $response->successful();
    }
}
