<?php

use App\Enums\AuditEventType;
use App\Enums\TeamRole;
use App\Models\AuditEvent;
use App\Models\OrgToken;
use App\Models\Team;
use App\Models\User;

function hasAudit(Team $team, AuditEventType $type): bool
{
    return AuditEvent::query()->where('team_id', $team->id)->where('event_type', $type)->exists();
}

test('deleting_a_team_requires_the_typed_name_revokes_tokens_and_is_audited', function () {
    $team = Team::factory()->create(['name' => 'Doomed Co']);
    $owner = memberOfTeam($team, TeamRole::Admin);
    $team->forceFill(['owner_user_id' => $owner->id])->save();
    $token = OrgToken::factory()->create(['team_id' => $team->id, 'user_id' => $owner->id]);

    test()->actingAs($owner)->delete(route('teams.destroy', $team), ['name' => 'wrong'])->assertSessionHasErrors('name');
    expect($team->fresh()->trashed())->toBeFalse();

    test()->actingAs($owner)->delete(route('teams.destroy', $team), ['name' => 'Doomed Co'])->assertRedirect();

    expect(Team::withTrashed()->find($team->id)->trashed())->toBeTrue()
        ->and($token->fresh()->revoked_at)->not->toBeNull()
        ->and(hasAudit($team, AuditEventType::TeamDeleted))->toBeTrue();
    test()->actingAs($owner)->get(route('teams.edit', $team))->assertNotFound();
});

test('leaving_a_team_is_audited', function () {
    $team = Team::factory()->create();
    $admin = memberOfTeam($team, TeamRole::Admin);
    $member = memberOfTeam($team, TeamRole::Member);

    test()->actingAs($member)->delete(route('teams.leave', $team))->assertRedirect();

    expect(hasAudit($team, AuditEventType::MemberLeft))->toBeTrue();
});

test('renaming_a_team_is_audited_only_when_the_name_changes', function () {
    $team = Team::factory()->create(['name' => 'Old Name']);
    $admin = memberOfTeam($team, TeamRole::Admin);

    test()->actingAs($admin)->patch(route('teams.update', $team), ['name' => 'Old Name'])->assertRedirect();
    expect(hasAudit($team, AuditEventType::TeamRenamed))->toBeFalse();

    test()->actingAs($admin)->patch(route('teams.update', $team), ['name' => 'New Name'])->assertRedirect();
    expect(hasAudit($team, AuditEventType::TeamRenamed))->toBeTrue();
});

test('creating_a_team_is_audited', function () {
    $user = User::factory()->create();

    test()->actingAs($user)->post(route('teams.store'), ['name' => 'Fresh Co'])->assertRedirect();

    $team = Team::query()->where('name', 'Fresh Co')->firstOrFail();
    expect(hasAudit($team, AuditEventType::TeamCreated))->toBeTrue();
});
