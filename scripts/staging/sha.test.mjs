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

test('shaMatches accepts exact and prefix/suffix overlaps', () => {
    assert.equal(shaMatches('abc123def', 'abc123def'), true);
    assert.equal(shaMatches('abc123def456', 'abc123def'), true);
    assert.equal(shaMatches('abc123def', 'abc123def456'), true);
    assert.equal(shaMatches('abc123def', null), false);
    assert.equal(shaMatches('abc123def', 'zzz'), false);
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

test('assertStagingDeploymentSha fails closed for missing SHA, missing service, mismatch, and failure', () => {
    assert.equal(
        assertStagingDeploymentSha({ expectedSha: '', deployments: [] }).code,
        'missing_sha',
    );

    assert.equal(
        assertStagingDeploymentSha({
            expectedSha: 'deadbeef',
            deployments: [
                {
                    serviceName: 'staging-web',
                    status: 'SUCCESS',
                    meta: { commitHash: 'deadbeef' },
                },
            ],
        }).code,
        'missing_service',
    );

    assert.equal(
        assertStagingDeploymentSha({
            expectedSha: 'deadbeef',
            deployments: [
                {
                    serviceName: 'staging-web',
                    status: 'SUCCESS',
                    meta: { commitHash: 'aaaaaaaa' },
                },
                {
                    serviceName: 'staging-queue',
                    status: 'SUCCESS',
                    meta: { commitHash: 'deadbeef' },
                },
                {
                    serviceName: 'staging-scheduler',
                    status: 'SUCCESS',
                    meta: { commitHash: 'deadbeef' },
                },
            ],
        }).code,
        'sha_mismatch',
    );

    assert.equal(
        assertStagingDeploymentSha({
            expectedSha: 'deadbeef',
            deployments: [
                {
                    serviceName: 'staging-web',
                    status: 'FAILED',
                    meta: { commitHash: 'deadbeef' },
                },
                {
                    serviceName: 'staging-queue',
                    status: 'SUCCESS',
                    meta: { commitHash: 'deadbeef' },
                },
                {
                    serviceName: 'staging-scheduler',
                    status: 'SUCCESS',
                    meta: { commitHash: 'deadbeef' },
                },
            ],
        }).code,
        'deploy_failed',
    );
});

test('production-only SUCCESS deployments never satisfy a staging SHA check', () => {
    const result = assertStagingDeploymentSha({
        expectedSha: 'deadbeef',
        deployments: [
            {
                serviceName: 'web',
                status: 'SUCCESS',
                meta: { commitHash: 'deadbeef' },
            },
            {
                serviceName: 'queue',
                status: 'SUCCESS',
                meta: { commitHash: 'deadbeef' },
            },
            {
                serviceName: 'scheduler',
                status: 'SUCCESS',
                meta: { commitHash: 'deadbeef' },
            },
        ],
    });

    assert.equal(result.ok, false);
    assert.equal(result.code, 'missing_service');
    assert.match(result.message, /staging-web/);
});
