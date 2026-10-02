<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequireOrgScope
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next, string $scope): Response
    {
        $claims = $request->attributes->get('org_jwt_claims', []);
        $scopes = is_array($claims)
            ? preg_split('/\s+/', trim((string) ($claims['scope'] ?? '')))
            : [];

        abort_unless(in_array($scope, $scopes ?: [], true), 403, "The connection requires the {$scope} scope.");

        return $next($request);
    }
}
