<?php

use App\Enums\TeamRole;
use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Facades\Notification;

test('the_pending_invitation_cap_is_enforced', function () {
    Notification::fake();
    config(['teams.max_pending_invitations' => 1]);
    $team = Team::factory()->create();
    $admin = memberOfTeam($team, TeamRole::Admin);

    test()->actingAs($admin)->post(route('teams.invitations.store', $team), ['email' => 'a@example.com', 'role' => 'member'])->assertRedirect();
    test()->actingAs($admin)->post(route('teams.invitations.store', $team), ['email' => 'b@example.com', 'role' => 'member'])->assertSessionHasErrors('email');

    expect($team->invitations()->count())->toBe(1);
});

test('members_cannot_invite_when_the_policy_is_admins_only', function () {
    Notification::fake();
    config(['teams.members_can_invite' => false]);
    $team = Team::factory()->create();
    $admin = memberOfTeam($team, TeamRole::Admin);
    $member = memberOfTeam($team, TeamRole::Member);

    test()->actingAs($member)->post(route('teams.invitations.store', $team), ['email' => 'a@example.com', 'role' => 'viewer'])->assertForbidden();
    test()->actingAs($admin)->post(route('teams.invitations.store', $team), ['email' => 'a@example.com', 'role' => 'viewer'])->assertRedirect();
});

test('consent_is_required_before_the_console_when_enabled', function () {
    config(['legal.consent_required' => true, 'legal.terms_version' => 'v1']);
    $team = Team::factory()->create();
    $user = memberOfTeam($team, TeamRole::Member);
    $user->switchTeam($team);

    test()->actingAs($user)->get(route('dashboard', $team))->assertRedirect(route('terms.accept.show'));
    test()->actingAs($user)->get(route('terms'))->assertOk();
    test()->actingAs($user)->get(route('privacy'))->assertOk();
});

test('accepting_the_terms_stores_the_version_and_time_and_unblocks', function () {
    config(['legal.consent_required' => true, 'legal.terms_version' => 'v1']);
    $team = Team::factory()->create();
    $user = memberOfTeam($team, TeamRole::Member);
    $user->switchTeam($team);

    test()->actingAs($user)->post(route('terms.accept'), [])->assertSessionHasErrors('accepted');
    test()->actingAs($user)->post(route('terms.accept'), ['accepted' => true])->assertRedirect();

    $user->refresh();
    expect($user->terms_version)->toBe('v1')->and($user->terms_accepted_at)->not->toBeNull();
    test()->actingAs($user)->get(route('dashboard', $team))->assertOk();
});

test('a_new_terms_version_asks_again', function () {
    config(['legal.consent_required' => true, 'legal.terms_version' => 'v2']);
    $team = Team::factory()->create();
    $user = memberOfTeam($team, TeamRole::Member);
    $user->switchTeam($team);
    $user->forceFill(['terms_version' => 'v1', 'terms_accepted_at' => now()])->save();

    test()->actingAs($user)->get(route('dashboard', $team))->assertRedirect(route('terms.accept.show'));
});

test('consent_is_not_enforced_when_the_flag_is_off', function () {
    config(['legal.consent_required' => false]);
    $team = Team::factory()->create();
    $user = memberOfTeam($team, TeamRole::Member);
    $user->switchTeam($team);

    test()->actingAs($user)->get(route('dashboard', $team))->assertOk();
});

test('logout_is_never_blocked_by_consent', function () {
    config(['legal.consent_required' => true, 'legal.terms_version' => 'v1']);

    test()->actingAs(User::factory()->create())->post(route('logout'))->assertRedirect(route('home'));
});

test('accepting_the_terms_returns_the_user_to_the_page_they_wanted', function () {
    config(['legal.consent_required' => true, 'legal.terms_version' => 'v1']);
    $team = Team::factory()->create();
    $user = memberOfTeam($team, TeamRole::Member);
    $user->switchTeam($team);

    test()->actingAs($user)->get(route('dashboard', $team))->assertRedirect(route('terms.accept.show'));
    test()->post(route('terms.accept'), ['accepted' => true])->assertRedirect(route('dashboard', $team));
});
