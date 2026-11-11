<?php

use App\Enums\Plan;
use App\Enums\TeamRole;
use App\Models\Team;

/**
 * Per-seat Stripe quantity sync was retired with flat Team pricing (RUB-529).
 * The scheduled command remains registered as a harmless no-op.
 */
test('sync_seats_is_a_noop_under_flat_pricing', function () {
    $team = Team::factory()->create([
        'plan' => Plan::Team,
        'stripe_subscription_id' => 'sub_'.uniqid(),
        'seats_billed' => 1,
    ]);
    memberOfTeam($team, TeamRole::Admin);
    memberOfTeam($team, TeamRole::Member);

    test()->artisan('billing:sync-seats', ['org' => $team->slug])->assertSuccessful();

    expect($team->fresh()->seats_billed)->toBe(1);
});

test('team_without_subscription_is_still_skipped', function () {
    $team = Team::factory()->create();
    memberOfTeam($team, TeamRole::Admin);

    test()->artisan('billing:sync-seats')->assertSuccessful();

    expect($team->fresh()->seats_billed)->toBeNull();
});
