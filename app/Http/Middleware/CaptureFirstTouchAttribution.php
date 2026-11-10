<?php

namespace App\Http\Middleware;

use App\Services\Analytics\FirstTouchAttribution;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Persist first-touch UTMs / referrer / landing path in the session on the
 * first marketing or app page view. No new cookie — Laravel session only.
 */
final class CaptureFirstTouchAttribution
{
    public function __construct(private FirstTouchAttribution $firstTouch) {}

    public function handle(Request $request, Closure $next): Response
    {
        if ($request->isMethod('GET') && ! $request->expectsJson()) {
            $this->firstTouch->captureFromRequest($request);
        }

        return $next($request);
    }
}
