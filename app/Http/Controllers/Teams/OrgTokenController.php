<?php

namespace App\Http\Controllers\Teams;

use App\Enums\TeamRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Teams\CreateOrgTokenRequest;
use App\Models\OrgToken;
use App\Models\Team;
use App\Services\Auth\OrgJwtService;
use App\Services\Auth\RevocationWriter;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

class OrgTokenController extends Controller
{
    /**
     * Mint a new org token (spec 07 `orgToken`) for the CLI/MCP server/CI.
     * The raw JWT is returned exactly once, here, in this response — the
     * `org_tokens` row stores only `jti` and a display-only `last_four`
     * (spec 07: "token creation returns value once only").
     */
    public function store(CreateOrgTokenRequest $request, Team $team): JsonResponse
    {
        Gate::authorize('create', [OrgToken::class, $team]);

        $role = TeamRole::from($request->validated('role'));
        $ttlSeconds = (int) $request->validated('ttl_seconds', 31536000);

        $minted = OrgJwtService::default()->mint($team, $request->user(), $role, $ttlSeconds);

        $orgToken = OrgToken::query()->create([
            'team_id' => $team->id,
            'user_id' => $request->user()->id,
            'name' => $request->validated('name'),
            'jti' => $minted['jti'],
            'role' => $role,
            'last_four' => Str::substr($minted['token'], -4),
            'expires_at' => $minted['expires_at'],
        ]);

        return response()->json([
            'id' => $orgToken->id,
            'token' => $minted['token'],
            'expires_at' => $minted['expires_at']->toRfc3339String(),
        ], 201);
    }

    /**
     * Revoke an org token: marks it revoked locally and writes to the
     * Worker's KV denylist so the next request using it is rejected at the
     * edge within the documented propagation window.
     */
    public function destroy(Team $team, OrgToken $token): JsonResponse
    {
        abort_unless($token->team_id === $team->id, 404);

        Gate::authorize('revoke', $token);

        $token->revoked_at = now();
        $token->save();

        RevocationWriter::default()->revoke($token->jti, $token->expires_at);

        return response()->json(['revoked' => true]);
    }
}
