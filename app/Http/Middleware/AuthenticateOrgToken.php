<?php

namespace App\Http\Middleware;

use App\Models\OrgToken;
use App\Models\Team;
use App\Services\Auth\OrgJwtService;
use Closure;
use Illuminate\Http\Request;
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

        $orgToken = OrgToken::query()->where('jti', $claims['jti'])->first();
        if ($orgToken !== null && $orgToken->revoked_at !== null) {
            return response()->json(['error' => 'Token revoked'], 401);
        }

        $team = Team::query()->where('slug', $claims['org_id'])->first();
        if ($team === null) {
            return response()->json(['error' => 'Unknown org'], 401);
        }

        $request->attributes->set('org_jwt_team', $team);
        $request->attributes->set('org_jwt_claims', $claims);

        return $next($request);
    }
}
