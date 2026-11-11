<?php

use App\Enums\Plan;
use App\Enums\TeamRole;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * @return array{0: User, 1: Team}
 */
function entitlementsNavUser(Plan $plan, TeamRole $role, string $slug): array
{
    $user = User::factory()->create([
        'name' => $slug.' user',
        'email' => $slug.'@example.com',
    ]);
    $team = Team::factory()->create([
        'plan' => $plan,
        'slug' => $slug,
        'name' => ucfirst($slug).' Workspace',
        'owner_user_id' => $role === TeamRole::Admin ? $user->id : null,
    ]);
    $team->memberships()->create(['user_id' => $user->id, 'role' => $role]);
    $user->forceFill(['current_team_id' => $team->id])->save();

    return [$user, $team];
}

test('nav screenshots for free pro team-admin and team-member', function () {
    [$freeAdmin, $freeTeam] = entitlementsNavUser(Plan::Free, TeamRole::Admin, 'nav-free');
    test()->actingAs($freeAdmin);
    visit(route('dashboard', ['current_team' => $freeTeam->slug], absolute: false))
        ->assertSee('Admin')
        ->assertNoJavaScriptErrors()
        ->screenshot(filename: 'rub529-nav-free-admin');

    [$proAdmin, $proTeam] = entitlementsNavUser(Plan::Pro, TeamRole::Admin, 'nav-pro');
    test()->actingAs($proAdmin);
    visit(route('dashboard', ['current_team' => $proTeam->slug], absolute: false))
        ->assertSee('Admin')
        ->screenshot(filename: 'rub529-nav-pro-admin');

    [$teamAdmin, $teamTeam] = entitlementsNavUser(Plan::Team, TeamRole::Admin, 'nav-team-admin');
    test()->actingAs($teamAdmin);
    visit(route('teams.admin.show', $teamTeam, absolute: false))
        ->assertSee('Admin')
        ->assertSee('Members')
        ->assertSee('Billing')
        ->screenshot(filename: 'rub529-nav-team-admin');

    [$teamMember, $memberTeam] = entitlementsNavUser(Plan::Team, TeamRole::Member, 'nav-team-member');
    test()->actingAs($teamMember);
    visit(route('dashboard', ['current_team' => $memberTeam->slug], absolute: false))
        ->assertSee('Team')
        ->assertDontSee('Admin')
        ->screenshot(filename: 'rub529-nav-team-member');
});
