import assert from 'node:assert/strict';
import { test } from 'node:test';

import { waitForProductionDeployments } from './railway-poller.mjs';

const projectId = 'f54567a7-a1fe-4d1d-ae2c-02477781226c';
const environmentId = 'ac8c4e43-6051-41f1-9c6d-05f2aa0b29a2';
const releaseSha = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
const oldSha = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';

function projectPayload(services) {
    return {
        project: {
            id: projectId,
            services: {
                edges: services.map((service) => ({
                    node: {
                        id: service.id,
                        name: service.name,
                    },
                })),
            },
        },
    };
}

test('A6: succeeds only when web, queue and scheduler SUCCESS for the release commit', async () => {
    const result = await waitForProductionDeployments({
        commitSha: releaseSha,
        projectId,
        environmentId,
        pollIntervalMs: 1,
        timeoutMs: 50,
        sleep: async () => {},
        now: () => 0,
        graphql: async (query, variables) => {
            if (query.includes('project(')) {
                return projectPayload([
                    { id: 'svc-web', name: 'web' },
                    { id: 'svc-queue', name: 'queue' },
                    { id: 'svc-scheduler', name: 'scheduler' },
                ]);
            }

            if (query.includes('deployments(')) {
                const serviceId = variables.input.serviceId;
                const metaSha =
                    serviceId === 'svc-web' ||
                    serviceId === 'svc-queue' ||
                    serviceId === 'svc-scheduler'
                        ? releaseSha
                        : oldSha;

                return {
                    deployments: {
                        edges: [
                            {
                                node: {
                                    id: `dep-${serviceId}`,
                                    status: 'SUCCESS',
                                    createdAt: '2026-10-31T00:00:00.000Z',
                                    meta: { commitHash: metaSha },
                                },
                            },
                        ],
                    },
                };
            }

            throw new Error(`unexpected query: ${query}`);
        },
    });

    assert.equal(result.ok, true);
    assert.deepEqual(
        result.services.map((s) => s.name).sort(),
        ['queue', 'scheduler', 'web'],
    );
    assert.ok(result.services.every((s) => s.status === 'SUCCESS'));
    assert.ok(result.services.every((s) => s.commitSha === releaseSha));
});

test('A6: an older-commit SUCCESS deploy does not count', async () => {
    const result = await waitForProductionDeployments({
        commitSha: releaseSha,
        projectId,
        environmentId,
        pollIntervalMs: 1,
        timeoutMs: 30,
        sleep: async () => {},
        now: (() => {
            let t = 0;

            return () => {
                const current = t;
                t += 20;

                return current;
            };
        })(),
        graphql: async (query) => {
            if (query.includes('project(')) {
                return projectPayload([
                    { id: 'svc-web', name: 'web' },
                    { id: 'svc-queue', name: 'queue' },
                    { id: 'svc-scheduler', name: 'scheduler' },
                ]);
            }

            return {
                deployments: {
                    edges: [
                        {
                            node: {
                                id: 'dep-old',
                                status: 'SUCCESS',
                                createdAt: '2026-10-30T00:00:00.000Z',
                                meta: { commitHash: oldSha },
                            },
                        },
                    ],
                },
            };
        },
    });

    assert.equal(result.ok, false);
    assert.equal(result.code, 'deploy_timeout');
    assert.match(result.message, /web|queue|scheduler/i);
});

test('A6: FAILED or CRASHED fails and names the service', async () => {
    for (const terminal of ['FAILED', 'CRASHED']) {
        const result = await waitForProductionDeployments({
            commitSha: releaseSha,
            projectId,
            environmentId,
            pollIntervalMs: 1,
            timeoutMs: 50,
            sleep: async () => {},
            now: () => 0,
            graphql: async (query, variables) => {
                if (query.includes('project(')) {
                    return projectPayload([
                        { id: 'svc-web', name: 'web' },
                        { id: 'svc-queue', name: 'queue' },
                        { id: 'svc-scheduler', name: 'scheduler' },
                    ]);
                }

                const serviceId = variables.input.serviceId;
                const status = serviceId === 'svc-queue' ? terminal : 'DEPLOYING';

            return {
                deployments: {
                    edges: [
                        {
                            node: {
                                id: `dep-${serviceId}`,
                                status,
                                createdAt: '2026-10-31T00:00:00.000Z',
                                meta: { commitHash: releaseSha },
                            },
                        },
                    ],
                },
            };
        },
    });

        assert.equal(result.ok, false);
        assert.equal(result.code, 'deploy_failed');
        assert.equal(result.service, 'queue');
        assert.match(result.message, /queue/i);
        assert.match(result.message, new RegExp(terminal));
    }
});
