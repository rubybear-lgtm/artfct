import assert from 'node:assert/strict';
import { test } from 'node:test';

import {
    readExpectedMcpToolNames,
    readFlaggedMcpToolNames,
} from './expected-tools.mjs';

test('expected MCP tools are read from ArtfctServer.php (not hard-coded)', () => {
    const names = readExpectedMcpToolNames();
    assert.ok(names.includes('deploy_artifact'));
    assert.ok(names.includes('set_avatar'));
    assert.ok(names.includes('set_preference'));
    assert.equal(names.length, 19);
    // Flag-gated tools (the artifact database) are not part of the base catalog.
    assert.ok(!names.some((name) => name.startsWith('db_')));
    assert.equal(new Set(names).size, names.length);
});

test('flag-gated MCP tools are read separately and never overlap the base catalog', () => {
    const flagged = readFlaggedMcpToolNames();
    const base = readExpectedMcpToolNames();

    assert.deepEqual([...flagged].sort(), [
        'db_batch',
        'db_delete',
        'db_get',
        'db_list',
        'db_schema',
        'db_set',
        'db_update',
    ]);
    assert.ok(flagged.every((name) => !base.includes(name)));
});
