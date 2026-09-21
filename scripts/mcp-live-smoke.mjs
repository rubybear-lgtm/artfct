import { spawn } from 'node:child_process';
import { createHash, randomBytes } from 'node:crypto';
import { createServer } from 'node:http';

/**
 * Credentials come from OAuth (PKCE, browser consent per organization) unless
 * both MCP_LIVE_TOKEN_A and MCP_LIVE_TOKEN_B are set, which keeps
 * non-interactive CI runs working. OAuth tokens live in memory only.
 */
const baseUrl = requiredEnv('MCP_LIVE_BASE_URL').replace(/\/$/, '');
const expectedOrgA = requiredEnv('MCP_LIVE_EXPECTED_ORG_A');
const expectedOrgB = requiredEnv('MCP_LIVE_EXPECTED_ORG_B');
const usingEnvTokens = Boolean(
    process.env.MCP_LIVE_TOKEN_A?.trim() &&
    process.env.MCP_LIVE_TOKEN_B?.trim(),
);
const tokenA = usingEnvTokens
    ? { access: requiredEnv('MCP_LIVE_TOKEN_A') }
    : await oauthLogin(expectedOrgA);
const tokenB = usingEnvTokens
    ? { access: requiredEnv('MCP_LIVE_TOKEN_B') }
    : await oauthLogin(expectedOrgB);
const concurrency = optionalInteger('MCP_LIVE_CONCURRENCY', 8, 2, 32);

const supportedTools = new Set([
    'deploy_artifact',
    'deploy_to_canvas',
    'search_artifacts',
    'get_connection',
    'get_usage',
    'get_artifact',
    'list_collections',
    'create_collection',
    'add_collection_artifact',
]);

const client = {
    name: 'artfct-live-smoke',
    version: '1.0.0',
};

const sessionA = await initialize(tokenA, expectedOrgA);
const sessionB = await initialize(tokenB, expectedOrgB);

const privateArtifactA =
    process.env.MCP_LIVE_PRIVATE_ARTIFACT_A?.trim() ||
    (await deployPrivateFixture(tokenA, sessionA));

const tools = await rpc(tokenA, sessionA, 'tools/list', {});
const toolNames = new Set((tools.result?.tools ?? []).map((tool) => tool.name));

for (const tool of tools.result?.tools ?? []) {
    const metadata = tool._meta?.artfct;
    assert(
        metadata?.owner === 'artfct-mcp',
        tool.name + ' omitted its owner metadata',
    );
    const legacy = tool.name === 'deploy_to_canvas';
    assert(
        metadata?.compatibility === (legacy ? 'deprecated' : 'stable'),
        tool.name + ' reported unexpected compatibility metadata',
    );
    assert(
        !legacy || metadata?.replacedBy === 'deploy_artifact',
        'deploy_to_canvas must point at deploy_artifact',
    );
    assert(
        Array.isArray(metadata?.requiredScopes),
        tool.name + ' omitted required scope metadata',
    );
    assert(
        Array.isArray(metadata?.examples) && metadata.examples.length > 0,
        tool.name + ' omitted invocation examples',
    );
    assert(
        typeof tool.annotations?.readOnlyHint === 'boolean' &&
            typeof tool.annotations?.idempotentHint === 'boolean' &&
            typeof tool.annotations?.destructiveHint === 'boolean',
        tool.name + ' omitted MCP annotations',
    );
}

for (const name of supportedTools) {
    assert(toolNames.has(name), `Hosted MCP catalog is missing ${name}`);
}

assert(
    toolNames.size === supportedTools.size,
    `Hosted MCP catalog changed unexpectedly; expected ${supportedTools.size} tools, got ${toolNames.size}`,
);
assert(
    typeof sessionA.id === 'string' && sessionA.id !== '',
    'Hosted MCP did not issue an MCP-Session-Id on initialize',
);
assert(
    !tools.sessionId || tools.sessionId === sessionA.id,
    'Hosted MCP replied to tools/list with a different session ID than initialize issued',
);

await assertConnection(tokenA, sessionA, expectedOrgA);
await assertConnection(tokenB, sessionB, expectedOrgB);

const usage = await rpc(tokenA, sessionA, 'tools/call', {
    name: 'get_usage',
    arguments: {},
});
assert(
    usage.result?.structuredContent?.organization === expectedOrgA,
    'Usage resolved to the wrong organization',
);
assert(
    typeof usage.result?.structuredContent?.period?.resets_at === 'string',
    'Usage response omitted the monthly reset timestamp',
);

const collections = await rpc(tokenA, sessionA, 'tools/call', {
    name: 'list_collections',
    arguments: { limit: 20 },
});
assert(
    Array.isArray(collections.result?.structuredContent?.collections),
    'Collection discovery did not return a bounded collection list',
);

const ownerRead = await rpc(tokenA, sessionA, 'tools/call', {
    name: 'get_artifact',
    arguments: { id: privateArtifactA },
});
assert(
    ownerRead.result?.structuredContent?.id === privateArtifactA,
    'Organization A could not read its configured private artifact fixture: ' +
        describeResult(ownerRead),
);

const foreignRead = await rpc(tokenB, sessionB, 'tools/call', {
    name: 'get_artifact',
    arguments: { id: privateArtifactA },
});
assert(
    foreignRead.result?.isError === true,
    'Organization B could read organization A artifact metadata',
);

const concurrentSessions = await Promise.all([
    ...Array.from({ length: concurrency }, async () => ({
        expectedOrganization: expectedOrgA,
        session: await initialize(tokenA, expectedOrgA),
        token: tokenA,
    })),
    ...Array.from({ length: concurrency }, async () => ({
        expectedOrganization: expectedOrgB,
        session: await initialize(tokenB, expectedOrgB),
        token: tokenB,
    })),
]);
const concurrentConnections = await Promise.all(
    concurrentSessions.map(({ session, token }) =>
        rpc(token, session, 'tools/call', {
            name: 'get_connection',
            arguments: {},
        }),
    ),
);

concurrentConnections.forEach((connection, index) => {
    const expectedOrganization = concurrentSessions[index].expectedOrganization;
    assert(
        connection.result?.structuredContent?.organization ===
            expectedOrganization,
        `Concurrent session ${index + 1} resolved to the wrong organization`,
    );
});

console.log(
    JSON.stringify({
        status: 'passed',
        protocolVersion: sessionA.protocolVersion,
        tools: [...toolNames].sort(),
        tenantIsolation: 'passed',
        concurrentSessions: concurrency * 2,
    }),
);

async function deployPrivateFixture(token, session) {
    const deployed = await rpc(token, session, 'tools/call', {
        name: 'deploy_artifact',
        arguments: {
            html: '<!doctype html><title>mcp-live fixture</title><p>isolation fixture</p>',
            tier: 'secure',
        },
    });
    const id = deployed.result?.structuredContent?.id;
    assert(
        typeof id === 'string' && id !== '',
        'Could not deploy the private isolation fixture for organization A',
    );

    return id;
}

async function initialize(token, expectedOrg) {
    const response = await rpc(token, null, 'initialize', {
        protocolVersion: '2025-11-25',
        clientInfo: client,
        capabilities: {},
    });
    const protocolVersion = response.result?.protocolVersion;
    assert(
        ['2025-11-25', '2025-06-18', '2025-03-26', '2024-11-05'].includes(
            protocolVersion,
        ),
        `Unexpected negotiated protocol version for ${expectedOrg}`,
    );

    return {
        id: response.sessionId,
        protocolVersion,
    };
}

async function assertConnection(token, session, expectedOrganization) {
    const response = await rpc(token, session, 'tools/call', {
        name: 'get_connection',
        arguments: {},
    });
    assert(
        response.result?.structuredContent?.organization ===
            expectedOrganization,
        `Connection resolved to the wrong organization; expected ${expectedOrganization}`,
    );
}

async function rpc(token, session, method, params) {
    const headers = {
        Accept: 'application/json, text/event-stream',
        Authorization: `Bearer ${token.access}`,
        'Content-Type': 'application/json',
    };

    if (session?.id) {
        headers['MCP-Session-Id'] = session.id;
    }

    const send = () =>
        fetch(`${baseUrl}/mcp`, {
            method: 'POST',
            headers,
            body: JSON.stringify({
                jsonrpc: '2.0',
                id: crypto.randomUUID(),
                method,
                params,
            }),
        });

    let response = await send();

    if (response.status === 401 && token.refresh) {
        await refresh(token);
        headers.Authorization = `Bearer ${token.access}`;
        response = await send();
    }

    if (!response.ok) {
        throw new Error(`${method} failed with HTTP ${response.status}`);
    }

    const payload = await response.json();

    if (payload.error) {
        throw new Error(
            `${method} returned ${payload.error.code ?? 'an MCP error'}`,
        );
    }

    return {
        ...payload,
        sessionId: response.headers.get('MCP-Session-Id'),
    };
}

function requiredEnv(name) {
    const value = process.env[name]?.trim();

    if (!value) {
        throw new Error(`Missing required environment variable ${name}`);
    }

    return value;
}

function optionalInteger(name, fallback, minimum, maximum) {
    const value = process.env[name]?.trim();

    if (!value) {
        return fallback;
    }

    const parsed = Number(value);
    assert(
        Number.isInteger(parsed) && parsed >= minimum && parsed <= maximum,
        `${name} must be an integer between ${minimum} and ${maximum}`,
    );

    return parsed;
}

function assert(condition, message) {
    if (!condition) {
        throw new Error(message);
    }
}

async function oauthLogin(organization) {
    const verifier = randomBytes(48).toString('base64url');
    const challenge = createHash('sha256').update(verifier).digest('base64url');
    const state = randomBytes(16).toString('hex');
    const redirectUri = await new Promise((resolve, reject) => {
        const server = createServer();
        server.once('error', reject);
        server.listen(0, '127.0.0.1', () => resolve(server));
    }).then((server) => {
        oauthLogin.server = server;

        return `http://127.0.0.1:${server.address().port}/callback`;
    });

    const authorizeUrl = new URL(`${baseUrl}/oauth/authorize`);
    authorizeUrl.search = new URLSearchParams({
        response_type: 'code',
        client_id: 'artfct-cli',
        redirect_uri: redirectUri,
        scope: 'artifacts:read artifacts:deploy collections:read usage:read',
        state,
        code_challenge: challenge,
        code_challenge_method: 'S256',
        team: organization,
    }).toString();

    console.error(
        `Approve access for organization "${organization}" in your browser:\n${authorizeUrl}`,
    );
    openBrowser(authorizeUrl.toString());

    const code = await new Promise((resolve, reject) => {
        const timeout = setTimeout(
            () =>
                reject(
                    new Error(
                        `Timed out waiting for consent (${organization})`,
                    ),
                ),
            300_000,
        );
        oauthLogin.server.on('request', (request, response) => {
            const url = new URL(request.url, redirectUri);

            if (url.pathname !== '/callback') {
                response.writeHead(404).end();

                return;
            }

            clearTimeout(timeout);
            response.writeHead(200, { 'Content-Type': 'text/plain' });
            response.end('Artfct live smoke: you can close this tab.');

            if (url.searchParams.get('state') !== state) {
                reject(new Error('OAuth state mismatch'));
            } else if (url.searchParams.get('error')) {
                reject(
                    new Error(
                        `Consent denied: ${url.searchParams.get('error')}`,
                    ),
                );
            } else {
                resolve(url.searchParams.get('code'));
            }
        });
    }).finally(() => oauthLogin.server.close());

    const token = await tokenRequest({
        grant_type: 'authorization_code',
        code,
        client_id: 'artfct-cli',
        redirect_uri: redirectUri,
        code_verifier: verifier,
    });
    assert(
        token.organization === organization,
        `Consent resolved to "${token.organization}" instead of "${organization}"`,
    );

    return { access: token.access_token, refresh: token.refresh_token };
}

async function refresh(token) {
    const renewed = await tokenRequest({
        grant_type: 'refresh_token',
        refresh_token: token.refresh,
        client_id: 'artfct-cli',
    });
    token.access = renewed.access_token;
    token.refresh = renewed.refresh_token;
}

async function tokenRequest(body) {
    const response = await fetch(`${baseUrl}/oauth/token`, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            Accept: 'application/json',
        },
        body: JSON.stringify(body),
    });

    if (!response.ok) {
        throw new Error(
            `OAuth token request failed with HTTP ${response.status}`,
        );
    }

    return response.json();
}

function openBrowser(url) {
    const command =
        process.platform === 'darwin'
            ? 'open'
            : process.platform === 'win32'
              ? 'explorer'
              : 'xdg-open';

    try {
        spawn(command, [url], { stdio: 'ignore', detached: true })
            .on('error', () => {})
            .unref();
    } catch {
        // The URL is already printed; the user can open it by hand.
    }
}

function describeResult(response) {
    const result = response.result ?? {};
    const code = result.content?.[0]?._meta?.artfct?.errorCode;
    const text = String(result.content?.[0]?.text ?? '').slice(0, 200);

    return JSON.stringify({
        isError: result.isError ?? false,
        errorCode: code ?? null,
        message: text,
        returnedId: result.structuredContent?.id ?? null,
        expectedId: privateArtifactA,
    });
}
