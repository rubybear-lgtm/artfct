<?php

namespace App\Services\Billing;

use App\Enums\BillingPrice;
use App\Models\Team;
use Illuminate\Support\Str;

final class FakeBilling implements BillingContract
{
    /** @var array<string, array{team_slug: string, seat_count: int, cancelled: bool, status?: string, trial_period_days?: int, trial_end?: int, lookup_key?: string}> */
    public array $subscriptions = [];

    /** @var array<string, array{team_slug: string, lookup_key: string}> */
    public array $checkoutSessions = [];

    /** @var list<array{subscription_id: string, amount: int}> */
    public array $charges = [];

    /** @var list<string> lookup keys resolved during this process */
    public array $resolvedLookupKeys = [];

    public function createCheckoutSession(Team $team, BillingPrice $price): string
    {
        $sessionId = 'cs_test_'.Str::random(16);
        $this->checkoutSessions[$sessionId] = [
            'team_slug' => $team->slug,
            'lookup_key' => $price->value,
        ];
        $this->resolvedLookupKeys[] = $price->value;

        return "https://checkout.stripe.test/pay/{$sessionId}";
    }

    /**
     * Test helper: simulates Stripe's `checkout.session.completed`.
     */
    public function completeCheckout(Team $team, BillingPrice $price = BillingPrice::TeamMonthly): string
    {
        $subscriptionId = 'sub_test_'.Str::random(16);
        $this->subscriptions[$subscriptionId] = [
            'team_slug' => $team->slug,
            'seat_count' => 1,
            'cancelled' => false,
            'status' => 'active',
            'lookup_key' => $price->value,
        ];

        return $subscriptionId;
    }

    public function createTrialSubscription(Team $team): array
    {
        $customer = $team->stripe_customer_id ?? 'cus_test_'.Str::random(10);
        if ($team->stripe_customer_id === null) {
            $team->forceFill(['stripe_customer_id' => $customer])->save();
        }

        $subscriptionId = 'sub_trial_'.Str::random(16);
        $trialDays = (int) config('billing.trial_days', 90);
        $trialEnd = now()->addDays($trialDays)->timestamp;
        $lookupKey = BillingPrice::TeamMonthly->value;

        $this->subscriptions[$subscriptionId] = [
            'team_slug' => $team->slug,
            'seat_count' => 1,
            'cancelled' => false,
            'status' => 'trialing',
            'trial_period_days' => $trialDays,
            'trial_end' => $trialEnd,
            'lookup_key' => $lookupKey,
        ];
        $this->resolvedLookupKeys[] = $lookupKey;

        return [
            'id' => $subscriptionId,
            'customer' => $customer,
            'status' => 'trialing',
            'trial_end' => $trialEnd,
        ];
    }

    public function updateSeats(string $subscriptionId, int $seatCount): void
    {
        // No-op: flat pricing, quantity is always 1.
    }

    public function cancelSubscription(string $subscriptionId): void
    {
        if (isset($this->subscriptions[$subscriptionId])) {
            $this->subscriptions[$subscriptionId]['cancelled'] = true;
        }
    }

    public function deleteSubscription(string $subscriptionId): void
    {
        if (isset($this->subscriptions[$subscriptionId])) {
            $this->subscriptions[$subscriptionId]['cancelled'] = true;
            $this->subscriptions[$subscriptionId]['status'] = 'canceled';
        }
    }

    public function getSubscription(string $subscriptionId): ?array
    {
        if (! isset($this->subscriptions[$subscriptionId])) {
            return null;
        }

        $subscription = $this->subscriptions[$subscriptionId];

        return [
            'id' => $subscriptionId,
            'status' => (string) ($subscription['status'] ?? ($subscription['cancelled'] ? 'canceled' : 'active')),
            'lookup_key' => isset($subscription['lookup_key']) ? (string) $subscription['lookup_key'] : null,
        ];
    }

    /**
     * Test helper: Stripe converted a trial to paid after a card was added.
     */
    public function convertTrialToActive(string $subscriptionId): void
    {
        if (! isset($this->subscriptions[$subscriptionId])) {
            return;
        }

        $this->subscriptions[$subscriptionId]['status'] = 'active';
        unset($this->subscriptions[$subscriptionId]['trial_end']);
    }

    public function resumeSubscription(string $subscriptionId): void
    {
        if (isset($this->subscriptions[$subscriptionId])) {
            $this->subscriptions[$subscriptionId]['cancelled'] = false;
        }
    }

    /** @var list<array{number: string|null, amount: int, currency: string, status: string|null, date: int, url: string|null}> */
    public array $invoices = [];

    public function listInvoices(Team $team): array
    {
        return $this->invoices;
    }

    public function createPortalSession(Team $team): string
    {
        return "https://billing.stripe.test/portal/{$team->slug}";
    }
}
