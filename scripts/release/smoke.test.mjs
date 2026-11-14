import assert from 'node:assert/strict';
import { test } from 'node:test';

import { runProductionSmoke } from './smoke.mjs';

test('smoke accepts health 200 and matching tools/list from code catalog', async () => {
    const expected = ['deploy_artifact', 'search_artifacts'];
    /** @type {Array<{ url: string, init?: RequestInit }>} */
    const calls = [];

    const result = await runProductionSmoke({
        token: 'test-token',
        expectedTools: expected,
        fetchImpl: async (url, init) => {
            calls.push({ url: String(url), init });

            if (String(url).endsWith('/up')) {
                return new Response('ok', { status: 200 });
            }

            const body = JSON.parse(String(init?.body ?? '{}'));

            if (body.method === 'initialize') {
                return new Response(
                    JSON.stringify({
                        jsonrpc: '2.0',
                        id: 'initialize',
                        result: { protocolVersion: '2025-03-26' },
                    }),
                    {
                        status: 200,
                        headers: {
                            'content-type': 'application/json',
                            'mcp-session-id': 'sess-1',
                        },
                    },
                );
            }

            return new Response(
                JSON.stringify({
                    jsonrpc: '2.0',
                    id: 'tools/list',
                    result: {
                        tools: expected.map((name) => ({ name })),
                    },
                }),
                {
                    status: 200,
                    headers: { 'content-type': 'application/json' },
                },
            );
        },
    });

    assert.equal(result.ok, true);
    assert.equal(result.toolCount, 2);
    const authHeaders = calls
        .map((call) => {
            const headers = call.init?.headers;

            if (!headers || typeof headers !== 'object') {
                return '';
            }

            return String(
                /** @type {Record<string, string>} */ (headers).Authorization ??
                    '',
            );
        })
        .filter(Boolean);
    assert.ok(authHeaders.some((value) => value === 'Bearer test-token'));
});

/**
 * @param {string[]} listedTools
 * @returns {typeof fetch}
 */
function fakeMcp(listedTools) {
    return async (url, init) => {
        if (String(url).endsWith('/up')) {
            return new Response('ok', { status: 200 });
        }

        const body = JSON.parse(String(init?.body ?? '{}'));
        const result =
            body.method === 'initialize'
                ? { protocolVersion: '2025-03-26' }
                : { tools: listedTools.map((name) => ({ name })) };

        return new Response(JSON.stringify({ jsonrpc: '2.0', id: body.method, result }), {
            status: 200,
            headers: {
                'content-type': 'application/json',
                'mcp-session-id': 'sess-1',
            },
        });
    };
}

test('smoke accepts feature-flagged tools when the smoke team has them', async () => {
    const result = await runProductionSmoke({
        token: 'test-token',
        expectedTools: ['deploy_artifact'],
        flaggedTools: ['db_get', 'db_set'],
        fetchImpl: fakeMcp(['deploy_artifact', 'db_get', 'db_set']),
    });

    assert.equal(result.ok, true);
});

test('smoke accepts a team without the feature-flagged tools', async () => {
    const result = await runProductionSmoke({
        token: 'test-token',
        expectedTools: ['deploy_artifact'],
        flaggedTools: ['db_get'],
        fetchImpl: fakeMcp(['deploy_artifact']),
    });

    assert.equal(result.ok, true);
});

test('smoke still rejects an unknown tool and a missing base tool', async () => {
    const unknown = await runProductionSmoke({
        token: 'test-token',
        expectedTools: ['deploy_artifact'],
        flaggedTools: ['db_get'],
        fetchImpl: fakeMcp(['deploy_artifact', 'db_get', 'surprise_tool']),
    });

    assert.equal(unknown.ok, false);
    assert.equal(unknown.code, 'tools_mismatch');
    assert.deepEqual(unknown.unexpected, ['surprise_tool']);

    const missing = await runProductionSmoke({
        token: 'test-token',
        expectedTools: ['deploy_artifact', 'search_artifacts'],
        flaggedTools: ['db_get'],
        fetchImpl: fakeMcp(['deploy_artifact', 'db_get']),
    });

    assert.equal(missing.ok, false);
    assert.deepEqual(missing.missing, ['search_artifacts']);
});

test('smoke rejects health non-200 before calling MCP', async () => {
    let mcpCalls = 0;
    const result = await runProductionSmoke({
        token: 'test-token',
        expectedTools: ['deploy_artifact'],
        fetchImpl: async (url) => {
            if (String(url).endsWith('/up')) {
                return new Response('nope', { status: 503 });
            }

            mcpCalls += 1;

            return new Response('{}', { status: 200 });
        },
    });

    assert.equal(result.ok, false);
    assert.equal(result.code, 'health_failed');
    assert.equal(mcpCalls, 0);
});
