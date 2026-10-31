import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { test } from 'node:test';
import { fileURLToPath } from 'node:url';

import { parseArgs } from './release.mjs';

const root = resolve(dirname(fileURLToPath(import.meta.url)), '../..');
const workflowPath = resolve(
    root,
    '.github/workflows/release-to-production.yml',
);

test('B9: workflow_dispatch exposes sha and dry_run inputs', () => {
    const yaml = readFileSync(workflowPath, 'utf8');
    assert.match(yaml, /workflow_dispatch:/);
    assert.match(yaml, /\bsha:/);
    assert.match(yaml, /\bdry_run:/);
    assert.match(yaml, /concurrency:/);
    assert.match(yaml, /cancel-in-progress:\s*false/);
    assert.match(yaml, /permissions:/);
    assert.match(yaml, /contents:\s*write/);
    assert.match(yaml, /checks:\s*read/);
    assert.doesNotMatch(yaml, /--force(?:-with-lease)?\b/);
    assert.match(yaml, /git push origin <sha>:main/);
    const releaseSource = readFileSync(
        resolve(root, 'scripts/release/release.mjs'),
        'utf8',
    );
    assert.match(releaseSource, /push',\s*'origin',\s*`\$\{sha\}:main`/);
    assert.doesNotMatch(releaseSource, /--force(?:-with-lease)?\b/);
});

test('B9: release CLI defaults dryRun false and accepts --dry-run', () => {
    assert.deepEqual(parseArgs([]), {
        sha: null,
        dryRun: false,
        help: false,
    });
    assert.deepEqual(parseArgs(['--sha', 'abc', '--dry-run']), {
        sha: 'abc',
        dryRun: true,
        help: false,
    });
});

test('B11: tokens are passed via env and masked; never echoed as values', () => {
    const source = readFileSync(resolve(root, 'scripts/release/release.mjs'), 'utf8');
    const yaml = readFileSync(workflowPath, 'utf8');
    assert.match(source, /maskSecret\(process\.env\.RAILWAY_API_TOKEN\)/);
    assert.match(source, /maskSecret\(process\.env\.ARTFCT_SMOKE_BOT_TOKEN\)/);
    assert.match(
        yaml,
        /RAILWAY_API_TOKEN:\s*\$\{\{\s*secrets\.RAILWAY_API_TOKEN\s*\}\}/,
    );
    assert.match(
        yaml,
        /ARTFCT_SMOKE_BOT_TOKEN:\s*\$\{\{\s*secrets\.ARTFCT_SMOKE_BOT_TOKEN\s*\}\}/,
    );
    assert.match(yaml, /::add-mask::/);
    assert.doesNotMatch(yaml, /echo\s+"\$\{?RAILWAY_API_TOKEN\}?"/);
    assert.doesNotMatch(yaml, /echo\s+"\$\{?ARTFCT_SMOKE_BOT_TOKEN\}?"/);
});
