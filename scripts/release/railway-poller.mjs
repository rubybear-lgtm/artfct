/**
 * Railway production deploy poller for the release Action (RUB-517).
 */

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
 * @param {string | null} actual
 */
function shaMatches(expected, actual) {
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
 *   commitSha: string,
 *   projectId: string,
 *   environmentId: string,
 *   serviceNames?: string[],
 *   graphql: (query: string, variables?: Record<string, unknown>) => Promise<any>,
 *   sleep?: (ms: number) => Promise<void>,
 *   now?: () => number,
 *   pollIntervalMs?: number,
 *   timeoutMs?: number,
 * }} options
 */
export async function waitForProductionDeployments(options) {
    const serviceNames = options.serviceNames ?? PRODUCTION_SERVICE_NAMES;
    const sleep = options.sleep ?? ((ms) => new Promise((r) => setTimeout(r, ms)));
    const now = options.now ?? Date.now;
    const pollIntervalMs = options.pollIntervalMs ?? 20_000;
    const timeoutMs = options.timeoutMs ?? 30 * 60_000;
    const started = now();

    const project = await options.graphql(
        `query ProjectServices($id: String!) {
      project(id: $id) {
        id
        services {
          edges {
            node {
              id
              name
            }
          }
        }
      }
    }`,
        { id: options.projectId },
    );

    const services = (project?.project?.services?.edges ?? []).map(
        (edge) => edge.node,
    );
    /** @type {Map<string, string>} */
    const idByName = new Map();

    for (const name of serviceNames) {
        const match = services.find((service) => service.name === name);

        if (!match) {
            return {
                ok: false,
                code: 'service_missing',
                service: name,
                message: `Railway production service "${name}" was not found in project ${options.projectId}.`,
            };
        }

        idByName.set(name, match.id);
    }

    while (true) {
        /** @type {Array<{ name: string, status: string | null, deploymentId?: string, commitSha?: string | null }>} */
        const snapshot = [];

        for (const name of serviceNames) {
            const serviceId = idByName.get(name);
            const response = await options.graphql(
                `query Deployments($input: DeploymentListInput!) {
          deployments(input: $input, first: 20) {
            edges {
              node {
                id
                status
                createdAt
                meta
              }
            }
          }
        }`,
                {
                    input: {
                        projectId: options.projectId,
                        environmentId: options.environmentId,
                        serviceId,
                    },
                },
            );

            const deployments = (response?.deployments?.edges ?? []).map(
                (edge) => edge.node,
            );
            const matching = deployments.find((deployment) =>
                shaMatches(
                    options.commitSha,
                    commitShaFromMeta(deployment.meta),
                ),
            );

            if (!matching) {
                snapshot.push({
                    name,
                    status: null,
                    commitSha: null,
                });
                continue;
            }

            const commitSha = commitShaFromMeta(matching.meta);
            snapshot.push({
                name,
                status: matching.status,
                deploymentId: matching.id,
                commitSha,
            });

            if (TERMINAL_FAILURE.has(matching.status)) {
                return {
                    ok: false,
                    code: 'deploy_failed',
                    service: name,
                    services: snapshot,
                    message: `Railway production service "${name}" deployment ${matching.status} for commit ${options.commitSha}.`,
                };
            }
        }

        const allSuccess = serviceNames.every((name) => {
            const row = snapshot.find((entry) => entry.name === name);

            return row?.status === 'SUCCESS' && shaMatches(options.commitSha, row.commitSha ?? null);
        });

        if (allSuccess) {
            return {
                ok: true,
                services: snapshot.map((row) => ({
                    name: row.name,
                    status: /** @type {string} */ (row.status),
                    deploymentId: /** @type {string} */ (row.deploymentId),
                    commitSha: /** @type {string} */ (row.commitSha),
                })),
            };
        }

        if (now() - started >= timeoutMs) {
            const waiting = snapshot
                .filter((row) => row.status !== 'SUCCESS')
                .map((row) => row.name);

            return {
                ok: false,
                code: 'deploy_timeout',
                services: snapshot,
                message: `Timed out waiting for Railway production deployments of ${options.commitSha} to reach SUCCESS (still waiting on: ${waiting.join(', ') || serviceNames.join(', ')}).`,
            };
        }

        await sleep(pollIntervalMs);
    }
}
