<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'signing_secret' => env('SLACK_SIGNING_SECRET'),
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'cloudflare_email' => [
        'account_id' => env('CLOUDFLARE_ACCOUNT_ID'),
        'api_token' => env('CLOUDFLARE_EMAIL_API_TOKEN'),
    ],

    'oauth' => [
        // The authorization server's own issuer URL (RFC 8414). It must match
        // the host clients fetch the metadata from, so it is separate from the
        // org-token `iss` claim, which is a fixed contract with the Worker.
        'issuer' => env('OAUTH_ISSUER'),
    ],

    'org_jwt' => [
        // PEM, or base64 of the PEM (`ORG_JWT_PRIVATE_KEY_B64`) so it survives env-var line handling.
        'private_key' => env('ORG_JWT_PRIVATE_KEY_B64')
            ? (string) base64_decode((string) env('ORG_JWT_PRIVATE_KEY_B64'), true)
            : env('ORG_JWT_PRIVATE_KEY'),
        'kid' => env('ORG_JWT_KID'),
        'issuer' => env('ORG_JWT_ISSUER', 'https://artfct.dev'),
        'audience' => env('ORG_JWT_AUDIENCE', 'artfct-engine'),
        'worker_base_url' => env('ARTFCT_WORKER_BASE_URL'),
        'revocation_write_secret' => env('ARTFCT_REVOCATION_WRITE_SECRET'),
        'jwks_write_secret' => env('ARTFCT_JWKS_WRITE_SECRET'),
    ],

    'stripe' => [
        'secret' => env('STRIPE_SECRET_KEY'),
        'webhook_secret' => env('STRIPE_WEBHOOK_SECRET'),
        'team_price_id' => env('STRIPE_TEAM_PRICE_ID'),
    ],

    'polis' => [
        'base_url' => env('POLIS_BASE_URL'),
        'api_key' => env('POLIS_API_KEY'),
        'client_secret_verifier' => env('POLIS_CLIENT_SECRET_VERIFIER'),
        'webhook_secret' => env('POLIS_WEBHOOK_SECRET'),
    ],

    'authkit' => [
        // The passwordless dev login is on by default only locally and in tests;
        // any other environment must opt in with AUTHKIT_DEV_LOGIN_ENABLED=true.
        'dev_login_enabled' => (bool) env('AUTHKIT_DEV_LOGIN_ENABLED', in_array(env('APP_ENV'), ['local', 'testing'], true)),
        // Comma-separated email domains allowed through the non-production dev login.
        // Empty means unrestricted (local and tests).
        'dev_login_domains' => array_filter(explode(',', (string) env('AUTHKIT_DEV_LOGIN_DOMAINS', ''))),
    ],

    'worker' => [
        'base_url' => env('ARTFCT_WORKER_BASE_URL'),
        'org_token' => env('ARTFCT_ORG_TOKEN'),
        'limits_write_secret' => env('ARTFCT_LIMITS_WRITE_SECRET'),
        'governance_secret' => env('ARTFCT_GOVERNANCE_SECRET'),
    ],

    'artifact_access' => [
        // Shared HMAC secret for isolated-origin artifact links (spec 05),
        // matching the Worker's `ARTFCT_ARTIFACT_TOKEN_SECRET` binding by
        // name. Unset fails closed: no link is minted.
        'token_secret' => env('ARTFCT_ARTIFACT_TOKEN_SECRET'),
        // Mirrors the Worker's `ARTFCT_ARTIFACT_ORIGIN_SUFFIX` override
        // (staging folds its marker into the label: `--stg.artfct.dev`).
        // Unset keeps the production default.
        'origin_suffix' => env('ARTFCT_ARTIFACT_ORIGIN_SUFFIX', '.artfct.dev'),
        // How long a minted link stays valid; the Worker rejects a token at
        // or past its expiry.
        'token_ttl_minutes' => (int) env('ARTFCT_ARTIFACT_TOKEN_TTL_MINUTES', 60),
    ],

    'worker_events' => [
        'secret' => env('ARTFCT_WORKER_EVENT_SECRET'),
    ],

    'workos' => [
        'client_id' => env('WORKOS_CLIENT_ID'),
        'secret' => env('WORKOS_API_KEY'),
        'redirect_url' => env('WORKOS_REDIRECT_URL'),
    ],

];
