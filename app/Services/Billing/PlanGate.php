<?php

namespace App\Services\Billing;

use App\Enums\Plan;
use App\Models\OrgToken;
use App\Models\Team;
use App\Models\User;

/**
 * The single place every plan-gated feature checks `plan` (spec 14 / RUB-529).
 * Every gated feature calls {@see requireEnterprise()} or {@see requireTeam()}
 * and nothing else compares `$team->plan` directly
 * (`plan_gate_has_single_call_site` asserts this structurally).
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

    public static function is(Team $team, Plan $plan): bool
    {
        return $team->plan === $plan;
    }

    public static function isAny(Team $team, Plan ...$plans): bool
    {
        return in_array($team->plan, $plans, true);
    }

    /**
     * Team-tier features (search by description, custom web address): Pro,
     * Team (including an active trial), and Enterprise.
     *
     * @throws PlanGateException when the team is on Free (or an expired trial).
     */
    public static function requireTeam(Team $team, string $feature): void
    {
        if (! self::hasTeamFeatures($team)) {
            throw new PlanGateException($feature, 'Team');
        }
    }

    public static function hasTeamFeatures(Team $team): bool
    {
        app(TeamTrialService::class)->expireIfDue($team);
        $team->refresh();

        return match ($team->plan) {
            Plan::Pro, Plan::Team, Plan::Enterprise => true,
            Plan::Free => false,
        };
    }

    /**
     * Bot teammates and Agent/bot credentials (what `bot_connected` tracks).
     * Pro, Team (including trial), and Enterprise/Business. Free does not.
     * A person's own human OAuth MCP connection is not gated here.
     *
     * @throws PlanGateException when the team is on Free (or an expired trial).
     */
    public static function requireBotAccess(Team $team): void
    {
        if (! self::hasBotAccess($team)) {
            throw new PlanGateException('AI bot teammates', 'Pro or Team');
        }
    }

    public static function hasBotAccess(Team $team): bool
    {
        return self::hasTeamFeatures($team);
    }

    /**
     * Bot credentials are Agent tokens and/or bot-user actors. Human API /
     * user-OAuth tokens are not bot credentials.
     */
    public static function isBotCredential(?OrgToken $orgToken, ?User $actor): bool
    {
        if ($orgToken?->isAgent() === true) {
            return true;
        }

        return $actor?->isBot() === true;
    }
}
