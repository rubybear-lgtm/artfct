import assert from 'node:assert/strict';
import { test } from 'node:test';

import { buildReleaseNotes, formatReleaseTag } from './release-notes.mjs';

test('A7: formats release-YYYYMMDD-HHMM in UTC', () => {
    const tag = formatReleaseTag(new Date('2026-11-01T15:07:30.000Z'));
    assert.equal(tag, 'release-20261101-1507');
});

test('A7: release notes list PRs since the previous tag from fixture history', () => {
    const prs = [
        {
            number: 99,
            title: 'RUB-515: MCP tool fixes',
            url: 'https://github.com/rubybear-lgtm/artfct/pull/99',
        },
        {
            number: 97,
            title: 'Sync main into develop',
            url: 'https://github.com/rubybear-lgtm/artfct/pull/97',
        },
    ];

    const notes = buildReleaseNotes({
        previousTag: 'release-20261030-1200',
        headSha: 'abcdef',
        now: new Date('2026-11-01T15:07:30.000Z'),
        listMergedPrs: ({ fromRef, toSha }) => {
            assert.equal(fromRef, 'release-20261030-1200');
            assert.equal(toSha, 'abcdef');
            return prs;
        },
    });

    assert.equal(notes.tagName, 'release-20261101-1507');
    assert.deepEqual(notes.prs, prs);
    assert.match(notes.body, /#99/);
    assert.match(notes.body, /RUB-515: MCP tool fixes/);
    assert.match(notes.body, /#97/);
    assert.match(notes.body, /release-20261030-1200/);
});

test('A7: first release (no previous tag) still lists PRs and uses the UTC tag', () => {
    const notes = buildReleaseNotes({
        previousTag: null,
        headSha: 'abcdef',
        now: new Date('2026-11-01T09:00:00.000Z'),
        listMergedPrs: ({ fromRef, toSha }) => {
            assert.equal(fromRef, null);
            assert.equal(toSha, 'abcdef');
            return [
                {
                    number: 1,
                    title: 'Initial',
                    url: 'https://github.com/rubybear-lgtm/artfct/pull/1',
                },
            ];
        },
    });

    assert.equal(notes.tagName, 'release-20261101-0900');
    assert.match(notes.body, /#1/);
    assert.match(notes.body, /Initial/);
});
