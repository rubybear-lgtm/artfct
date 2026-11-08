import assert from 'node:assert/strict';
import { test } from 'node:test';

import {
    checkRailwayCredentials,
    checkSmokeCredentials,
} from './credential-check.mjs';

const serviceNames = ['web', 'queue', 'scheduler'];

/** @param {string[]} names */
function projectWith(names) {
    return {
        project: {
            id: 'p1',
            services: {
                edges: names.map((name, index) => ({
                    node: { id: `s${index}`, name },
                })),
            },
        },
    };
}

test('railway check fails with a secret-name hint when the token is empty', async () => {
    const result = await checkRailwayCredentials({
        graphql: undefined,
        projectId: 'p1',
        serviceNames,
    });

    assert.equal(result.ok, false);
    assert.match(result.message, /RAILWAY_API_TOKEN_PRODUCTION/);
});

test('railway check passes when the token reads the project and all services exist', async () => {
    const result = await checkRailwayCredentials({
        graphql: async () => projectWith(['web', 'queue', 'scheduler', 'extra']),
        projectId: 'p1',
        serviceNames,
    });

    assert.equal(result.ok, true);
});

test('railway check reports a rejected token', async () => {
    const result = await checkRailwayCredentials({
        graphql: async () => {
            throw new Error('Railway GraphQL error: Not Authorized');
        },
        projectId: 'p1',
        serviceNames,
    });

    assert.equal(result.ok, false);
    assert.match(result.message, /Not Authorized/);
});

test('railway check reports services missing from the project', async () => {
    const result = await checkRailwayCredentials({
        graphql: async () => projectWith(['web']),
        projectId: 'p1',
        serviceNames,
    });

    assert.equal(result.ok, false);
    assert.match(result.message, /queue, scheduler/);
});

test('smoke check fails when the token is empty and makes no request', async () => {
    let called = false;
    const result = await checkSmokeCredentials({
        token: '',
        fetchImpl: async () => {
            called = true;

            return new Response('');
        },
    });

    assert.equal(result.ok, false);
    assert.equal(called, false);
});

test('smoke check sends only initialize and passes on 2xx', async () => {
    /** @type {string[]} */
    const methods = [];
    const result = await checkSmokeCredentials({
        token: 'smoke-token',
        fetchImpl: async (_url, init) => {
            methods.push(JSON.parse(String(init?.body)).method);
            assert.equal(
                /** @type {Record<string, string>} */ (init?.headers).Authorization,
                'Bearer smoke-token',
            );

            return new Response('{}', { status: 200 });
        },
    });

    assert.equal(result.ok, true);
    assert.deepEqual(methods, ['initialize']);
});

test('smoke check fails on 401 and never echoes the token', async () => {
    const result = await checkSmokeCredentials({
        token: 'smoke-token',
        fetchImpl: async () => new Response('no', { status: 401 }),
    });

    assert.equal(result.ok, false);
    assert.match(result.message, /HTTP 401/);
    assert.doesNotMatch(result.message, /smoke-token/);
});

test('smoke check reports an unreachable endpoint', async () => {
    const result = await checkSmokeCredentials({
        token: 'smoke-token',
        fetchImpl: async () => {
            throw new Error('connect ECONNREFUSED');
        },
    });

    assert.equal(result.ok, false);
    assert.match(result.message, /unreachable/);
});
