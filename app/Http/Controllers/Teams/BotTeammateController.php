<?php

namespace App\Http\Controllers\Teams;

use App\Actions\Teams\CreateBotTeammate;
use App\Enums\AuditEventType;
use App\Enums\McpScope;
use App\Enums\TeamRole;
use App\Http\Controllers\Controller;
use App\Models\OrgToken;
use App\Models\Team;
use App\Models\User;
use App\Services\Auth\OrgTokenRevoker;
use App\Services\Governance\AuditLogger;
use App\Services\Teams\BotAvatarStore;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class BotTeammateController extends Controller
{
    /**
     * Add a bot teammate (no email) and mint its first agent token.
     * Same bar as minting org tokens: any non-viewer human member.
     */
    public function store(Request $request, Team $team, CreateBotTeammate $createBot, AuditLogger $auditLogger): JsonResponse|RedirectResponse
    {
        Gate::authorize('create', [OrgToken::class, $team]);
        abort_if($request->user()?->is_bot === true, 403);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'scopes' => ['sometimes', 'array', 'min:1'],
            'scopes.*' => ['string', Rule::in(McpScope::values())],
            'ttl_seconds' => ['sometimes', 'integer', 'min:60', 'max:31536000'],
            'avatar' => ['sometimes', 'nullable', 'image', 'max:2048'],
        ]);

        $scopes = array_values(array_intersect(
            McpScope::forRole($request->user()->teamRole($team)),
            $validated['scopes'] ?? [
                McpScope::ArtifactsRead->value,
                McpScope::ArtifactsDeploy->value,
                McpScope::CollectionsRead->value,
                McpScope::UsageRead->value,
            ],
        ));
        abort_if($scopes === [], 403, __('You cannot grant scopes beyond your team role.'));

        $avatarUrl = null;
        if ($request->hasFile('avatar')) {
            $avatarUrl = app(BotAvatarStore::class)->storeUploaded($request->file('avatar'));
        }

        $created = $createBot->handle(
            $team,
            $request->user(),
            $validated['name'],
            $scopes,
            isset($validated['ttl_seconds']) ? (int) $validated['ttl_seconds'] : null,
            $avatarUrl,
        );

        $auditLogger->recordForRequest(
            $request,
            AuditEventType::MemberAdded,
            $team,
            (string) $request->user()->id,
            "bot:{$created['bot']->id}",
        );

        if ($created['org_token'] !== null) {
            $auditLogger->recordForRequest(
                $request,
                AuditEventType::TokenCreated,
                $team,
                (string) $request->user()->id,
                "token:{$created['org_token']->id}",
            );
        }

        if ($request->expectsJson()) {
            return response()->json([
                'id' => $created['bot']->id,
                'name' => $created['bot']->name,
                'avatar' => $created['bot']->avatar,
                'token' => $created['token'],
                'expires_at' => $created['expires_at']?->toRfc3339String(),
                'mcp_endpoint' => url('/mcp'),
                'authorization_header' => $created['token'] === null ? null : 'Bearer '.$created['token'],
            ], 201);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Bot teammate added.')]);

        return back();
    }

    /**
     * Upload or replace a bot's avatar from team settings.
     */
    public function updateAvatar(Request $request, Team $team, User $user, BotAvatarStore $avatars): RedirectResponse
    {
        abort_unless($user->is_bot && $user->belongsToTeam($team), 404);
        Gate::authorize('create', [OrgToken::class, $team]);

        $validated = $request->validate([
            'avatar' => ['required', 'image', 'max:2048'],
        ]);

        $url = $avatars->storeUploaded($validated['avatar']);
        $avatars->deleteIfManaged($user->avatar);
        $user->forceFill(['avatar' => $url])->save();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Bot avatar updated.')]);

        return back();
    }

    /**
     * Remove a bot teammate and revoke its credentials.
     */
    public function destroy(Request $request, Team $team, User $user, AuditLogger $auditLogger, OrgTokenRevoker $tokenRevoker): RedirectResponse
    {
        abort_unless($user->is_bot && $user->belongsToTeam($team), 404);
        $canRemove = Gate::allows('removeMember', $team)
            || ($request->user()?->belongsToTeam($team) === true
                && $request->user()->teamRole($team) !== TeamRole::Viewer
                && $request->user()->is_bot !== true);
        abort_unless($canRemove, 403);

        if (! $tokenRevoker->revokeForMember($team, $user)) {
            abort(503, __('Unable to revoke this bot’s active credentials. Please retry shortly.'));
        }

        $team->memberships()->where('user_id', $user->id)->delete();
        app(BotAvatarStore::class)->deleteIfManaged($user->avatar);
        $user->delete();

        $auditLogger->recordForRequest(
            $request,
            AuditEventType::MemberRemoved,
            $team,
            (string) $request->user()->id,
            "bot:{$user->id}",
        );

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Bot teammate removed.')]);

        return to_route('teams.edit', ['team' => $team->slug]);
    }
}
