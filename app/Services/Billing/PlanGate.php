<?php

namespace App\Services\Billing;

use App\Enums\Plan;
use App\Models\Team;

/**
 * The single place every enterprise feature checks `plan` (spec 14).
 * "Setting `plan = enterprise` grants all of them with no other change" —
 * there is deliberately no per-feature flag; every gated feature calls
 * {@see requireEnterprise()} and nothing else compares `$team->plan`
 * directly (`plan_gate_has_single_call_site` asserts this structurally).
 */
final class PlanGate
{
    /**
     * @throws PlanGateException when the team's plan is not Enterprise.
     */
    public static function requireEnterprise(Team $team, string $feature): void
    {
        if ($team->plan !== Plan::Enterprise) {
            throw new PlanGateException($feature);
        }
    }

    public static function isEnterprise(Team $team): bool
    {
        return $team->plan === Plan::Enterprise;
    }
}
