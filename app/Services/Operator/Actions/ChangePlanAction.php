<?php

namespace App\Services\Operator\Actions;

use App\Enums\AuditEventType;
use App\Enums\PaymentStatus;
use App\Enums\Plan;
use App\Models\Team;
use App\Services\Billing\BillingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Operator plan and payment status change: a comp or an extension (spec 17).
 *
 * The console shows the diff first; this refuses an unchanged request and one
 * without `confirm`. The reason is stored with a `from -> to` suffix so the
 * tenant's admins see what changed. Stripe is never touched.
 */
final class ChangePlanAction
{
    public function __construct(
        private readonly OperatorActionRecorder $recorder,
        private readonly BillingService $billing,
    ) {}

    public function run(Request $request, Team $team, Plan $plan, PaymentStatus $status, bool $confirmed, string $reason): string
    {
        $type = AuditEventType::OperatorPlanChanged;

        $this->recorder->refuseIfDeleted($request, $type, $team, $team->slug, $reason);

        if (! $confirmed) {
            $this->recorder->refuse($request, $type, $team, $team->slug, $reason, 'confirm', 'Confirm the change after reviewing the difference.');
        }

        // The row is re-read under a lock and the guards run against it, so a
        // change that landed since the page loaded (or since the model was
        // resolved) is refused instead of recorded with a wrong "before". A
        // refusal is recorded after the transaction: inside it, the failure
        // event would roll back with the rest.
        $refusal = DB::transaction(function () use ($request, $type, $team, $plan, $status, $reason): ?string {
            /** @var Team $locked */
            $locked = Team::withTrashed()->whereKey($team->id)->lockForUpdate()->firstOrFail();

            if ($locked->trashed()) {
                return 'This tenant is deleted. No action can run against it.';
            }

            $currentPlan = $locked->plan ?? Plan::Free;
            $currentStatus = $locked->payment_status ?? PaymentStatus::Active;

            if ($plan === $currentPlan && $status === $currentStatus) {
                return 'The plan and payment status are already set to these values.';
            }

            $changes = [];
            if ($plan !== $currentPlan) {
                $changes[] = "plan {$currentPlan->value} → {$plan->value}";
            }
            if ($status !== $currentStatus) {
                $changes[] = "payment {$currentStatus->value} → {$status->value}";
            }

            $this->billing->applyOperatorPlanChange($locked, $plan, $status);
            $this->recorder->succeed($request, $type, $locked, $locked->slug, $reason.' ('.implode(', ', $changes).')');

            return null;
        });

        if ($refusal !== null) {
            $this->recorder->refuse($request, $type, $team, $team->slug, $reason, 'action', $refusal);
        }

        // After the commit: the Worker must never enforce a change that was
        // rolled back with a failed audit write.
        if (! $this->billing->pushLimits($team->refresh())) {
            return 'Plan updated, but the Worker did not accept the new limits. Use "Push limits" to retry.';
        }

        return 'Plan updated.';
    }
}
