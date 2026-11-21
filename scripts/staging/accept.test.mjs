import assert from 'node:assert/strict';
import { test } from 'node:test';

import {
    fetchStagingDeployments,
    parseArgs,
    runStagingAcceptance,
} from './accept.mjs';

test('accept refuses production and preview targets before any SHA work', async () => {
    for (const baseUrl of [
        'https://artfct.dev',
        'https://web-artfct-pr-103.up.railway.app',
    ]) {
        const result = await runStagingAcceptance({
            sha: 'deadbeef',
            baseUrl,
            authorizeMutations: false,
            mutating: false,
            environmentName: 'staging',
            deployments: [],
        });
        assert.equal(result.ok, false);
        assert.equal(result.step, 'target');
        assert.ok(
            result.code === 'production_forbidden' ||
                result.code === 'preview_forbidden',
        );
    }
});

test('accept verifies staging SHA and ignores production SUCCESS', async () => {
    const result = await runStagingAcceptance({
        sha: 'deadbeefcafebabe',
        baseUrl: 'https://staging.artfct.dev',
        authorizeMutations: false,
        mutating: false,
        environmentName: 'staging',
        deployments: [
            {
                serviceName: 'staging-web',
                status: 'SUCCESS',
                meta: { commitHash: 'deadbeefcafebabe' },
            },
            {
                serviceName: 'staging-queue',
                status: 'SUCCESS',
                meta: { commitHash: 'deadbeefcafebabe' },
            },
            {
                serviceName: 'staging-scheduler',
                status: 'SUCCESS',
                meta: { commitHash: 'deadbeefcafebabe' },
            },
            {
                serviceName: 'web',
                status: 'SUCCESS',
                meta: { commitHash: 'ffffffffffff' },
            },
        ],
    });

    assert.equal(result.ok, true);
    assert.equal(result.sha.ok, true);
});

test('accept blocks unauthorized shared-staging mutations', async () => {
    const result = await runStagingAcceptance({
        sha: 'deadbeefcafe',
        baseUrl: 'https://staging.artfct.dev',
        authorizeMutations: false,
        mutating: true,
        environmentName: 'staging',
        skipSha: true,
    });

    assert.equal(result.ok, false);
    assert.equal(result.step, 'authorize');
    assert.equal(result.code, 'mutations_not_authorized');
});

test('skipping SHA verification is never treated as success', async () => {
    const result = await runStagingAcceptance({
        sha: 'deadbeefcafe',
        baseUrl: 'https://staging.artfct.dev',
        authorizeMutations: false,
        mutating: false,
        environmentName: 'staging',
        skipSha: true,
    });

    assert.equal(result.ok, false);
    assert.equal(result.code, 'sha_skipped');
});

test('parseArgs defaults to staging and read-only', () => {
    const args = parseArgs(['--sha', 'deadbeefcafe']);
    assert.equal(args.baseUrl, 'https://staging.artfct.dev');
    assert.equal(args.authorizeMutations, false);
    assert.equal(args.mutating, false);
});

test('fetchStagingDeployments lists deployments at the top level and keeps the newest per service', async () => {
    /** @type {string | undefined} */
    let sentQuery;
    const fetchImpl = async (_url, init) => {
        sentQuery = JSON.parse(init.body).query;

        return {
            ok: true,
            json: async () => ({
                data: {
                    project: {
                        services: {
                            edges: [
                                {
                                    node: {
                                        id: 'svc-web',
                                        name: 'staging-web',
                                    },
                                },
                                {
                                    node: {
                                        id: 'svc-queue',
                                        name: 'staging-queue',
                                    },
                                },
                                { node: { id: 'svc-idle', name: 'Postgres' } },
                            ],
                        },
                    },
                    deployments: {
                        edges: [
                            {
                                node: {
                                    id: 'd1',
                                    serviceId: 'svc-web',
                                    status: 'FAILED',
                                    createdAt: '2026-11-21T07:21:00Z',
                                    meta: { commitHash: 'old' },
                                },
                            },
                            {
                                node: {
                                    id: 'd2',
                                    serviceId: 'svc-web',
                                    status: 'SUCCESS',
                                    createdAt: '2026-11-21T07:41:00Z',
                                    meta: { commitHash: 'new' },
                                },
                            },
                            {
                                node: {
                                    id: 'd3',
                                    serviceId: 'svc-queue',
                                    status: 'SUCCESS',
                                    createdAt: '2026-11-21T07:41:00Z',
                                    meta: { commitHash: 'new' },
                                },
                            },
                        ],
                    },
                },
            }),
        };
    };

    const deployments = await fetchStagingDeployments({
        token: 't',
        fetchImpl,
    });

    assert.deepEqual(deployments, [
        {
            serviceName: 'staging-web',
            status: 'SUCCESS',
            meta: { commitHash: 'new' },
        },
        {
            serviceName: 'staging-queue',
            status: 'SUCCESS',
            meta: { commitHash: 'new' },
        },
    ]);
    // Railway rejects `input` on Service.deployments with HTTP 400 (GRAPHQL_VALIDATION_FAILED).
    assert.doesNotMatch(sentQuery ?? '', /name\s+deployments\s*\(/);
    assert.match(
        sentQuery ?? '',
        /deployments\(\s*first: 50\s*input: \{ projectId: \$projectId, environmentId: \$environmentId \}/,
    );
});
