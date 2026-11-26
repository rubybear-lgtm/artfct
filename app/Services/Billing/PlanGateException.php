<?php

namespace App\Services\Billing;

use RuntimeException;

/**
 * Thrown by {@see PlanGate} — carries a clear refusal message rather than
 * surfacing as an unhandled 500. The message stays free of plan, upgrade,
 * pricing and checkout wording so ChatGPT-reachable tool errors stay inside
 * OpenAI's content rules (Spec 22 / RUB-606). Machine codes such as
 * `plan_upgrade_required` and `upgrade_plan` are unchanged.
 */
final class PlanGateException extends RuntimeException
{
    public function __construct(
        public readonly string $feature,
        public readonly string $requiredPlan = 'Enterprise',
    ) {
        parent::__construct("{$feature} isn't available for this workspace. Ask a workspace admin.");
    }
}
