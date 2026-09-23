<?php

/*
|--------------------------------------------------------------------------
| Trusted Ingress
|--------------------------------------------------------------------------
|
| The ranges passed to `TrustProxies::at()` in AppServiceProvider. They decide
| one thing: whether `X-Forwarded-Proto` and `X-Forwarded-Host` are believed, so
| that URL generation is correct behind the platform's edge. The scheme is pinned
| with `URL::forceScheme('https')` as well, so requests from outside these ranges
| still produce https links.
|
| They do NOT make any header trustworthy as a client address. App\Support\ClientIp
| reads no header at all and returns the transport peer, so nothing here can
| promote `CF-Connecting-IP` — or anything else a caller writes — into a throttle
| or audit key.
|
| Extending this list does not restore per-client bucket granularity, and must not
| be presented as a way to. The platform does not attest a client address, so a
| per-client key would rest on a header the caller writes; a previous revision of
| this file recommended adding the platform's edge ranges here for exactly that
| purpose, which would have handed back a rotating key while honest callers gained
| nothing. Granularity is provided instead by the per-account keys in
| AppServiceProvider.
|
| Cloudflare's published ranges are the default because they are the ingress that
| fronts the public domain and publishes its addresses. Refresh from
| https://www.cloudflare.com/ips-v4 and https://www.cloudflare.com/ips-v6, or set
| TRUSTED_INGRESS_EDGE_RANGES to override.
|
*/

$edgeRanges = [
    '173.245.48.0/20',
    '103.21.244.0/22',
    '103.22.200.0/22',
    '103.31.4.0/22',
    '141.101.64.0/18',
    '108.162.192.0/18',
    '190.93.240.0/20',
    '188.114.96.0/20',
    '197.234.240.0/22',
    '198.41.128.0/17',
    '162.158.0.0/15',
    '104.16.0.0/13',
    '104.24.0.0/14',
    '172.64.0.0/13',
    '131.0.72.0/22',
    '2400:cb00::/32',
    '2606:4700::/32',
    '2803:f800::/32',
    '2405:b500::/32',
    '2405:8100::/32',
    '2a06:98c0::/29',
    '2c0f:f248::/32',
];

$override = env('TRUSTED_INGRESS_EDGE_RANGES');

return [
    'edge_ranges' => $override
        ? array_values(array_filter(array_map('trim', explode(',', (string) $override))))
        : $edgeRanges,
];
