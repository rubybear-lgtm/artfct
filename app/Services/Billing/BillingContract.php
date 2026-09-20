<?php

namespace App\Services\Billing;

use App\Models\Team;

/**
 * Stripe billing seam (spec 14): per-seat Team-plan subscriptions.
 * Enterprise is invoiced manually and never touches this contract.
 */
interface BillingContract
{
    /**
     * Creates a Stripe Checkout session for `$team` to subscribe at
     * `$seatCount` seats. Returns the session URL to redirect the admin
     * to; the subscription itself is created by Stripe and confirmed via
     * webhook (`applyCheckoutCompleted`).
     */
    public function createCheckoutSession(Team $team, int $seatCount): string;

    /**
     * Updates a subscription's seat count — called at a billing period
     * boundary, not on every membership change (DoD: "Adding a member
     * mid-period bills correctly at the next boundary").
     */
    public function updateSeats(string $subscriptionId, int $seatCount): void;

    public function cancelSubscription(string $subscriptionId): void;

    /**
     * A Stripe customer-portal URL where the team manages its payment method
     * and downloads invoices.
     */
    public function createPortalSession(Team $team): string;
}
