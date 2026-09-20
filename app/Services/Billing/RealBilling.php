<?php

namespace App\Services\Billing;

use App\Models\Team;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Stripe (test mode on staging) over its REST API. No SDK: three calls are
 * all billing needs, and the form-encoded API is simple to fake in tests.
 */
final class RealBilling implements BillingContract
{
    private const BASE = 'https://api.stripe.com/v1';

    public function createCheckoutSession(Team $team, int $seatCount): string
    {
        $priceId = config('services.stripe.team_price_id');

        if (! $priceId) {
            throw new RuntimeException('services.stripe.team_price_id must be configured to start checkout.');
        }

        $response = $this->http()->asForm()->post(self::BASE.'/checkout/sessions', [
            'mode' => 'subscription',
            'client_reference_id' => (string) $team->id,
            'customer' => $this->customerFor($team),
            'line_items' => [['price' => $priceId, 'quantity' => max(1, $seatCount)]],
            'subscription_data' => ['metadata' => ['team_id' => (string) $team->id]],
            'success_url' => route('teams.billing.show', ['team' => $team->slug]).'?checkout=success',
            'cancel_url' => route('teams.billing.show', ['team' => $team->slug]).'?checkout=cancelled',
        ])->throw();

        return (string) $response->json('url');
    }

    public function updateSeats(string $subscriptionId, int $seatCount): void
    {
        $item = $this->http()->get(self::BASE."/subscriptions/{$subscriptionId}")->throw()->json('items.data.0.id');

        if (! is_string($item)) {
            throw new RuntimeException("Subscription {$subscriptionId} has no item to update.");
        }

        $this->http()->asForm()->post(self::BASE."/subscription_items/{$item}", ['quantity' => max(1, $seatCount)])->throw();
    }

    public function cancelSubscription(string $subscriptionId): void
    {
        $this->http()->asForm()->post(self::BASE."/subscriptions/{$subscriptionId}", ['cancel_at_period_end' => 'true'])->throw();
    }

    public function createPortalSession(Team $team): string
    {
        return (string) $this->http()->asForm()->post(self::BASE.'/billing_portal/sessions', [
            'customer' => $this->customerFor($team),
            'return_url' => route('teams.billing.show', ['team' => $team->slug]),
        ])->throw()->json('url');
    }

    /**
     * The team's Stripe customer, created once and reused.
     */
    private function customerFor(Team $team): string
    {
        if ($team->stripe_customer_id) {
            return $team->stripe_customer_id;
        }

        $email = $team->billing_email ?? $team->owner?->email;

        $id = (string) $this->http()->asForm()->post(self::BASE.'/customers', array_filter([
            'email' => $email,
            'name' => $team->name,
            'metadata' => ['team_id' => (string) $team->id, 'team_slug' => $team->slug],
        ]))->throw()->json('id');

        $team->forceFill(['stripe_customer_id' => $id])->save();

        return $id;
    }

    private function http(): PendingRequest
    {
        $secret = config('services.stripe.secret');

        if (! $secret) {
            throw new RuntimeException('services.stripe.secret must be configured to bill teams.');
        }

        return Http::withToken($secret)->acceptJson()->timeout(30);
    }
}
