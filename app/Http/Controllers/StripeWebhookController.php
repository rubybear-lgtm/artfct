<?php

namespace App\Http\Controllers;

use App\Models\Team;
use App\Services\Billing\BillingService;
use App\Services\Billing\StripeSignature;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * `POST /webhooks/stripe`. Verifies the signature over the raw body, records
 * the event id (a replay is a no-op), then applies the payment state to the
 * team it belongs to. Payment state only ever arrives here, never from the
 * checkout redirect.
 */
class StripeWebhookController extends Controller
{
    public function __invoke(Request $request, BillingService $billing): JsonResponse
    {
        $payload = $request->getContent();

        if (! StripeSignature::verify($request->header('Stripe-Signature'), $payload, config('services.stripe.webhook_secret'), now()->timestamp)) {
            return response()->json(['error' => 'invalid_signature'], 400);
        }

        $event = json_decode($payload, true);

        if (! is_array($event) || ! isset($event['id'], $event['type'])) {
            return response()->json(['error' => 'malformed'], 400);
        }

        try {
            DB::table('stripe_events_received')->insert(['event_id' => $event['id'], 'type' => $event['type'], 'received_at' => now()]);
        } catch (UniqueConstraintViolationException) {
            return response()->json(['status' => 'duplicate']);
        }

        $object = $event['data']['object'] ?? [];

        match ($event['type']) {
            'checkout.session.completed' => $this->checkoutCompleted($billing, $object),
            'invoice.payment_failed' => $this->forCustomer($object, fn (Team $team) => $billing->applyPaymentFailed($team)),
            'invoice.payment_succeeded' => $this->forCustomer($object, fn (Team $team) => $billing->applyPaymentSucceeded($team)),
            'customer.subscription.deleted' => $this->forCustomer($object, fn (Team $team) => $billing->applySubscriptionEnded($team)),
            default => Log::info('Ignoring Stripe event type.', ['type' => $event['type']]),
        };

        return response()->json(['status' => 'ok']);
    }

    /**
     * @param  array<string, mixed>  $session
     */
    private function checkoutCompleted(BillingService $billing, array $session): void
    {
        $team = Team::query()->find($session['client_reference_id'] ?? null);

        if ($team === null || ! isset($session['subscription'])) {
            Log::warning('Stripe checkout completed for an unknown team.', ['reference' => $session['client_reference_id'] ?? null]);

            return;
        }

        if (isset($session['customer']) && $team->stripe_customer_id !== $session['customer']) {
            $team->forceFill(['stripe_customer_id' => $session['customer']])->save();
        }

        $billing->applyCheckoutCompleted($team, (string) $session['subscription']);
    }

    /**
     * @param  array<string, mixed>  $object
     * @param  callable(Team): void  $apply
     */
    private function forCustomer(array $object, callable $apply): void
    {
        $team = Team::query()->where('stripe_customer_id', $object['customer'] ?? '')->first();

        if ($team === null) {
            Log::warning('Stripe event for an unknown customer.', ['customer' => $object['customer'] ?? null]);

            return;
        }

        $apply($team);
    }
}
