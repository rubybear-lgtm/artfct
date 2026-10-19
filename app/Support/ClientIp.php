<?php

namespace App\Support;

use Illuminate\Http\Request;

/**
 * The client address that security decisions may key on.
 *
 * It is the transport peer, and nothing else. No header is read, because in this
 * topology no header can be trusted: the peer is the platform's edge, the
 * platform does not attest a client address, and every header that could carry
 * one is written by the caller.
 *
 * Three earlier versions of this class tried to recover the client and all three
 * were wrong in the same way. The first returned the rightmost
 * `X-Forwarded-For` entry — caller-written. The second returned it only when it
 * looked like Cloudflare — a caller naming a Cloudflare address was believed.
 * The third kept reading `CF-Connecting-IP` whenever the peer matched a
 * configured range, which meant that adding the platform's own edge ranges to
 * that list — the move the config used to recommend for restoring per-client
 * buckets — turned the key back into something a caller rotates while honest
 * callers gained nothing.
 *
 * So this returns the peer, and the throttles carry a per-account key wherever
 * the request names an account. The cost is deliberate and stated where the
 * throttles are defined: callers arriving through one edge address share a
 * bucket. A per-client key here would have to rest on a header the caller
 * writes, which is not a key at all.
 */
final class ClientIp
{
    public const FALLBACK = 'unknown';

    public static function for(Request $request): string
    {
        $peer = trim((string) $request->server('REMOTE_ADDR'));

        return $peer === '' ? self::FALLBACK : $peer;
    }
}
