<?php

use App\Models\Team;
use App\Models\User;
use App\Services\Billing\RealBilling;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config(['services.stripe.secret' => 'sk_test_x', 'services.stripe.team_price_id' => 'price_team']);
});

test('checkout_session_carries_team_reference_and_quantity', function () {
    Http::fake([
        'api.stripe.com/v1/customers' => Http::response(['id' => 'cus_new']),
        'api.stripe.com/v1/checkout/sessions' => Http::response(['url' => 'https://checkout.stripe.test/s']),
    ]);
    $owner = User::factory()->create(['email' => 'captain@example.com']);
    $team = Team::factory()->create(['owner_user_id' => $owner->id]);

    $url = (new RealBilling)->createCheckoutSession($team, 4);

    expect($url)->toBe('https://checkout.stripe.test/s')->and($team->fresh()->stripe_customer_id)->toBe('cus_new');
    Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/checkout/sessions')
        && $request->hasHeader('Authorization', 'Bearer sk_test_x')
        && $request['client_reference_id'] === (string) $team->id
        && $request['line_items'][0]['quantity'] === 4
        && $request['line_items'][0]['price'] === 'price_team'
        && $request['customer'] === 'cus_new');
});

test('customer_is_created_once_and_reused', function () {
    Http::fake(['api.stripe.com/v1/checkout/sessions' => Http::response(['url' => 'https://c.test'])]);
    $team = Team::factory()->create(['stripe_customer_id' => 'cus_existing']);

    (new RealBilling)->createCheckoutSession($team, 1);

    Http::assertNotSent(fn (Request $request) => str_ends_with($request->url(), '/customers'));
});

test('update_seats_changes_the_subscription_item_quantity', function () {
    Http::fake([
        'api.stripe.com/v1/subscriptions/sub_1' => Http::response(['items' => ['data' => [['id' => 'si_1']]]]),
        'api.stripe.com/v1/subscription_items/si_1' => Http::response(['id' => 'si_1']),
    ]);

    (new RealBilling)->updateSeats('sub_1', 7);

    Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/subscription_items/si_1') && $request['quantity'] === 7);
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

    expect(fn () => (new RealBilling)->createCheckoutSession($team, 1))->toThrow(RequestException::class);
});

test('portal_session_returns_to_the_billing_page_for_the_teams_customer', function () {
    config(['services.stripe.secret' => 'sk_test_x']);
    Http::fake(['api.stripe.com/v1/billing_portal/sessions' => Http::response(['url' => 'https://billing.stripe.test/p'])]);
    $team = Team::factory()->create(['stripe_customer_id' => 'cus_1']);

    expect((new RealBilling)->createPortalSession($team))->toBe('https://billing.stripe.test/p');

    Http::assertSent(fn (Request $request) => $request['customer'] === 'cus_1'
        && $request['return_url'] === route('teams.billing.show', ['team' => $team->slug]));
});
