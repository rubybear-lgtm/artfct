import { spawn } from 'node:child_process';
import { createHash, randomBytes } from 'node:crypto';
import { createServer } from 'node:http';

/**
 * Credentials come from OAuth (PKCE, browser consent per organization) unless
 * both MCP_LIVE_TOKEN_A and MCP_LIVE_TOKEN_B are set, which keeps
 * non-interactive CI runs working. OAuth tokens live in memory only.
 */
const baseUrl = requiredEnv('MCP_LIVE_BASE_URL').replace(/\/$/, '');

// Check the advertised origin before spending any OAuth work on it. A drifted
// APP_URL/OAUTH_ISSUER still serves this metadata with a 200 and still issues
// tokens, so nothing downstream would notice: clients would simply resolve
// every endpoint on the wrong host.
await assertDiscoveryMatchesBaseUrl();
const expectedOrgA = requiredEnv('MCP_LIVE_EXPECTED_ORG_A');
const expectedOrgB = requiredEnv('MCP_LIVE_EXPECTED_ORG_B');
const usingEnvTokens = Boolean(
    process.env.MCP_LIVE_TOKEN_A?.trim() &&
    process.env.MCP_LIVE_TOKEN_B?.trim(),
);
// MCP_LIVE_DEV_LOGIN_EMAIL drives the same OAuth consent endpoint over plain
// HTTP instead of a browser, using the `authkit/dev-login` stand-in that
// scripts/mcp-e2e-stack.sh enables locally (AuthKitDevLoginController; never
// available in production). Faster and non-flaky compared to a headless
// browser, and exercises the real consent decision, not a shortcut around
// it — only *how the session is established* differs from oauthLogin.
const devLoginEmail = process.env.MCP_LIVE_DEV_LOGIN_EMAIL?.trim();
const login = usingEnvTokens
    ? null
    : devLoginEmail
      ? (organization) => devLoginConsent(devLoginEmail, organization)
      : oauthLogin;
const tokenA = usingEnvTokens
    ? { access: requiredEnv('MCP_LIVE_TOKEN_A') }
    : await login(expectedOrgA);
const tokenB = usingEnvTokens
    ? { access: requiredEnv('MCP_LIVE_TOKEN_B') }
    : await login(expectedOrgB);
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

await assertTenantIsolation(tokenA, sessionA, tokenB, sessionB, privateArtifactA);

await assertConcurrentSessionsKeepTheirTenant(concurrency);

const rateLimitCheck = optionalBoolean('MCP_LIVE_RATE_LIMIT_CHECK', true);
const rateLimitResult = rateLimitCheck
    ? await assertRateLimiting(tokenB, sessionB)
    : 'skipped';

console.log(
    JSON.stringify({
        status: 'passed',
        protocolVersion: sessionA.protocolVersion,
        tools: [...toolNames].sort(),
        tenantIsolation: 'passed',
        concurrentSessions: concurrency * 2,
        rateLimit: rateLimitResult,
    }),
);

/**
 * Proves the tenancy property the verification matrix names as the headline DoD
 * clause: "two organizations cannot see or mutate each other's artifacts". Both
 * halves are asserted -- the owner can read its artifact and a second
 * organization cannot, and the second organization's attempt to put the
 * other's artifact into a collection of its own is refused with no state change.
 * A blanket denial of every read would satisfy the read half alone, which is why
 * the owner's successful read is asserted too.
 */
async function assertTenantIsolation(
    ownerToken,
    ownerSession,
    foreignToken,
    foreignSession,
    artifactId,
) {
    const ownerRead = await rpc(ownerToken, ownerSession, 'tools/call', {
        name: 'get_artifact',
        arguments: { id: artifactId },
    });
    assert(
        ownerRead.result?.structuredContent?.id === artifactId,
        'Organization A could not read its configured private artifact fixture: ' +
            describeResult(ownerRead),
    );

    const foreignRead = await rpc(foreignToken, foreignSession, 'tools/call', {
        name: 'get_artifact',
        arguments: { id: artifactId },
    });
    assert(
        foreignRead.result?.isError === true,
        'Organization B could read organization A artifact metadata',
    );

    // The DoD says "cannot see or mutate", so the read denial is only half of it:
    // the foreign organization also attempts a state-changing call that names the
    // other organization's artifact. Its credential is valid for its own tenant,
    // so ownership is the only thing that can refuse this. The collection is
    // asserted rather than branched on, so a broken setup fails loudly instead of
    // silently skipping the mutation check.
    const foreignCollection = await rpc(foreignToken, foreignSession, 'tools/call', {
        name: 'create_collection',
        arguments: { name: 'cross-tenant-probe' },
    });
    const foreignCollectionId = foreignCollection.result?.structuredContent?.id;
    assert(
        typeof foreignCollectionId === 'number',
        'Organization B could not create its own collection to attempt the mutation with: ' +
            describeResult(foreignCollection),
    );

    const foreignMutation = await rpc(foreignToken, foreignSession, 'tools/call', {
        name: 'add_collection_artifact',
        arguments: { collection_id: foreignCollectionId, artifact_id: artifactId },
    });
    assert(
        foreignMutation.result?.isError === true,
        'Organization B could add organization A artifact to its own collection: ' +
            describeResult(foreignMutation),
    );

    // Control for the denial above: the same call shape, with the owner's token
    // and the owner's collection, must succeed. Without this the previous
    // assertion would still pass if the call were broken for an unrelated
    // reason, so this is what makes "refused" mean "refused on ownership".
    const ownerCollection = await rpc(ownerToken, ownerSession, 'tools/call', {
        name: 'create_collection',
        arguments: { name: 'own-artifact-probe' },
    });
    const ownerCollectionId = ownerCollection.result?.structuredContent?.id;
    assert(
        typeof ownerCollectionId === 'number',
        'Organization A could not create its own collection: ' + describeResult(ownerCollection),
    );

    const ownerMutation = await rpc(ownerToken, ownerSession, 'tools/call', {
        name: 'add_collection_artifact',
        arguments: { collection_id: ownerCollectionId, artifact_id: artifactId },
    });
    assert(
        ownerMutation.result?.isError !== true,
        'Organization A could not add its own artifact to its own collection: ' +
            describeResult(ownerMutation),
    );
}

/**
 * Opens a bounded set of concurrent remote sessions across both organizations
 * and asserts every one of them kept its own tenant context. This is the
 * concurrency half of the load check the verification matrix names.
 */
async function assertConcurrentSessionsKeepTheirTenant(concurrency) {
    const sessions = await Promise.all([
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

    const connections = await Promise.all(
        sessions.map(({ session, token }) =>
            rpc(token, session, 'tools/call', {
                name: 'get_connection',
                arguments: {},
            }),
        ),
    );

    connections.forEach((connection, index) => {
        const expectedOrganization = sessions[index].expectedOrganization;
        assert(
            connection.result?.structuredContent?.organization === expectedOrganization,
            `Concurrent session ${index + 1} resolved to the wrong organization`,
        );
    });
}

/**
 * Sends a bounded burst of raw JSON-RPC calls on a single connection until
 * the server returns a rate-limited (429) response, then asserts the
 * JSON-RPC error envelope and `Retry-After` header the runbook documents.
 * Runs on organization B's connection so it never contends with organization
 * A's fixture reads used earlier in the suite.
 */
async function assertRateLimiting(token, session) {
    const maxAttempts = optionalInteger(
        'MCP_LIVE_RATE_LIMIT_BURST',
        160,
        20,
        400,
    );
    const batchSize = 20;
    const headers = {
        Accept: 'application/json, text/event-stream',
        Authorization: `Bearer ${token.access}`,
        'Content-Type': 'application/json',
        'MCP-Session-Id': session.id,
    };
    const call = () =>
        fetch(`${baseUrl}/mcp`, {
            method: 'POST',
            headers,
            body: JSON.stringify({
                jsonrpc: '2.0',
                id: crypto.randomUUID(),
                method: 'tools/call',
                params: { name: 'get_connection', arguments: {} },
            }),
        });

    let attempts = 0;

    while (attempts < maxAttempts) {
        const batch = await Promise.all(
            Array.from({ length: batchSize }, call),
        );
        attempts += batchSize;

        const limited = batch.find((response) => response.status === 429);

        if (limited) {
            const retryAfter = limited.headers.get('Retry-After');
            assert(
                retryAfter !== null && Number(retryAfter) > 0,
                'Rate-limited response omitted a positive Retry-After header',
            );

            const payload = await limited.json();
            assert(
                payload.error?.code === -32029,
                'Rate-limited JSON-RPC response used an unexpected error code',
            );
            assert(
                payload.error?.data?.artfct?.errorCode === 'rate_limit' &&
                    payload.error?.data?.artfct?.retryable === true &&
                    typeof payload.error?.data?.artfct?.retryAfterSeconds ===
                        'number',
                'Rate-limited response omitted the stable artfct error envelope',
            );

            return {
                tripped: true,
                attempts,
                retryAfterSeconds: Number(retryAfter),
            };
        }

        for (const response of batch) {
            if (!response.ok) {
                throw new Error(
                    `Unexpected HTTP ${response.status} while probing the MCP rate limit`,
                );
            }
        }
    }

    throw new Error(
        `MCP rate limit was not enforced after ${attempts} requests on one connection`,
    );
}

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

function optionalBoolean(name, fallback) {
    const value = process.env[name]?.trim().toLowerCase();

    if (!value) {
        return fallback;
    }

    assert(
        value === 'true' || value === 'false',
        `${name} must be "true" or "false"`,
    );

    return value === 'true';
}

function assert(condition, message) {
    if (!condition) {
        throw new Error(message);
    }
}

/**
 * The authorization server and the protected resource must both name the host
 * clients came in on. A mismatch means the deployment's environment has drifted
 * from the domain it is actually served on.
 */
async function assertDiscoveryMatchesBaseUrl() {
    const authorizationServer = await (
        await fetch(`${baseUrl}/.well-known/oauth-authorization-server`)
    ).json();
    assert(
        authorizationServer.issuer === baseUrl,
        `Authorization server issuer ${authorizationServer.issuer} is not ${baseUrl}`,
    );

    const protectedResource = await (
        await fetch(`${baseUrl}/.well-known/oauth-protected-resource`)
    ).json();
    assert(
        protectedResource.resource === `${baseUrl}/mcp`,
        `Protected resource ${protectedResource.resource} is not ${baseUrl}/mcp`,
    );
    assert(
        protectedResource.authorization_servers?.includes(baseUrl),
        `Protected resource does not list ${baseUrl} as an authorization server`,
    );
}

/**
 * Same outcome as oauthLogin (an access+refresh token pair scoped to
 * `organization`), but establishes the session via the dev-login stand-in
 * and drives OAuth consent as a direct POST instead of opening a browser and
 * waiting for a loopback redirect. Requires AUTHKIT_DEV_LOGIN_ENABLED=true
 * on the target — true for scripts/mcp-e2e-stack.sh, never true in
 * production (AuthKitDevLoginController is not even registered there).
 */
async function devLoginConsent(email, organization) {
    const jar = new Map();
    const captureCookies = (response) => {
        for (const raw of response.headers.getSetCookie?.() ?? []) {
            const pair = raw.split(';', 1)[0];
            const separator = pair.indexOf('=');

            if (separator > 0) {
                jar.set(pair.slice(0, separator), pair.slice(separator + 1));
            }
        }
    };
    const cookieHeader = () =>
        [...jar].map(([name, value]) => `${name}=${value}`).join('; ');
    const xsrfToken = () => decodeURIComponent(jar.get('XSRF-TOKEN') ?? '');

    // GET any guest page to establish a session + XSRF-TOKEN cookie before
    // the first CSRF-protected POST.
    captureCookies(await fetch(`${baseUrl}/login`, { redirect: 'manual' }));

    const devLogin = await fetch(`${baseUrl}/authkit/dev-login`, {
        method: 'POST',
        redirect: 'manual',
        headers: {
            'Content-Type': 'application/json',
            Accept: 'application/json',
            Cookie: cookieHeader(),
            'X-XSRF-TOKEN': xsrfToken(),
        },
        body: JSON.stringify({ email, provider: 'MagicAuth' }),
    });
    captureCookies(devLogin);
    assert(
        devLogin.status === 302,
        `Dev login did not redirect (HTTP ${devLogin.status}); is AUTHKIT_DEV_LOGIN_ENABLED=true on ${baseUrl}?`,
    );

    const authenticate = await fetch(devLogin.headers.get('Location'), {
        redirect: 'manual',
        headers: { Cookie: cookieHeader() },
    });
    captureCookies(authenticate);
    assert(
        authenticate.status === 302,
        `/authenticate did not redirect (HTTP ${authenticate.status})`,
    );

    const verifier = randomBytes(48).toString('base64url');
    const challenge = createHash('sha256').update(verifier).digest('base64url');
    const state = randomBytes(16).toString('hex');
    const redirectUri = 'http://127.0.0.1:0/callback';
    const parameters = {
        response_type: 'code',
        client_id: 'artfct-cli',
        redirect_uri: redirectUri,
        scope: 'artifacts:read artifacts:deploy collections:read collections:write usage:read',
        state,
        code_challenge: challenge,
        code_challenge_method: 'S256',
        team: organization,
    };

    // The consent form is HMAC-bound to these exact parameters
    // (client/redirect/scope/state/PKCE) to stop tampering — the server
    // computes it from the app key and hands it back embedded in the
    // rendered page, never derivable by the client. A plain GET (no
    // X-Inertia header, which would instead trigger a 409 "asset version
    // stale" reload response) renders the full page with it embedded in
    // `<script data-page="app" type="application/json">`.
    const authorizeUrl = new URL(`${baseUrl}/oauth/authorize`);
    authorizeUrl.search = new URLSearchParams(parameters).toString();
    const authorizePage = await fetch(authorizeUrl, {
        headers: { Cookie: cookieHeader() },
    });
    captureCookies(authorizePage);
    const html = await authorizePage.text();
    const pageDataMatch = html.match(
        /<script data-page="app" type="application\/json">(.+?)<\/script>/s,
    );
    assert(
        authorizePage.status === 200 && pageDataMatch,
        `OAuth consent page did not render (HTTP ${authorizePage.status})`,
    );
    const consentToken = JSON.parse(pageDataMatch[1]).props?.consentToken;
    assert(
        typeof consentToken === 'string' && consentToken !== '',
        'OAuth consent page omitted its consent_token',
    );

    const consent = await fetch(`${baseUrl}/oauth/authorize`, {
        method: 'POST',
        redirect: 'manual',
        headers: {
            'Content-Type': 'application/json',
            Accept: 'application/json',
            'X-Inertia': 'true',
            Cookie: cookieHeader(),
            'X-XSRF-TOKEN': xsrfToken(),
        },
        body: JSON.stringify({
            ...parameters,
            decision: 'approve',
            consent_token: consentToken,
        }),
    });
    const location = consent.headers.get('X-Inertia-Location');
    assert(
        consent.status === 409 && location,
        `OAuth consent did not return a client redirect (HTTP ${consent.status})`,
    );

    const redirected = new URL(location);
    assert(
        !redirected.searchParams.get('error'),
        `Consent denied: ${redirected.searchParams.get('error')}`,
    );
    const code = redirected.searchParams.get('code');
    assert(code, 'Consent redirect omitted an authorization code');

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
        scope: 'artifacts:read artifacts:deploy collections:read collections:write usage:read',
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
