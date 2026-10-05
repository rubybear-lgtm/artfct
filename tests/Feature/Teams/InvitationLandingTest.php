<?php

use App\Enums\AuditEventType;
use App\Enums\TeamRole;
use App\Http\Controllers\Teams\InvitationLandingController;
use App\Models\AuditEvent;
use App\Models\Team;
use App\Models\TeamInvitation;
use App\Models\User;
use App\Notifications\Teams\TeamInvitation as TeamInvitationNotification;
use App\Services\AuthKit\AuthKitClientContract;
use App\Services\AuthKit\AuthKitProfile;
use App\Services\AuthKit\FakeAuthKitClient;
use App\Services\AuthKit\RealAuthKitClient;
use Illuminate\Support\Facades\URL;
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

    expect($mail->actionUrl)->toBe(route('invitations.show', $invitation))
        ->and($mail->actionText)->toBe('Join '.$invitation->team->name);
});

test('declining_an_invitation_is_audited_and_removes_it', function () {
    $team = Team::factory()->create();
    $inviter = memberOfTeam($team, TeamRole::Admin);
    $invitee = User::factory()->create(['email' => 'decliner@example.com']);
    $invitation = $team->invitations()->create(['email' => 'decliner@example.com', 'role' => TeamRole::Member, 'invited_by' => $inviter->id, 'expires_at' => now()->addDay()]);

    test()->actingAs($invitee)->delete(route('invitations.decline', $invitation))->assertRedirect();

    expect(TeamInvitation::query()->find($invitation->id))->toBeNull()
        ->and(AuditEvent::query()->where('team_id', $team->id)->where('event_type', AuditEventType::InvitationDeclined)->exists())->toBeTrue();
});

test('an_invitation_for_another_email_cannot_be_accepted', function () {
    $team = Team::factory()->create();
    $inviter = memberOfTeam($team, TeamRole::Admin);
    $other = User::factory()->create(['email' => 'not-invited@example.com']);
    $invitation = $team->invitations()->create(['email' => 'someone-else@example.com', 'role' => TeamRole::Member, 'invited_by' => $inviter->id, 'expires_at' => now()->addDay()]);

    test()->actingAs($other)->post(route('invitations.accept', $invitation))->assertSessionHasErrors('invitation');

    expect($team->memberships()->where('user_id', $other->id)->exists())->toBeFalse();
});

test('accepting_as_a_guest_sends_them_to_sign_up_with_the_invited_email', function () {
    $invitation = pendingInvitation('invitee@example.com');

    test()->post(route('invitations.join', $invitation), ['account' => 'new'])
        ->assertRedirect(route('login', ['screen_hint' => 'sign-up', 'login_hint' => 'invitee@example.com']));

    expect(session(InvitationLandingController::ACCEPTING_SESSION_KEY))->toBe($invitation->code)
        ->and(session('url.intended'))->toBe(route('invitations.show', $invitation));

    test()->post(route('invitations.join', $invitation), ['account' => 'existing'])
        ->assertRedirect(route('login', ['screen_hint' => 'sign-in', 'login_hint' => 'invitee@example.com']));
});

test('a_new_invitee_joins_the_team_straight_after_signing_up', function () {
    $invitation = pendingInvitation('invitee@example.com');
    test()->post(route('invitations.join', $invitation), ['account' => 'new']);

    $code = FakeAuthKitClient::codeFor(new AuthKitProfile('id-1', 'MagicAuth', 'invitee@example.com', true, 'Invitee', null, null));
    test()->get(route('authenticate', ['code' => $code]))->assertRedirect(route('invitations.show', $invitation));

    test()->get(route('invitations.show', $invitation))
        ->assertRedirect(route('dashboard', ['current_team' => $invitation->team->slug]));

    $user = User::query()->where('email', 'invitee@example.com')->sole();

    expect($invitation->team->memberships()->where('user_id', $user->id)->exists())->toBeTrue()
        ->and($invitation->fresh()->isAccepted())->toBeTrue()
        ->and($user->fresh()->current_team_id)->toBe($invitation->team_id)
        ->and(AuditEvent::query()->where('team_id', $invitation->team_id)->where('event_type', AuditEventType::MemberAdded)->exists())->toBeTrue()
        ->and(session(InvitationLandingController::ACCEPTING_SESSION_KEY))->toBeNull();
});

test('join_cannot_be_triggered_by_a_plain_link', function () {
    $invitation = pendingInvitation('invitee@example.com');

    test()->get(route('invitations.show', $invitation).'/join')->assertMethodNotAllowed();

    expect(session(InvitationLandingController::ACCEPTING_SESSION_KEY))->toBeNull();
});

test('signing_in_without_choosing_to_accept_does_not_join_automatically', function () {
    $invitation = pendingInvitation('invitee@example.com');
    $user = User::factory()->create(['email' => 'invitee@example.com']);

    test()->actingAs($user)->get(route('invitations.show', $invitation))
        ->assertInertia(fn (Assert $page) => $page->where('state', 'ready'));

    expect($invitation->team->memberships()->where('user_id', $user->id)->exists())->toBeFalse();
});

test('a_remembered_acceptance_never_joins_a_different_account', function () {
    $invitation = pendingInvitation('invitee@example.com');
    $stranger = User::factory()->create(['email' => 'stranger@example.com']);

    test()->actingAs($stranger)
        ->withSession([InvitationLandingController::ACCEPTING_SESSION_KEY => $invitation->code])
        ->get(route('invitations.show', $invitation))
        ->assertInertia(fn (Assert $page) => $page->where('state', 'wrong_email'));

    expect($invitation->team->memberships()->where('user_id', $stranger->id)->exists())->toBeFalse();
});

test('a_remembered_acceptance_for_another_invitation_is_ignored', function () {
    $invitation = pendingInvitation('invitee@example.com');
    $other = pendingInvitation('invitee@example.com');
    $user = User::factory()->create(['email' => 'invitee@example.com']);

    test()->actingAs($user)
        ->withSession([InvitationLandingController::ACCEPTING_SESSION_KEY => $other->code])
        ->get(route('invitations.show', $invitation))
        ->assertInertia(fn (Assert $page) => $page->where('state', 'ready'));

    expect($invitation->fresh()->isAccepted())->toBeFalse()
        ->and($other->fresh()->isAccepted())->toBeFalse();
});

test('accepting_terms_without_an_intended_url_returns_to_the_pending_invitation', function () {
    config(['legal.consent_required' => true, 'legal.terms_version' => 'v1']);
    $invitation = pendingInvitation('invitee@example.com');
    $user = User::factory()->create(['email' => 'invitee@example.com', 'terms_version' => null]);

    test()->actingAs($user)
        ->withSession([InvitationLandingController::ACCEPTING_SESSION_KEY => $invitation->code])
        ->post(route('terms.accept'), ['accepted' => true])
        ->assertRedirect(route('invitations.show', $invitation));

    test()->get(route('invitations.show', $invitation))
        ->assertRedirect(route('dashboard', ['current_team' => $invitation->team->slug]));

    expect($invitation->team->memberships()->where('user_id', $user->id)->exists())->toBeTrue()
        ->and($invitation->fresh()->isAccepted())->toBeTrue()
        ->and(session(InvitationLandingController::ACCEPTING_SESSION_KEY))->toBeNull();
});

test('the_full_flow_survives_the_terms_gate_and_a_double_submit', function () {
    config(['legal.consent_required' => true, 'legal.terms_version' => 'v1']);
    $invitation = pendingInvitation('invitee@example.com');
    test()->post(route('invitations.join', $invitation), ['account' => 'new']);

    $code = FakeAuthKitClient::codeFor(new AuthKitProfile('id-1', 'MagicAuth', 'invitee@example.com', true, 'Invitee', null, null));
    test()->get(route('authenticate', ['code' => $code]))->assertRedirect(route('invitations.show', $invitation));

    test()->get(route('invitations.show', $invitation))->assertRedirect(route('terms.accept.show'));

    test()->post(route('terms.accept'), ['accepted' => true]);
    test()->post(route('terms.accept'), ['accepted' => true])->assertRedirect(route('invitations.show', $invitation));

    test()->get(route('invitations.show', $invitation))
        ->assertRedirect(route('dashboard', ['current_team' => $invitation->team->slug]));

    $user = User::query()->where('email', 'invitee@example.com')->sole();

    expect($invitation->team->memberships()->where('user_id', $user->id)->exists())->toBeTrue()
        ->and($invitation->fresh()->isAccepted())->toBeTrue();
});

test('terms_fallback_is_unchanged_without_a_pending_invitation', function () {
    config(['legal.consent_required' => true, 'legal.terms_version' => 'v1']);
    $user = User::factory()->create(['email' => 'invitee@example.com', 'terms_version' => null]);

    test()->actingAs($user)
        ->post(route('terms.accept'), ['accepted' => true])
        ->assertRedirect(route('teams.index'));
});

test('a_pending_join_url_is_only_offered_for_an_existing_invitation', function () {
    config(['legal.consent_required' => true, 'legal.terms_version' => 'v1']);
    $invitation = pendingInvitation('invitee@example.com');
    $user = User::factory()->create(['email' => 'invitee@example.com', 'terms_version' => null]);

    test()->actingAs($user)
        ->withSession([InvitationLandingController::ACCEPTING_SESSION_KEY => 'no-such-code'])
        ->post(route('terms.accept'), ['accepted' => true])
        ->assertRedirect(route('teams.index'));

    expect($invitation->fresh()->isAccepted())->toBeFalse();
});

test('login_passes_only_valid_hints_to_workos', function () {
    config(['services.workos.client_id' => 'client_x', 'services.workos.secret' => 'sk_test_x', 'services.workos.redirect_url' => 'https://app.test/authenticate']);
    app()->instance(AuthKitClientContract::class, new RealAuthKitClient);

    $queryFor = function (array $params): array {
        $response = test()->get(route('login', $params));
        parse_str((string) parse_url((string) ($response->headers->get('X-Inertia-Location') ?? $response->headers->get('Location')), PHP_URL_QUERY), $query);

        return $query;
    };

    expect($queryFor(['screen_hint' => 'sign-up', 'login_hint' => 'invitee@example.com']))
        ->toMatchArray(['screen_hint' => 'sign-up', 'login_hint' => 'invitee@example.com']);

    expect($queryFor(['screen_hint' => 'evil', 'login_hint' => 'not an email']))
        ->not->toHaveKey('screen_hint')
        ->not->toHaveKey('login_hint');
});

test('dev_login_prefills_the_hinted_email', function () {
    test()->get(route('login', ['login_hint' => 'invitee@example.com']))
        ->assertInertia(fn (Assert $page) => $page->component('auth/login')->where('loginHint', 'invitee@example.com'));
});

test('the_terms_gate_returns_to_an_https_invitation_url_behind_an_untrusted_edge', function () {
    config(['app.url' => 'https://staging.artfct.dev', 'legal.consent_required' => true]);
    URL::forceRootUrl('https://staging.artfct.dev');
    URL::forceScheme('https');

    $invitation = pendingInvitation('invitee@example.com');
    $user = User::factory()->create(['email' => 'invitee@example.com', 'terms_version' => null]);

    // The Railway edge reaches the app over plain http and is not a trusted proxy.
    test()->actingAs($user)->get('http://staging.artfct.dev/invitations/'.$invitation->code)
        ->assertRedirect(route('terms.accept.show'));

    test()->actingAs($user)->post('http://staging.artfct.dev/terms/accept', ['accepted' => true])
        ->assertRedirect('https://staging.artfct.dev/invitations/'.$invitation->code);
});
