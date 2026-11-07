import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { test } from 'node:test';
import { fileURLToPath } from 'node:url';

const root = resolve(dirname(fileURLToPath(import.meta.url)), '../..');

function read(relativePath) {
    return readFileSync(resolve(root, relativePath), 'utf8');
}

test('ci.yml keeps required isolated gates and never waits on Railway preview status', () => {
    const ci = read('.github/workflows/ci.yml');

    assert.match(ci, /^name:\s*ci\b/m);
    assert.match(ci, /^\s+application:\s*$/m);
    assert.match(
        ci,
        /name:\s*'Release readiness: OpenAPI, contract and hosted MCP checks'/,
    );
    assert.match(ci, /name:\s*Pest \(browser\)/);
    assert.match(
        ci,
        /name:\s*MCP local end-to-end \(Postgres \+ Worker \+ hosted OAuth\)/,
    );
    assert.match(ci, /name:\s*Hosted MCP application contract gate/);
    assert.match(ci, /^\s+secrets:\s*$/m);

    assert.doesNotMatch(ci, /up\.railway\.app/);
    assert.doesNotMatch(ci, /web-artfct-pr-/);
    assert.doesNotMatch(ci, /environment:\s*staging/);
    assert.doesNotMatch(ci, /MCP_LIVE_TOKEN_/);
    assert.doesNotMatch(ci, /RAILWAY_API_TOKEN/);
    assert.doesNotMatch(ci, /prDeploy/i);
});

test('mcp-live.yml is dispatch-only, staging-environment gated, and validates the target before smoke', () => {
    const workflow = read('.github/workflows/mcp-live.yml');

    assert.match(workflow, /^name:\s*mcp-live\b/m);
    assert.match(workflow, /workflow_dispatch:/);
    assert.doesNotMatch(workflow, /pull_request:/);
    assert.doesNotMatch(workflow, /push:/);
    assert.match(workflow, /environment:\s*staging/);
    assert.match(workflow, /scripts\/staging\/assert-target\.mjs/);
    assert.match(workflow, /scripts\/mcp-live-smoke\.mjs/);
    assert.doesNotMatch(workflow, /artfct\.dev(?!\s)/); // default may mention staging only
    assert.match(workflow, /staging\.artfct\.dev/);
});

test('staging-acceptance.yml is opt-in, verifies SHA, and never triggers on every PR', () => {
    const workflow = read('.github/workflows/staging-acceptance.yml');

    assert.match(workflow, /^name:\s*Staging acceptance\b/m);
    assert.match(workflow, /workflow_dispatch:/);
    assert.doesNotMatch(workflow, /pull_request:/);
    assert.doesNotMatch(workflow, /^\s+push:/m);
    assert.match(workflow, /environment:\s*staging/);
    assert.match(workflow, /inputs:\s*\n\s+sha:/);
    assert.match(workflow, /authorize_mutations:/);
    assert.match(workflow, /scripts\/staging\/accept\.mjs/);
    assert.doesNotMatch(workflow, /up\.railway\.app/);
    assert.doesNotMatch(workflow, /web-artfct-pr-/);
});

test('ci.yml runs staging script tests and actionlint without renaming application', () => {
    const ci = read('.github/workflows/ci.yml');

    assert.match(ci, /^\s+staging_scripts:\s*$/m);
    assert.match(ci, /node --test scripts\/staging\/\*\.test\.mjs/);
    assert.match(ci, /^\s+actionlint:\s*$/m);
    assert.match(ci, /run:\s*actionlint -color/);
    assert.match(
        ci,
        /needs:[\s\S]*staging_scripts[\s\S]*actionlint[\s\S]*secrets/,
    );
    // Required status check name must stay exactly `application`.
    assert.match(ci, /^\s+application:\s*$/m);
});

test('Railway IaC files only describe staging and production, not PR preview envs', () => {
    const staging = read('.railway/staging.ts');
    const production = read('.railway/production.ts');
    const readme = read('.railway/README.md');

    assert.match(staging, /branch:\s*"develop"/);
    assert.match(staging, /staging\.artfct\.dev/);
    assert.doesNotMatch(staging, /prDeploy/i);
    assert.doesNotMatch(staging, /previewEnvironment/i);
    assert.doesNotMatch(staging, /artfct-pr-/);

    assert.match(production, /checkSuites:\s*true/);
    assert.doesNotMatch(production, /prDeploy/i);
    assert.doesNotMatch(production, /previewEnvironment/i);
    assert.doesNotMatch(production, /artfct-pr-/);

    assert.match(readme, /prDeploys/i);
    assert.match(readme, /RUB-519/);
});
