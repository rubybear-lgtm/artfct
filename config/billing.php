<?php

return [

    /*
    | Per-plan limits pushed to the Worker (RUB-348 / RUB-529). Pro matches
    | Team feature ceilings; Free stays the limited tier after a trial ends.
    */
    'plans' => [
        'free' => [
            'storage_bytes' => (int) env('BILLING_FREE_STORAGE_BYTES', 500 * 1024 * 1024),
            'artifacts_per_month' => (int) env('BILLING_FREE_ARTIFACTS_PER_MONTH', 50),
            'bundle_size_ceiling_bytes' => (int) env('BILLING_FREE_BUNDLE_CEILING_BYTES', 5 * 1024 * 1024),
            'render_minutes_per_month' => 10,
            'daily_publish' => [
                'per_user' => (int) env('BILLING_FREE_DAILY_PUBLISH_PER_USER', 100),
                'per_workspace' => (int) env('BILLING_FREE_DAILY_PUBLISH_PER_WORKSPACE', 300),
            ],
        ],
        'pro' => [
            'storage_bytes' => (int) env('BILLING_PRO_STORAGE_BYTES', 5 * 1024 * 1024 * 1024),
            'artifacts_per_month' => (int) env('BILLING_PRO_ARTIFACTS_PER_MONTH', 1000),
            'bundle_size_ceiling_bytes' => (int) env('BILLING_PRO_BUNDLE_CEILING_BYTES', 10 * 1024 * 1024),
            'render_minutes_per_month' => 100,
            'daily_publish' => [
                'per_user' => (int) env('BILLING_PRO_DAILY_PUBLISH_PER_USER', 200),
                'per_workspace' => (int) env('BILLING_PRO_DAILY_PUBLISH_PER_WORKSPACE', 2000),
            ],
        ],
        'team' => [
            'storage_bytes' => (int) env('BILLING_TEAM_STORAGE_BYTES', 5 * 1024 * 1024 * 1024),
            'artifacts_per_month' => (int) env('BILLING_TEAM_ARTIFACTS_PER_MONTH', 1000),
            'bundle_size_ceiling_bytes' => (int) env('BILLING_TEAM_BUNDLE_CEILING_BYTES', 10 * 1024 * 1024),
            'render_minutes_per_month' => 100,
            'daily_publish' => [
                'per_user' => (int) env('BILLING_TEAM_DAILY_PUBLISH_PER_USER', 200),
                'per_workspace' => (int) env('BILLING_TEAM_DAILY_PUBLISH_PER_WORKSPACE', 2000),
            ],
        ],
        'enterprise' => [
            'storage_bytes' => (int) env('BILLING_ENTERPRISE_STORAGE_BYTES', 50 * 1024 * 1024 * 1024),
            'artifacts_per_month' => (int) env('BILLING_ENTERPRISE_ARTIFACTS_PER_MONTH', 10000),
            'bundle_size_ceiling_bytes' => (int) env('BILLING_ENTERPRISE_BUNDLE_CEILING_BYTES', 25 * 1024 * 1024),
            'render_minutes_per_month' => 1000,
            'daily_publish' => [
                'per_user' => (int) env('BILLING_ENTERPRISE_DAILY_PUBLISH_PER_USER', 500),
                'per_workspace' => (int) env('BILLING_ENTERPRISE_DAILY_PUBLISH_PER_WORKSPACE', 10000),
            ],
        ],
    ],

    'daily_publish' => [
        'per_user' => (int) env('BILLING_DAILY_PUBLISH_PER_USER', 100),
        'per_workspace' => (int) env('BILLING_DAILY_PUBLISH_PER_WORKSPACE', 300),
    ],

    /*
    | Human member caps (bots are free and do not count). Null = no cap.
    | Pro is one person; Team is flat for up to 10; over that is Business.
    */
    'human_member_caps' => [
        // Free and Pro are one-person workspaces; pending invites cannot join
        // after a Team trial ends on Free (AcceptTeamInvitation uses this cap).
        'free' => 1,
        'pro' => 1,
        'team' => 10,
        'enterprise' => null,
    ],

    'business_contact_email' => env('BILLING_BUSINESS_CONTACT_EMAIL', 'support@artfct.dev'),

    /*
    | RUB-529: Team plan trial length. Stripe subscriptions use the same
    | trial_period_days on artfct_team_monthly.
    */
    'trial_days' => (int) env('BILLING_TRIAL_DAYS', 90),

    /*
    | Stripe price lookup_keys. Resolved to price IDs at runtime.
    */
    'price_lookup_keys' => [
        'pro_monthly' => 'artfct_pro_monthly',
        'pro_yearly' => 'artfct_pro_yearly',
        'team_monthly' => 'artfct_team_monthly',
        'team_yearly' => 'artfct_team_yearly',
    ],

];
