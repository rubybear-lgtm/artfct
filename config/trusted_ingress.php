<?php

/*
|--------------------------------------------------------------------------
| Trusted Ingress
|--------------------------------------------------------------------------
|
| `trustProxies(at: '*')` is deliberate: it is what keeps X-Forwarded-Proto
| honoured so generated URLs stay https behind the platform's edge, and
| narrowing it would break the OAuth redirect URIs. The consequence is that
| `$request->ip()` returns the *leftmost* X-Forwarded-For entry — the value the
| caller wrote — so throttling and the audit log must not key on it.
|
| These ranges are how a request that arrived through Cloudflare is told apart
| from one that reached the app directly on the Railway service domain, which
| still serves. Refresh from https://www.cloudflare.com/ips-v4 and
| https://www.cloudflare.com/ips-v6, or set the env var to override.
|
*/

$cloudflareRanges = [
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

$override = env('TRUSTED_INGRESS_CLOUDFLARE_RANGES');

return [
    'cloudflare_ranges' => $override
        ? array_values(array_filter(array_map('trim', explode(',', (string) $override))))
        : $cloudflareRanges,
];
