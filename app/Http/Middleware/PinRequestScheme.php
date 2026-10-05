<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Marks the request as https when the application URL is https.
 *
 * The Railway edge reaches the app over plain http and is not a trusted proxy
 * (RUB-372), so without this the request reports itself as http. Generated
 * URLs are already pinned by `URL::forceScheme('https')`, but URLs read back
 * from the request are not: `fullUrl()` stored as `url.intended` (by the auth
 * and terms gates) and `_previous.url`. An http redirect from an https page is
 * blocked as mixed content, so an Inertia form looked like it needed a second
 * click. This reads no header; the scheme comes from configuration only.
 */
class PinRequestScheme
{
    public function handle(Request $request, Closure $next): Response
    {
        if (str_starts_with((string) config('app.url'), 'https://') && ! $request->isSecure()) {
            $request->server->set('HTTPS', 'on');
        }

        return $next($request);
    }
}
