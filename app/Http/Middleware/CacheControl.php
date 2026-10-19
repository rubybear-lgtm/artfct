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
     * A signed-in visitor's page, and the artifact viewer (which carries a
     * short-lived access token in its props), are `private, no-store`: only a
     * public page for a guest is cacheable.
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
            $perViewer = $request->user() !== null || $request->is('a/*');

            $response->headers->set('Cache-Control', $perViewer ? 'private, no-store' : 'public, s-maxage=300, max-age=0');
        }

        return $response;
    }
}
