<?php

namespace App\Services\Billing;

use App\Models\Team;
use Illuminate\Support\Str;

final class FakeBilling implements BillingContract
{
    /** @var array<string, array{team_slug: string, seat_count: int, cancelled: bool}> */
    public array $subscriptions = [];

    /** @var array<string, string> checkout session id => team slug, for test inspection */
    public array $checkoutSessions = [];

    public function createCheckoutSession(Team $team, int $seatCount): string
    {
        $sessionId = 'cs_test_'.Str::random(16);
        $this->checkoutSessions[$sessionId] = $team->slug;

        return "https://checkout.stripe.test/pay/{$sessionId}";
    }

    /**
     * Test helper: simulates Stripe's `checkout.session.completed`
     * webhook, which is what actually creates the subscription.
     */
    public function completeCheckout(Team $team, int $seatCount): string
    {
        $subscriptionId = 'sub_test_'.Str::random(16);
        $this->subscriptions[$subscriptionId] = [
            'team_slug' => $team->slug,
            'seat_count' => $seatCount,
            'cancelled' => false,
        ];

        return $subscriptionId;
    }

    public function updateSeats(string $subscriptionId, int $seatCount): void
    {
        if (isset($this->subscriptions[$subscriptionId])) {
            $this->subscriptions[$subscriptionId]['seat_count'] = $seatCount;
        }
    }

    public function cancelSubscription(string $subscriptionId): void
    {
        if (isset($this->subscriptions[$subscriptionId])) {
            $this->subscriptions[$subscriptionId]['cancelled'] = true;
        }
    }
}
