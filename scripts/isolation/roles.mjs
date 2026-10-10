// Phase 2: what a role and a token's scope narrow things down to. A viewer can
// read but not write or delete, and a token consented with fewer scopes than
// its role allows gets only those scopes.
import {
    Session,
    SCOPES,
    authed,
    check,
    deploy,
    deployRequest,
    expectStatus,
    get,
    laravel,
    run,
    tinker,
    worker,
} from './lib.mjs';

const DENIED = [403, 404];

const addMember = (email, slug, role) =>
    tinker(`
        $team = App\\Models\\Team::where('slug', '${slug}')->firstOrFail();
        $user = App\\Models\\User::where('email', '${email}')->firstOrFail();
        $team->memberships()->create(['user_id' => $user->id, 'role' => App\\Enums\\TeamRole::${role}]);
        echo $user->id;
    `);

const api = (path, token, init = {}) =>
    fetch(`${laravel}${path}`, {
        redirect: 'manual',
        ...init,
        headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            ...authed(token),
            ...(init.headers ?? {}),
        },
    });

export async function roles(world) {
    const { alice, slugA, idA } = world;

    // Members are added straight in the database: the invitation e-mail flow is
    // not what is being tested here, the role they end up with is.
    const carol = new Session(`carol-${run}@northwind.example`);
    const dave = new Session(`dave-${run}@northwind.example`);
    await carol.signIn();
    await dave.signIn();
    const carolId = addMember(
        `carol-${run}@northwind.example`,
        slugA,
        'Viewer',
    );
    const daveId = addMember(`dave-${run}@northwind.example`, slugA, 'Member');

    // Asking for more than the role allows is refused when the code is exchanged
    // (invalid_scope), so a viewer cannot consent their way to write access.
    const greedy = await carol.accessTokenFor(slugA, SCOPES);
    check(
        'a viewer cannot obtain a token with write scopes (invalid_scope)',
        !greedy.token,
        'a token was issued',
    );
    const viewer = await carol.accessTokenFor(
        slugA,
        'artifacts:read collections:read usage:read',
    );
    const member = await dave.accessTokenFor(slugA);
    check(
        'a viewer gets a read-only token through OAuth consent',
        Boolean(viewer.token),
        String(viewer.refused),
    );
    check(
        'a member gets a token for the team through OAuth consent',
        Boolean(member.token),
        String(member.refused),
    );

    // A viewer reads, and nothing else.
    expectStatus(
        'viewer can serve the team artifact',
        await get(`/p/${idA}`, viewer.token),
        [200],
    );
    expectStatus(
        'viewer can list the team artifacts',
        await get(`/v1/orgs/${slugA}/artifacts`, viewer.token),
        [200],
    );
    expectStatus(
        'viewer cannot deploy',
        await deployRequest(viewer.token, `<h1>viewer ${run}</h1>`),
        [403],
    );
    expectStatus(
        'viewer cannot delete an artifact',
        await get(`/v1/artifacts/${idA}`, viewer.token, { method: 'DELETE' }),
        [403],
    );
    expectStatus(
        'viewer cannot revoke an artifact',
        await get(`/v1/orgs/${slugA}/artifacts/${idA}`, viewer.token, {
            method: 'PATCH',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ revoked_at: '2026-01-01T00:00:00Z' }),
        }),
        [403],
    );
    expectStatus(
        'viewer can list collections',
        await api('/api/collections', viewer.token),
        [200],
    );
    expectStatus(
        'viewer cannot create a collection',
        await api('/api/collections', viewer.token, {
            method: 'POST',
            body: JSON.stringify({ name: 'x' }),
        }),
        [403],
    );
    expectStatus(
        'the artifact survives the viewer',
        await get(`/p/${idA}`, world.a.token),
        [200],
    );

    // A member can write, but the team's administration is closed to them.
    const memberArtifact = await deploy(
        member.token,
        `<h1>member work ${run}</h1>`,
    );
    check('a member can deploy', Boolean(memberArtifact));

    const adminRoutes = [
        ['PATCH', '', { name: 'Taken Over' }],
        ['POST', '/invitations', { email: 'x@example.com', role: 'admin' }],
        ['PATCH', `/members/${carolId}`, { role: 'admin' }],
        ['DELETE', `/members/${carolId}`],
        ['PATCH', '/owner', { user_id: daveId }],
        ['POST', '/tokens', { name: 'x', role: 'admin', ttl_seconds: 3600 }],
        ['DELETE', ''],
    ];

    for (const [method, path, json] of adminRoutes) {
        for (const [who, session] of [
            ['member dave', dave],
            ['viewer carol', carol],
        ]) {
            expectStatus(
                `${who}: ${method} /settings/teams/{own}${path.replace(String(carolId), '{user}')} is closed to non-admins`,
                await session.request(`/settings/teams/${slugA}${path}`, {
                    method,
                    json,
                    headers: { Accept: 'application/json' },
                }),
                DENIED,
            );
        }
    }

    const stillAdmin = await alice.pageProps(`/settings/teams/${slugA}`);
    check(
        'the team name and membership were not changed by non-admins',
        stillAdmin.props?.team?.name === 'Acme Labs',
        String(stillAdmin.props?.team?.name),
    );

    // A token consented with fewer scopes than its role allows gets only those.
    const readOnly = await alice.accessTokenFor(slugA, 'artifacts:read');
    check(
        'an admin can consent to read-only access',
        Boolean(readOnly.token),
        String(readOnly.refused),
    );
    expectStatus(
        'read-only token can serve',
        await get(`/p/${idA}`, readOnly.token),
        [200],
    );
    expectStatus(
        'read-only token cannot deploy',
        await deployRequest(readOnly.token, `<h1>read-only ${run}</h1>`),
        [403],
    );
    expectStatus(
        'read-only token cannot delete',
        await get(`/v1/artifacts/${idA}`, readOnly.token, { method: 'DELETE' }),
        [403],
    );
    expectStatus(
        'read-only token cannot read usage',
        await get(`/v1/orgs/${slugA}/usage`, readOnly.token),
        [403],
    );
    expectStatus(
        'read-only token cannot read collections',
        await api('/api/collections', readOnly.token),
        [403],
    );

    const deployOnly = await alice.accessTokenFor(slugA, 'artifacts:deploy');
    check(
        'an admin can consent to deploy-only access',
        Boolean(deployOnly.token),
        String(deployOnly.refused),
    );
    expectStatus(
        'deploy-only token cannot list',
        await get(`/v1/orgs/${slugA}/artifacts`, deployOnly.token),
        [403],
    );
    expectStatus(
        'deploy-only token cannot export',
        await get(`/v1/orgs/${slugA}/export`, deployOnly.token),
        [403],
    );
    expectStatus(
        'deploy-only token cannot delete',
        await get(`/v1/artifacts/${idA}`, deployOnly.token, {
            method: 'DELETE',
        }),
        [403],
    );

    Object.assign(world, { carol, dave, carolId, daveId, viewer, member });
    void worker;
}
