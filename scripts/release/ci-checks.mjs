/**
 * Required CI check gate for the production release Action (RUB-517).
 */

const RAILWAY_CONTEXT = /^artfct\s*-/i;
const RAILWAY_NAME = /railway/i;
const IGNORED_APPS = new Set([
    'railway',
    'railway-app',
    'cloudflare-workers-and-pages',
    'gitguardian',
]);

/**
 * Railway deploy statuses / PR preview checks are ignored so a production
 * release is not blocked by (or waiting on) Railway itself.
 *
 * @param {{ name?: string, context?: string, appSlug?: string }} check
 * @returns {boolean}
 */
export function isIgnoredCheck(check) {
    const name = check.name ?? '';
    const context = check.context ?? '';
    const appSlug = (check.appSlug ?? '').toLowerCase();

    if (appSlug && IGNORED_APPS.has(appSlug)) {
        return true;
    }
    if (RAILWAY_CONTEXT.test(context) || RAILWAY_CONTEXT.test(name)) {
        return true;
    }
    if (RAILWAY_NAME.test(name) || RAILWAY_NAME.test(context)) {
        return true;
    }

    return false;
}

/**
 * @param {{
 *   name: string,
 *   status: string,
 *   conclusion: string | null,
 *   appSlug?: string,
 * }} check
 */
function isPending(check) {
    return (
        check.status === 'queued' ||
        check.status === 'in_progress' ||
        check.status === 'pending' ||
        check.conclusion === null ||
        check.conclusion === 'pending'
    );
}

/**
 * @param {{
 *   name: string,
 *   status: string,
 *   conclusion: string | null,
 *   appSlug?: string,
 * }} check
 */
function isAcceptable(check) {
    return (
        check.conclusion === 'success' ||
        check.conclusion === 'skipped' ||
        check.conclusion === 'neutral'
    );
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
 */
export async function assertRequiredChecksSucceeded(options) {
    const sleep = options.sleep ?? ((ms) => new Promise((r) => setTimeout(r, ms)));
    const now = options.now ?? Date.now;
    const pollIntervalMs = options.pollIntervalMs ?? 15_000;
    const timeoutMs = options.timeoutMs ?? 30 * 60_000;
    const started = now();

    while (true) {
        const checkRuns = (await options.listCheckRuns(options.sha)).filter(
            (check) => !isIgnoredCheck(check),
        );
        const statuses = options.listCommitStatuses
            ? (await options.listCommitStatuses(options.sha)).filter(
                  (status) =>
                      !isIgnoredCheck({
                          name: status.context,
                          context: status.context,
                      }),
              )
            : [];

        /** @type {Array<{ name: string, status: string, conclusion: string | null, appSlug?: string }>} */
        const normalizedStatuses = statuses.map((status) => ({
            name: status.context,
            status:
                status.state === 'pending' || status.state === 'expected'
                    ? 'in_progress'
                    : 'completed',
            conclusion:
                status.state === 'success'
                    ? 'success'
                    : status.state === 'failure' || status.state === 'error'
                      ? 'failure'
                      : status.state === 'pending' || status.state === 'expected'
                        ? null
                        : status.state,
            appSlug: undefined,
        }));

        const all = [...checkRuns, ...normalizedStatuses];
        const pending = all.filter(isPending).map((c) => c.name);
        const failed = all
            .filter((c) => !isPending(c) && !isAcceptable(c))
            .map((c) => c.name);

        if (failed.length > 0) {
            const uniqueFailed = [...new Set(failed)];
            return {
                ok: false,
                code: 'checks_failed',
                failed: uniqueFailed,
                message: `Required CI check(s) failed: ${uniqueFailed.join(', ')}.`,
            };
        }

        if (pending.length === 0) {
            return {
                ok: true,
                checks: all.map((c) => ({
                    name: c.name,
                    conclusion: c.conclusion ?? 'success',
                })),
            };
        }

        if (now() - started >= timeoutMs) {
            const uniquePending = [...new Set(pending)];
            return {
                ok: false,
                code: 'checks_pending_timeout',
                pending: uniquePending,
                message: `Timed out waiting for required CI check(s): ${uniquePending.join(', ')}.`,
            };
        }

        await sleep(pollIntervalMs);
    }
}
