/**
 * Risk-based staging acceptance policy (RUB-518).
 *
 * Staging is not a universal PR gate. Isolated CI remains mandatory; live
 * staging verification is opt-in when the change warrants it.
 */

/** @type {Array<{ id: string, label: string, pattern: RegExp }>} */
export const STAGING_RISK_PATTERNS = [
    {
        id: 'mcp',
        label: 'MCP hosted tool/server surface',
        pattern: /(^|\/)(app\/Mcp\/|scripts\/mcp-live|tests\/Feature\/Mcp)/,
    },
    {
        id: 'auth',
        label: 'authentication / OAuth / WorkOS / session',
        pattern:
            /(^|\/)(app\/Http\/Controllers\/Auth\/|app\/Services\/Auth|app\/Services\/Identity|app\/Http\/Middleware\/.*(Auth|WorkOs|Session)|config\/auth\.php|routes\/auth\.php)/i,
    },
    {
        id: 'billing',
        label: 'billing / Stripe',
        pattern: /(^|\/)(app\/Services\/Billing|app\/Http\/Controllers\/.*Billing|config\/cashier|Stripe)/i,
    },
    {
        id: 'worker',
        label: 'Cloudflare Worker / artifact data plane',
        pattern: /(^|\/)(backend\/|scripts\/deploy-worker)/,
    },
    {
        id: 'migrations',
        label: 'database migrations',
        pattern: /(^|\/)database\/migrations\//,
    },
    {
        id: 'railway',
        label: 'Railway control-plane / staging IaC',
        pattern: /(^|\/)(\.railway\/|scripts\/railway-config)/,
    },
    {
        id: 'sharing',
        label: 'sharing / privacy / access tokens',
        pattern:
            /(^|\/)(app\/Services\/(Sharing|Artifacts)|app\/Http\/Controllers\/Artifact|routes\/(api|web)\.php)/,
    },
    {
        id: 'staging_scripts',
        label: 'staging acceptance tooling itself',
        pattern: /(^|\/)(scripts\/staging\/|\.github\/workflows\/(mcp-live|staging-acceptance)\.yml)/,
    },
];

/** @type {RegExp} */
const LOW_RISK_ONLY =
    /^(docs\/|resources\/(css|js\/pages\/welcome)\/|skills\/.*\/drafts\/|.*\.(md|mdx)$|\.github\/workflows\/(polis-advisories)\.yml$)/;

/**
 * @param {{
 *   changedPaths?: string[],
 *   force?: boolean,
 *   labels?: string[],
 * }} options
 */
export function evaluateStagingAcceptanceNeed(options = {}) {
    const changedPaths = options.changedPaths ?? [];
    /** @type {string[]} */
    const reasons = [];

    if (options.force) {
        reasons.push('force flag requested staging acceptance');

        return {
            warranted: true,
            path: /** @type {const} */ ('staging-acceptance'),
            reasons,
            matchedRiskIds: ['force'],
        };
    }

    if ((options.labels ?? []).some((label) => /staging|live-acceptance/i.test(label))) {
        reasons.push('PR label requested staging acceptance');

        return {
            warranted: true,
            path: /** @type {const} */ ('staging-acceptance'),
            reasons,
            matchedRiskIds: ['label'],
        };
    }

    /** @type {string[]} */
    const matchedRiskIds = [];

    for (const path of changedPaths) {
        for (const risk of STAGING_RISK_PATTERNS) {
            if (risk.pattern.test(path) && !matchedRiskIds.includes(risk.id)) {
                matchedRiskIds.push(risk.id);
                reasons.push(`${risk.label}: ${path}`);
            }
        }
    }

    if (matchedRiskIds.length > 0) {
        return {
            warranted: true,
            path: /** @type {const} */ ('staging-acceptance'),
            reasons,
            matchedRiskIds,
        };
    }

    if (
        changedPaths.length > 0 &&
        changedPaths.every((path) => LOW_RISK_ONLY.test(path))
    ) {
        reasons.push('changed paths are docs/copy/skill-draft only');
    } else if (changedPaths.length === 0) {
        reasons.push('no changed paths provided; default to isolated CI');
    } else {
        reasons.push(
            'no staging-risk path matched; isolated unit/integration/browser/MCP CI remains mandatory',
        );
    }

    return {
        warranted: false,
        path: /** @type {const} */ ('isolated-ci-only'),
        reasons,
        matchedRiskIds,
    };
}

/**
 * @param {{
 *   warranted: boolean,
 *   path: 'isolated-ci-only' | 'staging-acceptance',
 *   reasons: string[],
 * }} decision
 */
export function summarizeAcceptancePath(decision) {
    if (decision.path === 'isolated-ci-only') {
        return `Isolated CI only (staging not warranted): ${decision.reasons.join('; ')}`;
    }

    return `Staging acceptance warranted: ${decision.reasons.join('; ')}`;
}
