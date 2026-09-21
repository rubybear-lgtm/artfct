<?php

namespace App\Services\Billing;

use App\Enums\TeamRole;
use App\Services\Auth\OrgJwtService;
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

        if (! $baseUrl) {
            throw new RuntimeException('services.worker.base_url must be configured to read usage.');
        }

        $token = OrgJwtService::default()->mintFor($orgSlug, 'system', TeamRole::Member)['token'];

        $response = Http::withToken($token)
            ->get(rtrim($baseUrl, '/')."/v1/orgs/{$orgSlug}/usage")
            ->throw();

        $usage = [
            'storage_bytes' => (int) $response->json('storage_bytes'),
            'artifacts_this_period' => (int) $response->json('artifacts_this_period'),
            'render_minutes_this_period' => 0,
        ];

        foreach (['period_start', 'period_end'] as $periodKey) {
            $periodValue = $response->json($periodKey);

            if (is_string($periodValue) && $periodValue !== '') {
                $usage[$periodKey] = $periodValue;
            }
        }

        // What the Worker actually enforces, when it says so.
        if (is_array($response->json('limits'))) {
            $usage['limits'] = [
                'storage_bytes' => (int) $response->json('limits.storage_bytes'),
                'artifacts_per_month' => (int) $response->json('limits.artifacts_per_month'),
            ];
        }

        return $usage;
    }
}
