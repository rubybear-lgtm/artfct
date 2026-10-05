<?php

use App\Enums\TeamRole;
use App\Models\Team;

test('an_admin_can_rename_a_team', function () {
    $team = Team::factory()->create(['name' => 'Old Name']);
    $admin = memberOfTeam($team, TeamRole::Admin);

    test()->actingAs($admin)
        ->patch(route('teams.update', $team), ['name' => 'New Name'])
        ->assertRedirect(route('teams.edit', ['team' => $team->slug]));

    expect($team->fresh()->name)->toBe('New Name');
});

test('a_viewer_cannot_rename_a_team', function () {
    $team = Team::factory()->create(['name' => 'Old Name']);
    $viewer = memberOfTeam($team, TeamRole::Viewer);

    test()->actingAs($viewer)
        ->patch(route('teams.update', $team), ['name' => 'New Name'])
        ->assertForbidden();

    expect($team->fresh()->name)->toBe('Old Name');
});
