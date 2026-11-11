<?php

namespace App\Services\Billing;

use App\Enums\BillingPrice;
use App\Models\Team;

/**
 * Stripe billing seam (spec 14 / RUB-529): flat Pro and Team prices by
 * lookup_key. Quantity is always 1 — no per-seat syncing.
 */
interface BillingContract
{
    /**
     * Creates a Stripe Checkout session for `$price` (quantity 1).
     * Returns the session URL to redirect the admin to.
     */
    public function createCheckoutSession(Team $team, BillingPrice $price): string;

    /**
     * Creates a Stripe subscription on `artfct_team_monthly` with a 90-day
     * trial, no payment method required, and
     * `trial_settings.end_behavior.missing_payment_method=cancel`.
     *
     * @return array{id: string, customer: string, status: string, trial_end: int}
     */
    public function createTrialSubscription(Team $team): array;

    /**
     * @deprecated Per-seat quantity syncing was removed in RUB-529.
     */
    public function updateSeats(string $subscriptionId, int $seatCount): void;

    public function cancelSubscription(string $subscriptionId): void;

    /**
     * Immediately cancels a subscription (trial replaced by paid checkout).
     */
    public function deleteSubscription(string $subscriptionId): void;

    /**
     * Latest Stripe subscription snapshot, or null when Stripe reports it gone.
     *
     * @return array{id: string, status: string, lookup_key: string|null}|null
     */
    public function getSubscription(string $subscriptionId): ?array;

    public function resumeSubscription(string $subscriptionId): void;

    /**
     * @return list<array{number: string|null, amount: int, currency: string, status: string|null, date: int, url: string|null}>
     */
    public function listInvoices(Team $team): array;

    public function createPortalSession(Team $team): string;
}
