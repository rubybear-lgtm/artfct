<?php

use App\Actions\Teams\CreateTeam;
use App\Enums\TeamRole;
use App\Models\Team;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

function freshSignUp(string $email = 'new@example.com'): User
{
    $user = User::factory()->create(['email' => $email]);
    // What sign-up does: a personal team the user did not choose.
    app(CreateTeam::class)->handle($user, "{$user->name}'s Team", isPersonal: true);

    return $user;
}

test('a_user_nobody_invited_is_asked_to_create_their_first_team', function () {
    $user = freshSignUp();
    $personal = $user->personalTeam();

    test()->actingAs($user)->get(route('dashboard', $personal))->assertRedirect(route('onboarding.team.show'));
    test()->actingAs($user)->get(route('onboarding.team.show'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('onboarding/team'));
});

test('creating_the_first_team_makes_the_user_its_owner_admin_and_lands_on_its_dashboard', function () {
    $user = freshSignUp();

    $response = test()->actingAs($user)->post(route('onboarding.team.store'), ['name' => 'Northwind Analytics']);

    $team = Team::query()->where('name', 'Northwind Analytics')->firstOrFail();
    $response->assertRedirect(route('dashboard', ['current_team' => $team->slug]));
    expect($team->owner_user_id)->toBe($user->id)
        ->and($team->memberships()->where('user_id', $user->id)->value('role'))->toBe(TeamRole::Admin)
        ->and($user->fresh()->current_team_id)->toBe($team->id)
        ->and($user->fresh()->needsFirstTeam())->toBeFalse();
});

test('an_invited_user_is_not_asked_and_sees_the_invitation_first', function () {
    $user = freshSignUp('invited@example.com');
    $team = Team::factory()->create();
    $leader = memberOfTeam($team, TeamRole::Admin);
    $team->invitations()->create(['email' => 'invited@example.com', 'role' => TeamRole::Member, 'invited_by' => $leader->id, 'expires_at' => now()->addDay()]);

    test()->actingAs($user)->get(route('dashboard', $user->personalTeam()))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('dashboard')->has('pendingInvitations', 1));
    test()->actingAs($user)->get(route('onboarding.team.show'))->assertRedirect(route('teams.index'));
});

test('a_user_who_declines_their_only_invitation_is_then_asked_to_create_a_team', function () {
    $user = freshSignUp('decliner2@example.com');
    $team = Team::factory()->create();
    $leader = memberOfTeam($team, TeamRole::Admin);
    $invitation = $team->invitations()->create(['email' => 'decliner2@example.com', 'role' => TeamRole::Member, 'invited_by' => $leader->id, 'expires_at' => now()->addDay()]);

    test()->actingAs($user)->delete(route('invitations.decline', $invitation));

    expect($user->fresh()->needsFirstTeam())->toBeTrue();
});

test('a_user_with_a_team_of_their_own_is_not_asked', function () {
    $team = Team::factory()->create();
    $member = memberOfTeam($team, TeamRole::Member);
    $member->switchTeam($team);

    expect($member->needsFirstTeam())->toBeFalse();
    test()->actingAs($member)->get(route('onboarding.team.show'))->assertRedirect(route('teams.index'));
});

test('the_first_team_needs_a_name', function () {
    $user = freshSignUp();

    test()->actingAs($user)->post(route('onboarding.team.store'), ['name' => ''])->assertSessionHasErrors('name');
    test()->actingAs($user)->post(route('onboarding.team.store'), ['name' => 'x'])->assertSessionHasErrors('name');
});
