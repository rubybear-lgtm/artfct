import { spawnSync } from 'node:child_process';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const root = resolve(dirname(fileURLToPath(import.meta.url)), '..');
const backend = resolve(root, 'backend');
const wrangler = resolve(root, 'node_modules/.bin/wrangler');

function run(command, args) {
    const result = spawnSync(command, args, {
        cwd: backend,
        env: process.env,
        encoding: 'utf8',
    });

    if (result.stdout) {
        process.stdout.write(result.stdout);
    }

    if (result.stderr) {
        process.stderr.write(result.stderr);
    }

    if (result.status !== 0) {
        process.exit(result.status ?? 1);
    }

    return `${result.stdout ?? ''}\n${result.stderr ?? ''}`;
}

const tag = process.env.GITHUB_SHA ? process.env.GITHUB_SHA.slice(0, 12) : undefined;
const message = tag ? `Deploy ${tag}` : 'Deploy Worker version';
const uploadArgs = ['versions', 'upload', '--message', message];

if (tag) {
    uploadArgs.push('--tag', tag);
}

const uploadOutput = run(wrangler, uploadArgs);
const versionId = uploadOutput.match(/Worker Version ID:\s*([a-f0-9-]+)/i)?.[1];

if (!versionId) {
    console.error('Unable to determine uploaded Worker version ID from Wrangler output.');
    process.exit(1);
}

run(wrangler, ['versions', 'deploy', `${versionId}@100`, '--yes', '--message', message]);
