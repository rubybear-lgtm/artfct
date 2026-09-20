<?php

namespace App\Enums;

use App\Services\Billing\PlanGate;

/**
 * An org's plan (spec 14). Every enterprise feature checks this through
 * exactly one place — {@see PlanGate} — "a gate that
 * exists in three files is a gate that will be wrong in one of them."
 */
enum Plan: string
{
    case Free = 'free';
    case Team = 'team';
    case Enterprise = 'enterprise';
}
