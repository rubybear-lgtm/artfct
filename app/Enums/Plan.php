<?php

namespace App\Enums;

use App\Services\Billing\PlanGate;

/**
 * An org's plan (spec 14 / RUB-529). Every gated feature checks this through
 * exactly one place — {@see PlanGate} — "a gate that exists in three files
 * is a gate that will be wrong in one of them."
 *
 * Pro = one person, Team features without teammates.
 * Team = flat plan for up to 10 humans (bots free).
 * Business is a sales path into Enterprise, not a Stripe price.
 */
enum Plan: string
{
    case Free = 'free';
    case Pro = 'pro';
    case Team = 'team';
    case Enterprise = 'enterprise';
}
