<?php

namespace App\Enums;

/**
 * Stripe price lookup_keys (RUB-529). Resolve to price IDs at runtime —
 * never hardcode live price IDs in application code.
 */
enum BillingPrice: string
{
    case ProMonthly = 'artfct_pro_monthly';
    case ProYearly = 'artfct_pro_yearly';
    case TeamMonthly = 'artfct_team_monthly';
    case TeamYearly = 'artfct_team_yearly';

    public function plan(): Plan
    {
        return match ($this) {
            self::ProMonthly, self::ProYearly => Plan::Pro,
            self::TeamMonthly, self::TeamYearly => Plan::Team,
        };
    }

    public function interval(): string
    {
        return match ($this) {
            self::ProMonthly, self::TeamMonthly => 'month',
            self::ProYearly, self::TeamYearly => 'year',
        };
    }

    public static function for(Plan $plan, string $interval): self
    {
        return match ([$plan, $interval]) {
            [Plan::Pro, 'month'] => self::ProMonthly,
            [Plan::Pro, 'year'] => self::ProYearly,
            [Plan::Team, 'month'] => self::TeamMonthly,
            [Plan::Team, 'year'] => self::TeamYearly,
            default => throw new \InvalidArgumentException("No Stripe price for {$plan->value}/{$interval}."),
        };
    }

    public static function tryFromLookupKey(string $lookupKey): ?self
    {
        return self::tryFrom($lookupKey);
    }
}
