<?php

namespace App\Http\Controllers\Teams;

use App\Actions\Teams\AcceptTeamInvitation;
use App\Http\Controllers\Controller;
use App\Models\TeamInvitation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Public landing page for an emailed invitation link. Signed-out visitors
 * see who invited them and buttons to create an account or sign in; either
 * one returns here afterwards and joins the team without another click.
 * Signed-in users see the accept/decline choice, or why the invitation
 * cannot be used.
 */
class InvitationLandingController extends Controller
{
    /**
     * Session key holding the code of the invitation a guest chose to accept
     * before being sent to sign in. Its presence is their consent to join.
     */
    public const ACCEPTING_SESSION_KEY = 'invitation.accepting';

    public function show(Request $request, TeamInvitation $invitation, AcceptTeamInvitation $acceptTeamInvitation): Response|RedirectResponse
    {
        $user = $request->user();

        if ($user === null) {
            $request->session()->put('url.intended', route('invitations.show', $invitation));
        }

        $state = match (true) {
            $invitation->isAccepted() => 'accepted',
            $invitation->isExpired() => 'expired',
            $user !== null && strtolower($user->email) !== strtolower($invitation->email) => 'wrong_email',
            $user === null => 'sign_in',
            default => 'ready',
        };

        $acceptingCode = $user !== null ? $request->session()->pull(self::ACCEPTING_SESSION_KEY) : null;

        if ($state === 'ready' && $acceptingCode === $invitation->code) {
            $acceptTeamInvitation->handle($request, $user, $invitation);

            Inertia::flash('toast', ['type' => 'success', 'message' => __('Welcome to :teamName.', ['teamName' => $invitation->team->name])]);

            return to_route('dashboard', ['current_team' => $invitation->team->slug]);
        }

        return Inertia::render('invitations/show', [
            'state' => $state,
            'invitation' => [
                'code' => $invitation->code,
                'email' => $invitation->email,
                'role' => $invitation->role->value,
                'teamName' => $invitation->team->name,
                'inviterName' => $invitation->inviter?->name,
                'expiresAt' => $invitation->expires_at?->toIso8601String(),
            ],
            'signedInAs' => $user?->email,
        ]);
    }

    /**
     * A guest chose to accept: remember that, then send them to create an
     * account (or sign in) with the invited email filled in. The sign-in
     * callback returns them to the landing page, which completes the join.
     */
    public function join(Request $request, TeamInvitation $invitation): RedirectResponse
    {
        $request->session()->put(self::ACCEPTING_SESSION_KEY, $invitation->code);
        $request->session()->put('url.intended', route('invitations.show', $invitation));

        return to_route('login', [
            'screen_hint' => $request->input('account') === 'existing' ? 'sign-in' : 'sign-up',
            'login_hint' => $invitation->email,
        ]);
    }
}
