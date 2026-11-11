<?php

namespace App\Services\Billing;

use App\Enums\Plan;
use App\Models\Team;

/**
 * Human member caps for flat Pro/Team pricing (RUB-529). Bots are free and
 * never count. Over Team's cap is a Business conversation, not a Stripe price.
 */
final class MemberCap
{
    public static function maxHumans(Team $team): ?int
    {
        $plan = $team->plan ?? Plan::Free;
        $cap = config("billing.human_member_caps.{$plan->value}");

        return is_int($cap) ? $cap : null;
    }

    public static function humanMemberCount(Team $team): int
    {
        return $team->memberships()
            ->whereHas('user', fn ($query) => $query
                ->whereNull('deactivated_at')
                ->where('is_bot', false))
            ->count();
    }

    /**
     * Whether adding one more human would exceed the plan cap.
     */
    public static function canAddHuman(Team $team): bool
    {
        $max = self::maxHumans($team);
        if ($max === null) {
            return true;
        }

        return self::humanMemberCount($team) < $max;
    }

    public static function upgradeMessage(Team $team): string
    {
        $plan = $team->plan ?? Plan::Free;
        $contact = (string) config('billing.business_contact_email', 'support@artfct.dev');

        return match ($plan) {
            Plan::Free => __('Free is for one person. Upgrade to Team to invite teammates, or contact :email about Business.', ['email' => $contact]),
            Plan::Pro => __('Pro is for one person. Upgrade to Team to invite teammates, or contact :email about Business.', ['email' => $contact]),
            Plan::Team => __('Team includes up to 10 people. Contact :email about Business for larger teams.', ['email' => $contact]),
            Plan::Enterprise => __('This team cannot add more members on the current plan. Contact :email about Business.', ['email' => $contact]),
        };
    }
}
