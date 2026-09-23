<?php

namespace App\Http\Controllers\Api;

use App\Enums\AuditEventType;
use App\Enums\TeamRole;
use App\Http\Controllers\Controller;
use App\Models\McpConnection;
use App\Models\Team;
use App\Models\User;
use App\Services\Governance\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class McpConnectionController extends Controller
{
    public function register(Request $request, AuditLogger $auditLogger): JsonResponse
    {
        $claims = $this->claims($request);
        $validated = $request->validate([
            'client_name' => ['required', 'string', 'max:100'],
            'client_version' => ['nullable', 'string', 'max:50'],
            'host' => ['nullable', 'string', 'max:100'],
            'transport' => ['required', Rule::in(['stdio', 'sse', 'streamable-http'])],
        ]);

        $connection = McpConnection::query()
            ->where('credential_jti', $claims['jti'])
            ->first();

        if ($connection?->revoked_at !== null) {
            return response()->json(['error' => 'MCP connection revoked'], 403);
        }

        $isNew = $connection === null;
        $connection ??= new McpConnection;
        $connection->forceFill([
            'public_id' => $connection->public_id,
            'team_id' => $claims['team']->id,
            'user_id' => User::query()->whereKey((int) $claims['user_id'])->value('id'),
            'credential_jti' => $claims['jti'],
            'name' => $connection->name ?: "{$validated['client_name']} MCP",
            'client_name' => $validated['client_name'],
            'client_version' => $validated['client_version'] ?? null,
            'transport' => $validated['transport'],
            'scopes' => $this->scopesForRole($claims['role'], $claims['scope'] ?? null),
            'metadata' => array_filter(['host' => $validated['host'] ?? null]),
            'last_used_at' => now(),
            'expires_at' => $connection->expires_at,
            'revoked_at' => null,
        ])->save();

        $auditLogger->recordForRequest(
            $request,
            $isNew ? AuditEventType::McpConnectionCreated : AuditEventType::McpConnectionRefreshed,
            $claims['team'],
            (string) $claims['user_id'],
            "mcp_connection:{$connection->public_id}",
        );

        return response()->json($this->response($connection, $claims), $isNew ? 201 : 200);
    }

    public function heartbeat(Request $request, AuditLogger $auditLogger): JsonResponse
    {
        $claims = $this->claims($request);
        $connection = McpConnection::query()
            ->where('credential_jti', $claims['jti'])
            ->where('team_id', $claims['team']->id)
            ->first();

        if ($connection === null) {
            return response()->json(['error' => 'MCP connection is not registered'], 404);
        }

        if ($connection->revoked_at !== null || $connection->isExpired()) {
            return response()->json(['error' => 'MCP connection is inactive'], 403);
        }

        $connection->touchUsage();

        $auditLogger->recordForRequest(
            $request,
            AuditEventType::McpConnectionRefreshed,
            $claims['team'],
            (string) $claims['user_id'],
            "mcp_connection:{$connection->public_id}",
        );

        return response()->json($this->response($connection, $claims));
    }

    /**
     * @return array{jti: string, user_id: string, role: string, team: Team, scope?: string}
     */
    private function claims(Request $request): array
    {
        /** @var array{jti: string, user_id: string, role: string} $claims */
        $claims = $request->attributes->get('org_jwt_claims');
        /** @var Team $team */
        $team = $request->attributes->get('org_jwt_team');

        return [...$claims, 'team' => $team];
    }

    /**
     * @param  array{jti: string, user_id: string, role: string, team: Team, scope?: string}  $claims
     * @return array<string, mixed>
     */
    private function response(McpConnection $connection, array $claims): array
    {
        return [
            'connection_id' => $connection->public_id,
            'organization' => $claims['team']->slug,
            'user_id' => $claims['user_id'],
            'scopes' => $connection->scopes,
            'last_used_at' => $connection->last_used_at?->toRfc3339String(),
        ];
    }

    /**
     * @return list<string>
     */
    private function scopesForRole(string $role, ?string $requestedScopes = null): array
    {
        $allowed = match (TeamRole::tryFrom($role)) {
            TeamRole::Admin, TeamRole::Member => ['artifacts:read', 'artifacts:deploy', 'artifacts:delete', 'collections:read', 'collections:write', 'usage:read'],
            TeamRole::Viewer => ['artifacts:read', 'collections:read', 'usage:read'],
            default => [],
        };

        if ($requestedScopes === null) {
            return $allowed;
        }

        return array_values(array_intersect($allowed, preg_split('/\s+/', trim($requestedScopes)) ?: []));
    }
}
