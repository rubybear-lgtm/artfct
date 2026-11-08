/**
 * Required CI check gate for the production release Action (RUB-517).
 */

const RAILWAY_CONTEXT = /^artfct\s*-/i;
const RAILWAY_NAME = /railway/i;
const RAILWAY_APPS = new Set(['railway', 'railway-app']);

/**
 * Check names that must appear and conclude successfully (or skipped for the
 * path-filtered MCP e2e job) before a release may proceed.
 */
export const REQUIRED_CHECK_NAMES = [
    'Pest (browser)',
    'MCP local end-to-end (Postgres + Worker + hosted OAuth)',
    'application',
];

/**
 * Railway deploy statuses / PR preview checks are ignored so a production
 * release is not blocked by (or waiting on) Railway itself. Other apps
 * (Workers Builds, GitGuardian, …) are still evaluated when present.
 *
 * @param {{ name?: string, context?: string, appSlug?: string }} check
 * @returns {boolean}
 */
export function isIgnoredCheck(check) {
    const name = check.name ?? '';
    const context = check.context ?? '';
    const appSlug = (check.appSlug ?? '').toLowerCase();

    if (appSlug && RAILWAY_APPS.has(appSlug)) {
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
 * Keep the newest check run per name (GitHub returns every attempt).
 *
 * @template {{ name: string, startedAt?: string | null, completedAt?: string | null, id?: number | string }} T
 * @param {T[]} checks
 * @returns {T[]}
 */
export function latestChecksByName(checks) {
    /** @type {Map<string, T>} */
    const latest = new Map();

    for (const check of checks) {
        const previous = latest.get(check.name);

        if (!previous) {
            latest.set(check.name, check);
            continue;
        }

        const previousTs = timestampForCheck(previous);
        const nextTs = timestampForCheck(check);

        if (nextTs >= previousTs) {
            latest.set(check.name, check);
        }
    }

    return [...latest.values()];
}

/**
 * @param {{ startedAt?: string | null, completedAt?: string | null, id?: number | string }} check
 */
function timestampForCheck(check) {
    const raw = check.completedAt || check.startedAt;

    if (raw) {
        const parsed = Date.parse(raw);

        if (!Number.isNaN(parsed)) {
            return parsed;
        }
    }

    if (typeof check.id === 'number') {
        return check.id;
    }

    if (typeof check.id === 'string' && /^\d+$/.test(check.id)) {
        return Number(check.id);
    }

    return 0;
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
    if (check.conclusion === 'success') {
        return true;
    }

    // Path-filtered MCP e2e is skipped on purpose; treat that as acceptable.
    if (
        check.name ===
            'MCP local end-to-end (Postgres + Worker + hosted OAuth)' &&
        check.conclusion === 'skipped'
    ) {
        return true;
    }

    return false;
}

/**
 * @param {{
 *   sha: string,
 *   requiredCheckNames?: string[],
 *   listCheckRuns: (sha: string) => Promise<Array<{
 *     name: string,
 *     status: string,
 *     conclusion: string | null,
 *     appSlug?: string,
 *     startedAt?: string | null,
 *     completedAt?: string | null,
 *     id?: number | string,
 *   }>>,
 *   listCommitStatuses?: (sha: string) => Promise<Array<{
 *     context: string,
 *     state: string,
 *     updatedAt?: string | null,
 *   }>>,
 *   sleep?: (ms: number) => Promise<void>,
 *   now?: () => number,
 *   pollIntervalMs?: number,
 *   timeoutMs?: number,
 * }} options
 */
export async function assertRequiredChecksSucceeded(options) {
    const sleep =
        options.sleep ?? ((ms) => new Promise((r) => setTimeout(r, ms)));
    const now = options.now ?? Date.now;
    const pollIntervalMs = options.pollIntervalMs ?? 15_000;
    const timeoutMs = options.timeoutMs ?? 30 * 60_000;
    const requiredNames = options.requiredCheckNames ?? REQUIRED_CHECK_NAMES;
    const started = now();

    while (true) {
        const checkRuns = latestChecksByName(
            (await options.listCheckRuns(options.sha)).filter(
                (check) => !isIgnoredCheck(check),
            ),
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

        /** @type {Array<{ name: string, status: string, conclusion: string | null, appSlug?: string, startedAt?: string | null, completedAt?: string | null, id?: number | string }>} */
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
            completedAt: status.updatedAt ?? null,
        }));

        const all = latestChecksByName([...checkRuns, ...normalizedStatuses]);
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
            const present = new Set(all.map((c) => c.name));
            const missing = requiredNames.filter((name) => !present.has(name));

            if (all.length === 0 || missing.length > 0) {
                return {
                    ok: false,
                    code: 'checks_missing',
                    failed: missing.length > 0 ? missing : requiredNames,
                    message:
                        all.length === 0
                            ? `No required CI checks found on ${options.sha}. Expected at least: ${requiredNames.join(', ')}.`
                            : `Required CI check(s) missing on ${options.sha}: ${missing.join(', ')}.`,
                };
            }

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
