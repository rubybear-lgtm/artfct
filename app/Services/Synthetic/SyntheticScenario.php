<?php

namespace App\Services\Synthetic;

use App\Enums\PaymentStatus;
use App\Models\Team;
use App\Services\Billing\OrgLimitsWriter;
use App\Services\Billing\QuotaLimits;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;

/**
 * Puts a synthetic org into a quota or payment state on demand (RUB-325) so
 * quota and billing stories can be exercised: limits are pushed to the
 * Worker with the same writer billing uses.
 */
final class SyntheticScenario
{
    public const NAMES = ['healthy', 'near-quota', 'over-quota', 'past-due'];

    /**
     * @return array{limits: QuotaLimits, payment_status: PaymentStatus, pushed: bool}
     */
    public function apply(Team $team, string $scenario, WorkerTarget $worker): array
    {
        SyntheticSeeder::assertSafe($team->slug);

        $defaults = QuotaLimits::default();
        $usage = $this->usage($worker);
        $paymentStatus = PaymentStatus::Active;

        $limits = match ($scenario) {
            'healthy', 'past-due' => $defaults,
            // 85% of the current storage, so usage sits above the 80% warning line.
            'near-quota' => $this->withStorage($defaults, max(1, (int) ceil($usage['storage_bytes'] / 0.85))),
            // Limit at or below current usage: the next create is refused.
            'over-quota' => $this->withStorage($defaults, max(1, min($defaults->storageBytes, $usage['storage_bytes']))),
            default => throw new InvalidArgumentException("Unknown scenario [{$scenario}]; use one of: ".implode(', ', self::NAMES)),
        };

        if ($scenario === 'past-due') {
            $paymentStatus = PaymentStatus::PastDue;
        }

        $team->forceFill(['payment_status' => $paymentStatus])->save();
        $pushed = (new OrgLimitsWriter($worker->url, $worker->limitsSecret))->push($team, $limits);

        return ['limits' => $limits, 'payment_status' => $paymentStatus, 'pushed' => $pushed];
    }

    private function withStorage(QuotaLimits $limits, int $storageBytes): QuotaLimits
    {
        return new QuotaLimits($storageBytes, $limits->artifactsPerMonth, $limits->bundleSizeCeilingBytes, $limits->renderMinutesPerMonth);
    }

    /**
     * @return array{storage_bytes: int, artifacts_this_period: int}
     */
    private function usage(WorkerTarget $worker): array
    {
        $response = Http::withToken($worker->token)->get("{$worker->url}/v1/orgs/{$worker->orgSlug}/usage")->throw();

        return [
            'storage_bytes' => (int) $response->json('storage_bytes'),
            'artifacts_this_period' => (int) $response->json('artifacts_this_period'),
        ];
    }
}
