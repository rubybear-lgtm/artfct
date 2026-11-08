import assert from 'node:assert/strict';
import { test } from 'node:test';

import {
    evaluateStagingAcceptanceNeed,
    summarizeAcceptancePath,
} from './policy.mjs';

test('docs-only and copy-only changes stay on isolated CI', () => {
    const decision = evaluateStagingAcceptanceNeed({
        changedPaths: [
            'README.md',
            'docs/mcp-runbook.md',
            'resources/css/app.css',
            'skills/artfct-content/drafts/what-is-mcp.md',
        ],
    });

    assert.equal(decision.warranted, false);
    assert.equal(decision.path, 'isolated-ci-only');
    assert.ok(decision.reasons.length > 0);
});

test('MCP, auth, billing, worker, and migration changes warrant staging acceptance', () => {
    const cases = [
        ['app/Mcp/Tools/DeployArtifactTool.php'],
        ['app/Http/Controllers/Auth/WorkOsController.php'],
        ['app/Services/Billing/StripeBilling.php'],
        ['backend/src/lib.rs'],
        ['database/migrations/2026_11_07_000001_add_thing.php'],
        ['.railway/staging.ts'],
        ['scripts/mcp-live-smoke.mjs'],
    ];

    for (const changedPaths of cases) {
        const decision = evaluateStagingAcceptanceNeed({ changedPaths });
        assert.equal(
            decision.warranted,
            true,
            `${changedPaths[0]} should warrant staging`,
        );
        assert.equal(decision.path, 'staging-acceptance');
    }
});

test('force flag warrants staging even for low-risk paths', () => {
    const decision = evaluateStagingAcceptanceNeed({
        changedPaths: ['README.md'],
        force: true,
    });
    assert.equal(decision.warranted, true);
    assert.equal(decision.path, 'staging-acceptance');
    assert.ok(decision.reasons.some((reason) => /force/i.test(reason)));
});

test('summarizeAcceptancePath explains both paths', () => {
    assert.match(
        summarizeAcceptancePath({
            warranted: false,
            path: 'isolated-ci-only',
            reasons: ['docs only'],
        }),
        /isolated/i,
    );
    assert.match(
        summarizeAcceptancePath({
            warranted: true,
            path: 'staging-acceptance',
            reasons: ['MCP surface'],
        }),
        /staging/i,
    );
});
