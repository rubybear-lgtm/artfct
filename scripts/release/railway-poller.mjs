/**
 * Railway production deploy poller for the release Action (RUB-517).
 * Stub until tests fail, then implemented.
 */

export const PRODUCTION_SERVICE_NAMES = ['web', 'queue', 'scheduler'];

/**
 * @param {{
 *   commitSha: string,
 *   projectId: string,
 *   environmentId: string,
 *   serviceNames?: string[],
 *   graphql: (query: string, variables?: Record<string, unknown>) => Promise<unknown>,
 *   sleep?: (ms: number) => Promise<void>,
 *   now?: () => number,
 *   pollIntervalMs?: number,
 *   timeoutMs?: number,
 * }} options
 * @returns {Promise<{
 *   ok: true,
 *   services: Array<{ name: string, status: string, deploymentId: string, commitSha: string }>,
 * } | {
 *   ok: false,
 *   code: string,
 *   message: string,
 *   service?: string,
 *   services?: Array<{ name: string, status: string | null, deploymentId?: string, commitSha?: string | null }>,
 * }>}
 */
export async function waitForProductionDeployments(options) {
    void options;
    throw new Error('waitForProductionDeployments: not implemented');
}
