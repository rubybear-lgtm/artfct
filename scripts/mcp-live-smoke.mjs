const baseUrl = requiredEnv('MCP_LIVE_BASE_URL').replace(/\/$/, '');
const tokenA = requiredEnv('MCP_LIVE_TOKEN_A');
const tokenB = requiredEnv('MCP_LIVE_TOKEN_B');
const expectedOrgA = requiredEnv('MCP_LIVE_EXPECTED_ORG_A');
const expectedOrgB = requiredEnv('MCP_LIVE_EXPECTED_ORG_B');
const privateArtifactA = requiredEnv('MCP_LIVE_PRIVATE_ARTIFACT_A');
const concurrency = optionalInteger('MCP_LIVE_CONCURRENCY', 8, 2, 32);

const supportedTools = new Set([
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

const tools = await rpc(tokenA, sessionA, 'tools/list', {});
const toolNames = new Set((tools.result?.tools ?? []).map((tool) => tool.name));

for (const tool of tools.result?.tools ?? []) {
    const metadata = tool._meta?.artfct;
    assert(
        metadata?.owner === 'artfct-mcp',
        tool.name + ' omitted its owner metadata',
    );
    assert(
        metadata?.compatibility === 'stable',
        tool.name + ' omitted stable compatibility metadata',
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
    sessionA.id && tools.sessionId === sessionA.id,
    'Hosted MCP did not preserve the initialize session for tools/list',
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
    'Organization A could not read its configured private artifact fixture',
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
        Authorization: `Bearer ${token}`,
        'Content-Type': 'application/json',
    };

    if (session?.id) {
        headers['MCP-Session-Id'] = session.id;
    }

    const response = await fetch(`${baseUrl}/mcp`, {
        method: 'POST',
        headers,
        body: JSON.stringify({
            jsonrpc: '2.0',
            id: crypto.randomUUID(),
            method,
            params,
        }),
    });

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
