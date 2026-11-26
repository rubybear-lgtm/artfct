<?php

use App\Enums\FeatureReleaseState;
use App\Enums\TeamRole;
use App\Models\Collection;
use App\Models\FeatureRelease;
use App\Models\Team;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->withoutVite();
});

function railCollectionsTeam(): array
{
    $team = Team::factory()->create();
    $admin = memberOfTeam($team, TeamRole::Admin);
    $member = memberOfTeam($team, TeamRole::Member);

    return [$team, $admin, $member];
}

test('flag off exposes unified-rail as false with the same collections payload', function () {
    [$team, , $member] = railCollectionsTeam();
    Collection::create(['team_id' => $team->id, 'name' => 'c', 'created_by_user_id' => $member->id]);

    test()->actingAs($member)->get(route('teams.collections.index', $team))
        ->assertInertia(fn (Assert $page) => $page
            ->component('teams/collections')
            ->where('features.unified-rail', false)
            ->where('collections.0.name', 'c')
            ->where('canEdit', true));
});

test('flag on exposes unified-rail as true with the same collections payload', function () {
    [$team, , $member] = railCollectionsTeam();
    Collection::create(['team_id' => $team->id, 'name' => 'c', 'canonical' => true, 'created_by_user_id' => $member->id]);

    FeatureRelease::factory()->create(['key' => 'unified-rail', 'state' => FeatureReleaseState::Released]);

    test()->actingAs($member)->get(route('teams.collections.index', $team))
        ->assertInertia(fn (Assert $page) => $page
            ->component('teams/collections')
            ->where('features.unified-rail', true)
            ->where('collections.0.name', 'c')
            ->where('collections.0.canonical', true)
            ->where('canEdit', true));
});

test('collection management actions work with the flag on', function () {
    [$team, $admin, $member] = railCollectionsTeam();
    FeatureRelease::factory()->create(['key' => 'unified-rail', 'state' => FeatureReleaseState::Released]);

    test()->actingAs($member)->post(route('teams.collections.store', $team), ['name' => 'reporting'])->assertRedirect();
    $collection = Collection::query()->where('team_id', $team->id)->firstOrFail();

    test()->actingAs($member)->patch(route('teams.collections.update', [$team, $collection]), ['name' => 'reports'])->assertRedirect();
    expect($collection->fresh()->name)->toBe('reports');

    test()->actingAs($admin)->post(route('teams.collections.pin', [$team, $collection]))->assertRedirect();
    expect($collection->fresh()->canonical)->toBeTrue();

    test()->actingAs($admin)->delete(route('teams.collections.unpin', [$team, $collection]))->assertRedirect();
    expect($collection->fresh()->canonical)->toBeFalse();
});
