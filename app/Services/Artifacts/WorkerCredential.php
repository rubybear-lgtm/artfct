<?php

namespace App\Services\Artifacts;

use App\Models\Team;
use App\Services\Auth\OrgJwtService;
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * The credential for a Worker call about an org: the caller's own bearer token
 * when the request carries one (API callers), otherwise a short-lived org token
 * minted for the signed-in user with their role in that team. A user who is not
 * a member of the team gets no credential, so the Worker never sees a request
 * about a team the user cannot access.
 */
final class WorkerCredential
{
    public static function forOrg(string $orgSlug): string
    {
        if ($bearer = request()->bearerToken()) {
            return $bearer;
        }

        $user = auth()->user();
        $team = Team::query()->where('slug', $orgSlug)->first();
        $role = $user !== null && $team !== null ? $user->teamRole($team) : null;

        if ($role === null) {
            throw new HttpResponseException(response()->json(['error' => 'Forbidden'], 403));
        }

        return OrgJwtService::default()->mintFor($orgSlug, (string) $user->id, $role)['token'];
    }
}
