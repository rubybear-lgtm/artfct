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
