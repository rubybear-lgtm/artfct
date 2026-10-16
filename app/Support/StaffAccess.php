<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Exception\SuspiciousOperationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The one definition of "is this user staff right now" (spec 17).
 *
 * `users.staff_at` alone is a grant, not proof of a staff sign-in. A request
 * is a staff request when the grant exists and one of these holds:
 *
 *  - the environment is `local` or `testing` AND the request is addressed to a
 *    loopback or `.test` host, so a deployed host that is mis-set to
 *    `APP_ENV=local` does not drop the staff-organization requirement; or
 *  - the session carries `staff_session` written by the AuthKit callback for
 *    this very user, at or after the current grant, which only happens for a
 *    sign-in through the staff WorkOS organization on the real client.
 *
 * Both the `operate` gate and the `EnsureStaff` middleware call this, and
 * nothing else decides it.
 */
final class StaffAccess
{
    public const SESSION_KEY = 'staff_session';

    public static function allows(User $user, ?Request $request = null): bool
    {
        // The raw column check; `User::isStaff()` is not authorization.
        if ($user->staff_at === null) {
            return false;
        }

        $request ??= request();

        if (app()->environment(['local', 'testing']) && self::isDevelopmentHost($request)) {
            return true;
        }

        return $request->hasSession()
            && self::sessionBelongsToGrant($request->session()->get(self::SESSION_KEY), $user);
    }

    /**
     * The 404 a refused `/ops` request is answered with. It carries the same
     * message Laravel gives an unmatched URL, so a JSON client cannot tell the
     * two apart.
     */
    public static function notFound(Request $request, ?\Throwable $previous = null): NotFoundHttpException
    {
        return new NotFoundHttpException(
            sprintf('The route %s could not be found.', $request->path()),
            $previous,
        );
    }

    private static function isDevelopmentHost(Request $request): bool
    {
        try {
            $host = strtolower($request->getHost());
        } catch (SuspiciousOperationException) {
            return false;
        }

        return in_array($host, ['localhost', '127.0.0.1', '::1', '[::1]'], true)
            || str_ends_with($host, '.test');
    }

    /**
     * A staff session is `['user_id' => int, 'at' => int]`. It counts only for
     * the user it was written for, and only when it is not older than the
     * current grant, so a revoke followed by a re-grant needs a fresh sign-in
     * through the staff organization.
     */
    private static function sessionBelongsToGrant(mixed $flag, User $user): bool
    {
        if (! is_array($flag)) {
            return false;
        }

        $userId = $flag['user_id'] ?? null;
        $signedInAt = $flag['at'] ?? null;

        $grantedAt = match (true) {
            $user->staff_at instanceof \DateTimeInterface => $user->staff_at->getTimestamp(),
            is_string($user->staff_at) => strtotime($user->staff_at),
            default => false,
        };

        return $userId === $user->id
            && is_int($signedInAt)
            && $grantedAt !== false
            && $signedInAt >= $grantedAt;
    }
}
