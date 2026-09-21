<?php

namespace App\Http\Controllers;

use App\Enums\AuditEventType;
use App\Models\Membership;
use App\Models\OAuthRefreshToken;
use App\Models\Team;
use App\Models\User;
use App\Services\Auth\RevocationWriter;
use App\Services\Governance\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
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
            'teams' => $user->toUserTeams(includeCurrent: true)->map(fn ($team): array => [
                'slug' => $team->slug,
                'name' => $team->name,
                'role' => $team->roleLabel,
            ])->values(),
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
    public function destroy(Request $request, AuditLogger $auditLogger): RedirectResponse
    {
        $request->validate(['confirmation' => ['required', 'in:DELETE']]);

        $user = $request->user();

        if ($this->teamsBlockingDeletion($user)->isNotEmpty()) {
            Inertia::flash('toast', ['type' => 'error', 'message' => __('Transfer ownership of your teams before deleting your account.')]);

            return back();
        }

        $ownedTeamIds = Team::query()->where('owner_user_id', $user->id)->pluck('id');
        Team::query()
            ->whereIn('id', Membership::query()->where('user_id', $user->id)->pluck('team_id'))
            ->whereNotIn('id', $ownedTeamIds)
            ->get()
            ->each(fn (Team $team) => $auditLogger->recordForRequest($request, AuditEventType::AccountDeleted, $team, (string) $user->id, "user:{$user->id}"));
        $auditLogger->recordForRequest($request, AuditEventType::AccountDeleted, null, (string) $user->id, "user:{$user->id}");

        $user->orgTokens()->whereNull('revoked_at')->get()->each(function ($token): void {
            $token->revoked_at = now();
            $token->save();
            RevocationWriter::default()->revoke($token->jti, $token->expires_at);
        });
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
