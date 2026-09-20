<?php

namespace App\Services\Billing;

use App\Enums\PaymentStatus;
use App\Enums\Plan;
use App\Models\Team;

/**
 * Quota status and enforcement (spec 14). "Bundle size is the only line
 * that can run away" — behaviour at the limit is a soft warning at 80%,
 * then refusal to create, **never deletion of existing artifacts and
 * never a serving outage**: every method here only ever gates *new*
 * creation. Nothing in this class, or anything that calls it, touches an
 * existing artifact's row or the Worker's serving path — that guarantee
 * holds structurally, not by a check this class could get wrong.
 */
final class QuotaService
{
    private const WARNING_THRESHOLD = 0.8;

    public function __construct(
        private readonly UsageContract $usage,
        private readonly ?QuotaLimits $limits = null,
    ) {}

    public function status(Team $team): QuotaStatus
    {
        $limits = $this->limitsFor($team);
        $usage = $this->usage->currentUsage($team->slug);

        // Prefer the limits the Worker reports enforcing over the plan's, so the
        // meter can never disagree with what a create would actually hit.
        $storageLimit = $usage['limits']['storage_bytes'] ?? $limits->storageBytes;
        $artifactsLimit = $usage['limits']['artifacts_per_month'] ?? $limits->artifactsPerMonth;

        $storagePercent = $storageLimit > 0
            ? $usage['storage_bytes'] / $storageLimit
            : 0.0;
        $artifactsPercent = $artifactsLimit > 0
            ? $usage['artifacts_this_period'] / $artifactsLimit
            : 0.0;

        return new QuotaStatus(
            storagePercent: $storagePercent,
            artifactsPercent: $artifactsPercent,
            storageWarning: $storagePercent >= self::WARNING_THRESHOLD,
            artifactsWarning: $artifactsPercent >= self::WARNING_THRESHOLD,
            storageExceeded: $storagePercent >= 1.0,
            artifactsExceeded: $artifactsPercent >= 1.0,
        );
    }

    /**
     * @throws QuotaExceededException when the org is at or over its
     *                                storage or artifact-count quota, or
     *                                its payment is past due (spec 14:
     *                                "new creates are refused" — sharing
     *                                this one gate keeps "can a new
     *                                artifact be created" a single
     *                                question with two possible reasons,
     *                                rather than two separate checks a
     *                                caller could forget one of).
     * @throws BundleTooLargeException when `$bundleSizeBytes` exceeds the
     *                                 tenant's per-artifact ceiling.
     */
    public function assertCanCreateArtifact(Team $team, int $bundleSizeBytes): void
    {
        $limits = $this->limitsFor($team);

        if ($bundleSizeBytes > $limits->bundleSizeCeilingBytes) {
            throw new BundleTooLargeException($bundleSizeBytes, $limits->bundleSizeCeilingBytes);
        }

        if ($team->payment_status === PaymentStatus::PastDue) {
            throw new QuotaExceededException(
                "Team [{$team->slug}]'s payment is past due — new artifact creation is refused until payment is restored.",
            );
        }

        $status = $this->status($team);
        if ($status->anyExceeded()) {
            throw new QuotaExceededException(
                "Team [{$team->slug}] is over its quota — new artifact creation is refused until usage drops or the quota is raised.",
            );
        }
    }

    private function limitsFor(Team $team): QuotaLimits
    {
        return $this->limits ?? QuotaLimits::forPlan($team->plan ?? Plan::Free);
    }
}
