<?php

namespace App\Support;

use Illuminate\Http\Request;

/**
 * The client address that security decisions may key on.
 *
 * `trustProxies(at: '*')` is deliberate — it is what keeps `X-Forwarded-Proto`
 * honoured so generated URLs stay https behind the platform's edge, and
 * narrowing it would break the OAuth redirect URIs. The cost is that
 * `$request->ip()` returns the *leftmost* `X-Forwarded-For` entry, which is the
 * value the caller wrote: a client can rotate a forged header and land in a
 * fresh throttle bucket, and that same forged value is written into the audit
 * log.
 *
 * Every proxy in front appends the address it received the connection from to
 * the right of whatever it was given, so the **rightmost** entry is the
 * platform's own record. That makes it usable to *recognise* the ingress — a
 * caller can only prepend to it — but not to name the client: a caller sending
 * a single forged entry is itself the rightmost value.
 *
 * So the address returned is either one Cloudflare wrote, or the peer:
 *
 * 1. If the rightmost entry is a Cloudflare address, the request came through
 *    Cloudflare, which sets `CF-Connecting-IP` itself and overwrites anything a
 *    client supplies — that value is the client.
 * 2. Otherwise the peer address. A header cannot influence it, which is the
 *    property that matters.
 *
 * What this gives up: traffic arriving outside Cloudflare is bucketed by the
 * edge address the platform recorded rather than by client. That is coarser,
 * and it is deliberate — an unforgeable coarse key beats an exact one a caller
 * chooses.
 */
final class ClientIp
{
    public const FALLBACK = 'unknown';

    public static function for(Request $request): string
    {
        $edge = self::rightmostForwarded($request);

        // A value here is authoritative: Cloudflare sets CF-Connecting-IP
        // itself and overwrites anything a client supplies. It is consulted
        // only when Cloudflare is the edge that recorded the connection, so a
        // client reaching the app directly cannot promote its own forged header
        // into a bucket key.
        if ($edge !== null && self::isCloudflare($edge)) {
            $connecting = trim((string) $request->headers->get('CF-Connecting-IP'));

            if ($connecting !== '') {
                return $connecting;
            }
        }

        // Everything else falls back to the peer. The rightmost forwarded entry
        // is deliberately NOT used here: a caller can send exactly one entry and
        // be it.
        return self::peer($request);
    }

    private static function rightmostForwarded(Request $request): ?string
    {
        $header = $request->headers->get('X-Forwarded-For');

        if ($header === null) {
            return null;
        }

        $chain = array_values(array_filter(
            array_map('trim', explode(',', $header)),
            static fn (string $entry): bool => $entry !== '',
        ));

        return $chain === [] ? null : $chain[count($chain) - 1];
    }

    private static function peer(Request $request): string
    {
        $peer = trim((string) $request->server('REMOTE_ADDR'));

        return $peer === '' ? self::FALLBACK : $peer;
    }

    private static function isCloudflare(string $address): bool
    {
        foreach ((array) config('trusted_ingress.cloudflare_ranges', []) as $range) {
            if (self::inRange($address, (string) $range)) {
                return true;
            }
        }

        return false;
    }

    private static function inRange(string $address, string $range): bool
    {
        if (! str_contains($range, '/')) {
            return strcasecmp($address, $range) === 0;
        }

        [$network, $bits] = explode('/', $range, 2);

        $addressBytes = @inet_pton($address);
        $networkBytes = @inet_pton($network);

        if ($addressBytes === false || $networkBytes === false) {
            return false;
        }

        // Never compare a v4 address against a v6 network or the reverse.
        if (strlen($addressBytes) !== strlen($networkBytes)) {
            return false;
        }

        $prefix = (int) $bits;

        if ($prefix < 0 || $prefix > strlen($addressBytes) * 8) {
            return false;
        }

        $wholeBytes = intdiv($prefix, 8);
        $remainingBits = $prefix % 8;

        if ($wholeBytes > 0 && substr($addressBytes, 0, $wholeBytes) !== substr($networkBytes, 0, $wholeBytes)) {
            return false;
        }

        if ($remainingBits === 0) {
            return true;
        }

        $mask = 0xFF << (8 - $remainingBits) & 0xFF;

        return (ord($addressBytes[$wholeBytes]) & $mask) === (ord($networkBytes[$wholeBytes]) & $mask);
    }
}
