/**
 * Production smoke checks after a Railway deploy (RUB-517).
 */

import { readExpectedMcpToolNames } from './expected-tools.mjs';

/**
 * @param {{
 *   healthUrl?: string,
 *   mcpUrl?: string,
 *   token: string,
 *   fetchImpl?: typeof fetch,
 *   expectedTools?: string[],
 * }} options
 */
export async function runProductionSmoke(options) {
    const fetchImpl = options.fetchImpl ?? fetch;
    const healthUrl = options.healthUrl ?? 'https://artfct.dev/up';
    const mcpUrl = options.mcpUrl ?? 'https://artfct.dev/mcp';
    const expectedTools = options.expectedTools ?? readExpectedMcpToolNames();

    if (!options.token) {
        return {
            ok: false,
            code: 'missing_token',
            message: 'ARTFCT_SMOKE_BOT_TOKEN is required for the MCP smoke check.',
        };
    }

    const health = await fetchImpl(healthUrl, { method: 'GET', redirect: 'manual' });

    if (health.status !== 200) {
        return {
            ok: false,
            code: 'health_failed',
            message: `Health check ${healthUrl} returned HTTP ${health.status}.`,
            healthStatus: health.status,
        };
    }

    const initialize = await mcpRpc(fetchImpl, mcpUrl, options.token, null, 'initialize', {
        protocolVersion: '2025-03-26',
        capabilities: {},
        clientInfo: { name: 'artfct-release-smoke', version: '1.0.0' },
    });

    if (!initialize.ok) {
        return initialize;
    }

    const sessionId = initialize.sessionId;
    const toolsList = await mcpRpc(
        fetchImpl,
        mcpUrl,
        options.token,
        sessionId,
        'tools/list',
        {},
    );

    if (!toolsList.ok) {
        return toolsList;
    }

    const actual = new Set(
        (toolsList.result?.tools ?? []).map((tool) => tool.name).filter(Boolean),
    );
    const missing = expectedTools.filter((name) => !actual.has(name));
    const unexpected = [...actual].filter((name) => !expectedTools.includes(name));

    if (missing.length > 0 || unexpected.length > 0) {
        return {
            ok: false,
            code: 'tools_mismatch',
            message: `MCP tools/list mismatch. missing=[${missing.join(', ')}] unexpected=[${unexpected.join(', ')}]`,
            expectedCount: expectedTools.length,
            actualCount: actual.size,
            missing,
            unexpected,
        };
    }

    return {
        ok: true,
        healthStatus: 200,
        toolCount: actual.size,
        tools: [...actual].sort(),
    };
}

/**
 * @param {typeof fetch} fetchImpl
 * @param {string} mcpUrl
 * @param {string} token
 * @param {string | null} sessionId
 * @param {string} method
 * @param {Record<string, unknown>} params
 */
async function mcpRpc(fetchImpl, mcpUrl, token, sessionId, method, params) {
    /** @type {Record<string, string>} */
    const headers = {
        Authorization: `Bearer ${token}`,
        Accept: 'application/json, text/event-stream',
        'Content-Type': 'application/json',
        'MCP-Protocol-Version': '2025-03-26',
    };

    if (sessionId) {
        headers['Mcp-Session-Id'] = sessionId;
    }

    const response = await fetchImpl(mcpUrl, {
        method: 'POST',
        headers,
        body: JSON.stringify({
            jsonrpc: '2.0',
            id: method,
            method,
            params,
        }),
    });

    if (response.status < 200 || response.status >= 300) {
        return {
            ok: false,
            code: 'mcp_http_error',
            message: `MCP ${method} returned HTTP ${response.status}.`,
        };
    }

    const nextSession =
        response.headers.get('mcp-session-id') ??
        response.headers.get('Mcp-Session-Id') ??
        sessionId;

    const payload = await readJsonRpc(response);

    if (payload.error) {
        return {
            ok: false,
            code: 'mcp_rpc_error',
            message: `MCP ${method} error: ${payload.error.message ?? JSON.stringify(payload.error)}`,
        };
    }

    return {
        ok: true,
        result: payload.result,
        sessionId: nextSession,
    };
}

/**
 * @param {Response} response
 */
async function readJsonRpc(response) {
    const contentType = response.headers.get('content-type') ?? '';
    const text = await response.text();

    if (contentType.includes('text/event-stream')) {
        const dataLines = text
            .split('\n')
            .filter((line) => line.startsWith('data:'))
            .map((line) => line.slice(5).trim())
            .filter(Boolean);

        for (const line of dataLines.reverse()) {
            try {
                return JSON.parse(line);
            } catch {
                // keep scanning
            }
        }

        throw new Error('No JSON-RPC payload in SSE response');
    }

    return JSON.parse(text);
}
