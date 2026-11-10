<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Product analytics (PostHog)
    |--------------------------------------------------------------------------
    |
    | Disabled unless explicitly enabled AND a project API key is set.
    | Tests and local default to off so CI never sends real events.
    |
    */

    'enabled' => (bool) env('POSTHOG_ENABLED', false),

    'api_key' => env('POSTHOG_API_KEY'),

    'host' => env('POSTHOG_HOST', 'https://us.i.posthog.com'),

    /*
    | Tag on every event. Prefer an explicit POSTHOG_ENVIRONMENT; otherwise
    | derive from APP_ENV (production/staging/local).
    */
    'environment' => env('POSTHOG_ENVIRONMENT') ?: match (env('APP_ENV', 'production')) {
        'production' => 'production',
        'staging' => 'staging',
        default => 'local',
    },

    /*
    | Internal traffic exclusion: dashboards filter is_internal = false.
    | Team slugs, user ids, and email domains all mark events/person props.
    */
    'internal' => [
        'team_slugs' => array_values(array_filter(array_map(
            trim(...),
            explode(',', (string) env('POSTHOG_INTERNAL_TEAM_SLUGS', 'ruben-tortoleros-team')),
        ))),
        'user_ids' => array_values(array_filter(array_map(
            static fn (string $id): int => (int) trim($id),
            explode(',', (string) env('POSTHOG_INTERNAL_USER_IDS', '')),
        ), static fn (int $id): bool => $id > 0)),
        'email_domains' => array_values(array_filter(array_map(
            static fn (string $domain): string => strtolower(trim($domain)),
            explode(',', (string) env('POSTHOG_INTERNAL_EMAIL_DOMAINS', 'artfct.dev')),
        ))),
    ],

];
