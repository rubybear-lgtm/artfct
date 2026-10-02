<?php

namespace App\Http\Controllers\Teams;

use App\Http\Controllers\Controller;
use App\Models\TeamInvitation;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Public landing page for an emailed invitation link. Signed-out visitors
 * see who invited them and a sign-in button that returns here afterwards
 * (the callback redirects to the intended URL); signed-in users see the
 * accept/decline choice, or why the invitation cannot be used.
 */
class InvitationLandingController extends Controller
{
    public function __invoke(Request $request, TeamInvitation $invitation): Response
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
}
