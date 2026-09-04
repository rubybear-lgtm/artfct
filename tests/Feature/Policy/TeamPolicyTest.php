<?php

use App\Enums\TeamRole;
use App\Models\Team;
use App\Models\User;

function memberOf(Team $team, TeamRole $role): User
{
    $user = User::factory()->create();
    $team->memberships()->create(['user_id' => $user->id, 'role' => $role]);

    return $user;
}

test('viewer_cannot_invite', function () {
    $team = Team::factory()->create();
    $viewer = memberOf($team, TeamRole::Viewer);

    $response = test()->actingAs($viewer)->post("/settings/teams/{$team->slug}/invitations", [
        'email' => 'someone@example.com',
        'role' => 'member',
    ]);

    $response->assertForbidden();
});

test('member_cannot_change_auth_mode', function () {
    $team = Team::factory()->create();
    $team->domains()->create(['domain' => 'acme.com', 'verified_at' => now()]);
    $member = memberOf($team, TeamRole::Member);

    $response = test()->actingAs($member)->patch("/settings/teams/{$team->slug}/auth-mode", [
        'auth_mode' => 'dual',
    ]);

    $response->assertForbidden();
    expect($team->fresh()->auth_mode->value)->toBe('authkit');
});

test('admin_can_change_auth_mode', function () {
    $team = Team::factory()->create();
    $team->domains()->create(['domain' => 'acme.com', 'verified_at' => now()]);
    $admin = memberOf($team, TeamRole::Admin);

    $response = test()->actingAs($admin)->patch("/settings/teams/{$team->slug}/auth-mode", [
        'auth_mode' => 'dual',
    ]);

    $response->assertRedirect();
    expect($team->fresh()->auth_mode->value)->toBe('dual');
});

test('member_of_org_a_cannot_read_org_b', function () {
    $orgA = Team::factory()->create();
    $orgB = Team::factory()->create();
    $memberOfA = memberOf($orgA, TeamRole::Member);

    $response = test()->actingAs($memberOfA)->get("/settings/teams/{$orgB->slug}");

    $response->assertForbidden();
});
