<?php

namespace App\Http\Controllers;

use App\Models\Team;
use App\Services\Billing\BillingService;
use App\Services\Billing\StripeSignature;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
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

        // An event from the other Stripe mode is never ours to apply: a test-mode
        // event must not upgrade a team in production, and the reverse.
        if (isset($event['livemode']) && (bool) $event['livemode'] !== $this->liveKeyConfigured()) {
            Log::warning('Ignoring a Stripe event from the other mode.', ['type' => $event['type'], 'livemode' => (bool) $event['livemode']]);

            return response()->json(['status' => 'ignored']);
        }

        try {
            // The idempotency row and the state it guards commit together. The row
            // used to be written and committed first, so a failure partway through
            // applying state left the event marked as received: Stripe's retry was
            // then answered `duplicate` and the state was never applied, with
            // nothing recording that it had been dropped (RUB-373). Inside one
            // transaction a mid-apply failure rolls the row back, so the retry
            // processes it for real.
            DB::transaction(function () use ($billing, $event): void {
                DB::table('stripe_events_received')->insert([
                    'event_id' => $event['id'],
                    'type' => $event['type'],
                    'received_at' => now(),
                ]);

                $this->apply($billing, $event);
            });
        } catch (UniqueConstraintViolationException $exception) {
            // Only the event-id constraint means "already processed". Any other
            // unique violation is a real failure, and answering it as a duplicate
            // would reintroduce the same silent drop through a different door.
            if (! str_contains($exception->getMessage(), 'stripe_events_received')) {
                throw $exception;
            }

            return response()->json(['status' => 'duplicate']);
        }

        return response()->json(['status' => 'ok']);
    }

    /**
     * @param  array<string, mixed>  $event
     */
    private function apply(BillingService $billing, array $event): void
    {
        $object = $event['data']['object'] ?? [];
        $created = (int) ($event['created'] ?? now()->timestamp);

        match ($event['type']) {
            'checkout.session.completed' => $this->checkoutCompleted($billing, $object, $created),
            'invoice.payment_failed' => $this->forCustomer($object, $created, fn (Team $team) => $billing->applyPaymentFailed($team)),
            'invoice.payment_succeeded' => $this->forCustomer($object, $created, fn (Team $team) => $billing->applyPaymentSucceeded($team)),
            'customer.subscription.deleted' => $this->forCustomer($object, $created, fn (Team $team) => $billing->applySubscriptionEnded($team)),
            'customer.subscription.updated' => $this->forCustomer($object, $created, fn (Team $team) => $billing->applySubscriptionUpdated(
                $team,
                (bool) ($object['cancel_at_period_end'] ?? false),
                isset($object['current_period_end']) ? (int) $object['current_period_end'] : ($object['items']['data'][0]['current_period_end'] ?? null),
                isset($object['status']) ? (string) $object['status'] : null,
            )),
            default => Log::info('Ignoring Stripe event type.', ['type' => $event['type']]),
        };
    }

    /**
     * @param  array<string, mixed>  $session
     */
    private function checkoutCompleted(BillingService $billing, array $session, int $created): void
    {
        $team = Team::query()->find($session['client_reference_id'] ?? null);

        if ($team === null || ! isset($session['subscription'])) {
            Log::warning('Stripe checkout completed for an unknown team.', ['reference' => $session['client_reference_id'] ?? null]);

            return;
        }

        // A completed session is not a paid one: an asynchronous payment method
        // can complete a session whose money has not arrived and may never.
        if (! in_array($session['payment_status'] ?? null, ['paid', 'no_payment_required'], true)) {
            Log::warning('Stripe checkout completed without payment; the plan is not changed.', ['payment_status' => $session['payment_status'] ?? null]);

            return;
        }

        $this->applyToTeam($team, $created, function (Team $team) use ($billing, $session): void {
            if (isset($session['customer']) && $team->stripe_customer_id !== $session['customer']) {
                $team->forceFill(['stripe_customer_id' => $session['customer']])->save();
            }

            $billing->applyCheckoutCompleted($team, (string) $session['subscription']);
        });
    }

    /**
     * @param  array<string, mixed>  $object
     * @param  callable(Team): void  $apply
     */
    private function forCustomer(array $object, int $created, callable $apply): void
    {
        $team = Team::query()->where('stripe_customer_id', $object['customer'] ?? '')->first();

        if ($team === null) {
            Log::warning('Stripe event for an unknown customer.', ['customer' => $object['customer'] ?? null]);

            return;
        }

        $this->applyToTeam($team, $created, $apply);
    }

    /**
     * Applies an event to a team under a row lock, and only if it is not older
     * than the last event applied to that team. Stripe retries failed deliveries
     * for days, so a stale event can arrive after a newer one; applied blindly it
     * would put a cancelled team back on the paid plan, or a paying one back to
     * read-only. Events with the same timestamp are applied.
     *
     * @param  callable(Team): void  $apply
     */
    private function applyToTeam(Team $team, int $created, callable $apply): void
    {
        $locked = Team::query()->whereKey($team->id)->lockForUpdate()->first();

        if ($locked === null) {
            return;
        }

        if ($locked->stripe_event_at !== null && $created < $locked->stripe_event_at->timestamp) {
            Log::info('Ignoring a Stripe event older than the last one applied.', ['team' => $locked->id, 'created' => $created]);

            return;
        }

        $apply($locked);

        $locked->forceFill(['stripe_event_at' => Carbon::createFromTimestamp($created)])->save();
    }

    private function liveKeyConfigured(): bool
    {
        $key = (string) config('services.stripe.secret');

        return str_starts_with($key, 'sk_live_') || str_starts_with($key, 'rk_live_');
    }
}
