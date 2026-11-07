/**
 * Staging deployment SHA verification (RUB-518).
 *
 * Only staging-web / staging-queue / staging-scheduler count. Production
 * service SUCCESS is never a fallback.
 */

export const STAGING_SERVICE_NAMES = [
    'staging-web',
    'staging-queue',
    'staging-scheduler',
];

export const PRODUCTION_SERVICE_NAMES = ['web', 'queue', 'scheduler'];

/** Deployed revisions that may sleep after idle (Railway sleepApplication). */
export const DEPLOYED_OK_STATUSES = new Set(['SUCCESS', 'SLEEPING']);

const TERMINAL_FAILURE = new Set(['FAILED', 'CRASHED']);

/** Minimum hex prefix length accepted for a commit match. */
export const MIN_SHA_PREFIX_LENGTH = 12;

/**
 * @param {unknown} value
 * @returns {string}
 */
export function normalizeSha(value) {
    if (typeof value !== 'string') {
        return '';
    }

    return value.trim().toLowerCase();
}

/**
 * @param {unknown} value
 * @returns {boolean}
 */
export function isValidShaInput(value) {
    const sha = normalizeSha(value);

    return /^[0-9a-f]{12,64}$/.test(sha);
}

/**
 * @param {unknown} meta
 * @returns {string | null}
 */
export function commitShaFromMeta(meta) {
    if (!meta || typeof meta !== 'object') {
        return null;
    }

    const record = /** @type {Record<string, unknown>} */ (meta);

    for (const key of [
        'commitHash',
        'commitSha',
        'commit',
        'gitCommitSha',
        'SHA',
    ]) {
        const value = record[key];

        if (typeof value === 'string' && value.length > 0) {
            return value;
        }
    }

    return null;
}

/**
 * Exact match, or prefix match only when both sides are long enough.
 *
 * @param {string} expected
 * @param {string | null | undefined} actual
 */
export function shaMatches(expected, actual) {
    const left = normalizeSha(expected);
    const right = normalizeSha(actual);

    if (
        left.length < MIN_SHA_PREFIX_LENGTH ||
        right.length < MIN_SHA_PREFIX_LENGTH
    ) {
        return false;
    }

    return left === right || left.startsWith(right) || right.startsWith(left);
}

/**
 * @param {{
 *   expectedSha: string,
 *   deployments: Array<{
 *     serviceName: string,
 *     status: string,
 *     meta?: unknown,
 *   }>,
 * }} options
 */
export function assertStagingDeploymentSha(options) {
    const expectedSha = normalizeSha(options.expectedSha);

    if (!expectedSha) {
        return {
            ok: false,
            code: 'missing_sha',
            message:
                'expectedSha is required. Staging acceptance must verify an exact commit.',
        };
    }

    if (!isValidShaInput(expectedSha)) {
        return {
            ok: false,
            code: 'invalid_sha',
            message: `expectedSha must be a hex commit of at least ${MIN_SHA_PREFIX_LENGTH} characters.`,
            expectedSha,
        };
    }

    /** @type {string[]} */
    const matchedServices = [];

    for (const serviceName of STAGING_SERVICE_NAMES) {
        const deployment = (options.deployments ?? []).find(
            (entry) => entry.serviceName === serviceName,
        );

        if (!deployment) {
            return {
                ok: false,
                code: 'missing_service',
                message: `No deployment found for staging service ${serviceName}. Production services are never used as a fallback.`,
            };
        }

        if (TERMINAL_FAILURE.has(deployment.status)) {
            return {
                ok: false,
                code: 'deploy_failed',
                message: `Staging service ${serviceName} is ${deployment.status} for the requested commit.`,
                serviceName,
                status: deployment.status,
            };
        }

        if (!DEPLOYED_OK_STATUSES.has(deployment.status)) {
            return {
                ok: false,
                code: 'deploy_not_ready',
                message: `Staging service ${serviceName} status is ${deployment.status}; need SUCCESS or SLEEPING for ${expectedSha}.`,
                serviceName,
                status: deployment.status,
            };
        }

        const actualSha = commitShaFromMeta(deployment.meta);

        if (!shaMatches(expectedSha, actualSha)) {
            return {
                ok: false,
                code: 'sha_mismatch',
                message: `Staging service ${serviceName} commit ${actualSha ?? '(missing)'} does not match expected ${expectedSha}.`,
                serviceName,
                expectedSha,
                actualSha,
            };
        }

        matchedServices.push(serviceName);
    }

    return {
        ok: true,
        expectedSha,
        matchedServices,
    };
}
