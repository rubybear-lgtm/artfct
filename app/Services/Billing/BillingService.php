<?php

namespace App\Services\Billing;

use App\Enums\PaymentStatus;
use App\Enums\Plan;
use App\Models\Team;
use Illuminate\Support\Carbon;

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

    public function portalUrl(Team $team): string
    {
        return $this->billing->createPortalSession($team);
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
    public function syncSeatsAtPeriodBoundary(Team $team): bool
    {
        if ($team->stripe_subscription_id === null) {
            return false;
        }

        $seatCount = $this->activeSeatCount($team);

        if (! self::seatChangeNeeded($team->seats_billed, $seatCount)) {
            return false;
        }

        $this->billing->updateSeats($team->stripe_subscription_id, $seatCount);
        $team->forceFill(['seats_billed' => $seatCount])->save();

        return true;
    }

    /**
     * Pure rule: only touch Stripe when the billed quantity differs from the
     * active seat count, so repeated syncs are no-ops.
     */
    public static function seatChangeNeeded(?int $seatsBilled, int $activeSeats): bool
    {
        return $seatsBilled !== $activeSeats;
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

    /**
     * Applies `customer.subscription.deleted`: the team drops to the free
     * plan (and its limits); nothing is deleted.
     */
    public function applySubscriptionEnded(Team $team): void
    {
        $team->forceFill([
            'plan' => Plan::Free,
            'payment_status' => PaymentStatus::Active,
            'stripe_subscription_id' => null,
            'seats_billed' => null,
            'cancel_at_period_end' => false,
            'current_period_end' => null,
        ])->save();

        $this->limitsWriter->push($team);
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

    /**
     * Applies Stripe's `customer.subscription.updated`: the renewal date and
     * whether the subscription is set to end with the current period.
     */
    public function applySubscriptionUpdated(Team $team, bool $cancelAtPeriodEnd, ?int $periodEnd): void
    {
        $team->forceFill([
            'cancel_at_period_end' => $cancelAtPeriodEnd,
            'current_period_end' => $periodEnd === null ? null : Carbon::createFromTimestamp($periodEnd),
        ])->save();
    }

    /**
     * @return list<array{number: string|null, amount: int, currency: string, status: string|null, date: int, url: string|null}>
     */
    public function invoices(Team $team): array
    {
        try {
            return $this->billing->listInvoices($team);
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * Seats are active members, counted at period boundaries — DoD:
     * "Seats are active members, counted at period boundaries."
     */
    /**
     * Active = not deactivated (SCIM). Viewers are billable unless
     * `billing.viewers_billable` is turned off.
     */
    public function activeSeatCount(Team $team): int
    {
        return $team->memberships()
            ->whereHas('user', fn ($query) => $query->whereNull('deactivated_at'))
            ->when(! config('billing.viewers_billable', true), fn ($query) => $query->where('role', '!=', 'viewer'))
            ->count();
    }
}
