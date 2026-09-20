<?php

use App\Enums\TeamRole;
use App\Models\Collection;
use App\Models\Team;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

function collectionTeam(): array
{
    $team = Team::factory()->create();
    $admin = memberOfTeam($team, TeamRole::Admin);
    $member = memberOfTeam($team, TeamRole::Member);

    return [$team, $admin, $member];
}

test('a_member_creates_a_collection_and_adds_and_removes_artifacts', function () {
    [$team, , $member] = collectionTeam();

    test()->actingAs($member)->post(route('teams.collections.store', $team), ['name' => 'reporting'])->assertRedirect();
    $collection = Collection::query()->where('team_id', $team->id)->firstOrFail();

    test()->actingAs($member)->post(route('teams.collections.artifacts.add', [$team, $collection]), ['artifact_id' => 'art1'])->assertRedirect();
    expect($collection->artifacts()->count())->toBe(1);

    test()->actingAs($member)->delete(route('teams.collections.artifacts.remove', [$team, $collection, 'art1']))->assertRedirect();
    expect($collection->artifacts()->count())->toBe(0);
});

test('collection_names_are_unique_per_team_and_renamable', function () {
    [$team, , $member] = collectionTeam();
    test()->actingAs($member)->post(route('teams.collections.store', $team), ['name' => 'a']);

    test()->actingAs($member)->post(route('teams.collections.store', $team), ['name' => 'a'])->assertSessionHasErrors('name');

    $collection = Collection::query()->firstOrFail();
    test()->actingAs($member)->patch(route('teams.collections.update', [$team, $collection]), ['name' => 'b'])->assertRedirect();
    expect($collection->fresh()->name)->toBe('b');
});

test('only_admins_can_pin_canonical', function () {
    [$team, $admin, $member] = collectionTeam();
    $collection = Collection::create(['team_id' => $team->id, 'name' => 'c', 'created_by_user_id' => $admin->id]);

    test()->actingAs($member)->post(route('teams.collections.pin', [$team, $collection]))->assertForbidden();
    expect($collection->fresh()->canonical)->toBeFalse();

    test()->actingAs($admin)->post(route('teams.collections.pin', [$team, $collection]))->assertRedirect();
    expect($collection->fresh()->canonical)->toBeTrue();

    test()->actingAs($admin)->delete(route('teams.collections.unpin', [$team, $collection]))->assertRedirect();
    expect($collection->fresh()->canonical)->toBeFalse();
});

test('viewers_cannot_create_collections', function () {
    $team = Team::factory()->create();
    $viewer = memberOfTeam($team, TeamRole::Viewer);

    test()->actingAs($viewer)->post(route('teams.collections.store', $team), ['name' => 'nope'])->assertForbidden();
});

test('a_collection_from_another_team_is_not_reachable', function () {
    [$team, , $member] = collectionTeam();
    $other = Team::factory()->create();
    $foreign = Collection::create(['team_id' => $other->id, 'name' => 'x', 'created_by_user_id' => $member->id]);

    test()->actingAs($member)->post(route('teams.collections.artifacts.add', [$team, $foreign]), ['artifact_id' => 'a'])->assertNotFound();
    test()->actingAs(User::factory()->create())->get(route('teams.collections.index', $team))->assertNotFound();
});

test('the_page_lists_collections_with_permissions', function () {
    [$team, , $member] = collectionTeam();
    Collection::create(['team_id' => $team->id, 'name' => 'c', 'created_by_user_id' => $member->id]);

    test()->actingAs($member)->get(route('teams.collections.index', $team))
        ->assertInertia(fn (Assert $page) => $page
            ->component('teams/collections')
            ->where('canEdit', true)
            ->where('canPin', false)
            ->where('collections.0.name', 'c'));
});
