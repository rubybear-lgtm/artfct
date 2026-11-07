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

const TERMINAL_FAILURE = new Set(['FAILED', 'CRASHED']);

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
 * @param {string} expected
 * @param {string | null | undefined} actual
 */
export function shaMatches(expected, actual) {
    if (!actual) {
        return false;
    }

    return (
        actual === expected ||
        expected.startsWith(actual) ||
        actual.startsWith(expected)
    );
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
    const expectedSha = (options.expectedSha ?? '').trim();

    if (!expectedSha) {
        return {
            ok: false,
            code: 'missing_sha',
            message:
                'expectedSha is required. Staging acceptance must verify an exact commit.',
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

        if (deployment.status !== 'SUCCESS') {
            return {
                ok: false,
                code: 'deploy_not_ready',
                message: `Staging service ${serviceName} status is ${deployment.status}; need SUCCESS for ${expectedSha}.`,
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
