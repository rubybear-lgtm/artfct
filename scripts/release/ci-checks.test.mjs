import assert from 'node:assert/strict';
import { test } from 'node:test';

import {
    assertRequiredChecksSucceeded,
    isIgnoredCheck,
    latestChecksByName,
} from './ci-checks.mjs';

test('A5: Railway preview / deploy statuses are ignored', () => {
    assert.equal(
        isIgnoredCheck({
            context: 'artfct - web',
            name: 'artfct - web',
        }),
        true,
    );
    assert.equal(
        isIgnoredCheck({
            context: 'artfct - staging-web',
            name: 'artfct - staging-web',
        }),
        true,
    );
    assert.equal(
        isIgnoredCheck({
            name: 'Railway Preview',
            appSlug: 'railway',
        }),
        true,
    );
    assert.equal(
        isIgnoredCheck({
            name: 'Pest (browser)',
            appSlug: 'github-actions',
        }),
        false,
    );
    assert.equal(
        isIgnoredCheck({
            name: 'MCP local end-to-end (Postgres + Worker + hosted OAuth)',
            appSlug: 'github-actions',
        }),
        false,
    );
    assert.equal(
        isIgnoredCheck({
            name: 'Workers Builds: artfct-engine',
            appSlug: 'cloudflare-workers-and-pages',
        }),
        false,
    );
    assert.equal(
        isIgnoredCheck({
            name: 'GitGuardian Security Checks',
            appSlug: 'gitguardian',
        }),
        false,
    );
});

test('A3: rejects when one required check failed and names it', async () => {
    const result = await assertRequiredChecksSucceeded({
        sha: 'abc',
        timeoutMs: 1,
        pollIntervalMs: 1,
        sleep: async () => {},
        now: () => 0,
        listCheckRuns: async () => [
            {
                name: 'Pest (browser)',
                status: 'completed',
                conclusion: 'success',
                appSlug: 'github-actions',
            },
            {
                name: 'MCP local end-to-end (Postgres + Worker + hosted OAuth)',
                status: 'completed',
                conclusion: 'failure',
                appSlug: 'github-actions',
            },
            {
                name: 'application',
                status: 'completed',
                conclusion: 'success',
                appSlug: 'github-actions',
            },
        ],
        listCommitStatuses: async () => [
            { context: 'artfct - web', state: 'pending' },
        ],
    });

    assert.equal(result.ok, false);
    assert.equal(result.code, 'checks_failed');
    assert.deepEqual(result.failed, [
        'MCP local end-to-end (Postgres + Worker + hosted OAuth)',
    ]);
    assert.match(
        result.message,
        /MCP local end-to-end \(Postgres \+ Worker \+ hosted OAuth\)/,
    );
});

test('A4: waits for a pending check then rejects and names it', async () => {
    let now = 0;
    let polls = 0;
    const sleeps = [];

    const result = await assertRequiredChecksSucceeded({
        sha: 'abc',
        timeoutMs: 100,
        pollIntervalMs: 25,
        sleep: async (ms) => {
            sleeps.push(ms);
            now += ms;
        },
        now: () => now,
        listCheckRuns: async () => {
            polls += 1;

            return [
                {
                    name: 'Pest (browser)',
                    status: 'in_progress',
                    conclusion: null,
                    appSlug: 'github-actions',
                },
                {
                    name: 'application',
                    status: 'completed',
                    conclusion: 'success',
                    appSlug: 'github-actions',
                },
                {
                    name: 'MCP local end-to-end (Postgres + Worker + hosted OAuth)',
                    status: 'completed',
                    conclusion: 'success',
                    appSlug: 'github-actions',
                },
            ];
        },
        listCommitStatuses: async () => [],
    });

    assert.equal(result.ok, false);
    assert.equal(result.code, 'checks_pending_timeout');
    assert.ok(polls >= 2, `expected multiple polls, got ${polls}`);
    assert.ok(sleeps.length >= 1, 'expected to wait between polls');
    assert.deepEqual(result.pending, ['Pest (browser)']);
    assert.match(result.message, /Pest \(browser\)/);
});

test('A5: all required checks green are accepted; Railway statuses ignored', async () => {
    const result = await assertRequiredChecksSucceeded({
        sha: 'abc',
        timeoutMs: 1,
        pollIntervalMs: 1,
        sleep: async () => {},
        now: () => 0,
        listCheckRuns: async () => [
            {
                name: 'Pest (browser)',
                status: 'completed',
                conclusion: 'success',
                appSlug: 'github-actions',
            },
            {
                name: 'MCP local end-to-end (Postgres + Worker + hosted OAuth)',
                status: 'completed',
                conclusion: 'success',
                appSlug: 'github-actions',
            },
            {
                name: 'application',
                status: 'completed',
                conclusion: 'success',
                appSlug: 'github-actions',
            },
            {
                name: 'Release readiness: OpenAPI, contract and hosted MCP checks',
                status: 'completed',
                conclusion: 'success',
                appSlug: 'github-actions',
            },
            {
                name: 'Workers Builds: artfct-engine-staging',
                status: 'completed',
                conclusion: 'success',
                appSlug: 'cloudflare-workers-and-pages',
            },
        ],
        listCommitStatuses: async () => [
            { context: 'artfct - staging-web', state: 'success' },
            { context: 'artfct - web', state: 'failure' },
        ],
    });

    assert.equal(result.ok, true);
    assert.ok(result.checks.every((c) => c.conclusion === 'success' || c.conclusion === 'skipped'));
    assert.equal(
        result.checks.some((c) => /artfct -/i.test(c.name)),
        false,
    );
});

test('A5: empty or incomplete required checks are rejected', async () => {
    const empty = await assertRequiredChecksSucceeded({
        sha: 'abc',
        timeoutMs: 1,
        pollIntervalMs: 1,
        sleep: async () => {},
        now: () => 0,
        listCheckRuns: async () => [],
        listCommitStatuses: async () => [],
    });

    assert.equal(empty.ok, false);
    assert.equal(empty.code, 'checks_missing');

    const incomplete = await assertRequiredChecksSucceeded({
        sha: 'abc',
        timeoutMs: 1,
        pollIntervalMs: 1,
        sleep: async () => {},
        now: () => 0,
        listCheckRuns: async () => [
            {
                name: 'application',
                status: 'completed',
                conclusion: 'success',
                appSlug: 'github-actions',
            },
        ],
        listCommitStatuses: async () => [],
    });

    assert.equal(incomplete.ok, false);
    assert.equal(incomplete.code, 'checks_missing');
    assert.ok(incomplete.failed?.includes('Pest (browser)'));
});

test('A5: latest check run per name wins over an older failure', async () => {
    const latest = latestChecksByName([
        {
            name: 'Pest (browser)',
            status: 'completed',
            conclusion: 'failure',
            id: 1,
            completedAt: '2026-10-31T10:00:00.000Z',
        },
        {
            name: 'Pest (browser)',
            status: 'completed',
            conclusion: 'success',
            id: 2,
            completedAt: '2026-10-31T11:00:00.000Z',
        },
    ]);

    assert.equal(latest.length, 1);
    assert.equal(latest[0].conclusion, 'success');

    const result = await assertRequiredChecksSucceeded({
        sha: 'abc',
        timeoutMs: 1,
        pollIntervalMs: 1,
        sleep: async () => {},
        now: () => 0,
        listCheckRuns: async () => [
            {
                name: 'Pest (browser)',
                status: 'completed',
                conclusion: 'failure',
                appSlug: 'github-actions',
                completedAt: '2026-10-31T10:00:00.000Z',
                id: 1,
            },
            {
                name: 'Pest (browser)',
                status: 'completed',
                conclusion: 'success',
                appSlug: 'github-actions',
                completedAt: '2026-10-31T11:00:00.000Z',
                id: 2,
            },
            {
                name: 'MCP local end-to-end (Postgres + Worker + hosted OAuth)',
                status: 'completed',
                conclusion: 'skipped',
                appSlug: 'github-actions',
                completedAt: '2026-10-31T11:00:00.000Z',
                id: 3,
            },
            {
                name: 'application',
                status: 'completed',
                conclusion: 'success',
                appSlug: 'github-actions',
                completedAt: '2026-10-31T11:00:00.000Z',
                id: 4,
            },
        ],
        listCommitStatuses: async () => [],
    });

    assert.equal(result.ok, true);
});

test('A5: a failed non-Railway check is rejected', async () => {
    const result = await assertRequiredChecksSucceeded({
        sha: 'abc',
        timeoutMs: 1,
        pollIntervalMs: 1,
        sleep: async () => {},
        now: () => 0,
        listCheckRuns: async () => [
            {
                name: 'Pest (browser)',
                status: 'completed',
                conclusion: 'success',
                appSlug: 'github-actions',
            },
            {
                name: 'MCP local end-to-end (Postgres + Worker + hosted OAuth)',
                status: 'completed',
                conclusion: 'success',
                appSlug: 'github-actions',
            },
            {
                name: 'application',
                status: 'completed',
                conclusion: 'success',
                appSlug: 'github-actions',
            },
            {
                name: 'Workers Builds: artfct-engine',
                status: 'completed',
                conclusion: 'failure',
                appSlug: 'cloudflare-workers-and-pages',
            },
        ],
        listCommitStatuses: async () => [],
    });

    assert.equal(result.ok, false);
    assert.equal(result.code, 'checks_failed');
    assert.deepEqual(result.failed, ['Workers Builds: artfct-engine']);
});
