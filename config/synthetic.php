<?php

/*
| Synthetic test data (RUB-325). Each org deploys to its own Worker instance
| because a Worker serves one org (`ARTFCT_ORG_SLUG`) with a static token.
| Start them with scripts/synthetic-workers.sh. Staging values come from env
| and are only used with `--target=staging`.
*/
return [

    'prefix' => 'zz-',

    'email_domain' => 'northwind.example',

    // Token for remote `wrangler d1 execute` (seed-only backdating). Unset falls back to `wrangler login`.
    'cloudflare_api_token' => env('SYNTHETIC_CLOUDFLARE_API_TOKEN'),

    'targets' => [
        'local' => [
            'a' => [
                'url' => env('SYNTHETIC_LOCAL_A_URL', 'http://127.0.0.1:8801'),
                'token' => env('SYNTHETIC_LOCAL_A_TOKEN', 'synthetic-a-token'),
                'governance_secret' => env('SYNTHETIC_LOCAL_A_GOVERNANCE', 'synthetic-a-gov'),
                'limits_secret' => env('SYNTHETIC_LOCAL_A_LIMITS', 'synthetic-a-limits'),
                'persist_to' => env('SYNTHETIC_LOCAL_A_PERSIST', base_path('backend/.wrangler/synthetic-a')),
                'wrangler_config' => null,
                'remote' => false,
            ],
            'b' => [
                'url' => env('SYNTHETIC_LOCAL_B_URL', 'http://127.0.0.1:8802'),
                'token' => env('SYNTHETIC_LOCAL_B_TOKEN', 'synthetic-b-token'),
                'governance_secret' => env('SYNTHETIC_LOCAL_B_GOVERNANCE', 'synthetic-b-gov'),
                'limits_secret' => env('SYNTHETIC_LOCAL_B_LIMITS', 'synthetic-b-limits'),
                'persist_to' => env('SYNTHETIC_LOCAL_B_PERSIST', base_path('backend/.wrangler/synthetic-b')),
                'wrangler_config' => null,
                'remote' => false,
            ],
        ],
        'staging' => [
            'a' => [
                'url' => env('SYNTHETIC_STAGING_URL'),
                'token' => env('SYNTHETIC_STAGING_TOKEN'),
                'governance_secret' => env('SYNTHETIC_STAGING_GOVERNANCE'),
                'limits_secret' => env('SYNTHETIC_STAGING_LIMITS'),
                'persist_to' => null,
                'wrangler_config' => 'wrangler.staging.jsonc',
                'remote' => true,
            ],
            'b' => [
                'url' => env('SYNTHETIC_STAGING_B_URL'),
                'token' => env('SYNTHETIC_STAGING_B_TOKEN'),
                'governance_secret' => env('SYNTHETIC_STAGING_B_GOVERNANCE'),
                'limits_secret' => env('SYNTHETIC_STAGING_B_LIMITS'),
                'persist_to' => null,
                'wrangler_config' => 'wrangler.staging-b.jsonc',
                'remote' => true,
            ],
        ],
    ],

];
