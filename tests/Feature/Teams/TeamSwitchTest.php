<?php

use App\Enums\TeamRole;
use App\Models\Team;

test('switching_teams_redirects_to_the_switched_teams_dashboard', function () {
    $currentTeam = Team::factory()->create(['slug' => 'old-team']);
    $targetTeam = Team::factory()->create(['slug' => 'new-team']);

    $user = memberOfTeam($currentTeam, TeamRole::Admin);
    $targetTeam->memberships()->create(['user_id' => $user->id, 'role' => TeamRole::Member]);
    $user->switchTeam($currentTeam);

    test()->actingAs($user)
        ->post("/settings/teams/{$targetTeam->slug}/switch")
        ->assertRedirect(route('dashboard', ['current_team' => $targetTeam->slug]));

    expect($user->fresh()->current_team_id)->toBe($targetTeam->id);
});

test('switching_to_a_team_you_do_not_belong_to_is_forbidden', function () {
    $team = Team::factory()->create();
    $otherTeam = Team::factory()->create();

    $user = memberOfTeam($team, TeamRole::Admin);
    $user->switchTeam($team);

    test()->actingAs($user)
        ->post("/settings/teams/{$otherTeam->slug}/switch")
        ->assertForbidden();

    expect($user->fresh()->current_team_id)->toBe($team->id);
});
