<?php

use App\Actions\Teams\CreateTeam;
use App\Contracts\AnalyticsContract;
use App\Enums\BillingPrice;
use App\Enums\Plan;
use App\Enums\TeamRole;
use App\Models\Team;
use App\Models\User;
use App\Services\Analytics\FakeAnalytics;
use App\Services\Analytics\ProductAnalytics;
use App\Services\Billing\BillingContract;
use App\Services\Billing\BillingService;
use App\Services\Billing\FakeBilling;
use App\Services\Billing\PlanGate;
use App\Services\Billing\PlanGateException;
use App\Services\Billing\TeamTrialService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config([
        'analytics.enabled' => true,
        'analytics.api_key' => 'phc_test',
        'analytics.environment' => 'local',
        'services.worker.base_url' => 'https://worker.test',
        'services.worker.limits_write_secret' => 's',
        'services.stripe.secret' => 'sk_test_x',
    ]);
    Http::fake(['worker.test/*' => Http::response(['ok' => true])]);

    /** @var FakeAnalytics $analytics */
    $analytics = app(AnalyticsContract::class);
    $analytics->reset();
});

test('new_team_starting_team_gets_full_team_features_and_ninety_day_trial', function () {
    $this->freezeTime();
    $user = User::factory()->create();

    $team = app(CreateTeam::class)->handle($user, 'Trial Co');

    $team->refresh();
    expect($team->plan)->toBe(Plan::Team)
        ->and($team->trial_started_at)->not->toBeNull()
        ->and($team->trial_ends_at?->timestamp)->toBe(now()->addDays(90)->timestamp)
        ->and($team->stripe_subscription_id)->not->toBeNull()
        ->and(PlanGate::hasTeamFeatures($team))->toBeTrue();

    expect(fn () => PlanGate::requireTeam($team, 'Search by description'))
        ->not->toThrow(PlanGateException::class);
    expect(fn () => PlanGate::requireTeam($team, 'Custom web address'))
        ->not->toThrow(PlanGateException::class);

    /** @var FakeBilling $billing */
    $billing = app(BillingContract::class);
    expect($billing->subscriptions[$team->stripe_subscription_id]['status'] ?? null)->toBe('trialing')
        ->and($billing->subscriptions[$team->stripe_subscription_id]['trial_period_days'] ?? null)->toBe(90)
        ->and($billing->subscriptions[$team->stripe_subscription_id]['lookup_key'] ?? null)->toBe(BillingPrice::TeamMonthly->value)
        ->and($billing->subscriptions[$team->stripe_subscription_id]['seat_count'] ?? null)->toBe(1);
});

test('at_trial_end_plus_one_second_team_is_free_features_upgrade_prompt_artifacts_intact_no_charge', function () {
    $this->freezeTime();
    $user = User::factory()->create();
    $team = app(CreateTeam::class)->handle($user, 'Expires Co');
    $admin = memberOfTeam($team, TeamRole::Admin);
    $subscriptionId = $team->fresh()->stripe_subscription_id;

    $this->travelTo(now()->addDays(90)->addSecond());

    Artisan::call('billing:expire-trials');

    $team->refresh();
    expect($team->plan)->toBe(Plan::Free)
        ->and($team->stripe_subscription_id)->toBeNull()
        ->and(PlanGate::hasTeamFeatures($team))->toBeFalse();

    expect(fn () => PlanGate::requireTeam($team, 'Search by description'))
        ->toThrow(PlanGateException::class, 'Upgrade');

    $response = test()->actingAs($admin)->get("/settings/teams/{$team->slug}/console");
    $response->assertOk();
    $response->assertInertia(fn ($page) => $page->where('artifacts.0.id', '1234567890'));

    /** @var FakeBilling $billing */
    $billing = app(BillingContract::class);
    expect($billing->charges)->toBe([])
        ->and($billing->subscriptions[$subscriptionId]['status'] ?? null)->toBe('canceled');
});

test('trial_cannot_be_restarted_after_it_ends', function () {
    $this->freezeTime();
    $user = User::factory()->create();
    $team = app(CreateTeam::class)->handle($user, 'One Shot Co');
    $startedAt = $team->fresh()->trial_started_at;

    $this->travelTo(now()->addDays(90)->addSecond());
    Artisan::call('billing:expire-trials');

    $again = app(TeamTrialService::class)->start($team->fresh(), $user, 'web');

    expect($again)->toBeFalse()
        ->and($team->fresh()->plan)->toBe(Plan::Free)
        ->and($team->fresh()->trial_started_at?->equalTo($startedAt))->toBeTrue()
        ->and($team->fresh()->trial_ends_at?->isPast())->toBeTrue();
});

test('upgrading_mid_trial_through_paid_checkout_clears_trial_state', function () {
    $this->freezeTime();
    $user = User::factory()->create();
    $team = app(CreateTeam::class)->handle($user, 'Convert Co');
    expect($team->fresh()->trial_ends_at)->not->toBeNull();

    /** @var FakeBilling $billing */
    $billing = app(BillingContract::class);
    $paidSub = $billing->completeCheckout($team, BillingPrice::TeamMonthly);
    app(BillingService::class)->applyCheckoutCompleted($team->fresh(), $paidSub, Plan::Team);

    $team->refresh();
    expect($team->plan)->toBe(Plan::Team)
        ->and($team->stripe_subscription_id)->toBe($paidSub)
        ->and($team->trial_ends_at)->toBeNull()
        ->and($team->trial_started_at)->not->toBeNull()
        ->and(PlanGate::hasTeamFeatures($team))->toBeTrue();
});

test('existing_free_teams_get_a_trial_at_migration_time_and_migration_is_idempotent', function () {
    $this->freezeTime();
    $free = Team::factory()->create(['plan' => Plan::Free, 'is_personal' => false]);
    $personal = Team::factory()->personal()->create(['plan' => Plan::Free]);
    $paid = Team::factory()->create([
        'plan' => Plan::Team,
        'stripe_subscription_id' => 'sub_paid',
        'stripe_customer_id' => 'cus_paid',
    ]);

    Artisan::call('billing:grant-trials', ['--existing' => true]);
    Artisan::call('billing:grant-trials', ['--existing' => true]);

    $free->refresh();
    expect($free->plan)->toBe(Plan::Team)
        ->and($free->trial_started_at)->not->toBeNull()
        ->and($free->trial_ends_at?->timestamp)->toBe(now()->addDays(90)->timestamp)
        ->and($personal->fresh()->trial_started_at)->toBeNull()
        ->and($paid->fresh()->trial_started_at)->toBeNull();
});

test('trial_started_and_trial_ended_posthog_events_fire_with_listed_props', function () {
    $this->freezeTime();
    config([
        'analytics.internal.team_slugs' => [],
        'analytics.internal.user_ids' => [],
        'analytics.internal.email_domains' => [],
    ]);
    $user = User::factory()->create(['email' => 'someone@example.com']);

    $team = app(CreateTeam::class)->handle($user, 'Analytics Co');

    /** @var FakeAnalytics $analytics */
    $analytics = app(AnalyticsContract::class);
    $started = $analytics->eventsNamed(ProductAnalytics::TRIAL_STARTED);
    expect($started)->toHaveCount(1);
    $props = $started[0]['properties'];
    expect($props)->toHaveKeys(['team_id', 'plan', 'source', 'actor_is_bot', 'is_internal', 'trial_days_left'])
        ->and($props['team_id'])->toBe($team->id)
        ->and($props['plan'])->toBe(Plan::Team->value)
        ->and($props['source'])->toBe('web')
        ->and($props['actor_is_bot'])->toBeFalse()
        ->and($props['is_internal'])->toBeFalse()
        ->and($props['trial_days_left'])->toBe(90)
        ->and($props)->not->toHaveKey('email')
        ->and($props)->not->toHaveKey('query');

    $analytics->reset();
    $this->travelTo(now()->addDays(90)->addSecond());
    Artisan::call('billing:expire-trials');

    $ended = $analytics->eventsNamed(ProductAnalytics::TRIAL_ENDED);
    expect($ended)->toHaveCount(1);
    $endProps = $ended[0]['properties'];
    expect($endProps)->toHaveKeys(['team_id', 'plan', 'source', 'actor_is_bot', 'is_internal'])
        ->and($endProps['plan'])->toBe(Plan::Free->value)
        ->and($endProps)->not->toHaveKey('email');
});

test('marketing_copy_describes_the_ninety_day_trial_and_flat_pricing', function () {
    $landing = file_get_contents(resource_path('js/pages/landing.tsx'));
    $chrome = file_get_contents(resource_path('js/components/site-chrome.tsx'));
    $terms = file_get_contents(resource_path('js/pages/legal/terms.tsx'));

    expect($chrome)->toContain('Try Team free for 90 days')
        ->and($landing)->toContain('Free for 90 days, no card needed')
        ->and($landing)->toContain('$49/month for up to 10 people, bots free')
        ->and($landing)->toContain('$5/month')
        ->and($landing)->toContain('2 months free')
        ->and($landing)->toContain('Contact us')
        ->and($landing)->not->toContain('everything except search by description')
        ->and($landing)->not->toContain("Team pricing isn't published yet")
        ->and($landing)->not->toContain('Billed per person')
        ->and($terms)->toContain('90-day')
        ->and($terms)->toContain('Free');
});

test('personal_teams_do_not_auto_start_a_stripe_trial', function () {
    $user = User::factory()->create();
    $team = app(CreateTeam::class)->handle($user, "{$user->name}'s Team", isPersonal: true);

    expect($team->fresh()->plan)->toBe(Plan::Free)
        ->and($team->fresh()->trial_started_at)->toBeNull()
        ->and($team->fresh()->stripe_subscription_id)->toBeNull();
});

test('free_plan_rejects_search_by_description_with_upgrade_prompt', function () {
    $team = Team::factory()->create(['plan' => Plan::Free]);

    expect(fn () => PlanGate::requireTeam($team, 'Search by description'))
        ->toThrow(PlanGateException::class, 'Upgrade');
});
