<?php

use App\Enums\BillingPrice;
use App\Enums\Plan;
use App\Enums\TeamRole;
use App\Models\Team;
use App\Models\User;
use App\Services\Billing\BillingContract;
use App\Services\Billing\BillingService;
use App\Services\Billing\FakeBilling;
use App\Services\Billing\MemberCap;
use App\Services\Billing\PlanGate;
use App\Services\Billing\RealBilling;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    config([
        'services.stripe.secret' => 'sk_test_x',
        'services.worker.base_url' => null,
    ]);
});

test('checkout_resolves_each_lookup_key_with_quantity_one', function (BillingPrice $price) {
    Http::fake([
        'api.stripe.com/v1/prices*' => Http::response([
            'data' => [['id' => 'price_resolved_'.$price->value, 'lookup_key' => $price->value]],
        ]),
        'api.stripe.com/v1/customers' => Http::response(['id' => 'cus_flat']),
        'api.stripe.com/v1/checkout/sessions' => Http::response(['url' => 'https://checkout.stripe.test/flat']),
    ]);

    $owner = User::factory()->create();
    $team = Team::factory()->create(['owner_user_id' => $owner->id]);

    $url = (new RealBilling)->createCheckoutSession($team, $price);

    expect($url)->toBe('https://checkout.stripe.test/flat');
    Http::assertSent(fn (Request $request) => str_contains($request->url(), '/prices')
        && ($request['lookup_keys'][0] ?? null) === $price->value);
    Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/checkout/sessions')
        && $request['line_items'][0]['quantity'] === 1
        && $request['line_items'][0]['price'] === 'price_resolved_'.$price->value
        && $request['metadata']['lookup_key'] === $price->value
        && $request['metadata']['plan'] === $price->plan()->value);
})->with([
    'pro monthly' => BillingPrice::ProMonthly,
    'pro yearly' => BillingPrice::ProYearly,
    'team monthly' => BillingPrice::TeamMonthly,
    'team yearly' => BillingPrice::TeamYearly,
]);

test('trial_subscription_uses_team_monthly_lookup_key_quantity_one_and_cancel_without_card', function () {
    Http::fake([
        'api.stripe.com/v1/prices*' => Http::response([
            'data' => [['id' => 'price_team_m', 'lookup_key' => 'artfct_team_monthly']],
        ]),
        'api.stripe.com/v1/customers' => Http::response(['id' => 'cus_trial']),
        'api.stripe.com/v1/subscriptions' => Http::response([
            'id' => 'sub_trial',
            'status' => 'trialing',
            'trial_end' => now()->addDays(90)->timestamp,
        ]),
    ]);

    $team = Team::factory()->create();
    $result = (new RealBilling)->createTrialSubscription($team);

    expect($result['id'])->toBe('sub_trial')->and($result['status'])->toBe('trialing');
    Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/subscriptions')
        && $request['items'][0]['quantity'] === 1
        && $request['items'][0]['price'] === 'price_team_m'
        && (int) $request['trial_period_days'] === 90
        && $request['trial_settings']['end_behavior']['missing_payment_method'] === 'cancel');
});

test('checkout_completed_sets_pro_or_team_from_metadata_and_clears_trial', function (Plan $plan) {
    $team = Team::factory()->create([
        'plan' => Plan::Team,
        'trial_started_at' => now()->subDay(),
        'trial_ends_at' => now()->addDays(80),
        'stripe_subscription_id' => 'sub_old_trial',
    ]);

    /** @var FakeBilling $billing */
    $billing = app(BillingContract::class);
    $billing->subscriptions['sub_old_trial'] = [
        'team_slug' => $team->slug,
        'seat_count' => 1,
        'cancelled' => false,
        'status' => 'trialing',
    ];

    app(BillingService::class)->applyCheckoutCompleted($team, 'sub_paid_new', $plan);

    $team->refresh();
    expect($team->plan)->toBe($plan)
        ->and($team->trial_ends_at)->toBeNull()
        ->and($team->stripe_subscription_id)->toBe('sub_paid_new')
        ->and(PlanGate::hasTeamFeatures($team))->toBeTrue()
        ->and($billing->subscriptions['sub_old_trial']['status'])->toBe('canceled');
})->with([
    'pro' => Plan::Pro,
    'team' => Plan::Team,
]);

test('pro_has_team_features_but_cannot_add_teammates', function () {
    $team = Team::factory()->create(['plan' => Plan::Pro]);
    memberOfTeam($team, TeamRole::Admin);

    expect(PlanGate::hasTeamFeatures($team))->toBeTrue()
        ->and(MemberCap::canAddHuman($team))->toBeFalse()
        ->and(MemberCap::upgradeMessage($team))->toContain('Business');
});

test('team_member_cap_blocks_the_eleventh_human_invite', function () {
    $team = Team::factory()->create(['plan' => Plan::Team]);
    $admin = memberOfTeam($team, TeamRole::Admin);

    for ($i = 0; $i < 9; $i++) {
        memberOfTeam($team, TeamRole::Member);
    }

    expect(MemberCap::humanMemberCount($team))->toBe(10)
        ->and(MemberCap::canAddHuman($team))->toBeFalse();

    test()->actingAs($admin)->post(route('teams.invitations.store', $team), [
        'email' => 'eleventh@example.com',
        'role' => 'member',
    ])->assertSessionHasErrors('email');
});

test('bots_do_not_count_toward_the_team_member_cap', function () {
    $team = Team::factory()->create(['plan' => Plan::Team]);
    memberOfTeam($team, TeamRole::Admin);

    for ($i = 0; $i < 9; $i++) {
        memberOfTeam($team, TeamRole::Member);
    }

    $bot = User::factory()->create(['is_bot' => true]);
    $team->memberships()->create(['user_id' => $bot->id, 'role' => TeamRole::Member]);

    expect(MemberCap::humanMemberCount($team))->toBe(10)
        ->and(MemberCap::canAddHuman($team))->toBeFalse();
});

test('billing_checkout_route_accepts_plan_and_interval', function () {
    $team = Team::factory()->create(['plan' => Plan::Free]);
    $admin = memberOfTeam($team, TeamRole::Admin);
    $team->forceFill(['owner_user_id' => $admin->id])->save();

    /** @var FakeBilling $billing */
    $billing = app(BillingContract::class);

    test()->actingAs($admin)->post(route('teams.billing.checkout', $team), [
        'plan' => 'pro',
        'interval' => 'year',
    ], ['X-Inertia' => 'true'])
        ->assertStatus(409)
        ->assertHeader('X-Inertia-Location');

    expect(array_values($billing->checkoutSessions)[0]['lookup_key'] ?? null)->toBe(BillingPrice::ProYearly->value);
});

test('billing_page_exposes_flat_price_copy_and_toggle_savings', function () {
    $team = Team::factory()->create(['plan' => Plan::Free]);
    $admin = memberOfTeam($team, TeamRole::Admin);

    test()->actingAs($admin)->get(route('teams.billing.show', $team))
        ->assertInertia(fn (Assert $page) => $page
            ->where('prices.proMonthly', '$5/month')
            ->where('prices.teamMonthly', '$49/month for up to 10 people, bots free')
            ->where('prices.yearlySavings', '2 months free'));
});

test('seat_sync_command_is_a_noop_under_flat_pricing', function () {
    $team = Team::factory()->create([
        'plan' => Plan::Team,
        'stripe_subscription_id' => 'sub_1',
        'seats_billed' => 1,
    ]);
    memberOfTeam($team, TeamRole::Admin);
    memberOfTeam($team, TeamRole::Member);

    test()->artisan('billing:sync-seats', ['org' => $team->slug])->assertSuccessful();

    expect($team->fresh()->seats_billed)->toBe(1);
});
