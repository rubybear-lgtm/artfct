// Phase 2: who can open one artifact, at each sharing level. Two real teams;
// in team A a plain member who publishes (the owner), a second plain member, and
// a team admin; in team B a member who belongs to nothing else; and a visitor
// with no session. The owner publishes one private, one team and one public
// artifact through the real Worker, and every actor then tries the viewer, the
// freshness poll, the download, the in-app preview, the console's "open" link
// and the MCP tools, each with its own credential.
//
// The rules under test: private is visible to its owner and to team admins,
// team to every member of A, public to everyone. A page that is not visible
// must be indistinguishable from a missing id (same status, headers and body),
// so no route is an existence oracle. Download, preview and the MCP tools are
// membership routes: public means "anyone may open the viewer", not "anyone may
// download or read it through team A". The same requests are then repeated
// interleaved, and concurrently, so a resident worker (Octane) that keeps state
// between requests would show up as a changed answer.
//
// Every verdict names the actor, route, sharing level, expected and actual
// result. Nothing here depends on a value from another run: everything derives
// from `run`.
import { createHash } from 'node:crypto';
import http from 'node:http';
import {
    Session,
    authed,
    check,
    deploy,
    indexingEnabled,
    laravel,
    retry429,
    run,
    tinker,
    worker,
} from './lib.mjs';

const LEVELS = ['private', 'team', 'public'];
const ROUNDS = 8;
const MEMBERS_OF_A = ['owner', 'member', 'admin'];
const ACTOR_LABELS = {
    owner: 'owner',
    member: 'non-owner member of A',
    admin: 'admin of A',
    outsider: 'member of B only',
    anonymous: 'anonymous',
};

const tally = { routes: new Map(), actors: new Map() };
const bump = (map, key) => map.set(key, (map.get(key) ?? 0) + 1);

/** One named check: the failure message carries actor, route, sharing, expected and actual. */
function verdict({ actor, route, level, expected, actual, ok }) {
    bump(tally.routes, route);
    bump(tally.actors, actor);
    const subject =
        level === 'every' ? 'every artifact' : `a ${level} artifact`;
    check(
        `${route}: ${actor} on ${subject} -> ${expected}`,
        ok,
        `actor=${actor} route=${route} sharing=${level} expected=${expected} actual=${actual}`,
    );
}

const addMember = (email, slug, role) =>
    tinker(`
        $team = App\\Models\\Team::where('slug', '${slug}')->firstOrFail();
        $user = App\\Models\\User::where('email', '${email}')->firstOrFail();
        $team->memberships()->create(['user_id' => $user->id, 'role' => App\\Enums\\TeamRole::${role}]);
        echo $user->id;
    `);

const digest = (text) => createHash('sha256').update(text).digest('hex');

function readFrame(frameUrl) {
    const frame = new URL(frameUrl);
    const target = new URL(worker);

    return new Promise((resolve, reject) => {
        const request = http.get(
            {
                hostname: target.hostname,
                port: target.port,
                path: `${frame.pathname}${frame.search}`,
                headers: { Host: frame.host },
            },
            (response) => {
                const chunks = [];
                response.on('data', (chunk) => chunks.push(chunk));
                response.on('end', () =>
                    resolve({
                        status: response.statusCode,
                        body: Buffer.concat(chunks).toString(),
                    }),
                );
                response.on('error', reject);
            },
        );
        request.on('error', reject);
    });
}

/** The data-page props of an Inertia HTML response, or null for anything else. */
function pageProps(text) {
    const match = text.match(
        /<script data-page="app" type="application\/json">(.+?)<\/script>/s,
    );

    return match ? (JSON.parse(match[1]).props ?? null) : null;
}

/** Replace what legitimately differs per request (ids, CSRF, nonces) so bodies can be compared. */
function normalise(text, ids) {
    let out = text;

    for (const id of ids) {
        out = out.split(id).join('<id>');
    }

    return out
        .replace(/(name="csrf-token" content=")[^"]*/g, '$1<csrf>')
        .replace(/nonce="[^"]*"/g, 'nonce="<nonce>"');
}

function firstDifference(a, b) {
    let at = 0;

    while (at < a.length && a[at] === b[at]) {
        at += 1;
    }

    const window = (text) =>
        JSON.stringify(text.slice(Math.max(0, at - 30), at + 70));

    return `body differs at ${at}: ${window(a)} vs ${window(b)}`;
}

/** Why a refusal of a real id can be told apart from a refusal of a missing id, or null. */
function distinguishable(real, missing, ids) {
    if (real.status !== missing.status) {
        return `status ${real.status} vs ${missing.status}`;
    }

    if (normalise(real.location, ids) !== normalise(missing.location, ids)) {
        return `Location ${real.location} vs ${missing.location}`;
    }

    if (real.type !== missing.type || real.cache !== missing.cache) {
        return `headers ${real.type} / ${real.cache} vs ${missing.type} / ${missing.cache}`;
    }

    const a = normalise(real.text, ids);
    const b = normalise(missing.text, ids);

    return a === b ? null : firstDifference(a, b);
}

/** Cache headers a shared cache must never be able to keep a per-viewer page under. */
const cacheIsPerViewer = (cache) =>
    /\b(private|no-store)\b/.test(cache) &&
    !/\b(public|s-maxage)\b/.test(cache);

async function snapshot(response) {
    return {
        status: response.status,
        location: response.headers.get('Location') ?? '',
        cache: response.headers.get('Cache-Control') ?? '',
        etag: response.headers.get('ETag') ?? '',
        type: response.headers.get('Content-Type') ?? '',
        disposition: response.headers.get('Content-Disposition') ?? '',
        csp: response.headers.get('Content-Security-Policy') ?? '',
        text: await response.text(),
    };
}

/** A page request as an actor: with their session, or with none at all. */
async function web(actor, path, headers = {}) {
    const merged = { Accept: 'text/html', ...headers };

    if (actor.session) {
        return snapshot(await actor.session.request(path, { headers: merged }));
    }

    return snapshot(
        await fetch(`${laravel}${path}`, {
            redirect: 'manual',
            headers: merged,
        }),
    );
}

async function mcpRequest(token, session, method, params) {
    // A team shares one 120-calls-a-minute bucket; a throttled call waits out
    // Retry-After once instead of aborting the whole run.
    const response = await retry429(() =>
        fetch(`${laravel}/mcp`, {
            method: 'POST',
            headers: {
                Accept: 'application/json, text/event-stream',
                'Content-Type': 'application/json',
                ...(token ? authed(token) : {}),
                ...(session ? { 'MCP-Session-Id': session } : {}),
            },
            body: JSON.stringify({
                jsonrpc: '2.0',
                id: crypto.randomUUID(),
                method,
                params,
            }),
        }),
    );
    const text = await response.text();
    let payload = null;

    try {
        payload = JSON.parse(text);
    } catch {
        // A refusal before the MCP layer (401) has no JSON-RPC body worth parsing.
    }

    return {
        status: response.status,
        payload,
        text,
        sessionId: response.headers.get('MCP-Session-Id'),
    };
}

/** An MCP tool call with the actor's own credential; returns the tool result. */
async function mcpTool(actor, name, args) {
    if (!actor.mcpSession) {
        const init = await mcpRequest(actor.token, null, 'initialize', {
            protocolVersion: '2025-11-25',
            clientInfo: { name: 'artfct isolation suite', version: '1.0.0' },
            capabilities: {},
        });

        if (init.status !== 200 || !init.sessionId) {
            throw new Error(
                `MCP initialize for ${actor.name} answered ${init.status}`,
            );
        }

        actor.mcpSession = init.sessionId;
    }

    const response = await mcpRequest(
        actor.token,
        actor.mcpSession,
        'tools/call',
        { name, arguments: args },
    );

    if (response.status !== 200 || !response.payload?.result) {
        throw new Error(
            `MCP ${name} for ${actor.name} answered ${response.status}: ${response.text.slice(0, 200)}`,
        );
    }

    return response.payload.result;
}

/**
 * What read_artifact answers for an artifact the caller may see: it only gets
 * to these after the Worker's visibility pre-check passes. Whether the text is
 * indexed yet (it is not, on this stack) decides which one. artifact_not_found
 * and infrastructure errors (upstream_unavailable, artifact_retrieval_failed,
 * configuration_error) are deliberately not in the list.
 */
const READ_REACHED_CONTENT = [
    'ok',
    'content_not_indexed',
    'content_not_available',
    'version_not_indexed',
];

const mcpCode = (result) =>
    result?.isError === true
        ? (result.structuredContent?.code ?? 'error')
        : 'ok';

export async function artifactAccess(world) {
    const slugA = `iso-access-a-${run}`;
    const slugB = `iso-access-b-${run}`;
    const email = (name) => `${name}-${run}@northwind.example`;

    // ── Two teams and the five actors ────────────────────────────────────────
    const ada = new Session(email('ada'));
    const bella = new Session(email('bella'));
    const oscar = new Session(email('oscar'));
    const mia = new Session(email('mia'));
    const ben = new Session(email('ben'));

    for (const session of [ada, bella, oscar, mia, ben]) {
        await session.signIn();
    }

    check(
        'ada creates team A through the app (she is its admin)',
        [302, 303].includes((await ada.createTeam('Access A', slugA)).status),
    );
    check(
        'bella creates team B through the app',
        [302, 303].includes((await bella.createTeam('Access B', slugB)).status),
    );
    const oscarId = addMember(email('oscar'), slugA, 'Member');
    addMember(email('mia'), slugA, 'Member');
    addMember(email('ben'), slugB, 'Member');

    const consent = {
        owner: await oscar.accessTokenFor(slugA),
        member: await mia.accessTokenFor(slugA),
        admin: await ada.accessTokenFor(slugA),
        outsider: await ben.accessTokenFor(slugB),
    };

    for (const [name, result] of Object.entries(consent)) {
        check(
            `${ACTOR_LABELS[name]} gets a token through real OAuth consent`,
            Boolean(result.token),
            String(result.refused),
        );
    }

    if (Object.values(consent).some((result) => !result.token)) {
        throw new Error('cannot continue without every actor credential');
    }

    const actors = {
        owner: { name: 'owner', session: oscar, token: consent.owner.token },
        member: { name: 'member', session: mia, token: consent.member.token },
        admin: { name: 'admin', session: ada, token: consent.admin.token },
        outsider: {
            name: 'outsider',
            session: ben,
            token: consent.outsider.token,
        },
        anonymous: { name: 'anonymous', session: null, token: null },
    };
    const actorKeys = Object.keys(actors);

    // ── The owner publishes one artifact per sharing level ───────────────────
    const artifacts = {};

    for (const level of LEVELS) {
        const marker = `access-marker-${level}-${run}`;
        const title = `Access ${level} ${run}`;
        const id = await deploy(actors.owner.token, `<h1>${marker}</h1>`, {
            sharing: level,
            title,
        });
        artifacts[level] = { level, id, marker, title };
    }

    for (const art of Object.values(artifacts)) {
        const metadata = await fetch(`${worker}/v1/artifacts/${art.id}`, {
            headers: authed(actors.owner.token),
        });
        const body = metadata.ok ? await metadata.json() : {};
        check(
            `the owner's ${art.level} artifact really is ${art.level} and owned by the owner`,
            body.sharing === art.level && body.owner_user_id === oscarId,
            `status ${metadata.status}, sharing=${body.sharing}, owner=${body.owner_user_id} (expected ${oscarId})`,
        );
    }

    // A well-formed id nobody published, shaped like the real ones.
    const sample = artifacts.team.id;
    const seed = createHash('sha256').update(`missing artifact ${run}`);
    const hexDigest = seed.digest('hex');
    const missingId = /^[0-9a-f]{32}$/.test(sample)
        ? hexDigest.slice(0, 32)
        : BigInt(`0x${hexDigest}`).toString(36).slice(0, sample.length);
    const missing = { level: 'missing', id: missingId, marker: '', title: '' };
    const ids = [...Object.values(artifacts).map((art) => art.id), missingId];

    // ── Routes ───────────────────────────────────────────────────────────────
    const previewPath = (id, slug = slugA) =>
        `/settings/teams/${slug}/artifacts/${id}/preview`;
    const routes = {
        viewer: (actor, id) => web(actor, `/a/${id}`),
        freshness: (actor, id, headers) =>
            web(actor, `/a/${id}/freshness`, {
                Accept: 'application/json',
                ...headers,
            }),
        download: (actor, id) => web(actor, `/a/${id}/download`),
        preview: (actor, id, headers) =>
            web(actor, previewPath(id), headers ?? {}),
        open: (actor, id) =>
            web(actor, `/settings/teams/${slugA}/console/artifacts/${id}/open`),
    };
    const ROUTE_LABELS = {
        viewer: 'GET /a/{id}',
        freshness: 'GET /a/{id}/freshness',
        download: 'GET /a/{id}/download',
        preview: 'GET /settings/teams/{team}/artifacts/{id}/preview',
        open: 'GET /settings/teams/{team}/console/artifacts/{id}/open',
    };

    /**
     * Everything one actor gets for one artifact id, across every route. Hosted
     * MCP is limited to 120 calls a minute per team (and the smoke asserts that
     * limit, so the stack keeps it); the repeat passes therefore ask MCP about
     * the private artifact only, and the web routes about all three.
     */
    async function probe(actor, art, withMcp = true) {
        const out = {};

        for (const [key, send] of Object.entries(routes)) {
            out[key] = await send(actor, art.id);
        }

        if (withMcp) {
            out.mcp = actor.token
                ? await mcpTool(actor, 'get_artifact', { id: art.id })
                : null;
        }

        return out;
    }

    /** A short, stable summary of a response, for "the same answer again" comparisons. */
    function fingerprint(kind, value) {
        if (kind === 'mcp') {
            return value === null
                ? 'no credential'
                : digest(JSON.stringify(value));
        }

        const props = kind === 'viewer' ? pageProps(value.text) : null;

        if (props) {
            return `${value.status} ${JSON.stringify([
                props.publicView,
                props.artifact?.id,
                props.artifact?.title,
                props.artifact?.sharing,
                props.artifact?.version,
                props.artifact?.ownerName,
                props.artifact?.canChangeSharing,
                props.downloadUrl,
                Boolean(props.frameUrl),
            ])}`;
        }

        return [
            value.status,
            normalise(value.location, ids),
            digest(normalise(value.text, ids)),
            kind === 'preview' ? value.etag : '',
        ].join(' ');
    }

    // Who may see what, by route. Public opens the viewer and the freshness poll
    // for anyone; the download, the preview and the MCP tools are membership
    // routes, so a public artifact is still refused to a visitor who is not in A.
    const openRoutes = new Set(['viewer', 'freshness']);
    const canSee = (route, actor, level) => {
        if (level === 'public' && openRoutes.has(route)) {
            return true;
        }

        if (!MEMBERS_OF_A.includes(actor)) {
            return false;
        }

        return level !== 'private' || ['owner', 'admin'].includes(actor);
    };

    // ── First pass: every actor, every level, every route ───────────────────
    const gone = {};
    const reference = new Map();

    for (const key of actorKeys) {
        gone[key] = await probe(actors[key], missing);
    }

    for (const level of LEVELS) {
        const art = artifacts[level];
        const results = {};

        for (const key of actorKeys) {
            const actor = actors[key];
            const real = await probe(actor, art);
            results[key] = real;
            reference.set(
                `${key}|${level}`,
                Object.fromEntries(
                    Object.entries(real).map(([kind, value]) => [
                        kind,
                        fingerprint(kind, value),
                    ]),
                ),
            );

            for (const route of Object.keys(routes)) {
                assertWebRoute({ key, level, art, route, snap: real[route] });
            }

            assertMcpGetArtifact({ key, level, art, result: real.mcp });
        }

        assertRefusalsMatchMissing({ level, results });
        artifacts[level].results = results;
    }

    /** The expected answer of one web route for one actor on one artifact. */
    function assertWebRoute({ key, level, art, route, snap }) {
        const label = ROUTE_LABELS[route];
        const visible = canSee(route, key, level);
        const leak =
            (snap.text.includes(art.marker) || snap.text.includes(art.title)) &&
            !visible;
        const props = route === 'viewer' ? pageProps(snap.text) : null;

        if (!visible) {
            const signedOut = key === 'anonymous';
            const refused = signedOut
                ? [302, 303].includes(snap.status) &&
                  snap.location.endsWith('/login')
                : snap.status === 404;
            verdict({
                actor: key,
                route: label,
                level,
                expected: signedOut
                    ? 'sign-in redirect and no artifact content'
                    : '404 and no artifact content',
                actual: `${snap.status}${snap.location ? ` -> ${snap.location}` : ''}${leak ? ', ARTIFACT CONTENT IN BODY' : ''}`,
                ok: refused && !leak,
            });

            if (route === 'viewer' || route === 'preview') {
                assertPerViewerCache({ key, level, label, snap });
            }

            return;
        }

        if (route === 'viewer') {
            const member = MEMBERS_OF_A.includes(key);
            const mode =
                props?.publicView === true
                    ? 'public view'
                    : props?.publicView === false
                      ? 'member view'
                      : 'no page';
            const expectedMode = member ? 'member view' : 'public view';
            // A visitor from outside team A gets the public view only: no owner
            // name, no sharing control, no download.
            const outsiderSafe =
                member ||
                (props?.artifact?.ownerName === null &&
                    props?.artifact?.canChangeSharing === false &&
                    props?.downloadUrl === null);
            verdict({
                actor: key,
                route: label,
                level,
                expected: `200, ${expectedMode} of the artifact with a frame`,
                actual: `${snap.status}, ${mode}, title=${props?.artifact?.title}, sharing=${props?.artifact?.sharing}`,
                ok:
                    snap.status === 200 &&
                    mode === expectedMode &&
                    props?.artifact?.id === art.id &&
                    props?.artifact?.title === art.title &&
                    props?.artifact?.sharing === level &&
                    Boolean(props?.frameUrl) &&
                    outsiderSafe,
            });
            assertPerViewerCache({ key, level, label, snap });

            return;
        }

        if (route === 'freshness') {
            const body = (() => {
                try {
                    return JSON.parse(snap.text);
                } catch {
                    return {};
                }
            })();
            verdict({
                actor: key,
                route: label,
                level,
                expected: `200, sharing=${level}, not revoked, no-store`,
                actual: `${snap.status}, sharing=${body.sharing}, revoked=${body.revoked}, ${snap.cache}`,
                ok:
                    snap.status === 200 &&
                    body.sharing === level &&
                    body.revoked === false &&
                    Number.isInteger(body.version) &&
                    /\bno-store\b/.test(snap.cache),
            });

            return;
        }

        if (route === 'open') {
            verdict({
                actor: key,
                route: label,
                level,
                expected: 'a 302 to the viewer of the artifact',
                actual: `${snap.status} -> ${snap.location || 'nowhere'}`,
                ok:
                    snap.status === 302 &&
                    snap.location.endsWith(`/a/${art.id}`),
            });

            return;
        }

        if (route === 'download') {
            verdict({
                actor: key,
                route: label,
                level,
                expected: '200, an HTML attachment holding the artifact',
                actual: `${snap.status}, ${snap.disposition || 'no disposition'}, marker ${snap.text.includes(art.marker) ? 'present' : 'absent'}`,
                ok:
                    snap.status === 200 &&
                    snap.disposition.startsWith('attachment') &&
                    snap.text.includes(art.marker),
            });

            return;
        }

        verdict({
            actor: key,
            route: label,
            level,
            expected: '200, the sandboxed artifact page with an ETag',
            actual: `${snap.status}, marker ${snap.text.includes(art.marker) ? 'present' : 'absent'}, etag ${snap.etag || 'none'}, csp ${snap.csp ? 'set' : 'missing'}`,
            ok:
                snap.status === 200 &&
                snap.text.includes(art.marker) &&
                snap.etag !== '' &&
                /default-src 'none'/.test(snap.csp) &&
                /\bsandbox\b/.test(snap.csp),
        });
        assertPerViewerCache({ key, level, label, snap });
    }

    /** The viewer and the preview are per-viewer pages: no shared cache may keep them. */
    function assertPerViewerCache({ key, level, label, snap }) {
        verdict({
            actor: key,
            route: `${label} Cache-Control`,
            level,
            expected: 'private or no-store, never public or s-maxage',
            actual: `${snap.status} "${snap.cache}"`,
            ok: cacheIsPerViewer(snap.cache),
        });
    }

    function assertMcpGetArtifact({ key, level, art, result }) {
        if (result === null) {
            return;
        }

        const visible = canSee('mcp', key, level);
        const data = result.structuredContent ?? {};
        verdict({
            actor: key,
            route: 'MCP get_artifact',
            level,
            expected: visible
                ? `the artifact's metadata (owner_is_you=${key === 'owner'})`
                : 'artifact_not_found',
            actual: `${mcpCode(result)}, id=${data.id ?? 'none'}, sharing=${data.sharing ?? 'none'}, owner_is_you=${data.owner_is_you}`,
            ok: visible
                ? result.isError !== true &&
                  data.id === art.id &&
                  data.title === art.title &&
                  data.sharing === level &&
                  data.owner_is_you === (key === 'owner')
                : result.isError === true &&
                  data.code === 'artifact_not_found' &&
                  !JSON.stringify(result).includes(art.title),
        });
    }

    /** A refusal must read exactly like a missing id: status, headers and body. */
    function assertRefusalsMatchMissing({ level, results }) {
        for (const key of actorKeys) {
            for (const route of Object.keys(routes)) {
                if (canSee(route, key, level)) {
                    continue;
                }

                const reason = distinguishable(
                    results[key][route],
                    gone[key][route],
                    ids,
                );
                verdict({
                    actor: key,
                    route: `${ROUTE_LABELS[route]} (vs a missing id)`,
                    level,
                    expected:
                        'the same status, headers and body as a missing id',
                    actual: reason ?? 'identical',
                    ok: reason === null,
                });
            }

            if (actors[key].token && !canSee('mcp', key, level)) {
                const same =
                    JSON.stringify(results[key].mcp) ===
                    JSON.stringify(gone[key].mcp);
                verdict({
                    actor: key,
                    route: 'MCP get_artifact (vs a missing id)',
                    level,
                    expected: 'the same result as a missing id',
                    actual: same
                        ? 'identical'
                        : `${mcpCode(results[key].mcp)} vs ${mcpCode(gone[key].mcp)}`,
                    ok: same,
                });
            }
        }
    }

    // ── The freshness poll's "revoked" answer is not an existence oracle ─────
    // A viewer that already rendered an artifact is told it went away; that
    // answer must not depend on whether the id ever existed.
    for (const key of ['member', 'outsider']) {
        for (const level of ['private', 'team']) {
            if (canSee('freshness', key, level)) {
                continue;
            }

            const real = await routes.freshness(
                actors[key],
                artifacts[level].id,
                { 'X-Freshness-Known': '1' },
            );
            const none = await routes.freshness(actors[key], missingId, {
                'X-Freshness-Known': '1',
            });
            const reason = distinguishable(real, none, ids);
            verdict({
                actor: key,
                route: `${ROUTE_LABELS.freshness} with X-Freshness-Known`,
                level,
                expected: 'the same answer as a missing id',
                actual: reason ?? `identical (${real.status})`,
                ok: reason === null,
            });
        }
    }

    // ── Preview through a team the actor does belong to ──────────────────────
    // The B member's own team slug with team A's artifact id (IDOR).
    for (const level of LEVELS) {
        const art = artifacts[level];
        const real = await web(actors.outsider, previewPath(art.id, slugB));
        const none = await web(actors.outsider, previewPath(missingId, slugB));
        const leak =
            real.text.includes(art.marker) || real.text.includes(art.title);
        const reason = distinguishable(real, none, ids);
        verdict({
            actor: 'outsider',
            route: 'GET /settings/teams/{own team}/artifacts/{id}/preview',
            level,
            expected: "404 like a missing id, nothing of A's artifact",
            actual: `${real.status}${leak ? ', ARTIFACT CONTENT IN BODY' : ''}${reason ? `, ${reason}` : ''}`,
            ok: real.status === 404 && !leak && reason === null,
        });
    }

    // ── The preview ETag is per viewer ───────────────────────────────────────
    const previewOf = (key, level) => artifacts[level].results[key].preview;

    for (const level of LEVELS) {
        const viewers = MEMBERS_OF_A.filter((key) =>
            canSee('preview', key, level),
        );
        const etags = viewers.map((key) => previewOf(key, level).etag);
        const distinct = new Set(etags).size === etags.length;
        verdict({
            actor: viewers.join('+'),
            route: 'preview ETag',
            level,
            expected: 'a non-empty ETag per viewer, different for each viewer',
            actual: etags.join(' | '),
            ok: etags.every((etag) => etag !== '') && distinct,
        });
    }

    const owner = actors.owner;
    const teamEtag = previewOf('owner', 'team').etag;
    const control = await routes.preview(owner, artifacts.team.id, {
        'If-None-Match': teamEtag,
    });
    verdict({
        actor: 'owner',
        route: 'preview ETag (conditional request)',
        level: 'team',
        expected: '304 for the viewer the ETag belongs to (control)',
        actual: String(control.status),
        ok: control.status === 304,
    });

    const crossViewer = [
        [
            'member',
            'team',
            'someone else has no use for the ETag: 200 with the page',
            (r) => r.status === 200 && r.text.includes(artifacts.team.marker),
        ],
        [
            'member',
            'private',
            '404 like a missing id, never 304',
            (r) => r.status === 404,
        ],
        [
            'admin',
            'private',
            '200 with the page, never 304',
            (r) =>
                r.status === 200 && r.text.includes(artifacts.private.marker),
        ],
        [
            'outsider',
            'team',
            '404 like a missing id, never 304',
            (r) => r.status === 404,
        ],
        [
            'anonymous',
            'team',
            'a sign-in redirect, never 304',
            (r) => [302, 303].includes(r.status),
        ],
    ];

    for (const [key, level, expected, passes] of crossViewer) {
        const ownerEtag = previewOf('owner', level).etag;
        const response = await routes.preview(
            actors[key],
            artifacts[level].id,
            { 'If-None-Match': ownerEtag },
        );
        verdict({
            actor: key,
            route: "preview with the owner's ETag in If-None-Match",
            level,
            expected,
            actual: `${response.status}`,
            ok: passes(response),
        });
    }

    // ── The MCP tools: read_artifact, search_artifacts, list_artifacts ───────
    const indexing = indexingEnabled();
    const withToken = actorKeys.filter((key) => actors[key].token);

    for (const level of LEVELS) {
        const art = artifacts[level];
        const noCredential = await mcpRequest(null, null, 'tools/call', {
            name: 'get_artifact',
            arguments: { id: art.id },
        });
        verdict({
            actor: 'anonymous',
            route: 'MCP get_artifact without a credential',
            level,
            expected: '401 and nothing about the artifact',
            actual: `${noCredential.status}${noCredential.text.includes(art.title) ? ', ARTIFACT TITLE IN BODY' : ''}`,
            ok:
                noCredential.status === 401 &&
                !noCredential.text.includes(art.title),
        });
    }

    check(
        indexing
            ? 'indexing is on, so read_artifact tells a hidden artifact from a visible one'
            : 'NOTE indexing is off in this stack: read_artifact and search_artifacts answer search_not_configured to everyone before they look at the artifact, so their visibility is not exercised (start with MCP_E2E_INDEXING_ENABLED=1; list_artifacts below covers the visible set)',
        true,
    );

    for (const level of LEVELS) {
        const art = artifacts[level];
        const answers = {};

        for (const key of withToken) {
            answers[key] = await mcpTool(actors[key], 'read_artifact', {
                id: art.id,
            });
        }

        const firstAnswer = JSON.stringify(answers[withToken[0]]);

        for (const key of withToken) {
            const visible = canSee('mcp', key, level);
            const code = mcpCode(answers[key]);
            verdict({
                actor: key,
                route: 'MCP read_artifact',
                level,
                expected: indexing
                    ? visible
                        ? `a read that got past the visibility check (${READ_REACHED_CONTENT.join(', ')})`
                        : 'artifact_not_found'
                    : 'search_not_configured, the same as for everyone',
                actual: code,
                ok: indexing
                    ? visible
                        ? READ_REACHED_CONTENT.includes(code)
                        : code === 'artifact_not_found'
                    : code === 'search_not_configured' &&
                      JSON.stringify(answers[key]) === firstAnswer,
            });
        }
    }

    for (const level of LEVELS) {
        const art = artifacts[level];

        for (const key of withToken) {
            if (canSee('mcp', key, level)) {
                continue;
            }

            const result = await mcpTool(actors[key], 'search_artifacts', {
                query: `${art.title} ${art.marker}`,
                limit: 10,
            });
            const named = JSON.stringify(result);
            verdict({
                actor: key,
                route: 'MCP search_artifacts',
                level,
                expected: 'no result that names the artifact',
                actual: `${mcpCode(result)}${named.includes(art.id) || named.includes(art.title) || named.includes(art.marker) ? ', ARTIFACT NAMED IN RESULT' : ''}`,
                ok:
                    !named.includes(art.id) &&
                    !named.includes(art.title) &&
                    !named.includes(art.marker),
            });
        }
    }

    for (const level of LEVELS) {
        const art = artifacts[level];

        for (const key of withToken) {
            const result = await mcpTool(actors[key], 'list_artifacts', {});
            const listed = (result.structuredContent?.artifacts ?? []).some(
                (item) => item.id === art.id,
            );
            const visible = canSee('mcp', key, level);
            verdict({
                actor: key,
                route: 'MCP list_artifacts',
                level,
                expected: visible ? 'listed' : 'not listed',
                actual: `${mcpCode(result)}, ${listed ? 'listed' : 'not listed'}`,
                ok: result.isError !== true && listed === visible,
            });
        }
    }

    // ── Resident workers: the same requests, interleaved and concurrent ──────
    // A worker that keeps the previous viewer's user, team, props or cache entry
    // between requests answers one of these differently from the first pass.
    const drifts = { interleaved: new Map(), concurrent: new Map() };

    async function compareAgainstFirstPass(mode, key, level) {
        const probed = await probe(
            actors[key],
            artifacts[level],
            level === 'private',
        );
        const expected = reference.get(`${key}|${level}`);

        for (const [kind, value] of Object.entries(probed)) {
            const actual = fingerprint(kind, value);

            if (actual !== expected[kind]) {
                const list = drifts[mode].get(key) ?? [];
                list.push(
                    `${kind} on ${level}: first pass ${expected[kind]}, now ${actual}`,
                );
                drifts[mode].set(key, list);
            }
        }
    }

    for (let round = 0; round < ROUNDS; round += 1) {
        const order = [
            ...actorKeys.slice(round % actorKeys.length),
            ...actorKeys.slice(0, round % actorKeys.length),
        ];

        // One level, the most sensitive first, with a different actor between
        // each pair of identical requests.
        for (const level of [...LEVELS].reverse()) {
            for (const key of order) {
                await compareAgainstFirstPass('interleaved', key, level);
            }
        }
    }

    for (let round = 0; round < ROUNDS; round += 1) {
        for (const level of LEVELS) {
            await Promise.all(
                actorKeys.map((key) =>
                    compareAgainstFirstPass('concurrent', key, level),
                ),
            );
        }
    }

    for (const mode of ['interleaved', 'concurrent']) {
        for (const key of actorKeys) {
            const list = drifts[mode].get(key) ?? [];
            verdict({
                actor: key,
                route: `${mode} repeat of every route and MCP get_artifact`,
                level: 'every',
                expected: `the first pass's answer on all ${ROUNDS} rounds`,
                actual:
                    list.length === 0
                        ? 'unchanged'
                        : `${list.length} change(s), first: ${list[0]}`,
                ok: list.length === 0,
            });
        }
    }

    // Exercise the web sharing endpoint against the real Worker, then open
    // the same link as a different member and as a signed-out visitor.
    const transition = artifacts.public;

    for (const level of ['team', 'private', 'public']) {
        const changed = await actors.owner.session.request(
            `/a/${transition.id}/sharing`,
            { method: 'PATCH', json: { sharing: level } },
        );
        check(
            `owner submits ${level} sharing through Laravel`,
            [302, 303].includes(changed.status),
            `status ${changed.status}`,
        );

        const metadata = await fetch(
            `${worker}/v1/artifacts/${transition.id}`,
            {
                headers: authed(actors.owner.token),
            },
        );
        const body = metadata.ok ? await metadata.json() : {};
        check(
            `Worker records ${level} sharing after the Laravel request`,
            metadata.ok && body.sharing === level,
            `status ${metadata.status}, sharing ${body.sharing}`,
        );

        for (const key of ['owner', 'member', 'anonymous']) {
            const visible =
                key === 'owner' ||
                level === 'public' ||
                (key === 'member' && level === 'team');
            const viewer = await routes.viewer(actors[key], transition.id);
            const props = pageProps(viewer.text);
            const allowed =
                viewer.status === 200 &&
                props?.artifact?.id === transition.id &&
                props.artifact.sharing === level &&
                Boolean(props.frameUrl);
            const denied =
                key === 'anonymous'
                    ? [302, 303].includes(viewer.status) &&
                      viewer.location.endsWith('/login')
                    : viewer.status === 404;
            check(
                `${key} ${visible ? 'opens' : 'cannot open'} the ${level} viewer after the change`,
                visible
                    ? allowed
                    : denied && !viewer.text.includes(transition.title),
                `status ${viewer.status}, sharing ${props?.artifact?.sharing}`,
            );

            if (visible && allowed) {
                const content = await readFrame(props.frameUrl);
                check(
                    `${key} receives the ${level} artifact content from the Worker`,
                    content.status === 200 &&
                        content.body.includes(transition.marker),
                    `status ${content.status}`,
                );
            }
        }
    }

    const total = [...tally.routes.values()].reduce((sum, n) => sum + n, 0);
    console.log(
        `artifact access: ${total} verdicts over ${tally.routes.size} routes`,
    );

    for (const [route, count] of tally.routes) {
        console.log(`  ${String(count).padStart(3)}  ${route}`);
    }

    for (const [actor, count] of tally.actors) {
        console.log(`  ${String(count).padStart(3)}  as ${actor}`);
    }

    Object.assign(world, { accessSlugA: slugA, accessSlugB: slugB, artifacts });
}
