// Phase 2: the signed-in web surface. A user who is not a member of a team must
// get nothing from any of that team's settings, console or API routes, and a
// member of one team must get nothing by pairing their own team's URL with
// another team's ids (IDOR).
import {
    Session,
    check,
    deploy,
    expectStatus,
    indexingEnabled,
    run,
    tinker,
} from './lib.mjs';

const DENIED = [403, 404];

export async function webRoutes(world) {
    const { alice, bob, slugB } = world;
    const slugV = `iso-victim-${run}`;

    // A dedicated victim team, so the destructive routes below can never damage
    // the teams the other scenarios depend on if a guard were ever missing.
    expectStatus(
        'alice creates a victim team',
        await alice.createTeam('Victim', slugV),
        [302, 303],
    );
    const v = await alice.accessTokenFor(slugV);
    check(
        'alice gets a token for the victim team',
        Boolean(v.token),
        String(v.refused),
    );
    const idV = await deploy(v.token, `<h1>victim secret ${run}</h1>`);

    expectStatus(
        'alice creates a collection in the victim team',
        await alice.request(`/settings/teams/${slugV}/collections`, {
            method: 'POST',
            json: { name: `victim collection ${run}` },
        }),
        [302, 303],
    );

    const ids = JSON.parse(
        tinker(`
        $team = App\\Models\\Team::where('slug', '${slugV}')->firstOrFail();
        $alice = App\\Models\\User::where('email', 'alice-${run}@northwind.example')->firstOrFail();
        echo json_encode([
            'alice' => $alice->id,
            'collection' => App\\Models\\Collection::where('team_id', $team->id)->value('id'),
            'connection' => App\\Models\\McpConnection::where('team_id', $team->id)->value('public_id'),
            'token' => App\\Models\\OrgToken::factory()->create(['team_id' => $team->id, 'user_id' => $alice->id])->id,
        ]);
    `),
    );
    check(
        'set-up produced a collection and an MCP connection to attack',
        Boolean(ids.collection && ids.connection),
        JSON.stringify(ids),
    );

    const V = `/settings/teams/${slugV}`;
    const B = `/settings/teams/${slugB}`;

    // 1. A non-member against the victim team's own routes.
    const nonMember = [
        ['GET', ''],
        ['GET', '/audit'],
        ['GET', '/audit/export'],
        ['GET', '/authentication'],
        ['GET', '/billing'],
        ['GET', '/collections'],
        ['GET', '/console'],
        ['GET', '/console/export'],
        ['GET', '/governance'],
        ['GET', '/mcp-connections'],
        ['GET', '/search'],
        ['GET', '/tokens'],
        ['GET', `/console/artifacts/${idV}/open`],
        ['POST', `/console/artifacts/${idV}/reindex`, {}],
        ['PATCH', `/console/artifacts/${idV}/revoke`, {}],
        ['POST', '/collections', { name: 'x' }],
        ['PATCH', `/collections/${ids.collection}`, { name: 'x' }],
        [
            'POST',
            `/collections/${ids.collection}/artifacts`,
            { artifact_id: idV },
        ],
        ['DELETE', `/collections/${ids.collection}/artifacts/${idV}`],
        ['POST', `/collections/${ids.collection}/pin`, {}],
        ['DELETE', `/collections/${ids.collection}/pin`],
        ['POST', '/domains', { domain: 'example.com' }],
        ['POST', '/invitations', { email: 'x@example.com', role: 'admin' }],
        ['POST', '/governance/holds', { artifact_id: idV }],
        ['POST', '/governance/preview', {}],
        ['PATCH', '/retention', {}],
        ['PATCH', '/auth-mode', {}],
        ['PATCH', '/owner', { user_id: 1 }],
        ['PATCH', `/members/${ids.alice}`, { role: 'viewer' }],
        ['DELETE', `/members/${ids.alice}`],
        [
            'POST',
            '/mcp-connections',
            { client_name: 'x', scopes: ['artifacts:read'] },
        ],
        ['DELETE', `/mcp-connections/${ids.connection}`],
        ['POST', `/mcp-connections/${ids.connection}/reauthorize`, {}],
        ['POST', '/tokens', { name: 'x' }],
        ['DELETE', `/tokens/${ids.token}`],
        ['POST', '/billing/checkout', {}],
        ['POST', '/switch', {}],
        ['PATCH', '', { name: 'Hijacked' }],
        ['DELETE', '', { name: 'Victim' }],
    ];

    for (const [method, path, json] of nonMember) {
        const response = await bob.request(`${V}${path}`, {
            method,
            json,
            headers: {
                Accept: method === 'GET' ? 'text/html' : 'application/json',
            },
        });
        expectStatus(
            `non-member bob: ${method} ${V.replace(slugV, '{victim}')}${path.replace(idV, '{artifact}').replace(String(ids.collection), '{collection}').replace(String(ids.connection), '{connection}').replace(String(ids.token), '{token}').replace(String(ids.alice), '{user}')}`,
            response,
            DENIED,
        );
    }

    // 2. IDOR: bob's own team URL, the victim team's ids.
    const idor = [
        ['GET', `/console/artifacts/${idV}/open`],
        ['POST', `/console/artifacts/${idV}/reindex`, {}],
        ['PATCH', `/console/artifacts/${idV}/revoke`, {}],
        ['PATCH', `/collections/${ids.collection}`, { name: 'x' }],
        [
            'POST',
            `/collections/${ids.collection}/artifacts`,
            { artifact_id: idV },
        ],
        ['DELETE', `/collections/${ids.collection}/artifacts/${idV}`],
        ['POST', `/collections/${ids.collection}/pin`, {}],
        ['DELETE', `/collections/${ids.collection}/pin`],
        ['DELETE', `/mcp-connections/${ids.connection}`],
        ['POST', `/mcp-connections/${ids.connection}/reauthorize`, {}],
        ['DELETE', `/tokens/${ids.token}`],
        ['DELETE', `/members/${ids.alice}`],
        ['PATCH', `/members/${ids.alice}`, { role: 'viewer' }],
    ];

    // The re-index route answers 409 ("Indexing is turned off") before it looks at
    // the artifact when indexing is off, so its ownership check is only
    // exercised, and only asserted, on a stack started with indexing on.
    const indexingOn = indexingEnabled();
    check(
        indexingOn
            ? 'indexing is on, so the re-index ownership check is exercised'
            : 'NOTE indexing is off in this stack: the re-index ownership check is not exercised (start with MCP_E2E_INDEXING_ENABLED=1)',
        true,
    );

    for (const [method, path, json] of idor) {
        const response = await bob.request(`${B}${path}`, {
            method,
            json,
            headers: {
                Accept: method === 'GET' ? 'text/html' : 'application/json',
            },
        });

        if (!indexingOn && path.endsWith('/reindex')) {
            expectStatus(
                'IDOR bob (own team URL, victim id): POST {own}/console/artifacts/{artifact}/reindex (indexing off: refused before the lookup)',
                response,
                [...DENIED, 409],
            );
            continue;
        }

        expectStatus(
            `IDOR bob (own team URL, victim id): ${method} {own}${path.replace(idV, '{artifact}').replace(String(ids.collection), '{collection}').replace(String(ids.connection), '{connection}').replace(String(ids.token), '{token}').replace(String(ids.alice), '{user}')}`,
            response,
            DENIED,
        );
    }

    // The victim's data is intact after all of that.
    expectStatus(
        'the victim artifact still serves for alice',
        await (await import('./lib.mjs')).get(`/p/${idV}`, v.token),
        [200],
    );
    const stillThere = JSON.parse(
        tinker(`
        $team = App\\Models\\Team::where('slug', '${slugV}')->first();
        echo json_encode([
            'team' => (bool) $team && $team->name === 'Victim',
            'members' => $team ? $team->memberships()->count() : 0,
            'collection' => App\\Models\\Collection::where('team_id', $team?->id)->where('id', ${ids.collection})->exists(),
            'connection' => App\\Models\\McpConnection::where('team_id', $team?->id)->where('public_id', '${ids.connection}')->whereNull('revoked_at')->exists(),
            'token' => App\\Models\\OrgToken::where('team_id', $team?->id)->where('id', ${ids.token})->whereNull('revoked_at')->exists(),
        ]);
    `),
    );
    check(
        'the victim team, its member, collection, connection and token are all untouched',
        stillThere.team &&
            stillThere.members === 1 &&
            stillThere.collection &&
            stillThere.connection &&
            stillThere.token,
        JSON.stringify(stillThere),
    );

    Object.assign(world, { slugV, idV, v, victimIds: ids });
    void Session;
}
