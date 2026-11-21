import assert from 'node:assert/strict';
import { existsSync, readFileSync } from 'node:fs';
import { dirname, join, resolve } from 'node:path';
import { test } from 'node:test';
import { fileURLToPath } from 'node:url';

const repo = resolve(dirname(fileURLToPath(import.meta.url)), '..', '..');
const skillsDir = join(repo, 'skills');
const manifestPath = join(skillsDir, 'pack.json');

const PACK_SKILLS = [
    'artfct',
    'artfct-search',
    'artfct-publish',
    'artfct-collaborate',
    'artfct-context-post',
    'artfct-team-doc',
    'presentation',
];
const TYPE_SKILLS = ['artfct-context-post', 'artfct-team-doc', 'presentation'];
const PUBLISH_TOPIC_SKILLS = ['artfct-context-post', 'artfct-team-doc'];
const CONNECT_PAGES = [
    'https://artfct.dev/connect/connect-claude)',
    'https://artfct.dev/connect/connect-cursor)',
    'https://artfct.dev/connect/connect-codex)',
    'https://artfct.dev/connect/connect-chatgpt)',
];

const FORBIDDEN_PATTERN = /<script\b|\bon[a-z]+\s*=|data:image\//i;
const NEGATION_PATTERN = /\b(not|never|no|don't|do not|blocked|without|avoid|cannot)\b/i;

function skillText(name) {
    return readFileSync(join(skillsDir, name, 'SKILL.md'), 'utf8');
}

function frontmatterValue(text, key) {
    const block = text.match(/^---\n([\s\S]*?)\n---/);
    assert.ok(block, 'SKILL.md has no frontmatter block');
    const line = block[1].split('\n').find((row) => row.startsWith(`${key}:`));
    assert.ok(line, `frontmatter has no ${key}`);

    return line
        .slice(key.length + 1)
        .trim()
        .replace(/^"(.*)"$/, '$1');
}

function readme() {
    return readFileSync(join(repo, 'README.md'), 'utf8');
}

function skillPackSection() {
    const lines = readme().split('\n');
    const start = lines.findIndex((line) => line === '### Skill pack');
    assert.notEqual(start, -1, 'README has no "### Skill pack" section');
    const end = lines.findIndex((line, index) => index > start && line.startsWith('#'));

    return lines.slice(start, end === -1 ? undefined : end).join('\n');
}

test('the pack lists exactly the seven skills, each with a folder and SKILL.md', () => {
    assert.ok(existsSync(manifestPath), 'skills/pack.json is missing');

    const pack = JSON.parse(readFileSync(manifestPath, 'utf8'));
    assert.deepEqual(pack.skills, PACK_SKILLS);

    for (const skill of PACK_SKILLS) {
        assert.ok(existsSync(join(skillsDir, skill, 'SKILL.md')), `${skill} has no SKILL.md`);
    }
});

test('each pack skill has a folder, a SKILL.md with name equal to its folder, and a description of at most 1024 characters', () => {
    for (const skill of PACK_SKILLS) {
        const description = frontmatterValue(skillText(skill), 'description');

        assert.equal(frontmatterValue(skillText(skill), 'name'), skill, `${skill} name differs from its folder`);
        assert.ok(description.length <= 1024, `${skill} description is ${description.length} characters`);
    }
});

test('every pack description starts with "Use when" except presentation', () => {
    for (const skill of PACK_SKILLS.filter((skill) => skill !== 'presentation')) {
        const description = frontmatterValue(skillText(skill), 'description');

        assert.ok(description.startsWith('Use when'), `${skill} description does not start with "Use when"`);
    }
});

test('no type skill tells a bot to use a script, an on-handler or a data image', () => {
    for (const skill of TYPE_SKILLS) {
        const offending = skillText(skill)
            .split('\n')
            .filter((line) => FORBIDDEN_PATTERN.test(line) && !NEGATION_PATTERN.test(line));

        assert.deepEqual(offending, [], `${skill} tells a bot to use a forbidden pattern`);
    }
});

test('the context-post and team-doc skills name get_guidance topic publish', () => {
    for (const skill of PUBLISH_TOPIC_SKILLS) {
        assert.match(
            skillText(skill),
            /get_guidance[^\n]*topic `publish`/,
            `${skill} does not name get_guidance topic publish`,
        );
    }
});

test('README has a Skill pack section with the seven skills and the four connect pages, and no install steps', () => {
    const section = skillPackSection();

    for (const skill of PACK_SKILLS) {
        assert.match(section, new RegExp(`^- \`${skill}\``, 'm'), `${skill} is not listed on one line`);
    }

    for (const page of CONNECT_PAGES) {
        assert.ok(section.includes(page), `section does not link ${page}`);
    }

    assert.doesNotMatch(
        section,
        /npx |claude mcp add|codex mcp |opencode mcp|agy mcp/,
        'the Skill pack section repeats install steps',
    );
});
