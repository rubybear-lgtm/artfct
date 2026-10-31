/**
 * Git ancestry gates for the production release Action (RUB-517).
 */

/**
 * @param {{
 *   sha: string,
 *   developRef?: string,
 *   runGit: (args: string[]) => { status: number, stdout: string, stderr: string },
 * }} options
 * @returns {{ ok: true, sha: string } | { ok: false, code: string, message: string }}
 */
export function assertCommitOnDevelop(options) {
    const developRef = options.developRef ?? 'refs/remotes/origin/develop';
    const resolved = options.runGit([
        'rev-parse',
        '--verify',
        `${options.sha}^{commit}`,
    ]);

    if (resolved.status !== 0) {
        return {
            ok: false,
            code: 'unknown_sha',
            message: `Commit ${options.sha} could not be resolved.`,
        };
    }

    const sha = resolved.stdout.trim();
    const onDevelop = options.runGit([
        'merge-base',
        '--is-ancestor',
        sha,
        developRef,
    ]);

    if (onDevelop.status !== 0) {
        return {
            ok: false,
            code: 'not_on_develop',
            message: `Commit ${sha} is not on develop (${developRef}). Only commits reachable from develop can be released.`,
        };
    }

    return { ok: true, sha };
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
    const mainRef = options.mainRef ?? 'refs/remotes/origin/main';
    const ancestor = options.runGit([
        'merge-base',
        '--is-ancestor',
        mainRef,
        options.sha,
    ]);

    if (ancestor.status !== 0) {
        return {
            ok: false,
            code: 'not_fast_forward',
            message: [
                `Cannot fast-forward main (${mainRef}) to ${options.sha}: main is not an ancestor of that commit.`,
                'Someone likely committed or merged straight onto main, so a fast-forward would rewrite history.',
                'Fix: sync by merging main into develop (open a sync PR), wait for CI on develop, then release the synced develop tip.',
                'Nothing was pushed.',
            ].join(' '),
        };
    }

    return { ok: true };
}
