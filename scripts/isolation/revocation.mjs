// Phase 2: access that must end when membership or the team ends. A removed
// member's token and refresh token, and every credential of a deleted team,
// must stop working. Revocation reaches the Worker asynchronously, so each
// check polls briefly instead of asserting at one instant.
import {
    Session,
    authed,
    check,
    clientId,
    deploy,
    eventually,
    expectStatus,
    get,
    laravel,
    run,
} from './lib.mjs';

const refused = (status) => status === 401 || status === 403 || status === 404;

async function refreshAttempt(refreshToken) {
    return fetch(`${laravel}/oauth/token`, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            Accept: 'application/json',
        },
        body: JSON.stringify({
            grant_type: 'refresh_token',
            refresh_token: refreshToken,
            client_id: await clientId(),
        }),
    });
}

async function assertCredentialDead(
    label,
    { token, refresh, org, artifactId },
) {
    const checks = [
        [
            'cannot list the artifacts',
            () => get(`/v1/orgs/${org}/artifacts`, token),
            (r) => refused(r.status),
        ],
        [
            'cannot serve the artifact',
            () => get(`/p/${artifactId}`, token),
            (r) => refused(r.status),
        ],
        [
            'cannot export',
            () => get(`/v1/orgs/${org}/export`, token),
            (r) => refused(r.status),
        ],
        [
            'cannot call the team API',
            () =>
                fetch(`${laravel}/api/collections`, {
                    headers: { Accept: 'application/json', ...authed(token) },
                }),
            (r) => refused(r.status),
        ],
        [
            'cannot use the MCP endpoint',
            () =>
                fetch(`${laravel}/mcp`, {
                    method: 'POST',
                    headers: {
                        Accept: 'application/json, text/event-stream',
                        'Content-Type': 'application/json',
                        ...authed(token),
                    },
                    body: JSON.stringify({
                        jsonrpc: '2.0',
                        id: 1,
                        method: 'tools/list',
                    }),
                }),
            (r) => refused(r.status),
        ],
    ];

    for (const [name, call, ok] of checks) {
        const result = await eventually(async () => {
            const response = await call();

            return { ok: ok(response), status: response.status };
        });
        check(`${label} ${name}`, result.ok, `still answered ${result.status}`);
    }

    if (refresh) {
        const response = await refreshAttempt(refresh);
        check(
            `${label} cannot refresh into a new token`,
            !response.ok,
            `refresh answered ${response.status}`,
        );
    }
}

export async function revocation(world) {
    const { alice, slugA, idA, carol, carolId, viewer } = world;

    // Sanity: the credentials work right up until the membership is removed.
    expectStatus(
        'before removal, the member token works',
        await get(`/v1/orgs/${slugA}/artifacts`, viewer.token),
        [200],
    );

    // 1. A member is removed from the team.
    expectStatus(
        'alice removes the member',
        await alice.request(`/settings/teams/${slugA}/members/${carolId}`, {
            method: 'DELETE',
            headers: { Accept: 'application/json' },
        }),
        [302, 303],
    );
    await assertCredentialDead('removed member', {
        token: viewer.token,
        refresh: viewer.refresh,
        org: slugA,
        artifactId: idA,
    });
    expectStatus(
        "removed member cannot open the team's pages any more",
        await carol.request(`/settings/teams/${slugA}`, {
            headers: { Accept: 'text/html' },
        }),
        [403, 404],
    );
    const reconsent = await carol.accessTokenFor(slugA, 'artifacts:read');
    check(
        'removed member cannot consent into the team again',
        !reconsent.token,
        'a token was issued',
    );
    expectStatus(
        'the team artifact is untouched',
        await get(`/p/${idA}`, world.a.token),
        [200],
    );

    // 2. A whole team is deleted.
    const erin = new Session(`erin-${run}@northwind.example`);
    await erin.signIn();
    const slugG = `iso-gamma-${run}`;
    expectStatus(
        'erin creates a team',
        await erin.createTeam('Gamma', slugG),
        [302, 303],
    );
    const g = await erin.accessTokenFor(slugG);
    check(
        'erin gets a token for her team',
        Boolean(g.token),
        String(g.refused),
    );
    const idG = await deploy(g.token, `<h1>gamma ${run}</h1>`);
    expectStatus(
        'before deletion, the team token works',
        await get(`/v1/orgs/${slugG}/artifacts`, g.token),
        [200],
    );

    expectStatus(
        'erin deletes her team (typing its name)',
        await erin.request(`/settings/teams/${slugG}`, {
            method: 'DELETE',
            json: { name: 'Gamma' },
            headers: { Accept: 'application/json' },
        }),
        [302, 303],
    );
    await assertCredentialDead('deleted team', {
        token: g.token,
        refresh: g.refresh,
        org: slugG,
        artifactId: idG,
    });

    // Nobody can take the deleted team's slug (the row is soft-deleted and keeps it).
    const intruder = new Session(`frank-${run}@northwind.example`);
    await intruder.signIn();
    const claim = await intruder.createTeam('Not Gamma', slugG);
    const claimedLocation = claim.headers.get('Location') ?? '';
    check(
        "a new team cannot claim the deleted team's slug",
        claim.status === 422 ||
            (claim.status === 302 &&
                !claimedLocation.includes(`/settings/teams/${slugG}`)),
        `status ${claim.status}, redirected to ${claimedLocation}`,
    );
    const takeover = await intruder.accessTokenFor(slugG);
    check(
        "nobody can get a token naming the deleted team's slug",
        !takeover.token,
        'a token was issued',
    );

    Object.assign(world, { slugG, idG });
}
