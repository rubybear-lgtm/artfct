import assert from 'node:assert/strict';
import { test } from 'node:test';

import { parseArgs, runStagingAcceptance } from './accept.mjs';

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
