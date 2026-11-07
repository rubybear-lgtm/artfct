import assert from 'node:assert/strict';
import { test } from 'node:test';

import {
    STAGING_BASE_URL,
    assertSmokeBaseUrl,
    assertStagingOnlyBaseUrl,
    classifyDeployTarget,
    normalizeBaseUrl,
} from './target.mjs';

test('normalizeBaseUrl strips a trailing slash', () => {
    assert.equal(
        normalizeBaseUrl('https://staging.artfct.dev/'),
        'https://staging.artfct.dev',
    );
});

test('staging.artfct.dev is the only accepted live acceptance host', () => {
    assert.equal(classifyDeployTarget(STAGING_BASE_URL), 'staging');
    assert.deepEqual(assertStagingOnlyBaseUrl(STAGING_BASE_URL), {
        ok: true,
        baseUrl: STAGING_BASE_URL,
        target: 'staging',
    });
    assert.deepEqual(assertStagingOnlyBaseUrl(`${STAGING_BASE_URL}/`), {
        ok: true,
        baseUrl: STAGING_BASE_URL,
        target: 'staging',
    });
});

test('production hosts are rejected with no fallback', () => {
    for (const url of [
        'https://artfct.dev',
        'https://artfct.dev/',
        'https://www.artfct.dev',
        'https://www.artfct.dev/mcp',
    ]) {
        assert.equal(classifyDeployTarget(url), 'production');
        const result = assertStagingOnlyBaseUrl(url);
        assert.equal(result.ok, false);
        assert.equal(result.code, 'production_forbidden');
        assert.match(result.message, /production/i);
        assert.match(result.message, /staging\.artfct\.dev/);
    }
});

test('Railway PR preview hosts are rejected', () => {
    for (const url of [
        'https://web-artfct-pr-103.up.railway.app',
        'https://web-artfct-pr-101.up.railway.app',
        'https://staging-web-artfct-pr-88.up.railway.app',
        'https://something.up.railway.app',
    ]) {
        assert.equal(classifyDeployTarget(url), 'preview');
        const result = assertStagingOnlyBaseUrl(url);
        assert.equal(result.ok, false);
        assert.equal(result.code, 'preview_forbidden');
        assert.match(result.message, /preview/i);
    }
});

test('missing, blank, and invalid targets fail closed', () => {
    for (const url of [undefined, null, '', '   ', 'not-a-url', 'ftp://staging.artfct.dev']) {
        const result = assertStagingOnlyBaseUrl(url);
        assert.equal(result.ok, false);
        assert.ok(
            result.code === 'missing_target' || result.code === 'invalid_target',
            `unexpected code ${result.code} for ${String(url)}`,
        );
    }
});

test('non-staging artfct subdomains are unknown and rejected', () => {
    const result = assertStagingOnlyBaseUrl('https://canary.artfct.dev');
    assert.equal(result.ok, false);
    assert.equal(result.code, 'unknown_target');
});

test('local e2e loopback is allowed for smoke but not for staging acceptance', () => {
    assert.equal(classifyDeployTarget('http://127.0.0.1:8080'), 'local');
    const smoke = assertSmokeBaseUrl('http://127.0.0.1:8080');
    assert.equal(smoke.ok, true);
    assert.equal(smoke.target, 'local');
    assert.equal(smoke.baseUrl, 'http://127.0.0.1:8080');

    const acceptance = assertStagingOnlyBaseUrl('http://127.0.0.1:8080');
    assert.equal(acceptance.ok, false);
    assert.equal(acceptance.code, 'local_forbidden');
});

test('smoke entry also rejects production and preview hosts', () => {
    assert.equal(assertSmokeBaseUrl('https://artfct.dev').ok, false);
    assert.equal(
        assertSmokeBaseUrl('https://web-artfct-pr-103.up.railway.app').ok,
        false,
    );
});
