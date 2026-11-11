<?php

namespace Tests\Fixtures\FeatureFlags;

use App\Models\Team;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ReleaseTestContextMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        $request->attributes->set('org_jwt_team', Team::findOrFail((int) $request->header('X-Test-Team')));

        return $next($request);
    }
}
