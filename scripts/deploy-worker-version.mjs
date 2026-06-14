import { spawnSync } from 'node:child_process';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const root = resolve(dirname(fileURLToPath(import.meta.url)), '..');
const backend = resolve(root, 'backend');
const wrangler = resolve(root, 'node_modules/.bin/wrangler');
const workerTools = resolve(backend, 'target/worker-tools');

function run(command, args, options = {}) {
    const result = spawnSync(command, args, {
        cwd: options.cwd ?? backend,
        env: {
            ...process.env,
            PATH: [
                '/opt/homebrew/opt/rustup/bin',
                `${process.env.HOME}/.cargo/bin`,
                `${workerTools}/bin`,
                process.env.PATH,
            ]
                .filter(Boolean)
                .join(':'),
        },
        encoding: 'utf8',
        shell: options.shell ?? false,
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

run('sh', [
    '-lc',
    'command -v cargo >/dev/null 2>&1 || (curl https://sh.rustup.rs -sSf | sh -s -- -y --profile minimal)',
]);
run('rustup', ['target', 'add', 'wasm32-unknown-unknown']);
run('cargo', ['install', '-q', 'worker-build', '--version', '0.8.3', '--root', workerTools]);
run('worker-build', ['--release']);

const tag = process.env.GITHUB_SHA ? process.env.GITHUB_SHA.slice(0, 12) : undefined;
const message = tag ? `Deploy ${tag}` : 'Deploy Worker version';
const uploadArgs = ['versions', 'upload', '--message', message];

if (process.env.ARTFCT_WORKER_DEPLOY_DRY_RUN === '1') {
    uploadArgs.push('--dry-run');
}

if (tag) {
    uploadArgs.push('--tag', tag);
}

const uploadOutput = run(wrangler, uploadArgs);

if (process.env.ARTFCT_WORKER_DEPLOY_DRY_RUN === '1') {
    process.exit(0);
}

const versionId = uploadOutput.match(/Worker Version ID:\s*([a-f0-9-]+)/i)?.[1];

if (!versionId) {
    console.error('Unable to determine uploaded Worker version ID from Wrangler output.');
    process.exit(1);
}

run(wrangler, ['versions', 'deploy', `${versionId}@100`, '--yes', '--message', message]);
