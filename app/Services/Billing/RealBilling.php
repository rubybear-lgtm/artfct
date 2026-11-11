<?php

namespace App\Services\Billing;

use App\Enums\BillingPrice;
use App\Enums\Plan;
use App\Models\Team;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Stripe over its REST API. Prices are resolved by lookup_key (RUB-529).
 * Subscription quantity is always 1 — no per-seat syncing.
 */
final class RealBilling implements BillingContract
{
    private const BASE = 'https://api.stripe.com/v1';

    /** @var array<string, string> lookup_key => price id */
    private array $priceIdCache = [];

    public function createCheckoutSession(Team $team, BillingPrice $price): string
    {
        $priceId = $this->priceIdFor($price);
        $customer = $this->customerFor($team);

        $response = $this->http()->withHeaders([
            'Idempotency-Key' => 'artfct-checkout-'.$team->id.'-'.$price->value.'-'.intdiv(now()->timestamp, 600),
        ])->asForm()->post(self::BASE.'/checkout/sessions', [
            'mode' => 'subscription',
            'payment_method_types' => ['card'],
            'client_reference_id' => (string) $team->id,
            'customer' => $customer,
            'line_items' => [['price' => $priceId, 'quantity' => 1]],
            'subscription_data' => [
                'metadata' => [
                    'team_id' => (string) $team->id,
                    'plan' => $price->plan()->value,
                    'interval' => $price->interval(),
                    'lookup_key' => $price->value,
                ],
            ],
            'metadata' => [
                'team_id' => (string) $team->id,
                'plan' => $price->plan()->value,
                'interval' => $price->interval(),
                'lookup_key' => $price->value,
            ],
            'success_url' => route('teams.billing.show', ['team' => $team->slug]).'?checkout=success',
            'cancel_url' => route('teams.billing.show', ['team' => $team->slug]).'?checkout=cancelled',
        ])->throw();

        return (string) $response->json('url');
    }

    public function createTrialSubscription(Team $team): array
    {
        $price = BillingPrice::TeamMonthly;
        $priceId = $this->priceIdFor($price);
        $customer = $this->customerFor($team);
        $trialDays = (int) config('billing.trial_days', 90);

        $response = $this->http()
            ->withHeaders(['Idempotency-Key' => 'artfct-trial-'.$team->id])
            ->asForm()
            ->post(self::BASE.'/subscriptions', [
                'customer' => $customer,
                'items' => [['price' => $priceId, 'quantity' => 1]],
                'trial_period_days' => $trialDays,
                'trial_settings' => [
                    'end_behavior' => [
                        'missing_payment_method' => 'cancel',
                    ],
                ],
                'metadata' => [
                    'team_id' => (string) $team->id,
                    'team_slug' => $team->slug,
                    'plan' => Plan::Team->value,
                    'lookup_key' => $price->value,
                    'trial' => 'true',
                ],
            ])
            ->throw();

        $id = $response->json('id');
        $status = $response->json('status');
        $trialEnd = $response->json('trial_end');

        if (! is_string($id) || ! is_string($status) || ! is_int($trialEnd)) {
            throw new RuntimeException('Stripe trial subscription response was missing id, status, or trial_end.');
        }

        return [
            'id' => $id,
            'customer' => $customer,
            'status' => $status,
            'trial_end' => $trialEnd,
        ];
    }

    public function updateSeats(string $subscriptionId, int $seatCount): void
    {
        // Flat pricing: quantity stays 1. Intentionally a no-op.
    }

    public function cancelSubscription(string $subscriptionId): void
    {
        $this->http()->asForm()->post(self::BASE."/subscriptions/{$subscriptionId}", ['cancel_at_period_end' => 'true'])->throw();
    }

    public function deleteSubscription(string $subscriptionId): void
    {
        $this->http()->delete(self::BASE."/subscriptions/{$subscriptionId}")->throw();
    }

    public function resumeSubscription(string $subscriptionId): void
    {
        $this->http()->asForm()->post(self::BASE."/subscriptions/{$subscriptionId}", ['cancel_at_period_end' => 'false'])->throw();
    }

    public function listInvoices(Team $team): array
    {
        if (! $team->stripe_customer_id) {
            return [];
        }

        /** @var array<int, array<string, mixed>> $invoices */
        $invoices = $this->http()->get(self::BASE.'/invoices', ['customer' => $team->stripe_customer_id, 'limit' => 12])->throw()->json('data', []);

        return collect($invoices)
            ->map(fn (array $invoice): array => [
                'number' => $invoice['number'] ?? null,
                'amount' => (int) ($invoice['amount_paid'] ?? $invoice['total'] ?? 0),
                'currency' => (string) ($invoice['currency'] ?? 'usd'),
                'status' => $invoice['status'] ?? null,
                'date' => (int) ($invoice['created'] ?? 0),
                'url' => $invoice['hosted_invoice_url'] ?? null,
            ])->values()->all();
    }

    public function createPortalSession(Team $team): string
    {
        return (string) $this->http()->asForm()->post(self::BASE.'/billing_portal/sessions', [
            'customer' => $this->customerFor($team),
            'return_url' => route('teams.billing.show', ['team' => $team->slug]),
        ])->throw()->json('url');
    }

    private function priceIdFor(BillingPrice $price): string
    {
        if (isset($this->priceIdCache[$price->value])) {
            return $this->priceIdCache[$price->value];
        }

        $response = $this->http()->get(self::BASE.'/prices', [
            'lookup_keys' => [$price->value],
            'active' => 'true',
            'limit' => 1,
        ])->throw();

        $id = $response->json('data.0.id');
        if (! is_string($id) || $id === '') {
            throw new RuntimeException("No active Stripe price found for lookup_key [{$price->value}].");
        }

        return $this->priceIdCache[$price->value] = $id;
    }

    private function customerFor(Team $team): string
    {
        if ($team->stripe_customer_id) {
            return $team->stripe_customer_id;
        }

        $email = $team->billing_email ?? $team->owner?->email;

        $parameters = array_filter([
            'email' => $email,
            'name' => $team->name,
            'metadata' => ['team_id' => (string) $team->id, 'team_slug' => $team->slug],
        ]);

        $id = (string) $this->http()->withHeaders(['Idempotency-Key' => 'artfct-customer-'.$team->id.'-'.substr(sha1((string) json_encode($parameters)), 0, 16)])
            ->asForm()->post(self::BASE.'/customers', $parameters)->throw()->json('id');

        $team->forceFill(['stripe_customer_id' => $id])->save();

        return $id;
    }

    private function http(): PendingRequest
    {
        $secret = config('services.stripe.secret');

        if (! $secret) {
            throw new RuntimeException('services.stripe.secret must be configured to bill teams.');
        }

        if (app()->environment('production') && preg_match('/^(sk|rk)_test_/', (string) $secret) === 1) {
            throw new RuntimeException('A Stripe test-mode key is configured in production; refusing to bill with it.');
        }

        return Http::withToken($secret)->acceptJson()->timeout(30);
    }
}
