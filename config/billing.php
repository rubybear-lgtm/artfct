<?php

return [

    /*
    | Per-plan limits pushed to the Worker (RUB-348). The Team values equal the
    | previous single default. Free values are placeholders until the plan
    | contents are decided; change them here, nowhere else.
    */
    'plans' => [
        'free' => [
            'storage_bytes' => (int) env('BILLING_FREE_STORAGE_BYTES', 500 * 1024 * 1024),
            'artifacts_per_month' => (int) env('BILLING_FREE_ARTIFACTS_PER_MONTH', 50),
            'bundle_size_ceiling_bytes' => (int) env('BILLING_FREE_BUNDLE_CEILING_BYTES', 5 * 1024 * 1024),
            'render_minutes_per_month' => 10,
        ],
        'team' => [
            'storage_bytes' => (int) env('BILLING_TEAM_STORAGE_BYTES', 5 * 1024 * 1024 * 1024),
            'artifacts_per_month' => (int) env('BILLING_TEAM_ARTIFACTS_PER_MONTH', 1000),
            'bundle_size_ceiling_bytes' => (int) env('BILLING_TEAM_BUNDLE_CEILING_BYTES', 10 * 1024 * 1024),
            'render_minutes_per_month' => 100,
        ],
        'enterprise' => [
            'storage_bytes' => (int) env('BILLING_ENTERPRISE_STORAGE_BYTES', 50 * 1024 * 1024 * 1024),
            'artifacts_per_month' => (int) env('BILLING_ENTERPRISE_ARTIFACTS_PER_MONTH', 10000),
            'bundle_size_ceiling_bytes' => (int) env('BILLING_ENTERPRISE_BUNDLE_CEILING_BYTES', 25 * 1024 * 1024),
            'render_minutes_per_month' => 1000,
        ],
    ],

    // Viewers count as billable seats unless turned off.
    'viewers_billable' => (bool) env('BILLING_VIEWERS_BILLABLE', true),

];
