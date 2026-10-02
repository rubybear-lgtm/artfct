<?php

namespace App\Services\Billing;

use RuntimeException;

/**
 * Thrown by {@see PlanGate::requireEnterprise()} — carries a clear
 * upgrade message rather than surfacing as an unhandled 500. DoD: "each
 * with a clear upgrade message, not a 500."
 */
final class PlanGateException extends RuntimeException
{
    public function __construct(public readonly string $feature)
    {
        parent::__construct("{$feature} requires the Enterprise plan. Upgrade your team's plan to enable it.");
    }
}
