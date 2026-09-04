<?php

namespace App\Services\Billing;

use App\Models\Team;
use RuntimeException;

/**
 * Real Stripe billing. Fails closed — no live Stripe account/key in this
 * environment, same `RealTenantProvisioner` pattern.
 */
final class RealBilling implements BillingContract
{
    public function createCheckoutSession(Team $team, int $seatCount): string
    {
        $this->requireConfigured();
        throw new RuntimeException('RealBilling::createCheckoutSession is not implemented — no live Stripe account in this environment.');
    }

    public function updateSeats(string $subscriptionId, int $seatCount): void
    {
        $this->requireConfigured();
        throw new RuntimeException('RealBilling::updateSeats is not implemented — no live Stripe account in this environment.');
    }

    public function cancelSubscription(string $subscriptionId): void
    {
        $this->requireConfigured();
        throw new RuntimeException('RealBilling::cancelSubscription is not implemented — no live Stripe account in this environment.');
    }

    private function requireConfigured(): void
    {
        if (! config('services.stripe.secret')) {
            throw new RuntimeException('services.stripe.secret must be configured to bill teams.');
        }
    }
}
