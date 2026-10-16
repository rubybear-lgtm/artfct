<?php

use App\Enums\TeamRole;
use App\Models\Team;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

test('shared_current_team_exposes_the_viewer_role', function (TeamRole $role, bool $isAdmin) {
    $team = Team::factory()->create();
    $user = memberOfTeam($team, $role);
    $user->switchTeam($team);

    test()->actingAs($user)->get(route('dashboard', $team))
        ->assertInertia(fn (Assert $page) => $page
            ->where('currentTeam.role', $role->value)
            ->where('currentTeam.isAdmin', $isAdmin));
})->with([
    'admin' => [TeamRole::Admin, true],
    'member' => [TeamRole::Member, false],
    'viewer' => [TeamRole::Viewer, false],
]);

test('setup_checklist_is_shown_to_admins_only', function (TeamRole $role, bool $seesSetup) {
    $team = Team::factory()->create();
    $user = memberOfTeam($team, $role);
    $user->switchTeam($team);

    test()->actingAs($user)->get(route('dashboard', $team))
        ->assertInertia(fn (Assert $page) => $seesSetup
            ? $page->whereNot('setup', null)
            : $page->where('setup', null));
})->with([
    'admin' => [TeamRole::Admin, true],
    'member' => [TeamRole::Member, false],
    'viewer' => [TeamRole::Viewer, false],
]);

test('admin_overview_renders_for_admins', function () {
    $team = Team::factory()->create();
    $admin = memberOfTeam($team, TeamRole::Admin);

    test()->actingAs($admin)->get(route('teams.admin.show', $team))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('teams/admin')
            ->where('team.slug', $team->slug));
});

test('admin_overview_is_forbidden_to_teammates', function (TeamRole $role) {
    $team = Team::factory()->create();
    $teammate = memberOfTeam($team, $role);

    test()->actingAs($teammate)->get(route('teams.admin.show', $team))->assertForbidden();
})->with([TeamRole::Member, TeamRole::Viewer]);

test('admin_overview_is_hidden_from_other_teams', function () {
    $team = Team::factory()->create();
    $stranger = User::factory()->create();

    test()->actingAs($stranger)->get(route('teams.admin.show', $team))->assertNotFound();
});

test('admin_overview_requires_sign_in', function () {
    $team = Team::factory()->create();

    test()->get(route('teams.admin.show', $team))->assertRedirect();
});
