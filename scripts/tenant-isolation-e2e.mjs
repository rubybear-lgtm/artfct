#!/usr/bin/env node
// RUB-435: tenant isolation, end to end. Two real teams, created by two real
// signed-in users through the app's own flows (dev-login stand-in, team
// creation, OAuth consent), and credentials minted by Laravel, not fabricated.
// Every check then tries to cross from one team into the other, against the
// real Laravel app and the real Worker.
//
// Run against the stack from scripts/mcp-e2e-stack.sh:
//   scripts/mcp-e2e-stack.sh isolation
//
// Requires AUTHKIT_DEV_LOGIN_ENABLED=true on the target (never true in
// production). Exits non-zero if any check fails.
import { createHash, randomBytes } from 'node:crypto';

const laravel = (process.env.ISO_LARAVEL_URL ?? 'http://127.0.0.1:8990').replace(/\/$/, '');
const worker = (process.env.ISO_WORKER_URL ?? 'http://127.0.0.1:8991').replace(/\/$/, '');
const run = randomBytes(3).toString('hex');
const SCOPES =
    'artifacts:read artifacts:deploy artifacts:delete collections:read collections:write usage:read';

let failures = 0;

function check(name, ok, detail = '') {
    if (!ok) {
        failures += 1;
    }

    console.log(`${ok ? 'ok  ' : 'FAIL'} ${name}${ok ? '' : detail ? ` (${detail})` : ''}`);
}

function expectStatus(name, response, allowed) {
    check(
        name,
        allowed.includes(response.status),
        `expected ${allowed.join(' or ')}, got ${response.status}`,
    );
}

/** A browser-like session: cookie jar plus CSRF, over plain HTTP. */
class Session {
    jar = new Map();

    constructor(email) {
        this.email = email;
    }

    capture(response) {
        for (const raw of response.headers.getSetCookie?.() ?? []) {
            const pair = raw.split(';', 1)[0];
            const at = pair.indexOf('=');

            if (at > 0) {
                this.jar.set(pair.slice(0, at), pair.slice(at + 1));
            }
        }
    }

    cookie() {
        return [...this.jar].map(([k, v]) => `${k}=${v}`).join('; ');
    }

    xsrf() {
        return decodeURIComponent(this.jar.get('XSRF-TOKEN') ?? '');
    }

    async request(path, options = {}) {
        const first = await this.send(path, options);

        // The sign-in limiter keys on the peer address, and every caller here is
        // 127.0.0.1, so back-to-back runs share one bucket. Wait it out once.
        if (first.status !== 429) {
            return first;
        }

        const wait = Math.min(Number(first.headers.get('Retry-After') ?? 60), 70);
        await new Promise((resolve) => setTimeout(resolve, (wait + 1) * 1000));

        return this.send(path, options);
    }

    async send(path, { method = 'GET', json, headers = {} } = {}) {
        const response = await fetch(path.startsWith('http') ? path : `${laravel}${path}`, {
            method,
            redirect: 'manual',
            headers: {
                Accept: 'application/json',
                Cookie: this.cookie(),
                'X-XSRF-TOKEN': this.xsrf(),
                ...(json === undefined ? {} : { 'Content-Type': 'application/json' }),
                ...headers,
            },
            body: json === undefined ? undefined : JSON.stringify(json),
        });
        this.capture(response);

        return response;
    }

    /** Dev-login, finish /authenticate, accept the terms. */
    async signIn() {
        this.capture(await fetch(`${laravel}/login`, { redirect: 'manual' }));
        const login = await this.request('/authkit/dev-login', {
            method: 'POST',
            json: { email: this.email, provider: 'MagicAuth' },
        });

        if (login.status !== 302) {
            throw new Error(`dev-login answered ${login.status}; is AUTHKIT_DEV_LOGIN_ENABLED=true?`);
        }

        const authenticate = await this.request(login.headers.get('Location'));

        if (authenticate.status !== 302) {
            throw new Error(`/authenticate answered ${authenticate.status}`);
        }

        const terms = await this.request('/terms/accept', { method: 'POST', json: { accepted: true } });

        if (![200, 302, 303].includes(terms.status)) {
            throw new Error(`terms acceptance answered ${terms.status}`);
        }
    }

    /** Inertia page props from a plain GET of a page (the data-page JSON in the HTML). */
    async pageProps(path) {
        const response = await this.request(path, { headers: { Accept: 'text/html' } });
        const match = (await response.text()).match(/<script data-page="app" type="application\/json">(.+?)<\/script>/s);

        return { status: response.status, props: match ? (JSON.parse(match[1]).props ?? {}) : null };
    }

    createTeam(name, slug) {
        return this.request('/settings/teams', { method: 'POST', json: { name, slug } });
    }

    renameTeam(slug, name) {
        return this.request(`/settings/teams/${slug}`, { method: 'PATCH', json: { name } });
    }

    /** Real OAuth consent as this user for the named team; returns the access token. */
    async accessTokenFor(team) {
        const verifier = randomBytes(48).toString('base64url');
        const challenge = createHash('sha256').update(verifier).digest('base64url');
        const redirectUri = 'http://127.0.0.1:0/callback';
        const parameters = {
            response_type: 'code',
            client_id: 'artfct-cli',
            redirect_uri: redirectUri,
            scope: SCOPES,
            state: randomBytes(8).toString('hex'),
            code_challenge: challenge,
            code_challenge_method: 'S256',
            team,
        };
        const page = await fetch(`${laravel}/oauth/authorize?${new URLSearchParams(parameters)}`, {
            headers: { Cookie: this.cookie() },
            redirect: 'manual',
        });
        this.capture(page);

        if (page.status !== 200) {
            return { refused: page.status };
        }

        const match = (await page.text()).match(
            /<script data-page="app" type="application\/json">(.+?)<\/script>/s,
        );
        const consentToken = match ? JSON.parse(match[1]).props?.consentToken : null;

        if (!consentToken) {
            return { refused: 'no consent token' };
        }

        const consent = await this.request('/oauth/authorize', {
            method: 'POST',
            json: { ...parameters, decision: 'approve', consent_token: consentToken },
            headers: { 'X-Inertia': 'true' },
        });
        const location = consent.headers.get('X-Inertia-Location');

        if (consent.status !== 409 || !location) {
            return { refused: consent.status };
        }

        const code = new URL(location).searchParams.get('code');

        if (!code) {
            return { refused: 'no code' };
        }

        const token = await fetch(`${laravel}/oauth/token`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
            body: JSON.stringify({
                grant_type: 'authorization_code',
                code,
                client_id: 'artfct-cli',
                redirect_uri: redirectUri,
                code_verifier: verifier,
            }),
        });

        if (!token.ok) {
            return { refused: `token ${token.status}` };
        }

        return { token: (await token.json()).access_token };
    }
}

const authed = (token) => ({ Authorization: `Bearer ${token}` });

/** Create a secure permanent artifact in the token's own org through the real deploy path. */
async function deploy(token, content) {
    const sha = createHash('sha256').update(content).digest('hex');
    const created = await fetch(`${worker}/v1/artifacts`, {
        method: 'POST',
        headers: { ...authed(token), 'Content-Type': 'application/json' },
        body: JSON.stringify({
            mode: 'permanent',
            tier: 'secure',
            title: 'isolation probe',
            description: 'tenant isolation e2e',
            thumbnail: '',
            preview_blurred: false,
            provenance: {},
            manifest: {
                entrypoint: 'index.html',
                external_origins: [],
                files: [
                    {
                        path: 'index.html',
                        sha256: sha,
                        size_bytes: Buffer.byteLength(content),
                        content_type: 'text/html',
                    },
                ],
            },
        }),
    });

    if (!created.ok) {
        throw new Error(`deploy answered ${created.status}`);
    }

    const { id } = await created.json();
    const upload = await fetch(`${worker}/v1/artifacts/${id}/files/${sha}`, {
        method: 'PUT',
        headers: authed(token),
        body: content,
    });

    if (!upload.ok) {
        throw new Error(`file upload answered ${upload.status}`);
    }

    return id;
}

const get = (path, token, init = {}) =>
    fetch(`${worker}${path}`, {
        redirect: 'manual',
        ...init,
        headers: { ...(token ? authed(token) : {}), ...(init.headers ?? {}) },
    });

/** What an outsider's credentials must not be able to do to someone else's artifact. */
async function assertCannotTouch({ label, token, victimOrg, victimId }) {
    const denied = [403, 404];
    expectStatus(`${label}: cannot list ${victimOrg}'s artifacts`, await get(`/v1/orgs/${victimOrg}/artifacts`, token), denied);
    expectStatus(`${label}: cannot export ${victimOrg}`, await get(`/v1/orgs/${victimOrg}/export`, token), denied);
    expectStatus(`${label}: cannot read ${victimOrg}'s usage`, await get(`/v1/orgs/${victimOrg}/usage`, token), denied);
    expectStatus(
        `${label}: cannot read the victim's content`,
        await get(`/v1/orgs/${victimOrg}/artifacts/${victimId}/content`, token),
        denied,
    );
    expectStatus(`${label}: cannot read the victim's metadata`, await get(`/v1/artifacts/${victimId}`, token), denied);
    expectStatus(`${label}: cannot serve the victim's secure artifact`, await get(`/p/${victimId}`, token), [401, 403, 404]);
    expectStatus(`${label}: cannot download the victim's blob`, await get(`/v1/blobs/${'0'.repeat(64)}`, token), denied);
    expectStatus(`${label}: cannot delete the victim's artifact`, await get(`/v1/artifacts/${victimId}`, token, { method: 'DELETE' }), denied);
}

async function main() {
    console.log(`tenant isolation e2e (run ${run})  laravel=${laravel}  worker=${worker}`);

    const slugA = `iso-acme-${run}`;
    const slugB = `iso-beta-${run}`;
    const alice = new Session(`alice-${run}@northwind.example`);
    const bob = new Session(`bob-${run}@northwind.example`);
    await alice.signIn();
    await bob.signIn();

    // Phase 1: two real teams, each owned by its own user.
    const teamA = await alice.createTeam('Acme', slugA);
    const teamB = await bob.createTeam('Beta', slugB);
    expectStatus('alice creates her team through the app', teamA, [302, 303]);
    expectStatus('bob creates his team through the app', teamB, [302, 303]);

    const a = await alice.accessTokenFor(slugA);
    const b = await bob.accessTokenFor(slugB);
    check('alice gets a token for her team through real OAuth consent', Boolean(a.token), String(a.refused));
    check('bob gets a token for his team through real OAuth consent', Boolean(b.token), String(b.refused));

    if (!a.token || !b.token) {
        throw new Error('cannot continue without both tokens');
    }

    const idA = await deploy(a.token, `<h1>acme secret ${run}</h1>`);
    const idB = await deploy(b.token, `<h1>beta secret ${run}</h1>`);
    expectStatus('alice serves her own secure artifact', await get(`/p/${idA}`, a.token), [200]);
    expectStatus('bob serves his own secure artifact', await get(`/p/${idB}`, b.token), [200]);

    // Phase 2, baseline: neither team can reach the other with a valid token of its own.
    await assertCannotTouch({ label: 'bob -> acme', token: b.token, victimOrg: slugA, victimId: idA });
    await assertCannotTouch({ label: 'alice -> beta', token: a.token, victimOrg: slugB, victimId: idB });

    // Phase 2, the RUB-434 attack: rename a team, then claim its old slug.
    const rename = await alice.renameTeam(slugA, 'Acme Labs');
    expectStatus('alice renames her team', rename, [302, 303]);
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
        'bob cannot claim the slug alice\'s team still holds',
        claim.status === 422 || (claim.status === 302 && !claimedLocation.includes(`/settings/teams/${slugA}`)),
        `status ${claim.status}, redirected to ${claimedLocation}`,
    );
    expectStatus(
        'bob cannot open alice\'s team settings after the claim attempt',
        await bob.request(`/settings/teams/${slugA}`, { headers: { Accept: 'text/html' } }),
        [403, 404],
    );
    const hijack = await bob.accessTokenFor(slugA);
    check('bob cannot obtain a token naming alice\'s team through OAuth consent', !hijack.token, 'a token was issued');

    // The decisive check: whatever bob did, alice's data is still unreachable for him.
    // If the attack produced a token that names alice's team, that token is the
    // exploit, so it is the one that must be refused by the Worker.
    await assertCannotTouch({ label: 'bob after the claim attempt -> acme', token: b.token, victimOrg: slugA, victimId: idA });

    if (hijack.token) {
        await assertCannotTouch({ label: 'bob with the token naming acme -> acme', token: hijack.token, victimOrg: slugA, victimId: idA });
    }
    expectStatus('alice can still serve her artifact after the rename', await get(`/p/${idA}`, a.token), [200]);

    console.log(failures === 0 ? '\nALL ISOLATION CHECKS PASSED' : `\n${failures} ISOLATION CHECK(S) FAILED`);
    process.exit(failures === 0 ? 0 : 1);
}

main().catch((error) => {
    console.error(`\nERROR ${error.message}`);
    process.exit(2);
});
