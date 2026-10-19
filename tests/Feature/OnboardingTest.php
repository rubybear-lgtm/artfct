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

test('a_new_user_lands_in_their_personal_workspace_without_being_asked_for_a_team', function () {
    $user = freshSignUp();
    $personal = $user->personalTeam();

    test()->actingAs($user)->get(route('dashboard', $personal))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('dashboard'));
    test()->actingAs($user)->get(route('onboarding.team.show'))
        ->assertRedirect(route('dashboard', ['current_team' => $personal->slug]));
    expect($user->teams()->count())->toBe(1);
});

test('creating_a_team_makes_the_user_its_owner_admin_and_lands_on_its_settings', function () {
    $user = freshSignUp();

    $response = test()->actingAs($user)->post(route('teams.store'), ['name' => 'Northwind Analytics']);

    $team = Team::query()->where('name', 'Northwind Analytics')->firstOrFail();
    $response->assertRedirect(route('teams.edit', ['team' => $team->slug]));
    expect($team->owner_user_id)->toBe($user->id)
        ->and($team->is_personal)->toBeFalse()
        ->and($team->memberships()->where('user_id', $user->id)->value('role'))->toBe(TeamRole::Admin)
        ->and($user->teams()->count())->toBe(2);
});

test('an_invited_user_sees_the_invitation_on_their_dashboard', function () {
    $user = freshSignUp('invited@example.com');
    $team = Team::factory()->create();
    $leader = memberOfTeam($team, TeamRole::Admin);
    $team->invitations()->create(['email' => 'invited@example.com', 'role' => TeamRole::Member, 'invited_by' => $leader->id, 'expires_at' => now()->addDay()]);

    test()->actingAs($user)->get(route('dashboard', $user->personalTeam()))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('dashboard')->has('pendingInvitations', 1));
});

test('a_personal_team_can_be_upgraded_in_place_and_keeps_its_slug', function () {
    $user = freshSignUp();
    $personal = $user->personalTeam();
    $slug = $personal->slug;

    test()->actingAs($user)->post(route('teams.upgrade', $personal), ['name' => 'Northwind Analytics'])
        ->assertRedirect(route('teams.billing.show', ['team' => $slug]));

    $personal->refresh();
    expect($personal->name)->toBe('Northwind Analytics')
        ->and($personal->is_personal)->toBeFalse()
        ->and($personal->slug)->toBe($slug)
        ->and($user->fresh()->current_team_id)->toBe($personal->id)
        ->and($user->fresh()->personalTeam())->not->toBeNull()
        ->and($user->fresh()->personalTeam()->id)->not->toBe($personal->id);
});

test('only_a_personal_team_can_be_upgraded_and_it_needs_a_valid_name', function () {
    $user = freshSignUp();
    test()->actingAs($user)->post(route('teams.store'), ['name' => 'Acme']);
    $team = Team::query()->where('name', 'Acme')->firstOrFail();

    test()->actingAs($user)->post(route('teams.upgrade', $team), ['name' => 'Other'])->assertForbidden();
    test()->actingAs($user)->post(route('teams.upgrade', $user->personalTeam()), ['name' => 'x'])->assertSessionHasErrors('name');
});

test('a_personal_team_cannot_start_checkout', function () {
    $user = freshSignUp();

    test()->actingAs($user)->post(route('teams.billing.checkout', $user->personalTeam()))->assertForbidden();
});
