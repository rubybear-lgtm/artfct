<?php

namespace App\Support;

use Illuminate\Http\Request;

/**
 * The client address that security decisions may key on.
 *
 * Only one value in a request is beyond the caller's reach: the transport peer,
 * `REMOTE_ADDR`. Everything else — the whole `X-Forwarded-For` header,
 * `CF-Connecting-IP`, `X-Forwarded-Proto` — is either written by the caller or
 * indistinguishable from something the caller wrote, because on the direct path
 * nothing appends behind them.
 *
 * So the peer gates everything else. When the peer is inside the configured edge
 * ranges, the request arrived through that edge and the address the edge wrote
 * can be believed; otherwise the peer is the answer. An earlier version of this
 * class decided by looking for a Cloudflare-shaped address in
 * `X-Forwarded-For`, which is itself caller-supplied — naming a Cloudflare
 * address was enough to have a forged `CF-Connecting-IP` believed.
 *
 * What this gives up: when the peer is not a recognised edge address, every
 * caller behind that peer shares one bucket. That is deliberate — an unforgeable
 * coarse key beats an exact one the caller chooses.
 */
final class ClientIp
{
    public const FALLBACK = 'unknown';

    public static function for(Request $request): string
    {
        $peer = self::peer($request);

        if (self::isTrustedEdge($peer)) {
            // The edge writes this itself and overwrites anything a client
            // supplies, so a value here is the client. Consulted only when the
            // peer proves the request actually came through that edge.
            $connecting = trim((string) $request->headers->get('CF-Connecting-IP'));

            if ($connecting !== '') {
                return $connecting;
            }
        }

        return $peer;
    }

    private static function peer(Request $request): string
    {
        $peer = trim((string) $request->server('REMOTE_ADDR'));

        return $peer === '' ? self::FALLBACK : $peer;
    }

    private static function isTrustedEdge(string $address): bool
    {
        foreach ((array) config('trusted_ingress.edge_ranges', []) as $range) {
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
