<?php

use App\Enums\AuditEventType;
use App\Enums\TeamRole;
use App\Models\AuditEvent;
use App\Models\Membership;
use App\Models\Team;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

test('account_page_lists_teams_that_block_deletion', function () {
    $team = Team::factory()->create();
    $owner = memberOfTeam($team, TeamRole::Admin);
    $team->forceFill(['owner_user_id' => $owner->id])->save();
    memberOfTeam($team, TeamRole::Member);
    $owner->switchTeam($team);

    test()->actingAs($owner)->get(route('account.show'))->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('account')
            ->where('blockingTeams', [$team->name]));
});

test('name_can_be_updated', function () {
    $user = User::factory()->create();

    test()->actingAs($user)->patch(route('account.update'), ['name' => 'New Name'])->assertRedirect();

    expect($user->fresh()->name)->toBe('New Name');
});

test('deletion_is_blocked_while_owning_a_team_with_other_members', function () {
    $team = Team::factory()->create();
    $owner = memberOfTeam($team, TeamRole::Admin);
    $team->forceFill(['owner_user_id' => $owner->id])->save();
    memberOfTeam($team, TeamRole::Member);

    test()->actingAs($owner)->delete(route('account.destroy'), ['confirmation' => 'DELETE'])->assertRedirect();

    expect($owner->fresh()->deactivated_at)->toBeNull();
    expect($team->fresh()->trashed())->toBeFalse();
});

test('deletion_closes_the_account_and_removes_solely_owned_teams', function () {
    $team = Team::factory()->create();
    $owner = memberOfTeam($team, TeamRole::Admin);
    $team->forceFill(['owner_user_id' => $owner->id])->save();

    test()->actingAs($owner)->delete(route('account.destroy'), ['confirmation' => 'DELETE'])->assertRedirect(route('home'));

    $owner->refresh();
    expect($owner->deactivated_at)->not->toBeNull();
    expect($owner->email)->toContain('deleted-');
    expect(Membership::query()->where('user_id', $owner->id)->count())->toBe(0);
    expect(Team::query()->find($team->id))->toBeNull();
    test()->assertGuest();
});

test('account_deletion_is_audited', function () {
    $team = Team::factory()->create();
    $owner = memberOfTeam($team, TeamRole::Admin);
    $team->forceFill(['owner_user_id' => $owner->id])->save();
    $other = Team::factory()->create();
    $other->memberships()->create(['user_id' => $owner->id, 'role' => TeamRole::Member]);

    test()->actingAs($owner)->delete(route('account.destroy'), ['confirmation' => 'DELETE'])->assertRedirect(route('home'));

    expect(AuditEvent::query()->where('event_type', AuditEventType::AccountDeleted)->where('team_id', $other->id)->exists())->toBeTrue();
});

test('deletion_requires_the_typed_confirmation', function () {
    $owner = User::factory()->create();

    test()->actingAs($owner)->delete(route('account.destroy'), ['confirmation' => 'nope'])->assertSessionHasErrors('confirmation');

    expect($owner->fresh()->deactivated_at)->toBeNull();
});

test('account_page_lists_the_users_teams', function () {
    $team = Team::factory()->create();
    $member = memberOfTeam($team, TeamRole::Member);
    $member->switchTeam($team);

    test()->actingAs($member)->get(route('account.show'))
        ->assertInertia(fn (Assert $page) => $page->where('teams.0.slug', $team->slug));
});
