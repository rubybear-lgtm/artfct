import assert from 'node:assert/strict';
import { test } from 'node:test';

import { createGithubClient, prsFromCommitMessage } from './github.mjs';

test('A7: first-release commit walk still collects merged PRs', async () => {
    const calls = [];
    const client = createGithubClient({
        token: 'test',
        repo: 'rubybear-lgtm/artfct',
        fetchImpl: async (url) => {
            calls.push(String(url));
            assert.match(String(url), /\/commits\?sha=abcdef/);

            return new Response(
                JSON.stringify([
                    {
                        commit: {
                            message:
                                'Merge pull request #99 from rubybear-lgtm/cursor/x\n\nRUB-515: MCP tool fixes',
                        },
                    },
                    {
                        commit: {
                            message: 'rub-515: something (#97)\n',
                        },
                    },
                ]),
                { status: 200, headers: { 'content-type': 'application/json' } },
            );
        },
    });

    const prs = await client.listMergedPrs({ fromRef: null, toSha: 'abcdef' });

    assert.deepEqual(
        prs.map((pr) => pr.number),
        [97, 99],
    );
    assert.equal(calls.length, 1);
});

test('prsFromCommitMessage parses merge and squash subjects', () => {
    assert.deepEqual(
        prsFromCommitMessage(
            'Merge pull request #12 from a/b\n\nHello',
            'o/r',
        ),
        [
            {
                number: 12,
                title: 'Hello',
                url: 'https://github.com/o/r/pull/12',
            },
        ],
    );
});
