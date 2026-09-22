<?php

/*
|--------------------------------------------------------------------------
| Trusted Ingress
|--------------------------------------------------------------------------
|
| The edge ranges whose word is believed. `App\Support\ClientIp` trusts the
| `CF-Connecting-IP` header only when the transport peer falls inside one of
| these, because the peer is the only value a caller cannot set.
|
| `TrustProxies::at()` is configured from the same list in AppServiceProvider,
| so the trusted-proxy set and the ClientIp signal cannot drift apart. Trusted
| proxies are what make `X-Forwarded-Proto` and `X-Forwarded-Host` usable for URL
| generation; the scheme is pinned with `URL::forceScheme('https')` as well, so
| requests from outside these ranges still produce https links.
|
| Cloudflare's published ranges are the default because they are the ingress
| that fronts the public domain and publishes its addresses. Refresh from
| https://www.cloudflare.com/ips-v4 and https://www.cloudflare.com/ips-v6, or set
| the env var to override — including to add the platform's own edge ranges if
| they are ever enumerated, which is what restores per-client buckets for
| traffic that does not arrive through Cloudflare.
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

$override = env('TRUSTED_INGRESS_EDGE_RANGES') ?? env('TRUSTED_INGRESS_CLOUDFLARE_RANGES');

return [
    'edge_ranges' => $override
        ? array_values(array_filter(array_map('trim', explode(',', (string) $override))))
        : $edgeRanges,
];
