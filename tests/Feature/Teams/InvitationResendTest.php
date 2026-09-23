<?php

use App\Enums\TeamRole;
use App\Models\Team;
use App\Models\TeamInvitation;
use App\Models\User;
use App\Notifications\Teams\TeamInvitation as TeamInvitationNotification;
use Illuminate\Support\Facades\Notification;

function resendableInvitation(Team $team, string $email = 'new@example.com'): TeamInvitation
{
    return $team->invitations()->create([
        'email' => $email,
        'role' => TeamRole::Member,
        'invited_by' => $team->memberships()->first()->user_id,
        'expires_at' => now()->addHour(),
    ]);
}

test('resending_emails_the_invitee_again_and_extends_expiry', function () {
    Notification::fake();
    $team = Team::factory()->create();
    $admin = memberOfTeam($team, TeamRole::Admin);
    $invitation = resendableInvitation($team);
    $oldCode = $invitation->code;

    test()->actingAs($admin)->post(route('teams.invitations.resend', [$team, $invitation]))->assertRedirect();

    Notification::assertSentOnDemand(TeamInvitationNotification::class);
    expect($invitation->fresh()->expires_at->gt(now()->addDays(2)))->toBeTrue()
        ->and($invitation->fresh()->code)->not->toBe($oldCode);
    test()->actingAs(User::factory()->create())->get(route('invitations.show', $oldCode))->assertNotFound();
});

test('viewers_cannot_resend', function () {
    Notification::fake();
    $team = Team::factory()->create();
    $admin = memberOfTeam($team, TeamRole::Admin);
    $viewer = memberOfTeam($team, TeamRole::Viewer);
    $invitation = resendableInvitation($team);

    test()->actingAs($viewer)->post(route('teams.invitations.resend', [$team, $invitation]))->assertForbidden();

    Notification::assertNothingSent();
});

test('an_invitation_from_another_team_cannot_be_resent', function () {
    Notification::fake();
    $team = Team::factory()->create();
    $admin = memberOfTeam($team, TeamRole::Admin);
    $other = Team::factory()->create();
    memberOfTeam($other, TeamRole::Admin);
    $foreign = resendableInvitation($other);

    test()->actingAs($admin)->post(route('teams.invitations.resend', [$team, $foreign]))->assertNotFound();
});
