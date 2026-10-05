<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CacheControl
{
    /**
     * Apply cache headers to successful GET responses for static HTML pages.
     *
     * Skips JSON/XHR requests (Inertia partial navigations), API routes,
     * and the session-authenticated artifact preview, which sets its own
     * `private, no-store` because its content is per-viewer and untrusted.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if (
            $request->isMethod('GET') &&
            $response->isSuccessful() &&
            ! $request->expectsJson() &&
            ! $request->is('api/*') &&
            ! $request->is('v1/*') &&
            ! $request->is('p/*') &&
            ! $request->routeIs('teams.artifacts.preview')
        ) {
            $response->headers->set('Cache-Control', 'public, s-maxage=300, max-age=0');
        }

        return $response;
    }
}
