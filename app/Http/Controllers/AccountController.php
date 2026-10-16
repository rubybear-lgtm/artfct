<?php

namespace App\Http\Controllers;

use App\Enums\AuditEventType;
use App\Enums\TeamRole;
use App\Models\Membership;
use App\Models\OAuthRefreshToken;
use App\Models\Team;
use App\Models\User;
use App\Services\Auth\OrgTokenRevoker;
use App\Services\Governance\AuditLogger;
use App\Services\Teams\LastAdminGuard;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class AccountController extends Controller
{
    public function show(Request $request): Response
    {
        $user = $request->user();

        return Inertia::render('account', [
            'identities' => $user->externalIdentities()
                ->get()
                ->map(fn ($identity) => [
                    'provider' => $identity->provider,
                    'email' => $identity->email,
                ])->values(),
            'teams' => $user->teams()->get()->map(function (Team $team) use ($user): array {
                $userTeam = $user->toUserTeam($team);
                $leave = $this->leaveStatusFor($user, $team);

                return [
                    'slug' => $userTeam->slug,
                    'name' => $userTeam->name,
                    'role' => $userTeam->roleLabel,
                    'canLeave' => $leave['canLeave'],
                    'leaveBlockedReason' => $leave['reason'],
                ];
            })->values(),
            'blockingTeams' => $this->teamsBlockingDeletion($user)->map->name->values(),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $validated = $request->validate(['name' => ['required', 'string', 'max:255']]);

        $request->user()->forceFill(['name' => $validated['name']])->save();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Profile updated.')]);

        return back();
    }

    /**
     * Close the account: revoke the user's tokens, leave every team, delete
     * teams only they were in, then deactivate and anonymise the row (kept so
     * audit history stays intact). Blocked while they own a team that still
     * has other members: ownership must be transferred first.
     */
    public function destroy(Request $request, AuditLogger $auditLogger, OrgTokenRevoker $tokenRevoker): RedirectResponse
    {
        $request->validate(['confirmation' => ['required', 'in:DELETE']]);

        $user = $request->user();

        if ($this->teamsBlockingDeletion($user)->isNotEmpty()) {
            Inertia::flash('toast', ['type' => 'error', 'message' => __('Transfer ownership of your teams before deleting your account.')]);

            return back();
        }

        $tokens = $user->orgTokens()->where('expires_at', '>', now())->get();
        if (! $tokenRevoker->denylistTokens($tokens)) {
            abort(503, __('Unable to revoke your active credentials. Your account was not deleted. Please retry shortly.'));
        }

        $ownedTeamIds = Team::query()->where('owner_user_id', $user->id)->pluck('id');
        Team::query()
            ->whereIn('id', Membership::query()->where('user_id', $user->id)->pluck('team_id'))
            ->whereNotIn('id', $ownedTeamIds)
            ->get()
            ->each(fn (Team $team) => $auditLogger->recordForRequest($request, AuditEventType::AccountDeleted, $team, (string) $user->id, "user:{$user->id}"));
        $auditLogger->recordForRequest($request, AuditEventType::AccountDeleted, null, (string) $user->id, "user:{$user->id}");

        $user->orgTokens()->whereNull('revoked_at')->update(['revoked_at' => now()]);
        OAuthRefreshToken::query()->where('user_id', $user->id)->whereNull('revoked_at')->update(['revoked_at' => now()]);

        DB::transaction(function () use ($user): void {
            Team::query()->where('owner_user_id', $user->id)->get()->each(function (Team $team): void {
                $team->invitations()->delete();
                $team->memberships()->delete();
                $team->delete();
            });

            Membership::query()->where('user_id', $user->id)->delete();
            $user->externalIdentities()->delete();
            $user->forceFill([
                'name' => 'Deleted user',
                'email' => "deleted-{$user->id}@invalid.example",
                'current_team_id' => null,
                'deactivated_at' => now(),
            ])->save();
        });

        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('home');
    }

    /**
     * Whether the account page can offer a Leave button for this team, and the
     * plain-language reason when it cannot. Mirrors what TeamController::leave
     * would accept: a member of a non-personal team, not its owner, and not the
     * last admin.
     *
     * @return array{canLeave: bool, reason: ?string}
     */
    private function leaveStatusFor(User $user, Team $team): array
    {
        if ($team->is_personal) {
            return ['canLeave' => false, 'reason' => __('This is your personal team.')];
        }

        if ($team->owner_user_id === $user->id) {
            return ['canLeave' => false, 'reason' => __('Transfer ownership first.')];
        }

        if (! Gate::forUser($user)->allows('leave', $team)) {
            return ['canLeave' => false, 'reason' => __('You can\'t leave this team.')];
        }

        $leavesNoAdmin = LastAdminGuard::leavesNoAdmin(
            $team->memberships()->where('role', TeamRole::Admin->value)->count(),
            $user->teamRole($team) === TeamRole::Admin,
            false,
        );

        return [
            'canLeave' => ! $leavesNoAdmin,
            'reason' => $leavesNoAdmin ? __('Promote another admin first.') : null,
        ];
    }

    /**
     * Teams the user owns that still have other members.
     *
     * @return Collection<int, Team>
     */
    private function teamsBlockingDeletion(User $user)
    {
        return Team::query()
            ->where('owner_user_id', $user->id)
            ->whereHas('memberships', fn ($query) => $query->where('user_id', '!=', $user->id))
            ->get();
    }
}
