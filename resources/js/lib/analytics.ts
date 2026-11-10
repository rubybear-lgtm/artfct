import posthog from 'posthog-js';

type AnalyticsConfig = {
    enabled: boolean;
    apiKey: string | null;
    host: string | null;
    environment: string | null;
};

type AnalyticsUser = {
    id: number | string;
};

type AnalyticsTeam = {
    id?: number;
    slug: string;
    name?: string;
    plan?: string;
    isPersonal?: boolean;
} | null;

let initialized = false;

/**
 * Browser PostHog for the Laravel app and marketing pages only.
 * Never load this on Worker `/p/*` artifact hosts.
 *
 * Cookieless/memory persistence for anonymous visitors (no consent banner).
 * Session replay off; autocapture conservative.
 */
export function initAnalytics(
    config: AnalyticsConfig | undefined,
    user?: AnalyticsUser | null,
    team?: AnalyticsTeam,
): void {
    if (initialized || !config?.enabled || !config.apiKey) {
        return;
    }

    posthog.init(config.apiKey, {
        api_host: config.host || 'https://us.i.posthog.com',
        // Memory persistence avoids a tracking cookie for anonymous visitors.
        persistence: 'memory',
        capture_pageview: true,
        capture_pageleave: true,
        autocapture: {
            dom_event_allowlist: ['click'],
            url_allowlist: [window.location.origin],
            element_allowlist: ['button', 'a'],
        },
        disable_session_recording: true,
        person_profiles: 'identified_only',
    });

    posthog.register({
        environment: config.environment || 'local',
    });

    if (user?.id != null) {
        posthog.identify(String(user.id));
    }

    if (team?.slug) {
        posthog.group('team', team.slug, {
            name: team.name,
            plan: team.plan,
            is_personal: team.isPersonal ?? false,
            team_id: team.id,
            team_slug: team.slug,
        });
    }

    initialized = true;
}

export function analyticsInitOptionsSnapshot(): Record<string, unknown> {
    return {
        persistence: 'memory',
        capture_pageview: true,
        disable_session_recording: true,
        person_profiles: 'identified_only',
        autocapture: 'conservative',
    };
}
