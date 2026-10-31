import assert from 'node:assert/strict';
import { test } from 'node:test';

import {
    assertCommitOnDevelop,
    assertFastForwardPossible,
} from './git-gates.mjs';

/**
 * @param {Record<string, { status?: number, stdout?: string, stderr?: string }>} map
 */
function gitStub(map) {
    return (args) => {
        const key = args.join(' ');
        const hit = map[key];
        if (!hit) {
            throw new Error(`unexpected git args: ${key}`);
        }

        return {
            status: hit.status ?? 0,
            stdout: hit.stdout ?? '',
            stderr: hit.stderr ?? '',
        };
    };
}

test('A1: rejects a commit that is not on develop', () => {
    const result = assertCommitOnDevelop({
        sha: 'deadbeef',
        developRef: 'refs/remotes/origin/develop',
        runGit: gitStub({
            'merge-base --is-ancestor deadbeef refs/remotes/origin/develop': {
                status: 1,
                stdout: '',
            },
            'rev-parse --verify deadbeef^{commit}': {
                status: 0,
                stdout: 'deadbeef\n',
            },
        }),
    });

    assert.equal(result.ok, false);
    assert.match(result.message, /not on develop/i);
    assert.equal(result.code, 'not_on_develop');
});

test('A2: rejects a non-fast-forward with a fix-it message and does not push', () => {
    const calls = [];
    const runGit = (args) => {
        calls.push(args.join(' '));
        if (args.join(' ') === 'merge-base --is-ancestor refs/remotes/origin/main abc123') {
            return { status: 1, stdout: '', stderr: '' };
        }
        if (args.join(' ') === 'rev-parse --verify abc123^{commit}') {
            return { status: 0, stdout: 'abc123\n', stderr: '' };
        }
        throw new Error(`unexpected git args: ${args.join(' ')}`);
    };

    const result = assertFastForwardPossible({
        sha: 'abc123',
        mainRef: 'refs/remotes/origin/main',
        runGit,
    });

    assert.equal(result.ok, false);
    assert.equal(result.code, 'not_fast_forward');
    assert.match(result.message, /fast-forward/i);
    assert.match(result.message, /main/i);
    assert.match(
        result.message,
        /sync|merge main into develop|ancestor|fix/i,
    );
    assert.equal(
        calls.some((c) => c.includes('push')),
        false,
        'gate must not push',
    );
});

test('A5 git half: accepts when the commit is on develop and main can fast-forward', () => {
    const resultOnDevelop = assertCommitOnDevelop({
        sha: 'goodsha',
        developRef: 'refs/remotes/origin/develop',
        runGit: gitStub({
            'rev-parse --verify goodsha^{commit}': {
                status: 0,
                stdout: 'goodsha\n',
            },
            'merge-base --is-ancestor goodsha refs/remotes/origin/develop': {
                status: 0,
                stdout: '',
            },
        }),
    });
    assert.equal(resultOnDevelop.ok, true);

    const resultFf = assertFastForwardPossible({
        sha: 'goodsha',
        mainRef: 'refs/remotes/origin/main',
        runGit: gitStub({
            'merge-base --is-ancestor refs/remotes/origin/main goodsha': {
                status: 0,
                stdout: '',
            },
        }),
    });
    assert.equal(resultFf.ok, true);
});
