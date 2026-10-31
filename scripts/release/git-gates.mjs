/**
 * Git ancestry gates for the production release Action (RUB-517).
 * Stub until tests fail, then implemented.
 */

/**
 * @param {{
 *   sha: string,
 *   developRef?: string,
 *   mainRef?: string,
 *   runGit: (args: string[]) => { status: number, stdout: string, stderr: string },
 * }} options
 * @returns {{ ok: true, sha: string } | { ok: false, code: string, message: string }}
 */
export function assertCommitOnDevelop(options) {
    void options;
    throw new Error('assertCommitOnDevelop: not implemented');
}

/**
 * @param {{
 *   sha: string,
 *   mainRef?: string,
 *   runGit: (args: string[]) => { status: number, stdout: string, stderr: string },
 * }} options
 * @returns {{ ok: true } | { ok: false, code: string, message: string }}
 */
export function assertFastForwardPossible(options) {
    void options;
    throw new Error('assertFastForwardPossible: not implemented');
}
