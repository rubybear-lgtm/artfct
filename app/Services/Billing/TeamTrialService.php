<?php

namespace App\Services\Billing;

use App\Enums\BillingPrice;
use App\Enums\PaymentStatus;
use App\Enums\Plan;
use App\Models\Team;
use App\Models\User;
use App\Notifications\Teams\TrialEndingSoon;
use App\Services\Analytics\ProductAnalytics;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * RUB-529: start, remind, and expire the 90-day Team trial on
 * `artfct_team_monthly`. Stripe cancels with no charge when no card is added.
 */
final class TeamTrialService
{
    public function __construct(
        private readonly BillingContract $billing,
        private readonly OrgLimitsWriter $limitsWriter,
        private readonly ProductAnalytics $analytics,
    ) {}

    public function start(Team $team, ?User $actor = null, string $source = 'web'): bool
    {
        if ($team->is_personal) {
            return false;
        }

        if ($team->trial_started_at !== null) {
            return false;
        }

        if ($team->stripe_subscription_id !== null && $team->trial_ends_at === null) {
            return false;
        }

        try {
            $subscription = $this->billing->createTrialSubscription($team);
        } catch (Throwable $exception) {
            Log::warning('Team trial subscription could not be created in Stripe.', [
                'team_id' => $team->id,
                'exception' => $exception->getMessage(),
            ]);
            report($exception);

            return false;
        }

        $endsAt = Carbon::createFromTimestamp($subscription['trial_end']);

        $team->forceFill([
            'plan' => Plan::Team,
            'payment_status' => PaymentStatus::Active,
            'stripe_customer_id' => $team->stripe_customer_id ?? $subscription['customer'],
            'stripe_subscription_id' => $subscription['id'],
            'trial_started_at' => now(),
            'trial_ends_at' => $endsAt,
            'seats_billed' => 1,
        ])->save();

        $this->limitsWriter->push($team);
        $this->analytics->trialStarted($actor, $team, $source, $this->daysLeft($team));

        return true;
    }

    public function isActive(Team $team): bool
    {
        return $team->trial_ends_at !== null
            && $team->trial_ends_at->isFuture()
            && PlanGate::is($team, Plan::Team);
    }

    public function expireIfDue(Team $team): bool
    {
        if ($team->trial_ends_at === null || $team->trial_ends_at->isFuture()) {
            return false;
        }

        if (! PlanGate::is($team, Plan::Team)) {
            return false;
        }

        return $this->expire($team, 'scheduler');
    }

    public function expire(Team $team, string $source = 'scheduler'): bool
    {
        if ($team->trial_started_at === null || $team->trial_ends_at === null) {
            return false;
        }

        if (PlanGate::is($team, Plan::Free) && $team->stripe_subscription_id === null) {
            return false;
        }

        $subscriptionId = $team->stripe_subscription_id;
        if ($subscriptionId !== null) {
            try {
                $this->billing->deleteSubscription($subscriptionId);
            } catch (Throwable $exception) {
                Log::warning('Could not delete Stripe trial subscription during expire.', [
                    'team_id' => $team->id,
                    'subscription' => $subscriptionId,
                    'exception' => $exception->getMessage(),
                ]);
                report($exception);
            }
        }

        $team->forceFill([
            'plan' => Plan::Free,
            'payment_status' => PaymentStatus::Active,
            'stripe_subscription_id' => null,
            'seats_billed' => null,
            'cancel_at_period_end' => false,
            'current_period_end' => null,
        ])->save();

        $this->limitsWriter->push($team);
        $this->recordTrialEndedAnalytics($team, $source);

        return true;
    }

    public function recordTrialEndedAnalytics(Team $team, string $source): void
    {
        $this->analytics->trialEnded(null, $team, $source);
    }

    public function grantToExistingFreeTeams(): int
    {
        $granted = 0;

        Team::query()
            ->where('is_personal', false)
            ->where('plan', Plan::Free)
            ->whereNull('trial_started_at')
            ->whereNull('stripe_subscription_id')
            ->orderBy('id')
            ->each(function (Team $team) use (&$granted): void {
                if ($this->start($team, $team->owner, 'migration')) {
                    $granted++;
                }
            });

        return $granted;
    }

    public function markReminderSent(Team $team): void
    {
        if ($team->trial_reminder_sent_at !== null) {
            return;
        }

        $team->forceFill(['trial_reminder_sent_at' => now()])->save();

        $recipient = $team->owner ?? $team->firstAdmin();
        if ($recipient !== null) {
            $recipient->notify(new TrialEndingSoon($team, $this->daysLeft($team)));
        }
    }

    public function daysLeft(Team $team): int
    {
        if ($team->trial_ends_at === null) {
            return 0;
        }

        $days = (int) ceil(now()->diffInDays($team->trial_ends_at, absolute: false));

        return max(0, $days);
    }

    /**
     * Mirror a Stripe subscription onto the team. `trialing` keeps Team +
     * trial_ends_at; `active` clears trial state and sets the plan from the
     * price lookup_key when present.
     */
    public function applyStripeSubscriptionState(
        Team $team,
        string $subscriptionId,
        string $status,
        ?int $trialEnd,
        ?string $lookupKey = null,
    ): void {
        $plan = $team->plan ?? Plan::Team;
        if (is_string($lookupKey) && $lookupKey !== '') {
            $price = BillingPrice::tryFromLookupKey($lookupKey);
            if ($price !== null) {
                $plan = $price->plan();
            }
        }

        if ($status === 'trialing') {
            $endsAt = $trialEnd !== null ? Carbon::createFromTimestamp($trialEnd) : $team->trial_ends_at;

            $team->forceFill([
                'plan' => Plan::Team,
                'payment_status' => PaymentStatus::Active,
                'stripe_subscription_id' => $subscriptionId !== '' ? $subscriptionId : $team->stripe_subscription_id,
                'trial_started_at' => $team->trial_started_at ?? now(),
                'trial_ends_at' => $endsAt,
                'seats_billed' => 1,
            ])->save();

            $this->limitsWriter->push($team);

            return;
        }

        if ($status === 'active') {
            if (! in_array($plan, [Plan::Pro, Plan::Team, Plan::Enterprise], true)) {
                $plan = Plan::Team;
            }

            $team->forceFill([
                'plan' => $plan,
                'payment_status' => PaymentStatus::Active,
                'stripe_subscription_id' => $subscriptionId !== '' ? $subscriptionId : $team->stripe_subscription_id,
                'trial_ends_at' => null,
                'seats_billed' => 1,
            ])->save();

            $this->limitsWriter->push($team);
        }
    }
}
