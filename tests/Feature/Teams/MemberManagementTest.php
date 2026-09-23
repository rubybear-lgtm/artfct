<?php

use App\Enums\AuditEventType;
use App\Enums\TeamRole;
use App\Models\AuditEvent;
use App\Models\Team;
use Inertia\Testing\AssertableInertia as Assert;

test('a_role_change_takes_effect_and_is_audited', function () {
    $team = Team::factory()->create();
    $admin = memberOfTeam($team, TeamRole::Admin);
    $member = memberOfTeam($team, TeamRole::Member);

    test()->actingAs($admin)->patch(route('teams.members.update', [$team, $member]), ['role' => 'viewer'])->assertRedirect();

    expect($team->memberships()->where('user_id', $member->id)->value('role'))->toBe(TeamRole::Viewer)
        ->and(AuditEvent::query()->where('team_id', $team->id)->where('event_type', AuditEventType::RoleChanged)->exists())->toBeTrue();
});

test('removing_a_member_is_immediate_and_audited', function () {
    $team = Team::factory()->create();
    $admin = memberOfTeam($team, TeamRole::Admin);
    $member = memberOfTeam($team, TeamRole::Member);

    test()->actingAs($admin)->delete(route('teams.members.destroy', [$team, $member]))->assertRedirect();

    expect($team->memberships()->where('user_id', $member->id)->exists())->toBeFalse()
        ->and(AuditEvent::query()->where('team_id', $team->id)->where('event_type', AuditEventType::MemberRemoved)->exists())->toBeTrue();
});

test('members_cannot_change_roles', function () {
    $team = Team::factory()->create();
    $member = memberOfTeam($team, TeamRole::Member);
    $other = memberOfTeam($team, TeamRole::Member);

    test()->actingAs($member)->patch(route('teams.members.update', [$team, $other]), ['role' => 'admin'])->assertForbidden();
});

test('deactivated_members_are_flagged_in_the_member_list', function () {
    $team = Team::factory()->create();
    $admin = memberOfTeam($team, TeamRole::Admin);
    $gone = memberOfTeam($team, TeamRole::Member);
    $gone->forceFill(['deactivated_at' => now()])->save();

    test()->actingAs($admin)->get(route('teams.edit', $team))
        ->assertInertia(fn (Assert $page) => $page
            ->where('members', fn ($members) => collect($members)->firstWhere('id', $gone->id)['deactivated'] === true));
});
