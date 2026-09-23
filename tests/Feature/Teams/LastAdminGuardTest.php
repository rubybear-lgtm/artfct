<?php

use App\Enums\TeamRole;
use App\Models\Team;
use Illuminate\Support\Facades\Notification;

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

test('member_cannot_invite_someone_as_admin', function () {
    Notification::fake();
    $team = Team::factory()->create();
    $member = memberOfTeam($team, TeamRole::Member);

    test()->actingAs($member)->post(route('teams.invitations.store', $team), ['email' => 'new@example.com', 'role' => 'admin'])->assertForbidden();

    expect($team->invitations()->count())->toBe(0);
});

test('member_can_invite_a_viewer', function () {
    Notification::fake();
    $team = Team::factory()->create();
    $member = memberOfTeam($team, TeamRole::Member);

    test()->actingAs($member)->post(route('teams.invitations.store', $team), ['email' => 'new@example.com', 'role' => 'viewer'])->assertSessionHasNoErrors();

    expect($team->invitations()->count())->toBe(1);
});
