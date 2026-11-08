<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Keeps private and auth surfaces out of search indexes (RUB-513).
 *
 * Artifact links (`/a/*`) and the sign-in entry (`/login`) are not marketing
 * pages. Google finds them from shared links and, without this header, may
 * treat the login redirect chain as a crawlable URL. Marketing pages stay
 * indexable: this middleware only touches the paths listed below.
 */
class NoIndexPrivateSurfaces
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if ($request->is('a', 'a/*', 'login')) {
            $response->headers->set('X-Robots-Tag', 'noindex, nofollow');
        }

        return $response;
    }
}
