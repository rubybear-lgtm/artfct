import assert from 'node:assert/strict';
import { test } from 'node:test';

import {
    PRODUCTION_SERVICE_NAMES,
    STAGING_SERVICE_NAMES,
    assertStagingDeploymentSha,
    commitShaFromMeta,
    shaMatches,
} from './sha.mjs';

test('staging service names never include production web/queue/scheduler', () => {
    assert.deepEqual(STAGING_SERVICE_NAMES, [
        'staging-web',
        'staging-queue',
        'staging-scheduler',
    ]);

    for (const name of PRODUCTION_SERVICE_NAMES) {
        assert.equal(STAGING_SERVICE_NAMES.includes(name), false);
    }
});

test('shaMatches requires a long enough hex prefix and is case-insensitive', () => {
    assert.equal(
        shaMatches(
            'deadbeefcafe',
            'DEADBEEFCAFE0123456789abcdef0123456789ab',
        ),
        true,
    );
    assert.equal(
        shaMatches(
            'deadbeefcafe0123456789abcdef0123456789ab',
            'deadbeefcafe',
        ),
        true,
    );
    assert.equal(
        shaMatches(
            'deadbeefcafe0123456789abcdef0123456789ab',
            'deadbeefcafe0123456789abcdef0123456789ab',
        ),
        true,
    );
    assert.equal(shaMatches('a', 'abcdef0123456789'), false);
    assert.equal(shaMatches('abcdef012', 'abcdef0123456789'), false);
    assert.equal(shaMatches('deadbeefcafe', null), false);
    assert.equal(shaMatches('deadbeefcafe', 'zzzzzzzzzzzz'), false);
});

test('commitShaFromMeta reads known Railway meta keys', () => {
    assert.equal(commitShaFromMeta({ commitHash: 'aaa' }), 'aaa');
    assert.equal(commitShaFromMeta({ commitSha: 'bbb' }), 'bbb');
    assert.equal(commitShaFromMeta({}), null);
    assert.equal(commitShaFromMeta(null), null);
});

test('assertStagingDeploymentSha passes when every staging service is SUCCESS for the SHA', () => {
    const result = assertStagingDeploymentSha({
        expectedSha: 'deadbeefcafebabe',
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
            // Production success for a different SHA must be ignored, never used as fallback.
            {
                serviceName: 'web',
                status: 'SUCCESS',
                meta: { commitHash: 'ffffffffffff' },
            },
        ],
    });

    assert.equal(result.ok, true);
    assert.equal(result.matchedServices.length, 3);
});

test('SLEEPING staging-web counts as deployed when the commit matches', () => {
    const result = assertStagingDeploymentSha({
        expectedSha: 'deadbeefcafebabe0123',
        deployments: [
            {
                serviceName: 'staging-web',
                status: 'SLEEPING',
                meta: { commitHash: 'deadbeefcafebabe0123' },
            },
            {
                serviceName: 'staging-queue',
                status: 'SUCCESS',
                meta: { commitHash: 'deadbeefcafebabe0123' },
            },
            {
                serviceName: 'staging-scheduler',
                status: 'SUCCESS',
                meta: { commitHash: 'deadbeefcafebabe0123' },
            },
        ],
    });

    assert.equal(result.ok, true);
});

test('assertStagingDeploymentSha fails closed for missing SHA, missing service, mismatch, and failure', () => {
    assert.equal(
        assertStagingDeploymentSha({ expectedSha: '', deployments: [] }).code,
        'missing_sha',
    );

    assert.equal(
        assertStagingDeploymentSha({
            expectedSha: 'short',
            deployments: [],
        }).code,
        'invalid_sha',
    );

    assert.equal(
        assertStagingDeploymentSha({
            expectedSha: 'deadbeefcafe',
            deployments: [
                {
                    serviceName: 'staging-web',
                    status: 'SUCCESS',
                    meta: { commitHash: 'deadbeefcafe' },
                },
            ],
        }).code,
        'missing_service',
    );

    assert.equal(
        assertStagingDeploymentSha({
            expectedSha: 'deadbeefcafe',
            deployments: [
                {
                    serviceName: 'staging-web',
                    status: 'SUCCESS',
                    meta: { commitHash: 'aaaaaaaaaaaa' },
                },
                {
                    serviceName: 'staging-queue',
                    status: 'SUCCESS',
                    meta: { commitHash: 'deadbeefcafe' },
                },
                {
                    serviceName: 'staging-scheduler',
                    status: 'SUCCESS',
                    meta: { commitHash: 'deadbeefcafe' },
                },
            ],
        }).code,
        'sha_mismatch',
    );

    assert.equal(
        assertStagingDeploymentSha({
            expectedSha: 'deadbeefcafe',
            deployments: [
                {
                    serviceName: 'staging-web',
                    status: 'FAILED',
                    meta: { commitHash: 'deadbeefcafe' },
                },
                {
                    serviceName: 'staging-queue',
                    status: 'SUCCESS',
                    meta: { commitHash: 'deadbeefcafe' },
                },
                {
                    serviceName: 'staging-scheduler',
                    status: 'SUCCESS',
                    meta: { commitHash: 'deadbeefcafe' },
                },
            ],
        }).code,
        'deploy_failed',
    );

    assert.equal(
        assertStagingDeploymentSha({
            expectedSha: 'deadbeefcafe',
            deployments: [
                {
                    serviceName: 'staging-web',
                    status: 'BUILDING',
                    meta: { commitHash: 'deadbeefcafe' },
                },
                {
                    serviceName: 'staging-queue',
                    status: 'SUCCESS',
                    meta: { commitHash: 'deadbeefcafe' },
                },
                {
                    serviceName: 'staging-scheduler',
                    status: 'SUCCESS',
                    meta: { commitHash: 'deadbeefcafe' },
                },
            ],
        }).code,
        'deploy_not_ready',
    );
});

test('production-only SUCCESS deployments never satisfy a staging SHA check', () => {
    const result = assertStagingDeploymentSha({
        expectedSha: 'deadbeefcafe',
        deployments: [
            {
                serviceName: 'web',
                status: 'SUCCESS',
                meta: { commitHash: 'deadbeefcafe' },
            },
            {
                serviceName: 'queue',
                status: 'SUCCESS',
                meta: { commitHash: 'deadbeefcafe' },
            },
            {
                serviceName: 'scheduler',
                status: 'SUCCESS',
                meta: { commitHash: 'deadbeefcafe' },
            },
        ],
    });

    assert.equal(result.ok, false);
    assert.equal(result.code, 'missing_service');
    assert.match(result.message, /staging-web/);
});
