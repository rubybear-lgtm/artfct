/**
 * Required CI check gate for the production release Action (RUB-517).
 * Stub until tests fail, then implemented.
 */

/**
 * @param {{ name?: string, context?: string, appSlug?: string }} check
 * @returns {boolean}
 */
export function isIgnoredCheck(check) {
    void check;
    return false;
}

/**
 * @param {{
 *   sha: string,
 *   listCheckRuns: (sha: string) => Promise<Array<{
 *     name: string,
 *     status: string,
 *     conclusion: string | null,
 *     appSlug?: string,
 *   }>>,
 *   listCommitStatuses?: (sha: string) => Promise<Array<{
 *     context: string,
 *     state: string,
 *   }>>,
 *   sleep?: (ms: number) => Promise<void>,
 *   now?: () => number,
 *   pollIntervalMs?: number,
 *   timeoutMs?: number,
 * }} options
 * @returns {Promise<{ ok: true, checks: Array<{ name: string, conclusion: string }> } | { ok: false, code: string, message: string, pending?: string[], failed?: string[] }>}
 */
export async function assertRequiredChecksSucceeded(options) {
    void options;
    throw new Error('assertRequiredChecksSucceeded: not implemented');
}
