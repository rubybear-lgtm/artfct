<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Guards the whole `/ops` group (spec 17).
 *
 * It runs instead of `auth`: a signed-out visitor and a signed-in user who is
 * not staff both get the same 404 an unknown URL gets, never a redirect to
 * sign-in or a 403, so the surface is not discoverable.
 *
 * Staff need `users.staff_at` and a session that came through the dedicated
 * staff WorkOS organization (`staff_session`, set by the AuthKit callback).
 * Local and testing environments accept `staff_at` alone so the console is
 * usable without WorkOS. Dev login never sets the flag.
 */
class EnsureStaff
{
    public const SESSION_KEY = 'staff_session';

    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->isStaffRequest($request)) {
            return $this->notFound($request);
        }

        $response = $next($request);
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');

        return $response;
    }

    private function isStaffRequest(Request $request): bool
    {
        $user = $request->user();

        if (! $user instanceof User || Gate::forUser($user)->denies('operate')) {
            return false;
        }

        if (app()->environment(['local', 'testing'])) {
            return true;
        }

        return $request->hasSession() && $this->sessionBelongsToGrant($request->session()->get(self::SESSION_KEY), $user);
    }

    /**
     * A staff session is `['user_id' => int, 'at' => int]`, written by the
     * AuthKit callback. It counts only for the user it was written for, and
     * only when it is not older than the current grant, so a revoke followed
     * by a re-grant needs a fresh sign-in through the staff organization.
     */
    private function sessionBelongsToGrant(mixed $flag, User $user): bool
    {
        if (! is_array($flag) || $user->staff_at === null) {
            return false;
        }

        $userId = $flag['user_id'] ?? null;
        $signedInAt = $flag['at'] ?? null;

        return $userId === $user->id
            && is_int($signedInAt)
            && $signedInAt >= $user->staff_at->timestamp;
    }

    /**
     * Render the application's ordinary 404, byte for byte, so a refused
     * `/ops` request is indistinguishable from an unknown URL. No header is
     * added here for that reason.
     */
    private function notFound(Request $request): Response
    {
        return app(ExceptionHandler::class)->render($request, new NotFoundHttpException);
    }
}
