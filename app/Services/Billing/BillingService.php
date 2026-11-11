<?php

namespace App\Services\Billing;

use App\Enums\BillingPrice;
use App\Enums\PaymentStatus;
use App\Enums\Plan;
use App\Models\Team;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * Stripe checkout, trial conversion, and payment-status webhooks (spec 14 / RUB-529).
 * Flat Pro/Team prices; quantity is always 1.
 */
final class BillingService
{
    public function __construct(
        private readonly BillingContract $billing,
        private readonly OrgLimitsWriter $limitsWriter,
    ) {}

    public function startCheckout(Team $team, BillingPrice $price): string
    {
        return $this->billing->createCheckoutSession($team, $price);
    }

    public function portalUrl(Team $team): string
    {
        return $this->billing->createPortalSession($team);
    }

    /**
     * Applies Stripe's `checkout.session.completed`: sets the purchased plan,
     * clears any remaining trial state, and replaces a prior trial subscription.
     */
    public function applyCheckoutCompleted(Team $team, string $subscriptionId, Plan $plan = Plan::Team): void
    {
        $previous = $team->stripe_subscription_id;
        if ($previous !== null && $previous !== $subscriptionId) {
            try {
                $this->billing->deleteSubscription($previous);
            } catch (Throwable) {
                // Paid subscription is the source of truth.
            }
        }

        $team->forceFill([
            'plan' => $plan,
            'payment_status' => PaymentStatus::Active,
            'stripe_subscription_id' => $subscriptionId,
            'seats_billed' => 1,
            'trial_ends_at' => null,
        ])->save();

        $this->limitsWriter->push($team);
    }

    /**
     * Per-seat syncing was removed with flat Team pricing (RUB-529).
     * Kept as a no-op so the scheduled command stays harmless until retired.
     */
    public function syncSeatsAtPeriodBoundary(Team $team): bool
    {
        return false;
    }

    public static function seatChangeNeeded(?int $seatsBilled, int $activeSeats): bool
    {
        return false;
    }

    public function applyPaymentFailed(Team $team): void
    {
        $team->forceFill(['payment_status' => PaymentStatus::PastDue])->save();
        $this->limitsWriter->push($team);
    }

    public function applyPaymentSucceeded(Team $team): void
    {
        $team->forceFill(['payment_status' => PaymentStatus::Active])->save();
        $this->limitsWriter->push($team);
    }

    public function applySubscriptionEnded(Team $team): void
    {
        $hadActiveTrial = $team->trial_started_at !== null
            && $team->trial_ends_at !== null
            && PlanGate::is($team, Plan::Team);

        $team->forceFill([
            'plan' => Plan::Free,
            'payment_status' => PaymentStatus::Active,
            'stripe_subscription_id' => null,
            'seats_billed' => null,
            'cancel_at_period_end' => false,
            'current_period_end' => null,
        ])->save();

        $this->limitsWriter->push($team);

        if ($hadActiveTrial) {
            app(TeamTrialService::class)->recordTrialEndedAnalytics($team, 'stripe');
        }
    }

    public function applyOperatorPlanChange(Team $team, Plan $plan, PaymentStatus $status): void
    {
        $team->forceFill([
            'plan' => $plan,
            'payment_status' => $status,
        ])->save();
    }

    public function pushLimits(Team $team): bool
    {
        return $this->limitsWriter->push($team);
    }

    public function cancelSubscription(Team $team): void
    {
        if ($team->stripe_subscription_id !== null) {
            $this->billing->cancelSubscription($team->stripe_subscription_id);
            $team->forceFill(['cancel_at_period_end' => true])->save();
        }
    }

    public function resumeSubscription(Team $team): void
    {
        if ($team->stripe_subscription_id !== null) {
            $this->billing->resumeSubscription($team->stripe_subscription_id);
            $team->forceFill(['cancel_at_period_end' => false])->save();
        }
    }

    public function applySubscriptionUpdated(
        Team $team,
        bool $cancelAtPeriodEnd,
        ?int $periodEnd,
        ?string $status = null,
        ?string $subscriptionId = null,
        ?int $trialEnd = null,
        ?string $lookupKey = null,
    ): void {
        $attributes = [
            'cancel_at_period_end' => $cancelAtPeriodEnd,
            'current_period_end' => $periodEnd === null ? null : Carbon::createFromTimestamp($periodEnd),
        ];

        if (in_array($status, ['past_due', 'unpaid'], true)) {
            $attributes['payment_status'] = PaymentStatus::PastDue;
        } elseif (in_array($status, ['active', 'trialing'], true)) {
            $attributes['payment_status'] = PaymentStatus::Active;
        }

        $team->forceFill($attributes)->save();

        $shouldMirrorTrial = $status === 'trialing'
            || (
                in_array($status, ['active', 'past_due', 'unpaid'], true)
                && ($lookupKey !== null || $team->trial_ends_at !== null)
            );

        if ($shouldMirrorTrial) {
            app(TeamTrialService::class)->applyStripeSubscriptionState(
                $team->fresh(),
                $subscriptionId ?? (string) $team->stripe_subscription_id,
                $status,
                $trialEnd,
                $lookupKey,
            );
        } elseif ($team->wasChanged('payment_status')) {
            $this->limitsWriter->push($team);
        }
    }

    /**
     * @return list<array{number: string|null, amount: int, currency: string, status: string|null, date: int, url: string|null}>
     */
    public function invoices(Team $team): array
    {
        try {
            return $this->billing->listInvoices($team);
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * Active human members (bots excluded). Used for the Team member cap,
     * not for Stripe quantity.
     */
    public function activeSeatCount(Team $team): int
    {
        return MemberCap::humanMemberCount($team);
    }
}
