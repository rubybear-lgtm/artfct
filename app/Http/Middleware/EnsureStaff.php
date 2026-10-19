<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Support\StaffAccess;
use Closure;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Guards the whole `/ops` group (spec 17).
 *
 * It runs instead of `auth`: a signed-out visitor and a signed-in user who is
 * not staff both get the same 404 an unknown URL gets, never a redirect to
 * sign-in or a 403, so the surface is not discoverable.
 *
 * Who counts as staff is decided in one place, {@see StaffAccess}: `staff_at`
 * plus a session that came through the dedicated staff WorkOS organization, or
 * `staff_at` alone on a local or testing environment at a loopback host. Dev
 * login never produces a staff session.
 *
 * Known and accepted: a refused request still passes through the web
 * middleware group, so it may carry session and XSRF `Set-Cookie` headers
 * that an unmatched URL does not.
 */
class EnsureStaff
{
    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof User || ! StaffAccess::allows($user, $request)) {
            return $this->notFound($request);
        }

        $response = $next($request);
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');

        return $response;
    }

    /**
     * Render the application's ordinary 404, so a refused `/ops` request is
     * indistinguishable from an unknown URL. No header is added here for that
     * reason.
     */
    private function notFound(Request $request): Response
    {
        return app(ExceptionHandler::class)->render($request, StaffAccess::notFound($request));
    }
}
