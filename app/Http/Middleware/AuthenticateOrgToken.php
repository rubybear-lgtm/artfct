<?php

namespace App\Http\Middleware;

use App\Enums\AuditEventType;
use App\Enums\TeamRole;
use App\Models\McpConnection;
use App\Models\OrgToken;
use App\Models\Team;
use App\Models\User;
use App\Services\Auth\OrgJwtService;
use App\Services\Governance\AuditLogger;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Authenticates `/api/search` (spec 13) against an org token's bearer JWT,
 * resolving the org strictly from the credential — DoD: "Search runs
 * strictly within the caller's org, resolved from the credential, never
 * from a parameter." A missing/invalid/revoked token is 401, never a
 * silent fallback to some default org.
 */
class AuthenticateOrgToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $header = $request->header('Authorization', '');
        if (! str_starts_with($header, 'Bearer ')) {
            return response()->json(['error' => 'Missing bearer token'], 401);
        }

        $token = substr($header, strlen('Bearer '));

        try {
            $claims = OrgJwtService::default()->verify($token);
        } catch (\Throwable) {
            return response()->json(['error' => 'Invalid token'], 401);
        }

        if (! isset($claims['scope'])) {
            $claims['scope'] = match (TeamRole::tryFrom($claims['role'])) {
                TeamRole::Admin, TeamRole::Member => 'artifacts:read artifacts:deploy collections:read collections:write usage:read',
                TeamRole::Viewer => 'artifacts:read collections:read usage:read',
                default => '',
            };
        }

        $orgToken = OrgToken::query()->where('jti', $claims['jti'])->first();
        if ($orgToken !== null && $orgToken->revoked_at !== null) {
            return response()->json(['error' => 'Token revoked'], 401);
        }

        if ($request->is('mcp')) {
            $connection = McpConnection::query()
                ->where('credential_jti', $claims['jti'])
                ->first();

            if ($connection?->revoked_at !== null) {
                return response()->json(['error' => 'MCP connection revoked'], 401);
            }

            if ($connection?->isExpired()) {
                return response()->json(['error' => 'MCP connection expired'], 401);
            }
        }

        $team = Team::query()->where('slug', $claims['org_id'])->first();
        if ($team === null) {
            return response()->json(['error' => 'Unknown org'], 401);
        }

        if ($request->is('mcp')) {
            $this->syncHostedConnection($request, $claims, $team, app(AuditLogger::class));
        }

        $request->attributes->set('org_jwt_team', $team);
        $request->attributes->set('org_jwt_claims', $claims);

        return $next($request);
    }

    /**
     * Keep hosted MCP clients visible and revocable in the same connection
     * registry used by the local CLI. The credential JTI is the stable key;
     * client-provided metadata is display-only.
     *
     * @param  array{jti: string, user_id: string, role: string, scope?: string}  $claims
     */
    private function syncHostedConnection(Request $request, array $claims, Team $team, AuditLogger $auditLogger): void
    {
        $params = $request->json('params', []);
        $clientInfo = is_array($params) && is_array($params['clientInfo'] ?? null)
            ? $params['clientInfo']
            : [];
        $clientName = $this->boundedString(
            $clientInfo['name'] ?? $request->header('MCP-Client-Name'),
            100,
        );
        $clientVersion = $this->boundedString($clientInfo['version'] ?? $request->header('MCP-Client-Version'), 50);
        $connection = McpConnection::query()->where('credential_jti', $claims['jti'])->first();
        $isNew = $connection === null;

        if ($isNew) {
            $clientName ??= $this->boundedString($request->userAgent(), 100) ?? 'unknown-mcp-client';
        }

        if ($isNew) {
            $connection = new McpConnection;
            $connection->forceFill([
                'public_id' => (string) Str::uuid(),
                'credential_jti' => $claims['jti'],
                'team_id' => $team->id,
                'user_id' => User::query()->whereKey((int) $claims['user_id'])->value('id'),
                'name' => $clientName.' MCP',
            ]);
        }

        $connection->forceFill(array_filter([
            'client_name' => $clientName,
            'client_version' => $clientVersion,
            'transport' => 'streamable-http',
            'scopes' => preg_split('/\s+/', trim((string) ($claims['scope'] ?? ''))) ?: [],
            'last_used_at' => now(),
        ], static fn (mixed $value, string $key): bool => $key === 'client_name' || $key === 'client_version' ? $value !== null : true, ARRAY_FILTER_USE_BOTH))->saveQuietly();
        $request->attributes->set('mcp_connection', $connection);

        if ($isNew) {
            $auditLogger->recordForRequest(
                $request,
                AuditEventType::McpConnectionCreated,
                $team,
                (string) $claims['user_id'],
                "mcp_connection:{$connection->public_id}",
            );
        }
    }

    private function boundedString(mixed $value, int $length): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        return mb_substr(trim($value), 0, $length);
    }
}
