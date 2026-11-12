import assert from 'node:assert/strict';
import {
    existsSync,
    mkdirSync,
    mkdtempSync,
    readFileSync,
    writeFileSync,
} from 'node:fs';
import { tmpdir } from 'node:os';
import { dirname, join, resolve } from 'node:path';
import { test } from 'node:test';
import { fileURLToPath } from 'node:url';

import { PLUGIN_SKILLS, skillDifferences, syncSkills } from './sync-skills.mjs';

const repoSkills = join(
    resolve(dirname(fileURLToPath(import.meta.url)), '..', '..'),
    'skills',
);

function emptyTarget() {
    return join(mkdtempSync(join(tmpdir(), 'artfct-plugin-')), 'skills');
}

test('every plugin skill exists in this repo and the plugin leaves out artfct-init', () => {
    for (const skill of PLUGIN_SKILLS) {
        assert.ok(
            existsSync(join(repoSkills, skill, 'SKILL.md')),
            `${skill} has no SKILL.md`,
        );
    }

    assert.ok(
        !PLUGIN_SKILLS.includes('artfct-init'),
        'artfct-init needs Node and must stay out of the plugin',
    );
});

test('no plugin skill needs a terminal command to work', () => {
    for (const skill of PLUGIN_SKILLS) {
        const text = readFileSync(join(repoSkills, skill, 'SKILL.md'), 'utf8');
        assert.doesNotMatch(
            text,
            /npx |init\.mjs/,
            `${skill} mentions npx or the init script`,
        );
    }
});

test('syncing makes the copy match, and --check sees later edits and stale files', () => {
    const target = emptyTarget();

    assert.ok(
        skillDifferences(repoSkills, target).length > 0,
        'an empty target should differ',
    );

    syncSkills(repoSkills, target);
    assert.deepEqual(skillDifferences(repoSkills, target), []);

    writeFileSync(join(target, 'artfct-publish', 'SKILL.md'), 'edited by hand');
    mkdirSync(join(target, 'artfct-init'));
    writeFileSync(
        join(target, 'artfct-init', 'SKILL.md'),
        'should not be here',
    );

    const differences = skillDifferences(repoSkills, target);
    assert.ok(differences.includes('changed: artfct-publish/SKILL.md'));
    assert.ok(differences.includes('stale: artfct-init/'));

    syncSkills(repoSkills, target);
    assert.deepEqual(skillDifferences(repoSkills, target), []);
    assert.ok(!existsSync(join(target, 'artfct-init')));
});
