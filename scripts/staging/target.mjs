/**
 * Staging-only deploy target validation (RUB-518).
 *
 * Live acceptance may target staging.artfct.dev only. Production hosts and
 * Railway PR preview hosts are rejected with no fallback.
 */

export const STAGING_BASE_URL = 'https://staging.artfct.dev';

const PRODUCTION_HOSTS = new Set(['artfct.dev', 'www.artfct.dev']);

/**
 * @param {unknown} value
 * @returns {string}
 */
export function normalizeBaseUrl(value) {
    if (typeof value !== 'string') {
        return '';
    }

    return value.trim().replace(/\/+$/, '');
}

/**
 * @param {unknown} value
 * @returns {'staging' | 'production' | 'preview' | 'local' | 'unknown' | 'invalid' | 'missing'}
 */
export function classifyDeployTarget(value) {
    const normalized = normalizeBaseUrl(value);

    if (!normalized) {
        return 'missing';
    }

    let parsed;

    try {
        parsed = new URL(normalized);
    } catch {
        return 'invalid';
    }

    const host = parsed.hostname.toLowerCase();

    if (host === 'localhost' || host === '127.0.0.1' || host === '::1') {
        if (parsed.protocol !== 'http:' && parsed.protocol !== 'https:') {
            return 'invalid';
        }

        return 'local';
    }

    if (parsed.protocol !== 'https:') {
        return 'invalid';
    }

    if (host === 'staging.artfct.dev') {
        return 'staging';
    }

    if (PRODUCTION_HOSTS.has(host)) {
        return 'production';
    }

    if (
        host.endsWith('.up.railway.app') ||
        /^web-artfct-pr-\d+/i.test(host) ||
        /artfct-pr-\d+/i.test(host)
    ) {
        return 'preview';
    }

    return 'unknown';
}

/**
 * Live / deployed acceptance: staging only. Never production or preview.
 *
 * @param {unknown} value
 * @returns {{
 *   ok: true,
 *   baseUrl: string,
 *   target: 'staging',
 * } | {
 *   ok: false,
 *   code: 'missing_target' | 'invalid_target' | 'production_forbidden' | 'preview_forbidden' | 'unknown_target' | 'local_forbidden',
 *   message: string,
 *   target?: string,
 * }}
 */
export function assertStagingOnlyBaseUrl(value) {
    const target = classifyDeployTarget(value);
    const baseUrl = normalizeBaseUrl(value);

    if (target === 'missing') {
        return {
            ok: false,
            code: 'missing_target',
            message:
                'MCP_LIVE_BASE_URL / base_url is required and must be https://staging.artfct.dev.',
            target,
        };
    }

    if (target === 'invalid') {
        return {
            ok: false,
            code: 'invalid_target',
            message: `Invalid deploy target ${JSON.stringify(value)}; expected https://staging.artfct.dev.`,
            target,
        };
    }

    if (target === 'local') {
        return {
            ok: false,
            code: 'local_forbidden',
            message: `Refusing local host ${baseUrl} for deployed staging acceptance. Use isolated MCP e2e for localhost.`,
            target,
        };
    }

    if (target === 'production') {
        return {
            ok: false,
            code: 'production_forbidden',
            message: `Refusing production host ${baseUrl}. Live acceptance is staging-only (https://staging.artfct.dev); there is no production fallback.`,
            target,
        };
    }

    if (target === 'preview') {
        return {
            ok: false,
            code: 'preview_forbidden',
            message: `Refusing Railway PR preview host ${baseUrl}. PR preview environments are retired; use staging when deployed verification is warranted.`,
            target,
        };
    }

    if (target !== 'staging') {
        return {
            ok: false,
            code: 'unknown_target',
            message: `Refusing unknown host ${baseUrl}. Only https://staging.artfct.dev is allowed for live acceptance.`,
            target,
        };
    }

    return {
        ok: true,
        baseUrl: STAGING_BASE_URL,
        target: 'staging',
    };
}

/**
 * Hosted smoke script entry: staging for live runs, loopback for local e2e.
 * Production and Railway preview hosts are always rejected.
 *
 * @param {unknown} value
 * @returns {{
 *   ok: true,
 *   baseUrl: string,
 *   target: 'staging' | 'local',
 * } | {
 *   ok: false,
 *   code: string,
 *   message: string,
 *   target?: string,
 * }}
 */
export function assertSmokeBaseUrl(value) {
    const target = classifyDeployTarget(value);
    const baseUrl = normalizeBaseUrl(value);

    if (target === 'staging') {
        return {
            ok: true,
            baseUrl: STAGING_BASE_URL,
            target: 'staging',
        };
    }

    if (target === 'local') {
        return {
            ok: true,
            baseUrl,
            target: 'local',
        };
    }

    if (target === 'production' || target === 'preview') {
        return assertStagingOnlyBaseUrl(value);
    }

    if (target === 'missing') {
        return {
            ok: false,
            code: 'missing_target',
            message:
                'MCP_LIVE_BASE_URL is required (https://staging.artfct.dev or http://127.0.0.1 for local e2e).',
            target,
        };
    }

    return {
        ok: false,
        code: target === 'invalid' ? 'invalid_target' : 'unknown_target',
        message: `Refusing host ${baseUrl || JSON.stringify(value)}. Use staging for live smoke or loopback for local e2e; never production or PR previews.`,
        target,
    };
}
