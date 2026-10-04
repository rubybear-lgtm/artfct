// Phase 2: the isolated artifact origin. The console hands a member a signed
// link to `<team>--<artifact>.<suffix>`. That link must open only that one
// artifact: not another artifact's host, not without its token, not altered.
import http from 'node:http';
import { check, expectStatus, worker } from './lib.mjs';

/** A request to the Worker with an explicit Host header (fetch may not set it). */
function viaHost(host, pathAndQuery, headers = {}) {
    const target = new URL(worker);

    return new Promise((resolve, reject) => {
        const request = http.request(
            {
                host: target.hostname,
                port: target.port,
                path: pathAndQuery,
                method: 'GET',
                headers: { Host: host, ...headers },
            },
            (response) => {
                response.resume();
                response.on('end', () =>
                    resolve({
                        status: response.statusCode,
                        headers: response.headers,
                    }),
                );
            },
        );
        request.on('error', reject);
        request.end();
    });
}

export async function originLink(world) {
    const { alice, bob, slugA, slugB, idA, idB } = world;

    const open = await alice.request(
        `/settings/teams/${slugA}/console/artifacts/${idA}/open`,
        { headers: { Accept: 'text/html' } },
    );
    const location = open.headers.get('Location');
    check(
        'alice is sent to an isolated link for her own artifact',
        open.status === 302 && Boolean(location),
        `status ${open.status}`,
    );

    if (!location) {
        return;
    }

    const link = new URL(location);
    const pathAndQuery = `${link.pathname}${link.search}`;
    const token =
        link.searchParams.get('token') ?? link.searchParams.get('t') ?? '';
    check(
        'the link is on its own per-artifact host',
        link.hostname.startsWith(`${slugA}--${idA}`),
        link.hostname,
    );
    check(
        'the link carries a signed token',
        token.length > 20,
        `query was ${link.search.slice(0, 80)}`,
    );

    const valid = await viaHost(link.hostname, pathAndQuery);
    check(
        'the signed link opens the artifact on its own host',
        [200, 302].includes(valid.status),
        `status ${valid.status}`,
    );

    // The same token, aimed at a different artifact's host.
    const otherHost = link.hostname.replace(
        `${slugA}--${idA}`,
        `${slugB}--${idB}`,
    );
    const crossHost = await viaHost(otherHost, pathAndQuery);
    check(
        "alice's link does not open bob's artifact host",
        [401, 403, 404].includes(crossHost.status),
        `status ${crossHost.status}`,
    );

    // The same token, aimed at another artifact id on the same team's host pattern.
    const wrongId = link.hostname.replace(idA, 'f'.repeat(idA.length));
    const wrongArtifact = await viaHost(wrongId, pathAndQuery);
    check(
        'the link does not open another artifact id',
        [401, 403, 404].includes(wrongArtifact.status),
        `status ${wrongArtifact.status}`,
    );

    // Without the token, and with an altered one.
    const bare = await viaHost(link.hostname, link.pathname);
    check(
        'the host alone, without the token, does not open',
        [401, 403, 404].includes(bare.status),
        `status ${bare.status}`,
    );

    if (token) {
        const flipped = token.slice(0, -1) + (token.endsWith('A') ? 'B' : 'A');
        const altered = new URL(location);
        altered.searchParams.set(
            altered.searchParams.has('token') ? 'token' : 't',
            flipped,
        );
        const tampered = await viaHost(
            link.hostname,
            `${altered.pathname}${altered.search}`,
        );
        check(
            'an altered token does not open',
            [401, 403, 404].includes(tampered.status),
            `status ${tampered.status}`,
        );
    }

    // bob cannot even be handed a link to alice's artifact.
    expectStatus(
        "bob cannot get a link to alice's artifact through his own team",
        await bob.request(
            `/settings/teams/${slugB}/console/artifacts/${idA}/open`,
            { headers: { Accept: 'text/html' } },
        ),
        [403, 404],
    );
    expectStatus(
        "bob cannot get a link to alice's artifact through alice's team",
        await bob.request(
            `/settings/teams/${slugA}/console/artifacts/${idA}/open`,
            { headers: { Accept: 'text/html' } },
        ),
        [403, 404],
    );
}
