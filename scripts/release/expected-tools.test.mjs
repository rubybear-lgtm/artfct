import assert from 'node:assert/strict';
import { test } from 'node:test';

import { readExpectedMcpToolNames } from './expected-tools.mjs';

test('expected MCP tools are read from ArtfctServer.php (not hard-coded)', () => {
    const names = readExpectedMcpToolNames();
    assert.ok(names.includes('deploy_artifact'));
    assert.ok(names.includes('set_avatar'));
    assert.equal(names.length, 18);
    assert.equal(new Set(names).size, names.length);
});
