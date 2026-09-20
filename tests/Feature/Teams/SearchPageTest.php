<?php

use App\Enums\TeamRole;
use App\Models\Collection;
use App\Models\Team;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

test('the_search_page_explains_when_indexing_is_off', function () {
    config(['indexing.enabled' => false]);
    $team = Team::factory()->create();
    $member = memberOfTeam($team, TeamRole::Member);

    test()->actingAs($member)->get(route('teams.search', [$team, 'q' => 'billing']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('teams/search')
            ->where('indexingEnabled', false)
            ->where('searched', false)
            ->where('results', []));
});

test('a_member_search_runs_and_lists_the_teams_collections', function () {
    config(['indexing.enabled' => true]);
    $team = Team::factory()->create();
    $member = memberOfTeam($team, TeamRole::Member);
    Collection::create(['team_id' => $team->id, 'name' => 'reporting', 'created_by_user_id' => $member->id]);

    test()->actingAs($member)->get(route('teams.search', [$team, 'q' => 'billing']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('indexingEnabled', true)
            ->where('searched', true)
            ->where('collections.0.name', 'reporting'));
});

test('another_teams_collections_never_appear', function () {
    $team = Team::factory()->create();
    $member = memberOfTeam($team, TeamRole::Member);
    $other = Team::factory()->create();
    Collection::create(['team_id' => $other->id, 'name' => 'secret', 'created_by_user_id' => $member->id]);

    test()->actingAs($member)->get(route('teams.search', $team))
        ->assertInertia(fn (Assert $page) => $page->where('collections', []));
});

test('non_members_get_a_404_for_the_search_page', function () {
    $team = Team::factory()->create();

    test()->actingAs(User::factory()->create())->get(route('teams.search', $team))->assertNotFound();
});
