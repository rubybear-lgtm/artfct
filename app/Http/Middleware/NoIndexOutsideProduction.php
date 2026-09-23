<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Keeps staging and other non-production environments out of search indexes.
 */
class NoIndexOutsideProduction
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if (! app()->environment('production')) {
            $response->headers->set('X-Robots-Tag', 'noindex, nofollow');
        }

        return $response;
    }
}
