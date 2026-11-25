<?php

use App\Enums\FeatureReleaseState;
use App\Enums\TeamRole;
use App\Models\FeatureRelease;
use App\Models\Team;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function (): void {
    $this->withoutVite();
});

test('unified rail is registered and exposed to the browser', function (): void {
    $entry = config('features.registry.unified-rail');

    expect($entry)->not->toBeNull()
        ->and($entry['expose_to_browser'] ?? false)->toBeTrue();
});

test('a team page renders without the rail context when the flag is off', function (): void {
    $team = Team::factory()->create();
    $member = memberOfTeam($team, TeamRole::Member);

    test()->actingAs($member)->get(route('dashboard', $team))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('dashboard')
            ->where('features.unified-rail', false));
});

test('a team page exposes the rail flag when released', function (): void {
    $team = Team::factory()->create();
    $member = memberOfTeam($team, TeamRole::Member);
    FeatureRelease::factory()->create(['key' => 'unified-rail', 'state' => FeatureReleaseState::Released]);

    test()->actingAs($member)->get(route('dashboard', $team))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('dashboard')
            ->where('features.unified-rail', true));
});
