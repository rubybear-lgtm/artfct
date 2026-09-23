<?php

use App\Actions\Teams\BackfillTeamOwners;
use App\Actions\Teams\CreateTeam;
use App\Enums\AuditEventType;
use App\Enums\TeamRole;
use App\Models\AuditEvent;
use App\Models\Team;
use App\Models\User;

function teamWithOwner(): array
{
    $owner = User::factory()->create();
    $team = app(CreateTeam::class)->handle($owner, 'Captain Co');
    $admin = memberOfTeam($team, TeamRole::Admin);

    return [$team, $owner, $admin];
}

test('creator_becomes_owner', function () {
    [$team, $owner] = teamWithOwner();

    expect($team->fresh()->owner_user_id)->toBe($owner->id);
});

test('admin_who_is_not_owner_cannot_delete_team', function () {
    [$team, , $admin] = teamWithOwner();

    test()->actingAs($admin)->delete(route('teams.destroy', $team), ['name' => $team->name])->assertForbidden();

    expect(Team::query()->whereKey($team->id)->exists())->toBeTrue();
});

test('owner_can_delete_team_with_typed_name', function () {
    [$team, $owner] = teamWithOwner();

    test()->actingAs($owner)->delete(route('teams.destroy', $team), ['name' => $team->name])->assertRedirect();

    expect(Team::query()->whereKey($team->id)->exists())->toBeFalse();
});

test('owner_cannot_leave_without_transfer', function () {
    [$team, $owner] = teamWithOwner();

    test()->actingAs($owner)->delete(route('teams.leave', $team))->assertSessionHasErrors('role');
});

test('transfer_moves_ownership_and_is_audited', function () {
    [$team, $owner, $admin] = teamWithOwner();

    test()->actingAs($owner)->patch(route('teams.owner.update', $team), ['user_id' => $admin->id])->assertSessionHasNoErrors();

    expect($team->fresh()->owner_user_id)->toBe($admin->id)
        ->and(AuditEvent::query()->where('team_id', $team->id)->where('event_type', AuditEventType::OwnershipTransferred)->count())->toBe(1);

    test()->actingAs($owner)->delete(route('teams.leave', $team))->assertSessionHasNoErrors();
});

test('only_the_owner_can_transfer', function () {
    [$team, , $admin] = teamWithOwner();

    test()->actingAs($admin)->patch(route('teams.owner.update', $team), ['user_id' => $admin->id])->assertForbidden();
});

test('ownership_goes_only_to_an_admin', function () {
    [$team, $owner] = teamWithOwner();
    $member = memberOfTeam($team, TeamRole::Member);

    test()->actingAs($owner)->patch(route('teams.owner.update', $team), ['user_id' => $member->id])->assertSessionHasErrors('user_id');

    expect($team->fresh()->owner_user_id)->toBe($owner->id);
});

test('owner_cannot_be_removed_or_demoted', function () {
    [$team, $owner, $admin] = teamWithOwner();

    test()->actingAs($admin)->delete(route('teams.members.destroy', [$team, $owner]))->assertSessionHasErrors('role');
    test()->actingAs($admin)->patch(route('teams.members.update', [$team, $owner]), ['role' => 'member'])->assertSessionHasErrors('role');

    expect($team->memberships()->where('user_id', $owner->id)->value('role'))->toBe(TeamRole::Admin);
});

test('backfill_assigns_the_earliest_admin_as_owner', function () {
    $team = Team::factory()->create();
    $first = memberOfTeam($team, TeamRole::Admin);
    memberOfTeam($team, TeamRole::Admin);
    $orphan = Team::factory()->create();
    $team->forceFill(['owner_user_id' => null])->save();

    $updated = app(BackfillTeamOwners::class)->handle();

    expect($updated)->toBe(1)
        ->and($team->fresh()->owner_user_id)->toBe($first->id)
        ->and($orphan->fresh()->owner_user_id)->toBeNull();
});
