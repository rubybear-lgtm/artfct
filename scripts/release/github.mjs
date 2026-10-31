/**
 * Thin GitHub API helpers used by the release Action (RUB-517).
 * Tokens are read from env and must never be logged.
 */

/**
 * @param {{
 *   token: string,
 *   repo: string,
 *   fetchImpl?: typeof fetch,
 * }} options
 */
export function createGithubClient(options) {
    const fetchImpl = options.fetchImpl ?? fetch;
    const base = `https://api.github.com/repos/${options.repo}`;

    /**
     * @param {string} path
     * @param {RequestInit} [init]
     */
    async function request(path, init = {}) {
        const response = await fetchImpl(`${base}${path}`, {
            ...init,
            headers: {
                Accept: 'application/vnd.github+json',
                Authorization: `Bearer ${options.token}`,
                'X-GitHub-Api-Version': '2022-11-28',
                ...(init.headers ?? {}),
            },
        });

        if (!response.ok) {
            const body = await response.text();
            throw new Error(
                `GitHub API ${init.method ?? 'GET'} ${path} failed: HTTP ${response.status} ${body.slice(0, 500)}`,
            );
        }

        if (response.status === 204) {
            return null;
        }

        return response.json();
    }

    return {
        /**
         * @param {string} sha
         */
        async listCheckRuns(sha) {
            /** @type {Array<{ name: string, status: string, conclusion: string | null, appSlug?: string }>} */
            const runs = [];
            let page = 1;
            while (true) {
                const payload = await request(
                    `/commits/${encodeURIComponent(sha)}/check-runs?per_page=100&page=${page}`,
                );
                for (const run of payload.check_runs ?? []) {
                    runs.push({
                        name: run.name,
                        status: run.status,
                        conclusion: run.conclusion,
                        appSlug: run.app?.slug,
                    });
                }
                if ((payload.check_runs ?? []).length < 100) {
                    break;
                }
                page += 1;
            }
            return runs;
        },

        /**
         * @param {string} sha
         */
        async listCommitStatuses(sha) {
            const payload = await request(
                `/commits/${encodeURIComponent(sha)}/status`,
            );
            return (payload.statuses ?? []).map((status) => ({
                context: status.context,
                state: status.state,
            }));
        },

        /**
         * @param {{ fromRef: string | null, toSha: string }} range
         */
        async listMergedPrs(range) {
            const comparePath = range.fromRef
                ? `/compare/${encodeURIComponent(range.fromRef)}...${encodeURIComponent(range.toSha)}`
                : null;

            /** @type {Map<number, { number: number, title: string, url: string }>} */
            const prs = new Map();

            if (comparePath) {
                const compare = await request(comparePath);
                for (const commit of compare.commits ?? []) {
                    const message = commit.commit?.message ?? '';
                    const mergeMatch = message.match(
                        /Merge pull request #(\d+)/,
                    );
                    if (mergeMatch) {
                        const number = Number(mergeMatch[1]);
                        const titleLine = message.split('\n').slice(1).join(' ').trim();
                        prs.set(number, {
                            number,
                            title: titleLine || `PR #${number}`,
                            url: `https://github.com/${options.repo}/pull/${number}`,
                        });
                    }
                    const squashMatch = message.match(/\(#(\d+)\)\s*$/m);
                    if (squashMatch) {
                        const number = Number(squashMatch[1]);
                        const title = message.split('\n')[0].replace(/\s*\(#\d+\)\s*$/, '');
                        prs.set(number, {
                            number,
                            title,
                            url: `https://github.com/${options.repo}/pull/${number}`,
                        });
                    }
                }
            }

            return [...prs.values()].sort((a, b) => a.number - b.number);
        },

        /**
         * @param {{ tag: string, sha: string, body: string, name?: string }} release
         */
        async createRelease(release) {
            return request('/releases', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    tag_name: release.tag,
                    target_commitish: release.sha,
                    name: release.name ?? release.tag,
                    body: release.body,
                    draft: false,
                    prerelease: false,
                }),
            });
        },
    };
}

/**
 * Mask a secret in the GitHub Actions log stream without printing it.
 * @param {string | undefined} value
 * @param {(line: string) => void} [write]
 */
export function maskSecret(value, write = console.log) {
    if (!value) {
        return;
    }
    write(`::add-mask::${value}`);
}
