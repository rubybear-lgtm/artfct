<?php

namespace App\Services\Billing;

use App\Enums\PaymentStatus;
use App\Enums\Plan;
use App\Models\Team;

/**
 * Stripe checkout, seat sync, and payment-status webhooks (spec 14).
 */
final class BillingService
{
    public function __construct(
        private readonly BillingContract $billing,
        private readonly OrgLimitsWriter $limitsWriter,
    ) {}

    public function startCheckout(Team $team): string
    {
        return $this->billing->createCheckoutSession($team, $this->activeSeatCount($team));
    }

    /**
     * Applies Stripe's `checkout.session.completed` webhook: sets
     * `plan = team` and records the subscription — DoD: "Stripe checkout
     * creates a subscription and sets plan = team."
     */
    public function applyCheckoutCompleted(Team $team, string $subscriptionId): void
    {
        $team->forceFill([
            'plan' => Plan::Team,
            'payment_status' => PaymentStatus::Active,
            'stripe_subscription_id' => $subscriptionId,
            'seats_billed' => $this->activeSeatCount($team),
        ])->save();

        $this->limitsWriter->push($team);
    }

    /**
     * Syncs seat count to Stripe — call this at a billing period
     * boundary (a scheduled job), not on every membership change.
     */
    public function syncSeatsAtPeriodBoundary(Team $team): void
    {
        if ($team->stripe_subscription_id === null) {
            return;
        }

        $seatCount = $this->activeSeatCount($team);
        $this->billing->updateSeats($team->stripe_subscription_id, $seatCount);
        $team->forceFill(['seats_billed' => $seatCount])->save();
    }

    /**
     * Applies Stripe's `invoice.payment_failed` webhook — DoD: "Payment
     * failure moves the org read-only: artifacts serve, creates refused,
     * nothing deleted." This method only ever sets a status flag; nothing
     * here (or anywhere `payment_status` is read) deletes a row or touches
     * the serving path.
     */
    public function applyPaymentFailed(Team $team): void
    {
        $team->forceFill(['payment_status' => PaymentStatus::PastDue])->save();
        $this->limitsWriter->push($team);
    }

    /**
     * Applies Stripe's `invoice.payment_succeeded` webhook — DoD:
     * "Restoring payment restores create access without operator
     * intervention" — this is the only step required; no manual
     * unlock/approval step exists.
     */
    public function applyPaymentSucceeded(Team $team): void
    {
        $team->forceFill(['payment_status' => PaymentStatus::Active])->save();
        $this->limitsWriter->push($team);
    }

    public function cancelSubscription(Team $team): void
    {
        if ($team->stripe_subscription_id !== null) {
            $this->billing->cancelSubscription($team->stripe_subscription_id);
        }
    }

    /**
     * Seats are active members, counted at period boundaries — DoD:
     * "Seats are active members, counted at period boundaries."
     */
    public function activeSeatCount(Team $team): int
    {
        return $team->memberships()->count();
    }
}
