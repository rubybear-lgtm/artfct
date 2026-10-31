#!/usr/bin/env node
/**
 * Production release orchestrator (RUB-517).
 *
 * Usage:
 *   node scripts/release/release.mjs --sha <sha> [--dry-run]
 *
 * Env:
 *   GITHUB_TOKEN / GH_TOKEN — GitHub API (checks, PRs, release)
 *   RAILWAY_API_TOKEN — Railway GraphQL (deploy poll)
 *   ARTFCT_SMOKE_BOT_TOKEN — production MCP Bearer smoke
 *   GITHUB_REPOSITORY — owner/repo (default rubybear-lgtm/artfct)
 *   GITHUB_STEP_SUMMARY — optional job summary path
 */

import { spawnSync } from 'node:child_process';
import { appendFileSync, writeFileSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

import { assertRequiredChecksSucceeded } from './ci-checks.mjs';
import {
    assertCommitOnDevelop,
    assertFastForwardPossible,
} from './git-gates.mjs';
import { createGithubClient, maskSecret } from './github.mjs';
import { createRailwayGraphql } from './railway-api.mjs';
import { waitForProductionDeployments } from './railway-poller.mjs';
import { buildReleaseNotes } from './release-notes.mjs';
import { runProductionSmoke } from './smoke.mjs';

const root = resolve(dirname(fileURLToPath(import.meta.url)), '../..');

export const RAILWAY_PROJECT_ID = 'f54567a7-a1fe-4d1d-ae2c-02477781226c';
export const RAILWAY_PRODUCTION_ENVIRONMENT_ID =
    'ac8c4e43-6051-41f1-9c6d-05f2aa0b29a2';

/**
 * @param {string[]} argv
 */
export function parseArgs(argv) {
    /** @type {{ sha: string | null, dryRun: boolean, help: boolean }} */
    const options = { sha: null, dryRun: false, help: false };

    for (let i = 0; i < argv.length; i += 1) {
        const arg = argv[i];

        if (arg === '--sha') {
            options.sha = argv[++i] ?? null;
        } else if (arg.startsWith('--sha=')) {
            options.sha = arg.slice('--sha='.length);
        } else if (arg === '--dry-run') {
            options.dryRun = true;
        } else if (arg === '--help' || arg === '-h') {
            options.help = true;
        } else {
            throw new Error(`Unknown argument: ${arg}`);
        }
    }

    return options;
}

/**
 * @param {string[]} args
 */
function runGit(args) {
    const result = spawnSync('git', args, {
        cwd: root,
        encoding: 'utf8',
    });

    return {
        status: result.status ?? 1,
        stdout: result.stdout ?? '',
        stderr: result.stderr ?? '',
    };
}

/**
 * @param {string} message
 * @param {string} [level]
 */
function log(message, level = 'notice') {
    console.log(`::${level}::${message}`);
    console.log(message);
}

/**
 * @param {string} markdown
 */
function writeSummary(markdown) {
    const path = process.env.GITHUB_STEP_SUMMARY;

    if (path) {
        appendFileSync(path, `${markdown}\n`);
    }

    console.log(markdown);
}

/**
 * @returns {string | null}
 */
function previousReleaseTag() {
    const listed = runGit([
        'tag',
        '-l',
        'release-*',
        '--sort=-creatordate',
    ]);

    if (listed.status !== 0) {
        return null;
    }

    const tags = listed.stdout
        .split('\n')
        .map((line) => line.trim())
        .filter(Boolean);

    return tags[0] ?? null;
}

/**
 * @param {string | null} tag
 */
function commitForTag(tag) {
    if (!tag) {
        return null;
    }

    const resolved = runGit(['rev-list', '-n', '1', tag]);

    if (resolved.status !== 0) {
        return null;
    }

    return resolved.stdout.trim() || null;
}

async function main() {
    const options = parseArgs(process.argv.slice(2));

    if (options.help) {
        console.log(
            'Usage: node scripts/release/release.mjs --sha <sha> [--dry-run]',
        );
        process.exit(0);
    }

    maskSecret(process.env.GITHUB_TOKEN);
    maskSecret(process.env.GH_TOKEN);
    maskSecret(process.env.RAILWAY_API_TOKEN);
    maskSecret(process.env.ARTFCT_SMOKE_BOT_TOKEN);

    // Also mask common accidental prints of Authorization bearer values.
    if (process.env.ARTFCT_SMOKE_BOT_TOKEN) {
        maskSecret(`Bearer ${process.env.ARTFCT_SMOKE_BOT_TOKEN}`);
    }

    if (process.env.RAILWAY_API_TOKEN) {
        maskSecret(`Bearer ${process.env.RAILWAY_API_TOKEN}`);
    }

    const githubToken = process.env.GITHUB_TOKEN || process.env.GH_TOKEN;

    if (!githubToken) {
        throw new Error('GITHUB_TOKEN (or GH_TOKEN) is required');
    }

    const repo = process.env.GITHUB_REPOSITORY || 'rubybear-lgtm/artfct';
    const github = createGithubClient({ token: githubToken, repo });

    const fetchDevelop = runGit(['fetch', 'origin', 'develop', 'main', '--tags']);

    if (fetchDevelop.status !== 0) {
        throw new Error(`git fetch failed: ${fetchDevelop.stderr}`);
    }

    let sha = options.sha;

    if (!sha || sha.trim() === '' || sha === 'develop') {
        const head = runGit(['rev-parse', 'refs/remotes/origin/develop']);

        if (head.status !== 0) {
            throw new Error('Could not resolve origin/develop');
        }

        sha = head.stdout.trim();
    } else {
        const resolved = runGit(['rev-parse', '--verify', `${sha}^{commit}`]);

        if (resolved.status !== 0) {
            throw new Error(`Could not resolve sha ${sha}`);
        }

        sha = resolved.stdout.trim();
    }

    const previousTag = previousReleaseTag();
    const previousCommit = commitForTag(previousTag);

    /** @type {string[]} */
    const summaryLines = [
        '# Release to production',
        '',
        `- Commit: \`${sha}\``,
        `- Dry run: ${options.dryRun ? 'yes' : 'no'}`,
        `- Previous release tag: \`${previousTag ?? 'none'}\``,
        previousCommit ? `- Previous release commit: \`${previousCommit}\`` : null,
        '',
    ].filter(Boolean);

    const onDevelop = assertCommitOnDevelop({ sha, runGit });

    if (!onDevelop.ok) {
        summaryLines.push(`## Gate failed: not on develop`, '', onDevelop.message);
        writeSummary(summaryLines.join('\n'));

        throw new Error(onDevelop.message);
    }

    summaryLines.push('- Gate: commit is on develop ✅');

    const canFf = assertFastForwardPossible({ sha, runGit });

    if (!canFf.ok) {
        summaryLines.push(
            '',
            '## Gate failed: not a fast-forward',
            '',
            canFf.message,
            '',
            '### Rollback',
            '',
            previousTag
                ? `Previous release \`${previousTag}\` at \`${previousCommit}\`.`
                : 'No previous release tag to roll back to.',
        );
        writeSummary(summaryLines.join('\n'));

        throw new Error(canFf.message);
    }

    summaryLines.push('- Gate: main can fast-forward ✅');

    log(`Waiting for required CI checks on ${sha}`);
    const checks = await assertRequiredChecksSucceeded({
        sha,
        listCheckRuns: (target) => github.listCheckRuns(target),
        listCommitStatuses: (target) => github.listCommitStatuses(target),
        timeoutMs: Number(process.env.RELEASE_CHECKS_TIMEOUT_MS ?? 30 * 60_000),
        pollIntervalMs: Number(process.env.RELEASE_CHECKS_POLL_MS ?? 15_000),
    });

    if (!checks.ok) {
        summaryLines.push(
            '',
            '## Gate failed: CI checks',
            '',
            checks.message,
            '',
            '### Rollback',
            '',
            previousTag
                ? `Previous release \`${previousTag}\` at \`${previousCommit}\`.`
                : 'No previous release tag to roll back to.',
        );
        writeSummary(summaryLines.join('\n'));

        throw new Error(checks.message);
    }

    summaryLines.push('- Gate: required CI checks succeeded ✅ (Railway preview/deploy statuses ignored)');

    const prs = await github.listMergedPrs({
        fromRef: previousTag,
        toSha: sha,
    });
    const releaseNotes = buildReleaseNotes({
        previousTag,
        headSha: sha,
        listMergedPrs: () => prs,
    });

    summaryLines.push(
        `- Planned tag: \`${releaseNotes.tagName}\``,
        `- Planned PRs in notes: ${releaseNotes.prs.map((pr) => `#${pr.number}`).join(', ') || 'none'}`,
    );

    if (options.dryRun) {
        summaryLines.push(
            '',
            '## Dry run',
            '',
            'All gates evaluated. No push, tag, GitHub Release, Railway poll, or smoke test was performed.',
            '',
            'Would run:',
            '',
            '```',
            `git push origin ${sha}:main`,
            `git tag -a ${releaseNotes.tagName} ${sha}`,
            `git push origin refs/tags/${releaseNotes.tagName}`,
            '```',
            '',
            '### Release notes preview',
            '',
            releaseNotes.body,
        );
        writeSummary(summaryLines.join('\n'));
        log('Dry run complete: gates passed; nothing was pushed or tagged.');

        return;
    }

    log(`Fast-forwarding main to ${sha}`);
    const push = runGit(['push', 'origin', `${sha}:main`]);

    if (push.status !== 0) {
        summaryLines.push(
            '',
            '## Push failed',
            '',
            push.stderr || push.stdout || 'git push failed',
            '',
            '### Rollback',
            '',
            previousTag
                ? `Previous release \`${previousTag}\` at \`${previousCommit}\`. main was not updated.`
                : 'main was not updated.',
        );
        writeSummary(summaryLines.join('\n'));

        throw new Error(`Fast-forward push failed: ${push.stderr || push.stdout}`);
    }

    summaryLines.push('- Action: fast-forwarded `main` ✅ (no force)');

    /**
     * @param {string} title
     * @param {string} message
     * @param {string[]} [extra]
     */
    function failAfterPush(title, message, extra = []) {
        summaryLines.push(
            '',
            `## ${title}`,
            '',
            message,
            ...extra,
            '',
            '### Rollback',
            '',
            previousTag
                ? [
                      `Previous release \`${previousTag}\` at \`${previousCommit}\`.`,
                      'main may already point at the new commit; redeploy the previous release commit in Railway or investigate before the next fast-forward.',
                  ].join(' ')
                : 'No previous release tag recorded. main may already point at the new commit.',
        );
        writeSummary(summaryLines.join('\n'));

        throw new Error(message);
    }

    const tagMessage = `Production release ${releaseNotes.tagName}`;
    const tag = runGit([
        'tag',
        '-a',
        releaseNotes.tagName,
        sha,
        '-m',
        tagMessage,
    ]);

    if (tag.status !== 0) {
        failAfterPush('Tag failed', `git tag failed: ${tag.stderr}`);
    }

    const pushTag = runGit(['push', 'origin', `refs/tags/${releaseNotes.tagName}`]);

    if (pushTag.status !== 0) {
        failAfterPush('Tag push failed', `git push tag failed: ${pushTag.stderr}`);
    }

    summaryLines.push(`- Action: created tag \`${releaseNotes.tagName}\` ✅`);

    try {
        await github.createRelease({
            tag: releaseNotes.tagName,
            sha,
            body: releaseNotes.body,
            name: releaseNotes.tagName,
        });
    } catch (error) {
        failAfterPush(
            'GitHub Release failed',
            error instanceof Error ? error.message : String(error),
        );
    }

    summaryLines.push('- Action: created GitHub Release ✅');

    const railwayToken = process.env.RAILWAY_API_TOKEN;

    if (!railwayToken) {
        failAfterPush(
            'Missing Railway token',
            'RAILWAY_API_TOKEN is required after push',
        );
    }

    const graphql = createRailwayGraphql({ token: railwayToken });
    log('Polling Railway production deployments');
    const deploys = await waitForProductionDeployments({
        commitSha: sha,
        projectId: RAILWAY_PROJECT_ID,
        environmentId: RAILWAY_PRODUCTION_ENVIRONMENT_ID,
        graphql,
        timeoutMs: Number(process.env.RELEASE_RAILWAY_TIMEOUT_MS ?? 30 * 60_000),
        pollIntervalMs: Number(process.env.RELEASE_RAILWAY_POLL_MS ?? 20_000),
    });

    if (!deploys.ok) {
        failAfterPush('Railway deploy verification failed', deploys.message, [
            '',
            '### Service snapshot',
            '',
            ...(deploys.services ?? []).map(
                (service) =>
                    `- ${service.name}: ${service.status ?? 'missing'} (${service.commitSha ?? 'no matching commit'})`,
            ),
        ]);
    }

    summaryLines.push('', '## Railway production deployments', '');

    for (const service of deploys.services) {
        summaryLines.push(
            `- ${service.name}: ${service.status} (\`${service.commitSha.slice(0, 12)}\`, deployment \`${service.deploymentId}\`)`,
        );
    }

    const smokeToken = process.env.ARTFCT_SMOKE_BOT_TOKEN;

    if (!smokeToken) {
        failAfterPush(
            'Missing smoke token',
            'ARTFCT_SMOKE_BOT_TOKEN is required for smoke tests',
        );
    }

    log('Running production smoke checks');
    const smoke = await runProductionSmoke({ token: smokeToken });

    if (!smoke.ok) {
        failAfterPush('Smoke failed', smoke.message);
    }

    summaryLines.push(
        '',
        '## Smoke',
        '',
        `- Health /up: HTTP ${smoke.healthStatus} ✅`,
        `- MCP tools/list: ${smoke.toolCount} tools ✅`,
        '',
        'Release complete.',
    );
    writeSummary(summaryLines.join('\n'));

    if (process.env.GITHUB_OUTPUT) {
        writeFileSync(
            process.env.GITHUB_OUTPUT,
            [
                `sha=${sha}`,
                `tag=${releaseNotes.tagName}`,
                `dry_run=${options.dryRun}`,
            ].join('\n'),
            { flag: 'a' },
        );
    }
}

const isDirectRun =
    process.argv[1] &&
    resolve(process.argv[1]) === fileURLToPath(import.meta.url);

if (isDirectRun) {
    main().catch((error) => {
        console.error(error instanceof Error ? error.message : error);
        process.exit(1);
    });
}
