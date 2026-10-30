<?php

namespace App\Http\Controllers\Teams;

use App\Enums\AuditEventType;
use App\Enums\McpScope;
use App\Enums\OrgTokenKind;
use App\Enums\TeamRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Teams\CreateOrgTokenRequest;
use App\Models\McpConnection;
use App\Models\OrgToken;
use App\Models\Team;
use App\Models\User;
use App\Services\Auth\OrgJwtService;
use App\Services\Auth\OrgTokenRevoker;
use App\Services\Governance\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class OrgTokenController extends Controller
{
    public function index(Request $request, Team $team): Response
    {
        abort_unless($request->user()->belongsToTeam($team), 404);

        $role = $request->user()->teamRole($team);

        return Inertia::render('teams/tokens', [
            'team' => ['slug' => $team->slug, 'name' => $team->name],
            'canCreate' => $request->user()->can('create', [OrgToken::class, $team]),
            'mcpEndpoint' => url('/mcp'),
            'roles' => collect(TeamRole::cases())
                ->filter(fn (TeamRole $item) => $role?->canGrant($item))
                ->map(fn (TeamRole $item) => ['value' => $item->value, 'label' => $item->label()])
                ->values(),
            'scopeOptions' => McpScope::forRole($role),
            'scopeLabels' => collect(McpScope::cases())
                ->mapWithKeys(fn (McpScope $scope): array => [$scope->value => $scope->label()])
                ->all(),
            'defaultScopes' => [
                McpScope::ArtifactsRead->value,
                McpScope::ArtifactsDeploy->value,
                McpScope::CollectionsRead->value,
                McpScope::UsageRead->value,
            ],
            'bots' => $team->members()
                ->where('is_bot', true)
                ->orderBy('name')
                ->get()
                ->map(fn (User $bot): array => [
                    'id' => $bot->id,
                    'name' => $bot->name,
                    'avatar' => $bot->avatar,
                ])
                ->values(),
            'tokens' => OrgToken::query()->where('team_id', $team->id)->with('user:id,name,is_bot')->latest()->get()->map(fn (OrgToken $token) => [
                'id' => $token->id,
                'name' => $token->name,
                'kind' => $token->kind->value,
                'role' => $token->role->value,
                'scopes' => $token->scopes ?? [],
                'botId' => $token->user->is_bot ? $token->user_id : null,
                'botName' => $token->user->is_bot ? $token->user->name : null,
                'lastFour' => $token->last_four,
                'expiresAt' => $token->expires_at->toIso8601String(),
                'lastUsedAt' => $token->last_used_at?->toIso8601String(),
                'revokedAt' => $token->revoked_at?->toIso8601String(),
                'status' => $token->revoked_at !== null ? 'revoked' : ($token->expires_at->isPast() ? 'expired' : 'active'),
                'canRevoke' => $request->user()->can('revoke', $token),
            ]),
        ]);
    }

    /**
     * Mint a new org token for the REST API/CI or an MCP agent (RUB-511).
     * The raw JWT is returned exactly once — the `org_tokens` row stores only
     * `jti` and a display-only `last_four` (spec 07: "token creation returns
     * value once only"). Agent tokens also register an MCP connection so the
     * bot appears under AI tool connections and last-used updates on use.
     */
    public function store(CreateOrgTokenRequest $request, Team $team, AuditLogger $auditLogger): JsonResponse
    {
        Gate::authorize('create', [OrgToken::class, $team]);

        $kind = $request->kind();
        $scopes = $request->scopes();
        $creatorRole = $request->user()->teamRole($team);
        abort_unless($creatorRole instanceof TeamRole, 403);
        abort_if($request->user()->is_bot, 403);

        $tokenUser = $request->user();

        if ($kind === OrgTokenKind::Agent) {
            $bot = User::query()->whereKey((int) $request->validated('bot_id'))->firstOrFail();
            abort_unless($bot->is_bot && $bot->belongsToTeam($team), 404);
            $tokenUser = $bot;
            $role = $bot->teamRole($team) ?? TeamRole::Member;
            abort_if($scopes === null || $scopes === [], 422, __('Choose at least one permission for this agent token.'));
            $scopes = array_values(array_intersect(McpScope::forRole($creatorRole), $scopes));
            abort_if($scopes === [], 403, __('You cannot grant scopes beyond your team role.'));
        } else {
            $role = TeamRole::from($request->validated('role'));
            abort_unless($creatorRole->canGrant($role), 403, __('You cannot create a token with a higher role than your own.'));
            if ($scopes !== null) {
                $scopes = array_values(array_intersect(McpScope::forRole($creatorRole), $scopes));
            }
        }

        $ttlSeconds = (int) $request->validated('ttl_seconds', 31536000);

        $minted = OrgJwtService::default()->mint($team, $tokenUser, $role, $ttlSeconds, $scopes);

        $orgToken = DB::transaction(function () use ($request, $team, $kind, $role, $scopes, $minted, $tokenUser): OrgToken {
            $connection = null;

            if ($kind === OrgTokenKind::Agent) {
                $connection = new McpConnection;
                $connection->forceFill([
                    'public_id' => (string) Str::uuid(),
                    'credential_jti' => $minted['jti'],
                    'team_id' => $team->id,
                    'user_id' => $tokenUser->id,
                    'name' => $request->validated('name'),
                    'client_name' => $tokenUser->name,
                    'transport' => 'streamable-http',
                    'scopes' => $scopes,
                    'expires_at' => $minted['expires_at'],
                ])->save();
            }

            return OrgToken::query()->create([
                'team_id' => $team->id,
                'user_id' => $tokenUser->id,
                'mcp_connection_id' => $connection?->id,
                'name' => $request->validated('name'),
                'kind' => $kind,
                'jti' => $minted['jti'],
                'role' => $role,
                'scopes' => $scopes,
                'last_four' => Str::substr($minted['token'], -4),
                'expires_at' => $minted['expires_at'],
            ]);
        });

        $auditLogger->recordForRequest($request, AuditEventType::TokenCreated, $team, (string) $request->user()->id, "token:{$orgToken->id}");

        return response()->json([
            'id' => $orgToken->id,
            'token' => $minted['token'],
            'kind' => $kind->value,
            'expires_at' => $minted['expires_at']->toRfc3339String(),
            'mcp_endpoint' => url('/mcp'),
            'authorization_header' => 'Bearer '.$minted['token'],
        ], 201);
    }

    /**
     * Revoke an org token: marks it revoked locally and writes to the
     * Worker's KV denylist so the next request using it is rejected at the
     * edge within the documented propagation window.
     */
    public function destroy(Request $request, Team $team, OrgToken $token, AuditLogger $auditLogger, OrgTokenRevoker $tokenRevoker): JsonResponse|RedirectResponse
    {
        abort_unless($token->team_id === $team->id, 404);

        Gate::authorize('revoke', $token);

        if (! $tokenRevoker->denylistTokens([$token])) {
            if ($request->expectsJson()) {
                abort(503, __('Unable to revoke this token at the Worker. The token remains active; please retry shortly.'));
            }

            Inertia::flash('toast', ['type' => 'error', 'message' => __("Couldn't revoke the token right now. It still works, so try again in a minute.")]);

            return back();
        }

        $token->revoked_at = now();
        $token->save();
        McpConnection::query()
            ->where('credential_jti', $token->jti)
            ->whereNull('revoked_at')
            ->update(['revoked_at' => $token->revoked_at]);

        $auditLogger->recordForRequest($request, AuditEventType::TokenRevoked, $team, (string) $request->user()->id, "token:{$token->id}");

        if ($request->expectsJson()) {
            return response()->json(['revoked' => true]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Token revoked. Anything using it stops working within a minute.')]);

        return back();
    }
}
