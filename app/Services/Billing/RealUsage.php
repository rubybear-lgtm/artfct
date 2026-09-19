<?php

namespace App\Services\Billing;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Usage from the Worker's `GET /v1/orgs/{org}/usage` (RUB-310). D1 is the
 * source of truth, so the console figure equals what the create gate
 * enforces. Render minutes stay 0 until the real renderer exists
 * (RUB-316). Fails closed when the Worker is not configured.
 */
final class RealUsage implements UsageContract
{
    public function currentUsage(string $orgSlug): array
    {
        $baseUrl = config('services.worker.base_url');
        $orgToken = config('services.worker.org_token');

        if (! $baseUrl || ! $orgToken) {
            throw new RuntimeException('services.worker.base_url and services.worker.org_token must be configured to read usage.');
        }

        $response = Http::withToken($orgToken)
            ->get(rtrim($baseUrl, '/')."/v1/orgs/{$orgSlug}/usage")
            ->throw();

        return [
            'storage_bytes' => (int) $response->json('storage_bytes'),
            'artifacts_this_period' => (int) $response->json('artifacts_this_period'),
            'render_minutes_this_period' => 0,
        ];
    }
}
