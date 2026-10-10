<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Verifies Slack's request signature (`v0=` + HMAC-SHA256 over
 * `v0:{timestamp}:{rawBody}`) on every inbound Slack route. Any failure,
 * including an unset signing secret, is a bare 401 — never accepts.
 */
class VerifySlackSignature
{
    public const TOLERANCE_SECONDS = 300;

    public function handle(Request $request, Closure $next): Response
    {
        $secret = config('services.slack.signing_secret');
        $timestamp = $request->header('X-Slack-Request-Timestamp');
        $signature = $request->header('X-Slack-Signature');

        if (! is_string($secret) || $secret === '' || ! is_string($timestamp) || ! is_string($signature)) {
            return response('', 401);
        }

        if (! ctype_digit($timestamp) || abs(now()->timestamp - (int) $timestamp) > self::TOLERANCE_SECONDS) {
            return response('', 401);
        }

        $expected = 'v0='.hash_hmac('sha256', "v0:{$timestamp}:{$request->getContent()}", $secret);

        if (! hash_equals($expected, $signature)) {
            return response('', 401);
        }

        return $next($request);
    }
}
