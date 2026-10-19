// Phase 1 and the RUB-434 scenario: two real teams, the cross-team matrix, and
// the rename-then-reclaim attack. Fills `world` for the scenarios that follow.
import {
    Session,
    assertCannotTouch,
    check,
    deploy,
    expectStatus,
    get,
    run,
} from './lib.mjs';

export async function baseline(world) {
    const slugA = `iso-acme-${run}`;
    const slugB = `iso-beta-${run}`;
    const alice = new Session(`alice-${run}@northwind.example`);
    const bob = new Session(`bob-${run}@northwind.example`);
    await alice.signIn();
    await bob.signIn();

    // Phase 1: two real teams, each owned by its own user.
    expectStatus(
        'alice creates her team through the app',
        await alice.createTeam('Acme', slugA),
        [302, 303],
    );
    expectStatus(
        'bob creates his team through the app',
        await bob.createTeam('Beta', slugB),
        [302, 303],
    );

    const a = await alice.accessTokenFor(slugA);
    const b = await bob.accessTokenFor(slugB);
    check(
        'alice gets a token for her team through real OAuth consent',
        Boolean(a.token),
        String(a.refused),
    );
    check(
        'bob gets a token for his team through real OAuth consent',
        Boolean(b.token),
        String(b.refused),
    );

    if (!a.token || !b.token) {
        throw new Error('cannot continue without both tokens');
    }

    const idA = await deploy(a.token, `<h1>acme secret ${run}</h1>`);
    const idB = await deploy(b.token, `<h1>beta secret ${run}</h1>`);
    expectStatus(
        'alice serves her own secure artifact',
        await get(`/p/${idA}`, a.token),
        [200],
    );
    expectStatus(
        'bob serves his own secure artifact',
        await get(`/p/${idB}`, b.token),
        [200],
    );

    Object.assign(world, { slugA, slugB, alice, bob, a, b, idA, idB });

    // Baseline: neither team can reach the other with a valid token of its own.
    await assertCannotTouch({
        label: 'bob -> acme',
        token: b.token,
        victimOrg: slugA,
        victimId: idA,
    });
    await assertCannotTouch({
        label: 'alice -> beta',
        token: a.token,
        victimOrg: slugB,
        victimId: idB,
    });

    // The RUB-434 attack: rename a team, then claim its old slug.
    expectStatus(
        'alice renames her team',
        await alice.renameTeam(slugA, 'Acme Labs'),
        [302, 303],
    );

    // The rename must really have happened (a failed validation also redirects),
    // otherwise the slug checks below would pass without testing anything.
    const renamed = await alice.pageProps(`/settings/teams/${slugA}`);
    check(
        'the rename took effect (the team is now called Acme Labs) and the old slug still opens for alice',
        renamed.status === 200 && renamed.props?.team?.name === 'Acme Labs',
        `status ${renamed.status}, name ${renamed.props?.team?.name}`,
    );

    const claim = await bob.createTeam('Not Acme', slugA);
    // A rejected claim is Laravel's validation redirect (back to the previous
    // page); an accepted one redirects to the new team's own settings page.
    const claimedLocation = claim.headers.get('Location') ?? '';
    check(
        "bob cannot claim the slug alice's team still holds",
        claim.status === 422 ||
            (claim.status === 302 &&
                !claimedLocation.includes(`/settings/teams/${slugA}`)),
        `status ${claim.status}, redirected to ${claimedLocation}`,
    );
    expectStatus(
        "bob cannot open alice's team settings after the claim attempt",
        await bob.request(`/settings/teams/${slugA}`, {
            headers: { Accept: 'text/html' },
        }),
        [403, 404],
    );
    const hijack = await bob.accessTokenFor(slugA);
    check(
        "bob cannot obtain a token naming alice's team through OAuth consent",
        !hijack.token,
        'a token was issued',
    );

    // The decisive check: whatever bob did, alice's data is still unreachable for him.
    // If the attack produced a token that names alice's team, that token is the
    // exploit, so it is the one that must be refused by the Worker.
    await assertCannotTouch({
        label: 'bob after the claim attempt -> acme',
        token: b.token,
        victimOrg: slugA,
        victimId: idA,
    });

    if (hijack.token) {
        await assertCannotTouch({
            label: 'bob with the token naming acme -> acme',
            token: hijack.token,
            victimOrg: slugA,
            victimId: idA,
        });
    }

    expectStatus(
        'alice can still serve her artifact after the rename',
        await get(`/p/${idA}`, a.token),
        [200],
    );
}
