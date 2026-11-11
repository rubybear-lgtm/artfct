<?php

use App\Enums\BillingPrice;
use App\Models\Team;
use App\Models\User;
use App\Services\Billing\RealBilling;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config(['services.stripe.secret' => 'sk_test_x']);
});

test('checkout_session_resolves_lookup_key_and_uses_quantity_one', function () {
    Http::fake([
        'api.stripe.com/v1/prices*' => Http::response([
            'data' => [['id' => 'price_team', 'lookup_key' => 'artfct_team_monthly']],
        ]),
        'api.stripe.com/v1/customers' => Http::response(['id' => 'cus_new']),
        'api.stripe.com/v1/checkout/sessions' => Http::response(['url' => 'https://checkout.stripe.test/s']),
    ]);
    $owner = User::factory()->create(['email' => 'captain@example.com']);
    $team = Team::factory()->create(['owner_user_id' => $owner->id]);

    $url = (new RealBilling)->createCheckoutSession($team, BillingPrice::TeamMonthly);

    expect($url)->toBe('https://checkout.stripe.test/s')->and($team->fresh()->stripe_customer_id)->toBe('cus_new');
    Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/checkout/sessions')
        && $request->hasHeader('Authorization', 'Bearer sk_test_x')
        && $request['client_reference_id'] === (string) $team->id
        && $request['line_items'][0]['quantity'] === 1
        && $request['line_items'][0]['price'] === 'price_team'
        && $request['customer'] === 'cus_new'
        && $request['metadata']['lookup_key'] === 'artfct_team_monthly');
});

test('customer_is_created_once_and_reused', function () {
    Http::fake([
        'api.stripe.com/v1/prices*' => Http::response(['data' => [['id' => 'price_x']]]),
        'api.stripe.com/v1/checkout/sessions' => Http::response(['url' => 'https://c.test']),
    ]);
    $team = Team::factory()->create(['stripe_customer_id' => 'cus_existing']);

    (new RealBilling)->createCheckoutSession($team, BillingPrice::ProMonthly);

    Http::assertNotSent(fn (Request $request) => str_ends_with($request->url(), '/customers'));
});

test('cancel_schedules_cancellation_at_period_end', function () {
    Http::fake(['api.stripe.com/v1/subscriptions/sub_2' => Http::response(['id' => 'sub_2'])]);

    (new RealBilling)->cancelSubscription('sub_2');

    Http::assertSent(fn (Request $request) => $request['cancel_at_period_end'] === 'true');
});

test('missing_configuration_fails_closed', function () {
    config(['services.stripe.secret' => null]);
    Http::fake();

    expect(fn () => (new RealBilling)->cancelSubscription('sub_2'))->toThrow(RuntimeException::class);
    Http::assertNothingSent();
});

test('stripe_error_surfaces_as_an_exception_not_a_silent_success', function () {
    Http::fake(['api.stripe.com/*' => Http::response(['error' => ['message' => 'nope']], 402)]);
    $team = Team::factory()->create(['stripe_customer_id' => 'cus_1']);

    expect(fn () => (new RealBilling)->createCheckoutSession($team, BillingPrice::TeamMonthly))->toThrow(RequestException::class);
});

test('portal_session_returns_to_the_billing_page_for_the_teams_customer', function () {
    config(['services.stripe.secret' => 'sk_test_x']);
    Http::fake(['api.stripe.com/v1/billing_portal/sessions' => Http::response(['url' => 'https://billing.stripe.test/p'])]);
    $team = Team::factory()->create(['stripe_customer_id' => 'cus_1']);

    expect((new RealBilling)->createPortalSession($team))->toBe('https://billing.stripe.test/p');

    Http::assertSent(fn (Request $request) => $request['customer'] === 'cus_1'
        && $request['return_url'] === route('teams.billing.show', ['team' => $team->slug]));
});

test('checkout and customer creation carry idempotency keys and allow cards only', function () {
    Http::fake([
        'api.stripe.com/v1/prices*' => Http::response(['data' => [['id' => 'price_k']]]),
        'api.stripe.com/v1/customers' => Http::response(['id' => 'cus_key']),
        'api.stripe.com/v1/checkout/sessions' => Http::response(['url' => 'https://checkout.stripe.test/k']),
    ]);
    $team = Team::factory()->create(['owner_user_id' => User::factory()->create()->id]);

    (new RealBilling)->createCheckoutSession($team, BillingPrice::TeamMonthly);

    Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/customers')
        && str_starts_with($request->header('Idempotency-Key')[0] ?? '', 'artfct-customer-'.$team->id.'-'));
    Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/checkout/sessions')
        && str_starts_with($request->header('Idempotency-Key')[0] ?? '', 'artfct-checkout-'.$team->id.'-artfct_team_monthly-')
        && $request['payment_method_types'] === ['card']);
});

test('a test mode key is refused in production and allowed elsewhere', function () {
    config(['services.stripe.secret' => 'sk_test_x']);
    Http::fake([
        'api.stripe.com/v1/prices*' => Http::response(['data' => [['id' => 'price_env']]]),
        'api.stripe.com/v1/checkout/sessions' => Http::response(['url' => 'https://c.test']),
    ]);
    $team = Team::factory()->create(['stripe_customer_id' => 'cus_env']);

    expect((new RealBilling)->createCheckoutSession($team, BillingPrice::ProMonthly))->toBe('https://c.test');

    app()->detectEnvironment(fn (): string => 'production');

    expect(fn () => (new RealBilling)->createCheckoutSession($team, BillingPrice::ProMonthly))->toThrow(RuntimeException::class, 'test-mode key');
});
