#!/usr/bin/env node
// RUB-435: tenant isolation, end to end. Two real teams, created by two real
// signed-in users through the app's own flows (dev-login stand-in, team
// creation, OAuth consent), and credentials minted by Laravel, not fabricated.
// Every check then tries to cross from one team into the other, against the
// real Laravel app and the real Worker.
//
// Shared helpers for the isolation suite (scripts/tenant-isolation-e2e.mjs and
// scripts/isolation/*.mjs). Requires AUTHKIT_DEV_LOGIN_ENABLED=true on the
// target (never true in production).
import { execFileSync } from 'node:child_process';
import { createHash, randomBytes } from 'node:crypto';
import { readFileSync } from 'node:fs';

export const laravel = (
    process.env.ISO_LARAVEL_URL ?? 'http://127.0.0.1:8990'
).replace(/\/$/, '');
export const worker = (
    process.env.ISO_WORKER_URL ?? 'http://127.0.0.1:8991'
).replace(/\/$/, '');
export const run = randomBytes(3).toString('hex');
export const SCOPES =
    'artifacts:read artifacts:deploy artifacts:delete collections:read collections:write usage:read';

let failures = 0;
export const failureCount = () => failures;

export function check(name, ok, detail = '') {
    if (!ok) {
        failures += 1;
    }

    console.log(
        `${ok ? 'ok  ' : 'FAIL'} ${name}${ok ? '' : detail ? ` (${detail})` : ''}`,
    );
}

export function expectStatus(name, response, allowed) {
    check(
        name,
        allowed.includes(response.status),
        `expected ${allowed.join(' or ')}, got ${response.status}`,
    );
}

/** Retry a request once after the limiter's Retry-After if it answered 429. */
export async function retry429(send) {
    const first = await send();

    if (first.status !== 429) {
        return first;
    }

    const wait = Math.min(Number(first.headers.get('Retry-After') ?? 60), 70);
    await new Promise((resolve) => setTimeout(resolve, (wait + 1) * 1000));

    return send();
}

/** A browser-like session: cookie jar plus CSRF, over plain HTTP. */
export class Session {
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

        const wait = Math.min(
            Number(first.headers.get('Retry-After') ?? 60),
            70,
        );
        await new Promise((resolve) => setTimeout(resolve, (wait + 1) * 1000));

        return this.send(path, options);
    }

    async send(path, { method = 'GET', json, headers = {} } = {}) {
        const response = await fetch(
            path.startsWith('http') ? path : `${laravel}${path}`,
            {
                method,
                redirect: 'manual',
                headers: {
                    Accept: 'application/json',
                    Cookie: this.cookie(),
                    'X-XSRF-TOKEN': this.xsrf(),
                    ...(json === undefined
                        ? {}
                        : { 'Content-Type': 'application/json' }),
                    ...headers,
                },
                body: json === undefined ? undefined : JSON.stringify(json),
            },
        );
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
            throw new Error(
                `dev-login answered ${login.status}; is AUTHKIT_DEV_LOGIN_ENABLED=true?`,
            );
        }

        const authenticate = await this.request(login.headers.get('Location'));

        if (authenticate.status !== 302) {
            throw new Error(`/authenticate answered ${authenticate.status}`);
        }

        const terms = await this.request('/terms/accept', {
            method: 'POST',
            json: { accepted: true },
        });

        if (![200, 302, 303].includes(terms.status)) {
            throw new Error(`terms acceptance answered ${terms.status}`);
        }
    }

    /** Inertia page props from a plain GET of a page (the data-page JSON in the HTML). */
    async pageProps(path) {
        const response = await this.request(path, {
            headers: { Accept: 'text/html' },
        });
        const match = (await response.text()).match(
            /<script data-page="app" type="application\/json">(.+?)<\/script>/s,
        );

        return {
            status: response.status,
            props: match ? (JSON.parse(match[1]).props ?? {}) : null,
        };
    }

    createTeam(name, slug) {
        return this.request('/settings/teams', {
            method: 'POST',
            json: { name, slug },
        });
    }

    renameTeam(slug, name) {
        return this.request(`/settings/teams/${slug}`, {
            method: 'PATCH',
            json: { name },
        });
    }

    /** Real OAuth consent as this user for the named team; returns the access token. */
    async accessTokenFor(team, scope = SCOPES) {
        const verifier = randomBytes(48).toString('base64url');
        const challenge = createHash('sha256')
            .update(verifier)
            .digest('base64url');
        const redirectUri = 'http://127.0.0.1:0/callback';
        const parameters = {
            response_type: 'code',
            client_id: 'artfct-cli',
            redirect_uri: redirectUri,
            scope,
            state: randomBytes(8).toString('hex'),
            code_challenge: challenge,
            code_challenge_method: 'S256',
            team,
        };
        const page = await retry429(() =>
            fetch(
                `${laravel}/oauth/authorize?${new URLSearchParams(parameters)}`,
                {
                    headers: { Cookie: this.cookie() },
                    redirect: 'manual',
                },
            ),
        );
        this.capture(page);

        if (page.status !== 200) {
            return { refused: page.status };
        }

        const match = (await page.text()).match(
            /<script data-page="app" type="application\/json">(.+?)<\/script>/s,
        );
        const consentToken = match
            ? JSON.parse(match[1]).props?.consentToken
            : null;

        if (!consentToken) {
            return { refused: 'no consent token' };
        }

        const consent = await this.request('/oauth/authorize', {
            method: 'POST',
            json: {
                ...parameters,
                decision: 'approve',
                consent_token: consentToken,
            },
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

        const token = await retry429(() =>
            fetch(`${laravel}/oauth/token`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                },
                body: JSON.stringify({
                    grant_type: 'authorization_code',
                    code,
                    client_id: 'artfct-cli',
                    redirect_uri: redirectUri,
                    code_verifier: verifier,
                }),
            }),
        );

        if (!token.ok) {
            return { refused: `token ${token.status}` };
        }

        const body = await token.json();

        return { token: body.access_token, refresh: body.refresh_token };
    }
}

export const authed = (token) => ({ Authorization: `Bearer ${token}` });

/** The create-artifact request, returned as-is so a caller can assert on the refusal. */
export function deployRequest(token, content) {
    const sha = createHash('sha256').update(content).digest('hex');

    return fetch(`${worker}/v1/artifacts`, {
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
}

/** Create a secure permanent artifact in the token's own org through the real deploy path. */
export async function deploy(token, content) {
    const sha = createHash('sha256').update(content).digest('hex');
    const created = await deployRequest(token, content);

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

export const get = (path, token, init = {}) =>
    fetch(`${worker}${path}`, {
        redirect: 'manual',
        ...init,
        headers: { ...(token ? authed(token) : {}), ...(init.headers ?? {}) },
    });

/** What an outsider's credentials must not be able to do to someone else's artifact. */
export async function assertCannotTouch({ label, token, victimOrg, victimId }) {
    const denied = [403, 404];
    expectStatus(
        `${label}: cannot list ${victimOrg}'s artifacts`,
        await get(`/v1/orgs/${victimOrg}/artifacts`, token),
        denied,
    );
    expectStatus(
        `${label}: cannot export ${victimOrg}`,
        await get(`/v1/orgs/${victimOrg}/export`, token),
        denied,
    );
    expectStatus(
        `${label}: cannot read ${victimOrg}'s usage`,
        await get(`/v1/orgs/${victimOrg}/usage`, token),
        denied,
    );
    expectStatus(
        `${label}: cannot read the victim's content`,
        await get(`/v1/orgs/${victimOrg}/artifacts/${victimId}/content`, token),
        denied,
    );
    expectStatus(
        `${label}: cannot read the victim's metadata`,
        await get(`/v1/artifacts/${victimId}`, token),
        denied,
    );
    expectStatus(
        `${label}: cannot serve the victim's secure artifact`,
        await get(`/p/${victimId}`, token),
        [401, 403, 404],
    );
    expectStatus(
        `${label}: cannot download the victim's blob`,
        await get(`/v1/blobs/${'0'.repeat(64)}`, token),
        denied,
    );
    expectStatus(
        `${label}: cannot delete the victim's artifact`,
        await get(`/v1/artifacts/${victimId}`, token, { method: 'DELETE' }),
        denied,
    );
}

// ── helpers that reach past HTTP, for set-up the app has no endpoint for ─────

let stateEnv = null;

/** The environment the stack was started with (APP_KEY, DB_*, ...), from its state file. */
function loadStateEnv() {
    if (stateEnv) {
        return stateEnv;
    }

    stateEnv = {};

    for (const line of readFileSync(
        process.env.ISO_STATE_ENV ?? '.mcp-e2e/env',
        'utf8',
    ).split('\n')) {
        const at = line.indexOf('=');

        if (at > 0) {
            stateEnv[line.slice(0, at)] = line.slice(at + 1);
        }
    }

    return stateEnv;
}

/**
 * Run PHP in the app against the stack's own database and return what it
 * echoed. Used only for set-up with no HTTP route (adding a member without the
 * invitation e-mail, reading ids); every check itself goes over HTTP.
 */
export function tinker(code) {
    const output = execFileSync(
        'php',
        ['artisan', 'tinker', '--execute', code],
        {
            env: { ...process.env, ...loadStateEnv() },
            encoding: 'utf8',
            stdio: ['ignore', 'pipe', 'pipe'],
        },
    );

    return output.trim().split('\n').at(-1).trim();
}

/** Poll until the check passes or the time runs out (revocation reaches the Worker asynchronously). */
export async function eventually(
    fn,
    { timeoutMs = 20000, intervalMs = 500 } = {},
) {
    const deadline = Date.now() + timeoutMs;
    let last;

    while (Date.now() < deadline) {
        last = await fn();

        if (last.ok) {
            return last;
        }

        await new Promise((resolve) => setTimeout(resolve, intervalMs));
    }

    return last;
}

/** `php artisan <args>` against the stack's environment, as text. */
export function artisan(args) {
    return execFileSync('php', ['artisan', ...args], {
        env: { ...process.env, ...loadStateEnv() },
        encoding: 'utf8',
        maxBuffer: 32 * 1024 * 1024,
        stdio: ['ignore', 'pipe', 'ignore'],
    });
}

/** Whether the stack was started with indexing on (the re-index route only checks ownership then). */
export function indexingEnabled() {
    return ['1', 'true'].includes(
        String(loadStateEnv().INDEXING_ENABLED ?? '').toLowerCase(),
    );
}
