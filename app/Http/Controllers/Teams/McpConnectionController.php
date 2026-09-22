<?php

namespace App\Http\Controllers\Teams;

use App\Enums\AuditEventType;
use App\Enums\TeamPermission;
use App\Enums\TeamRole;
use App\Http\Controllers\Controller;
use App\Models\McpActivity;
use App\Models\McpConnection;
use App\Models\OrgToken;
use App\Models\Team;
use App\Services\Auth\RevocationWriter;
use App\Services\Governance\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class McpConnectionController extends Controller
{
    /** @var list<string> */
    private const SUPPORTED_SCOPES = ['artifacts:read', 'artifacts:deploy', 'collections:read', 'collections:write', 'usage:read'];

    /** @var list<string> */
    private const DEFAULT_SCOPES = ['artifacts:read', 'collections:read', 'usage:read'];

    public function index(Request $request, Team $team): Response
    {
        abort_unless($request->user()->belongsToTeam($team), 404);

        Gate::authorize('viewAny', [McpConnection::class, $team]);
        $canManageConnections = $request->user()->hasTeamPermission($team, TeamPermission::RemoveMember);
        $usageSince = now()->subDays(30);
        $usageQuery = McpActivity::query()
            ->where('team_id', $team->id)
            ->where('created_at', '>=', $usageSince);
        $usageCalls = (clone $usageQuery)->count();
        $usageSuccessfulCalls = (clone $usageQuery)->where('outcome', 'success')->count();
        $usageFailedCalls = $usageCalls - $usageSuccessfulCalls;
        $usageAverageLatency = (int) round((float) ((clone $usageQuery)->avg('latency_ms') ?? 0));
        $usageByTool = (clone $usageQuery)
            ->select('tool')
            ->selectRaw('COUNT(*) as calls')
            ->selectRaw("SUM(CASE WHEN outcome = 'success' THEN 1 ELSE 0 END) as successful_calls")
            ->selectRaw("SUM(CASE WHEN outcome != 'success' THEN 1 ELSE 0 END) as failed_calls")
            ->selectRaw('AVG(latency_ms) as average_latency_ms')
            ->groupBy('tool')
            ->orderByDesc('calls')
            ->get()
            ->map(fn (McpActivity $activity): array => [
                'tool' => $activity->tool,
                'calls' => (int) $activity->calls,
                'successfulCalls' => (int) $activity->successful_calls,
                'failedCalls' => (int) $activity->failed_calls,
                'averageLatencyMs' => (int) round((float) $activity->average_latency_ms),
            ])
            ->values()
            ->all();

        return Inertia::render('teams/mcp-connections', [
            'team' => ['slug' => $team->slug, 'name' => $team->name],
            'mcpEndpoint' => url('/mcp'),
            'oauthMetadataUrl' => url('/.well-known/oauth-protected-resource'),
            'scopeOptions' => $this->allowedScopesForRole($request->user()->teamRole($team)),
            'defaultScopes' => self::DEFAULT_SCOPES,
            'usage' => [
                'periodDays' => 30,
                'since' => $usageSince->toIso8601String(),
                'calls' => $usageCalls,
                'successfulCalls' => $usageSuccessfulCalls,
                'failedCalls' => $usageFailedCalls,
                'successRate' => $usageCalls === 0 ? 0 : round($usageSuccessfulCalls / $usageCalls * 100, 1),
                'averageLatencyMs' => $usageAverageLatency,
                'byTool' => $usageByTool,
            ],
            'connections' => $team->mcpConnections()
                ->with('user:id,name')
                ->latest('last_used_at')
                ->latest('created_at')
                ->get()
                ->map(fn (McpConnection $connection): array => [
                    'id' => $connection->public_id,
                    'name' => $connection->name,
                    'clientName' => $connection->client_name,
                    'clientVersion' => $connection->client_version,
                    'transport' => $connection->transport,
                    'scopes' => $connection->scopes,
                    'createdAt' => $connection->created_at?->toIso8601String(),
                    'lastUsedAt' => $connection->last_used_at?->toIso8601String(),
                    'expiresAt' => $connection->expires_at?->toIso8601String(),
                    'revokedAt' => $connection->revoked_at?->toIso8601String(),
                    'createdBy' => $connection->user?->name,
                    'canRevoke' => $connection->user_id === $request->user()->id || $canManageConnections,
                ]),
            'activity' => McpActivity::query()
                ->where('team_id', $team->id)
                ->latest('created_at')
                ->limit(25)
                ->get()
                ->map(fn (McpActivity $activity): array => [
                    'tool' => $activity->tool,
                    'clientName' => $activity->client_name,
                    'outcome' => $activity->outcome,
                    'latencyMs' => $activity->latency_ms,
                    'createdAt' => $activity->created_at?->toIso8601String(),
                    'requestId' => $activity->request_id,
                ]),
        ]);
    }

    /**
     * Start a connection for a client without requiring the client to
     * register itself first. Scopes are capped by the creator's role and
     * default to the read-only set, never to everything the role carries.
     */
    public function store(Request $request, Team $team, AuditLogger $auditLogger): RedirectResponse
    {
        abort_unless($request->user()->belongsToTeam($team), 404);

        $validated = $request->validate([
            'client_name' => ['required', 'string', 'max:100'],
            'scopes' => ['sometimes', 'array', 'min:1'],
            'scopes.*' => ['string', Rule::in(self::SUPPORTED_SCOPES)],
        ]);

        $scopes = array_values(array_intersect(
            $this->allowedScopesForRole($request->user()->teamRole($team)),
            $validated['scopes'] ?? self::DEFAULT_SCOPES,
        ));

        abort_if($scopes === [], 403, __('You cannot grant scopes beyond your team role.'));

        $connection = new McpConnection;
        $connection->forceFill([
            'team_id' => $team->id,
            'user_id' => $request->user()->id,
            'name' => "{$validated['client_name']} MCP",
            'client_name' => $validated['client_name'],
            'transport' => 'streamable-http',
            'scopes' => $scopes,
        ])->save();

        $auditLogger->recordForRequest(
            $request,
            AuditEventType::McpConnectionCreated,
            $team,
            (string) $request->user()->id,
            "mcp_connection:{$connection->public_id}",
        );

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Connection created. Reconnect the client to authorize it.')]);

        return back();
    }

    /**
     * Force reauthorization: revoke the credentials the live client holds so
     * it has to run the OAuth flow again, without retiring the connection
     * record. Shares the credential-revocation mechanism with `destroy()`.
     */
    public function reauthorize(Request $request, Team $team, McpConnection $connection, AuditLogger $auditLogger): RedirectResponse
    {
        abort_unless($request->user()->belongsToTeam($team), 404);
        abort_unless($connection->team_id === $team->id, 404);

        Gate::authorize('revoke', $connection);

        if ($connection->revoked_at === null) {
            $this->revokeLiveCredentials($connection);
            $auditLogger->recordForRequest(
                $request,
                AuditEventType::McpConnectionReauthorized,
                $team,
                (string) $request->user()->id,
                "mcp_connection:{$connection->public_id}",
            );
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Reauthorization required. Live credentials were revoked; reconnect the client to authorize again.')]);

        return back();
    }

    public function destroy(Request $request, Team $team, McpConnection $connection, AuditLogger $auditLogger): RedirectResponse
    {
        abort_unless($request->user()->belongsToTeam($team), 404);
        abort_unless($connection->team_id === $team->id, 404);

        Gate::authorize('revoke', $connection);

        if ($connection->revoked_at === null) {
            $connection->forceFill(['revoked_at' => now()])->save();
            $this->revokeLiveCredentials($connection);
            $auditLogger->recordForRequest(
                $request,
                AuditEventType::McpConnectionRevoked,
                $team,
                (string) $request->user()->id,
                "mcp_connection:{$connection->public_id}",
            );
        }

        return back();
    }

    /**
     * Revoke the credentials a live client is holding: refresh tokens and
     * org-scoped access tokens, including the Worker denylist entries. The
     * connection row itself is untouched.
     */
    private function revokeLiveCredentials(McpConnection $connection): void
    {
        $connection->oauthRefreshTokens()->whereNull('revoked_at')->update(['revoked_at' => now()]);
        $connection->orgTokens()->whereNull('revoked_at')->get()->each(function (OrgToken $orgToken): void {
            $orgToken->forceFill(['revoked_at' => now()])->saveQuietly();
            RevocationWriter::default()->revoke($orgToken->jti, $orgToken->expires_at);
        });
    }

    /**
     * The scopes a person may attach to a connection: never more than their
     * own role carries, and read-only for viewers. Mirrors the capping the
     * API's registration path applies (`Api\McpConnectionController`).
     *
     * @return list<string>
     */
    private function allowedScopesForRole(?TeamRole $role): array
    {
        return match ($role) {
            TeamRole::Admin, TeamRole::Member => self::SUPPORTED_SCOPES,
            default => ['artifacts:read', 'collections:read', 'usage:read'],
        };
    }
}
