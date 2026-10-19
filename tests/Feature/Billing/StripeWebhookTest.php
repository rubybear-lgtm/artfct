<?php

use App\Enums\PaymentStatus;
use App\Enums\Plan;
use App\Enums\TeamRole;
use App\Models\Team;
use App\Services\Billing\QuotaLimits;
use App\Services\Billing\StripeSignature;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;

beforeEach(function () {
    config(['services.stripe.webhook_secret' => 'whsec_test', 'services.worker.base_url' => 'https://worker.test', 'services.worker.limits_write_secret' => 's']);
    Http::fake(['worker.test/*' => Http::response(['ok' => true])]);
});

function stripeEvent(string $type, array $object, ?string $id = null, ?string $secret = 'whsec_test', ?int $timestamp = null, ?int $created = null, ?bool $livemode = null): TestResponse
{
    $body = json_encode(array_filter([
        'id' => $id ?? 'evt_'.uniqid(),
        'type' => $type,
        'created' => $created,
        'livemode' => $livemode,
        'data' => ['object' => $object],
    ], fn ($value): bool => $value !== null));
    $timestamp ??= time();

    return test()->call('POST', '/webhooks/stripe', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_STRIPE_SIGNATURE' => $secret === null ? '' : StripeSignature::header($body, $secret, $timestamp),
    ], $body);
}

test('checkout_completed_upgrades_the_team_and_pushes_limits', function () {
    $team = Team::factory()->create();
    memberOfTeam($team, TeamRole::Admin);

    stripeEvent('checkout.session.completed', ['client_reference_id' => (string) $team->id, 'subscription' => 'sub_1', 'customer' => 'cus_1', 'payment_status' => 'paid'])->assertOk();

    $team->refresh();
    expect($team->plan)->toBe(Plan::Team)
        ->and($team->stripe_subscription_id)->toBe('sub_1')
        ->and($team->stripe_customer_id)->toBe('cus_1')
        ->and($team->payment_status)->toBe(PaymentStatus::Active);
    Http::assertSent(fn ($request) => str_contains($request->url(), '/v1/internal/org-limits')
        && $request['storage_bytes'] === config('billing.plans.team.storage_bytes'));
});

test('bad_signature_is_rejected_and_changes_nothing', function () {
    $team = Team::factory()->create();

    stripeEvent('checkout.session.completed', ['client_reference_id' => (string) $team->id, 'subscription' => 'sub_1'], secret: 'whsec_wrong')->assertStatus(400);

    expect($team->fresh()->stripe_subscription_id)->toBeNull()
        ->and(DB::table('stripe_events_received')->count())->toBe(0);
});

test('replayed_event_is_a_no_op', function () {
    $team = Team::factory()->create();
    $object = ['client_reference_id' => (string) $team->id, 'subscription' => 'sub_1', 'customer' => 'cus_1', 'payment_status' => 'paid'];

    stripeEvent('checkout.session.completed', $object, 'evt_same')->assertOk();
    $team->forceFill(['plan' => Plan::Free])->save();
    stripeEvent('checkout.session.completed', $object, 'evt_same')->assertOk()->assertJson(['status' => 'duplicate']);

    expect($team->fresh()->plan)->toBe(Plan::Free)
        ->and(DB::table('stripe_events_received')->count())->toBe(1);
});

test('payment_failed_then_succeeded_toggles_past_due', function () {
    $team = Team::factory()->create(['plan' => Plan::Team, 'stripe_customer_id' => 'cus_9']);

    stripeEvent('invoice.payment_failed', ['customer' => 'cus_9'])->assertOk();
    expect($team->fresh()->payment_status)->toBe(PaymentStatus::PastDue);

    stripeEvent('invoice.payment_succeeded', ['customer' => 'cus_9'])->assertOk();
    expect($team->fresh()->payment_status)->toBe(PaymentStatus::Active);
});

test('subscription_deleted_drops_the_team_to_free', function () {
    $team = Team::factory()->create(['plan' => Plan::Team, 'stripe_customer_id' => 'cus_3', 'stripe_subscription_id' => 'sub_3']);

    stripeEvent('customer.subscription.deleted', ['customer' => 'cus_3'])->assertOk();

    expect($team->fresh()->plan)->toBe(Plan::Free)->and($team->fresh()->stripe_subscription_id)->toBeNull();
});

test('unknown_customer_and_unknown_type_are_ignored_not_errors', function () {
    stripeEvent('invoice.payment_failed', ['customer' => 'cus_none'])->assertOk();
    stripeEvent('charge.dispute.created', [])->assertOk();
});

test('unset_webhook_secret_fails_closed', function () {
    config(['services.stripe.webhook_secret' => null]);

    stripeEvent('invoice.payment_failed', ['customer' => 'cus_1'], secret: 'anything')->assertStatus(400);
});

test('plan_limits_differ_by_plan', function () {
    $free = QuotaLimits::forPlan(Plan::Free);
    $team = QuotaLimits::forPlan(Plan::Team);

    expect($free->storageBytes)->toBeLessThan($team->storageBytes)
        ->and($free->artifactsPerMonth)->toBeLessThan($team->artifactsPerMonth);
});

test('the_subscription_updated_webhook_records_renewal_and_scheduled_cancel', function () {
    $team = Team::factory()->create(['plan' => Plan::Team, 'stripe_subscription_id' => 'sub_1', 'stripe_customer_id' => 'cus_1']);
    $end = now()->addMonth()->timestamp;

    stripeEvent('customer.subscription.updated', ['customer' => 'cus_1', 'cancel_at_period_end' => true, 'current_period_end' => $end])->assertOk();

    $team->refresh();
    expect($team->cancel_at_period_end)->toBeTrue()->and($team->current_period_end->timestamp)->toBe($end);
});

test('a retry after a mid-apply failure is reprocessed, not swallowed', function (string $type, Closure $object) {
    $team = Team::factory()->create(['stripe_customer_id' => 'cus_retry']);
    $eventId = 'evt_retry_'.uniqid();
    $fail = true;

    // Induce a real failure partway through applying state: the model write the
    // handler performs throws. BillingService is final so it cannot be faked, and
    // a faked service would not prove the transaction covers the work it actually
    // does. Note the Worker push swallows its own failures, so the state write is
    // the failure that matters here.
    Team::saving(function (Team $saving) use (&$fail, $team) {
        if ($fail && $saving->is($team)) {
            throw new RuntimeException('induced mid-apply failure');
        }
    });

    // A 500 is the correct answer to Stripe: it is what makes it retry.
    stripeEvent($type, $object($team), $eventId);

    expect(DB::table('stripe_events_received')->where('event_id', $eventId)->count())
        ->toBe(0, 'a failed apply must not leave the event marked received');

    // The retry Stripe sends must therefore process the event for real. The row
    // appearing is itself the proof that the whole apply completed: it only
    // commits after the apply, in the same transaction.
    $fail = false;
    stripeEvent($type, $object($team), $eventId)->assertOk();

    expect(DB::table('stripe_events_received')->where('event_id', $eventId)->count())
        ->toBe(1, 'the retried event must be recorded once it has actually applied')
        ->and($team->refresh()->payment_status)->not->toBeNull();
})->with([
    'invoice.payment_succeeded' => ['invoice.payment_succeeded', fn (Team $t) => ['customer' => $t->stripe_customer_id]],
    'invoice.payment_failed' => ['invoice.payment_failed', fn (Team $t) => ['customer' => $t->stripe_customer_id]],
    'checkout.session.completed' => ['checkout.session.completed', fn (Team $t) => [
        'client_reference_id' => $t->id,
        'subscription' => 'sub_retry',
        'customer' => $t->stripe_customer_id,
        'payment_status' => 'paid',
    ]],
]);

test('a stale event retried after a newer one does not undo it', function () {
    $team = Team::factory()->create(['plan' => Plan::Team, 'stripe_customer_id' => 'cus_order', 'stripe_subscription_id' => 'sub_order']);
    $checkout = ['client_reference_id' => (string) $team->id, 'subscription' => 'sub_order', 'customer' => 'cus_order', 'payment_status' => 'paid'];

    stripeEvent('customer.subscription.deleted', ['customer' => 'cus_order'], created: 2000)->assertOk();
    expect($team->fresh()->plan)->toBe(Plan::Free);

    stripeEvent('checkout.session.completed', $checkout, created: 1000)->assertOk();

    expect($team->fresh()->plan)->toBe(Plan::Free)
        ->and($team->fresh()->stripe_subscription_id)->toBeNull();
});

test('a stale payment failure retried after a success does not leave a paying team read-only', function () {
    $team = Team::factory()->create(['plan' => Plan::Team, 'stripe_customer_id' => 'cus_pay']);

    stripeEvent('invoice.payment_succeeded', ['customer' => 'cus_pay'], created: 2000)->assertOk();
    stripeEvent('invoice.payment_failed', ['customer' => 'cus_pay'], created: 1000)->assertOk();

    expect($team->fresh()->payment_status)->toBe(PaymentStatus::Active);
});

test('events with the same timestamp are both applied', function () {
    $team = Team::factory()->create(['plan' => Plan::Team, 'stripe_customer_id' => 'cus_same']);

    stripeEvent('invoice.payment_failed', ['customer' => 'cus_same'], created: 3000)->assertOk();
    stripeEvent('invoice.payment_succeeded', ['customer' => 'cus_same'], created: 3000)->assertOk();

    expect($team->fresh()->payment_status)->toBe(PaymentStatus::Active);
});

test('a checkout session that is not paid does not upgrade the team', function (?string $status) {
    $team = Team::factory()->create();
    $session = ['client_reference_id' => (string) $team->id, 'subscription' => 'sub_unpaid', 'customer' => 'cus_unpaid'];

    stripeEvent('checkout.session.completed', $status === null ? $session : $session + ['payment_status' => $status])->assertOk();

    expect($team->fresh()->plan)->toBe(Plan::Free)
        ->and($team->fresh()->stripe_subscription_id)->toBeNull();
})->with([null, 'unpaid']);

test('a session with no payment due still upgrades the team', function () {
    $team = Team::factory()->create();

    stripeEvent('checkout.session.completed', ['client_reference_id' => (string) $team->id, 'subscription' => 'sub_free', 'customer' => 'cus_free', 'payment_status' => 'no_payment_required'])->assertOk();

    expect($team->fresh()->plan)->toBe(Plan::Team);
});

test('an event from the other Stripe mode is ignored and not recorded', function (string $key, bool $livemode, bool $applied) {
    config(['services.stripe.secret' => $key]);
    $team = Team::factory()->create();

    $response = stripeEvent('checkout.session.completed', ['client_reference_id' => (string) $team->id, 'subscription' => 'sub_mode', 'customer' => 'cus_mode', 'payment_status' => 'paid'], livemode: $livemode)->assertOk();

    expect($team->fresh()->plan)->toBe($applied ? Plan::Team : Plan::Free);
    $applied ? $response->assertJson(['status' => 'ok']) : $response->assertJson(['status' => 'ignored']);
})->with([
    'test event, live key' => ['sk_live_abc', false, false],
    'live event, test key' => ['sk_test_abc', true, false],
    'live event, restricted live key' => ['rk_live_abc', true, true],
    'test event, test key' => ['sk_test_abc', false, true],
]);

test('a past due subscription degrades the team even if the invoice event was lost', function () {
    $team = Team::factory()->create(['plan' => Plan::Team, 'stripe_customer_id' => 'cus_status']);

    stripeEvent('customer.subscription.updated', ['customer' => 'cus_status', 'status' => 'past_due', 'cancel_at_period_end' => false], created: 100)->assertOk();
    expect($team->fresh()->payment_status)->toBe(PaymentStatus::PastDue);

    stripeEvent('customer.subscription.updated', ['customer' => 'cus_status', 'status' => 'active', 'cancel_at_period_end' => false], created: 200)->assertOk();
    expect($team->fresh()->payment_status)->toBe(PaymentStatus::Active);
});
