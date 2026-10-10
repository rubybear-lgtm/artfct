// Phase 3: the route-driven matrix and its coverage guard.
//
// Laravel: every route with a {team} (or {current_team}) parameter is attacked
// automatically, by a signed-in user who is not a member, so a new team route
// is covered the moment it exists. Every other route must be classified in
// route-classification.json by how it is protected, and the class's own check
// is run. A route that is missing from the file (or a stale entry) fails.
//
// Worker: every /v1 and /p path in openapi/artfct.yaml is attacked with the
// other team's credentials against the first team's real ids, by a rule on the
// path. A path no rule recognises fails, so it cannot slip in unchecked.
import { createHash } from 'node:crypto';
import { readFileSync } from 'node:fs';
import { artisan, authed, check, laravel, worker } from './lib.mjs';

const TEAM_PARAMS = ['{team}', '{current_team}'];
const classificationFile = JSON.parse(
    readFileSync(
        new URL('./route-classification.json', import.meta.url),
        'utf8',
    ),
);
const classification = classificationFile.laravel;
const teamExceptions = Object.keys(classificationFile.team_exceptions).filter(
    (k) => !k.startsWith('_'),
);

function laravelRoutes() {
    const routes = [];

    for (const route of JSON.parse(artisan(['route:list', '--json']))) {
        for (const method of route.method
            .split('|')
            .filter((m) => m !== 'HEAD')) {
            routes.push({
                method,
                uri:
                    route.uri === '/'
                        ? '/'
                        : `/${route.uri.replace(/^\//, '')}`,
            });
        }
    }

    return routes;
}

/** The (method, path, x-status) of every operation in the OpenAPI contract, read line by line so CI needs no YAML library. */
function openapiPaths() {
    const operations = [];
    let inPaths = false;
    let path = null;
    let current = null;

    for (const line of readFileSync('openapi/artfct.yaml', 'utf8').split(
        '\n',
    )) {
        if (/^\S/.test(line)) {
            inPaths = line.startsWith('paths:');
            path = null;
            continue;
        }

        if (!inPaths) {
            continue;
        }

        const pathMatch = line.match(/^ {2}(\/\S*):\s*$/);

        if (pathMatch) {
            path = pathMatch[1];
            current = null;
            continue;
        }

        const methodMatch =
            path && line.match(/^ {4}(get|post|put|patch|delete):\s*$/);

        if (methodMatch) {
            current = {
                method: methodMatch[1].toUpperCase(),
                path,
                status: '',
            };
            operations.push(current);
            continue;
        }

        const statusMatch = current && line.match(/^ {6}x-status:\s*(\S+)/);

        if (statusMatch) {
            current.status = statusMatch[1];
        }
    }

    return operations;
}

const fill = (template, values) =>
    template.replace(/\{([^}]+)\}/g, (_, name) => values[name] ?? '1');

async function plain(method, url, { token, headers = {}, body } = {}) {
    return fetch(url, {
        method,
        redirect: 'manual',
        headers: {
            Accept: 'application/json',
            ...(body === undefined
                ? {}
                : { 'Content-Type': 'application/json' }),
            ...(token ? authed(token) : {}),
            ...headers,
        },
        body: ['GET', 'DELETE'].includes(method) ? undefined : (body ?? '{}'),
    });
}

export async function matrix(world) {
    const { bob, b, slugA, idA, slugV, idV, victimIds, a } = world;

    // ── Laravel ──────────────────────────────────────────────────────────────
    const routes = laravelRoutes();
    const team = routes.filter((r) =>
        TEAM_PARAMS.some((p) => r.uri.includes(p)),
    );
    const other = routes.filter(
        (r) => !TEAM_PARAMS.some((p) => r.uri.includes(p)),
    );

    const known = new Set(Object.keys(classification));
    const present = new Set(
        other.map((r) =>
            `${r.method} ${r.uri.replace(/^\//, '')}`.replace(/ $/, ''),
        ),
    );
    // The home page is the only route whose URI is "/" itself.
    const key = (r) =>
        r.uri === '/' ? `${r.method} /` : `${r.method} ${r.uri.slice(1)}`;
    const unclassified = other.filter((r) => !known.has(key(r))).map(key);
    const stale = [...known].filter((k) => !other.some((r) => key(r) === k));
    check(
        'every non-team Laravel route is classified',
        unclassified.length === 0,
        `unclassified: ${unclassified.join(', ')}`,
    );
    check(
        'the route classification has no stale entries',
        stale.length === 0,
        `stale: ${stale.join(', ')}`,
    );
    void present;

    const values = {
        team: slugV,
        current_team: slugV,
        artifactId: idV,
        collection: victimIds.collection,
        connection: victimIds.connection,
        token: victimIds.token,
        user: victimIds.alice,
        path: 'x',
    };

    for (const route of team) {
        const response = await bob.request(fill(route.uri, values), {
            method: route.method,
            json: ['GET', 'DELETE'].includes(route.method) ? undefined : {},
            headers: {
                Accept:
                    route.method === 'GET' ? 'text/html' : 'application/json',
            },
        });
        const routeKey = `${route.method} ${route.uri.replace(/^\//, '')}`;
        const allowed = teamExceptions.includes(routeKey)
            ? [302, 404]
            : [403, 404];
        const body = teamExceptions.includes(routeKey)
            ? (await response.text()).slice(0, 4000)
            : '';
        check(
            `matrix: non-member bob is refused ${route.method} ${route.uri}${allowed.includes(302) ? ' (SSO sign-in: redirect only)' : ''}`,
            allowed.includes(response.status) &&
                !body.includes(slugV + '-secret') &&
                !body.includes(idV),
            `got ${response.status}`,
        );
    }

    check(
        `the matrix attacked ${team.length} team routes`,
        team.length >= 40,
        `only ${team.length}`,
    );
    const missingExceptions = teamExceptions.filter(
        (k) =>
            !team.some((r) => `${r.method} ${r.uri.replace(/^\//, '')}` === k),
    );
    check(
        'every team-route exception still exists',
        missingExceptions.length === 0,
        `gone: ${missingExceptions.join(', ')}`,
    );

    // The class checks for everything that is not a team route.
    const anonymous = (r) =>
        plain(r.method, `${laravel}${fill(r.uri, values)}`);

    for (const route of other) {
        const cls = classification[key(route)];

        if (cls === 'user') {
            const response = await anonymous(route);
            check(
                `class user: anonymous is refused ${route.method} ${route.uri}`,
                [302, 401, 403, 419].includes(response.status),
                `got ${response.status}`,
            );
        } else if (cls === 'token') {
            const response = await anonymous(route);
            check(
                `class token: no bearer is refused ${route.method} ${route.uri}`,
                [401, 403, 405].includes(response.status),
                `got ${response.status}`,
            );
        } else if (cls === 'signed') {
            const response = await anonymous(route);
            check(
                `class signed: unsigned is refused ${route.method} ${route.uri}`,
                response.status >= 400 && response.status < 500,
                `got ${response.status}`,
            );
        } else if (cls === 'public' && route.method === 'GET') {
            const response = await anonymous(route);
            check(
                `class public: anonymous GET does not error ${route.uri}`,
                response.status < 500,
                `got ${response.status}`,
            );
        }
    }

    // The artifact viewer (RUB-438 / RUB-439) names an artifact without a
    // team: bob, signed in to his own team, must get the same 404 a missing
    // artifact gets for the victim's id, and never its content. A signed-out
    // visitor gets the sign-in redirect instead, with the same non-oracle
    // body.
    for (const [method, path] of [
        ['GET', `/a/${idV}`],
        ['GET', `/a/${idV}/download`],
        ['PATCH', `/a/${idV}/sharing`],
    ]) {
        const response = await bob.request(path, {
            method,
            json: method === 'GET' ? undefined : { sharing: 'public' },
            headers: {
                Accept: method === 'GET' ? 'text/html' : 'application/json',
            },
        });
        const body = (await response.text()).slice(0, 4000);
        check(
            `viewer: bob cannot reach the victim's artifact ${method} ${path}`,
            response.status === 404 && !body.includes(slugV + '-secret'),
            `got ${response.status}`,
        );
    }

    const anonymousViewer = await plain('GET', `${laravel}/a/${idV}`, {
        headers: { Accept: 'text/html' },
    });
    const anonymousViewerBody = (await anonymousViewer.text()).slice(0, 4000);
    check(
        "viewer: a signed-out visitor is sent to sign in, never shown the victim's artifact",
        [302, 303].includes(anonymousViewer.status) &&
            (anonymousViewer.headers.get('location') ?? '').includes(
                '/login',
            ) &&
            !anonymousViewerBody.includes('isolation probe') &&
            !anonymousViewerBody.includes(idV),
        `got ${anonymousViewer.status}`,
    );

    // Token routes that take another team's id: bob's token, the victim's id.
    const foreignCollection = await plain(
        'POST',
        `${laravel}/api/collections/${victimIds.collection}/artifacts`,
        { token: b.token, body: JSON.stringify({ artifact_id: idV }) },
    );
    check(
        "token route: bob's token cannot add to the victim's collection",
        [403, 404].includes(foreignCollection.status),
        `got ${foreignCollection.status}`,
    );

    // ── Worker ───────────────────────────────────────────────────────────────
    const sha = createHash('sha256')
        .update(`<h1>acme secret ${world.run ?? ''}</h1>`)
        .digest('hex');
    void sha;
    const operations = openapiPaths().filter(
        (o) => o.path.startsWith('/v1/') || o.path.startsWith('/p/'),
    );
    const attackValues = {
        org: slugA,
        id: idA,
        sha256: '0'.repeat(64),
        path: 'index.html',
    };
    const handled = [];

    for (const op of operations) {
        const url = `${worker}${fill(op.path, attackValues)}`;
        let rule;
        let expected;
        let token = b.token;

        if (op.path.startsWith('/v1/internal/')) {
            rule = 'internal';
            expected = [401, 403, 404];
        } else if (op.path.startsWith('/v1/orgs/{org}/')) {
            rule = 'org';
            expected = [403, 404];
        } else if (op.path === '/v1/artifacts' && op.method === 'POST') {
            rule = 'own-org';
        } else if (op.path === '/v1/artifacts' || op.path === '/v1/search') {
            rule = op.status === 'unimplemented' ? 'unimplemented' : null;
        } else if (op.path.startsWith('/v1/artifacts/{id}')) {
            rule = 'artifact';
            expected = [403, 404];
        } else if (op.path.startsWith('/v1/public/artifacts/')) {
            // Unauthenticated public read: an id that is not a live public
            // permanent artifact is the same 404 here as everywhere else.
            rule = 'public-read';
            expected = [404];
        } else if (op.path.startsWith('/v1/blobs/{sha256}')) {
            rule = 'blob';
            expected = [403, 404];
        } else if (op.path.startsWith('/p/{id}')) {
            rule = 'serve';
            expected = [401, 403, 404];
        } else {
            rule = null;
        }

        handled.push({ op, rule });

        if (!rule) {
            check(
                `every Worker path has a rule: ${op.method} ${op.path}`,
                false,
                'no rule recognises this path',
            );
            continue;
        }

        if (!expected) {
            continue;
        }

        const validBodies = {
            'PATCH /v1/artifacts/{id}': JSON.stringify({ ttl_minutes: 60 }),
            'PATCH /v1/orgs/{org}/artifacts/{id}': JSON.stringify({
                revoked_at: '2026-01-01T00:00:00Z',
            }),
        };
        const body =
            op.method === 'PUT'
                ? 'x'
                : ['GET', 'DELETE'].includes(op.method)
                  ? undefined
                  : (validBodies[`${op.method} ${op.path}`] ?? '{}');
        const withBob = await fetch(url, {
            method: op.method,
            redirect: 'manual',
            headers: {
                ...(token ? authed(token) : {}),
                ...(body && body.startsWith('{')
                    ? { 'Content-Type': 'application/json' }
                    : {}),
            },
            body,
        });
        check(
            `worker matrix: bob is refused ${op.method} ${op.path} (${rule})`,
            expected.includes(withBob.status),
            `got ${withBob.status}`,
        );

        if (rule === 'internal') {
            const bare = await fetch(url, {
                method: op.method,
                redirect: 'manual',
                body,
            });
            check(
                `worker matrix: no credential is refused ${op.method} ${op.path} (${rule})`,
                expected.includes(bare.status),
                `got ${bare.status}`,
            );
        }
    }

    check(
        `the Worker matrix covered ${handled.filter((h) => h.rule).length} of ${operations.length} contract operations`,
        handled.every((h) => h.rule),
    );

    // alice's data is intact after the whole matrix.
    const intact = await fetch(`${worker}/p/${idA}`, {
        headers: authed(a.token),
    });
    check(
        "alice's artifact is intact after the matrix",
        intact.status === 200,
        `got ${intact.status}`,
    );
    void bob;
}
