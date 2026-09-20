<?php

namespace App\Http\Controllers;

use App\Enums\Plan;
use App\Models\OrgToken;
use App\Models\TeamInvitation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __invoke(Request $request): Response|RedirectResponse
    {
        if ($request->user()->needsFirstTeam()) {
            return redirect()->route('onboarding.team.show');
        }

        $email = strtolower($request->user()->email);

        $pendingInvitations = TeamInvitation::query()
            ->with(['inviter', 'team'])
            ->whereRaw('LOWER(email) = ?', [$email])
            ->whereNull('accepted_at')
            ->where(fn ($query) => $query
                ->whereNull('expires_at')
                ->orWhere('expires_at', '>=', now()))
            ->latest()
            ->get()
            ->map(fn (TeamInvitation $invitation) => [
                'code' => $invitation->code,
                'inviterName' => $invitation->inviter->name,
                'team' => [
                    'name' => $invitation->team->name,
                    'slug' => $invitation->team->slug,
                ],
            ]);

        return Inertia::render('dashboard', [
            'pendingInvitations' => $pendingInvitations,
            'setup' => $this->setupProgress($request),
        ]);
    }

    /**
     * Which first-run steps the current team has completed, or null when the
     * user has no current team yet.
     *
     * @return array{invitedTeammates: bool, createdToken: bool, choseAPlan: bool}|null
     */
    private function setupProgress(Request $request): ?array
    {
        $team = $request->user()->currentTeam;

        if ($team === null) {
            return null;
        }

        return [
            'invitedTeammates' => $team->memberships()->count() > 1 || $team->invitations()->exists(),
            'createdToken' => OrgToken::query()->where('team_id', $team->id)->exists(),
            'choseAPlan' => ($team->plan ?? Plan::Free) !== Plan::Free,
        ];
    }
}
