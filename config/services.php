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

    'stripe' => [
        'secret' => env('STRIPE_SECRET_KEY'),
        'webhook_secret' => env('STRIPE_WEBHOOK_SECRET'),
        'team_price_id' => env('STRIPE_TEAM_PRICE_ID'),
    ],

    'authkit' => [
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

    'worker_events' => [
        'secret' => env('ARTFCT_WORKER_EVENT_SECRET'),
    ],

    'workos' => [
        'client_id' => env('WORKOS_CLIENT_ID'),
        'secret' => env('WORKOS_API_KEY'),
        'redirect_url' => env('WORKOS_REDIRECT_URL'),
    ],

];
