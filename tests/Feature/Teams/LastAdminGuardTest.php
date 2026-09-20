<?php

use App\Enums\TeamRole;
use App\Models\Team;

test('last_admin_cannot_leave', function () {
    $team = Team::factory()->create();
    $admin = memberOfTeam($team, TeamRole::Admin);
    memberOfTeam($team, TeamRole::Member);

    test()->actingAs($admin)->delete(route('teams.leave', $team))->assertSessionHasErrors('role');

    expect($team->memberships()->where('user_id', $admin->id)->exists())->toBeTrue();
});

test('last_admin_cannot_be_demoted', function () {
    $team = Team::factory()->create();
    $admin = memberOfTeam($team, TeamRole::Admin);

    test()->actingAs($admin)->patch(route('teams.members.update', [$team, $admin]), ['role' => 'member'])->assertSessionHasErrors('role');

    expect($team->memberships()->where('user_id', $admin->id)->value('role'))->toBe(TeamRole::Admin);
});

test('promote_then_leave_succeeds', function () {
    $team = Team::factory()->create();
    $admin = memberOfTeam($team, TeamRole::Admin);
    $other = memberOfTeam($team, TeamRole::Member);

    test()->actingAs($admin)->patch(route('teams.members.update', [$team, $other]), ['role' => 'admin'])->assertSessionHasNoErrors();
    test()->actingAs($admin)->delete(route('teams.leave', $team))->assertSessionHasNoErrors();

    expect($team->memberships()->where('user_id', $admin->id)->exists())->toBeFalse();
});

test('demoting_one_of_two_admins_is_allowed', function () {
    $team = Team::factory()->create();
    $first = memberOfTeam($team, TeamRole::Admin);
    $second = memberOfTeam($team, TeamRole::Admin);

    test()->actingAs($first)->patch(route('teams.members.update', [$team, $second]), ['role' => 'member'])->assertSessionHasNoErrors();
});
