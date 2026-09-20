<?php

use App\Enums\TeamRole;
use App\Models\Team;
use App\Models\TeamInvitation;
use App\Models\User;
use App\Notifications\Teams\TeamInvitation as TeamInvitationNotification;
use App\Services\AuthKit\AuthKitProfile;
use App\Services\AuthKit\FakeAuthKitClient;
use Inertia\Testing\AssertableInertia as Assert;

function pendingInvitation(string $email = 'invitee@example.com', array $overrides = []): TeamInvitation
{
    $team = Team::factory()->create();
    $admin = memberOfTeam($team, TeamRole::Admin);

    return $team->invitations()->create(array_merge([
        'email' => $email,
        'role' => TeamRole::Member,
        'invited_by' => $admin->id,
        'expires_at' => now()->addDays(3),
    ], $overrides));
}

test('guest_sees_sign_in_state_and_return_url_is_remembered', function () {
    $invitation = pendingInvitation();

    test()->get(route('invitations.show', $invitation))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('invitations/show')->where('state', 'sign_in')->where('invitation.email', 'invitee@example.com'));

    expect(session('url.intended'))->toBe(route('invitations.show', $invitation));
});

test('signing_in_returns_the_invitee_to_the_invitation', function () {
    $invitation = pendingInvitation('invitee@example.com');
    test()->get(route('invitations.show', $invitation));

    $code = FakeAuthKitClient::codeFor(new AuthKitProfile('id-1', 'MagicAuth', 'invitee@example.com', true, 'Invitee', null, null));

    test()->get(route('authenticate', ['code' => $code]))->assertRedirect(route('invitations.show', $invitation));
});

test('matching_user_sees_ready_and_can_accept', function () {
    $invitation = pendingInvitation('invitee@example.com');
    $user = User::factory()->create(['email' => 'invitee@example.com']);

    test()->actingAs($user)->get(route('invitations.show', $invitation))
        ->assertInertia(fn (Assert $page) => $page->where('state', 'ready'));

    test()->actingAs($user)->post(route('invitations.accept', $invitation))->assertRedirect();

    expect($invitation->team->memberships()->where('user_id', $user->id)->exists())->toBeTrue();
});

test('other_email_is_told_it_is_the_wrong_account', function () {
    $invitation = pendingInvitation('invitee@example.com');
    $stranger = User::factory()->create(['email' => 'stranger@example.com']);

    test()->actingAs($stranger)->get(route('invitations.show', $invitation))
        ->assertInertia(fn (Assert $page) => $page->where('state', 'wrong_email')->where('signedInAs', 'stranger@example.com'));

    test()->actingAs($stranger)->post(route('invitations.accept', $invitation))->assertSessionHasErrors();
    expect($invitation->team->memberships()->where('user_id', $stranger->id)->exists())->toBeFalse();
});

test('expired_and_accepted_invitations_show_a_clear_state', function () {
    $expired = pendingInvitation('a@example.com', ['expires_at' => now()->subDay()]);
    $accepted = pendingInvitation('b@example.com', ['accepted_at' => now()]);

    test()->get(route('invitations.show', $expired))->assertInertia(fn (Assert $page) => $page->where('state', 'expired'));
    test()->get(route('invitations.show', $accepted))->assertInertia(fn (Assert $page) => $page->where('state', 'accepted'));
});

test('invitation_email_links_to_the_landing_page', function () {
    $invitation = pendingInvitation();

    $mail = (new TeamInvitationNotification($invitation))->toMail(new stdClass);

    expect($mail->actionUrl)->toBe(route('invitations.show', $invitation));
});
