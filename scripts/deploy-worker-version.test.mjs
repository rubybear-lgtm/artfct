import assert from 'node:assert/strict';
import { spawnSync } from 'node:child_process';
import { mkdtempSync, rmSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { dirname, join, resolve } from 'node:path';
import { test } from 'node:test';
import { fileURLToPath } from 'node:url';

import {
    buildStatusArgs,
    buildTriggersArgs,
    buildUploadArgs,
    buildVersionDeployArgs,
    isSmokeSuccess,
    parseArgs,
    parseCurrentVersionId,
    parseJsonc,
    parseVersionId,
    readWranglerConfig,
    rollbackCommand,
    validateWranglerConfig,
} from './deploy-worker-version.mjs';

const root = resolve(dirname(fileURLToPath(import.meta.url)), '..');
const script = resolve(root, 'scripts/deploy-worker-version.mjs');
const productionConfig = resolve(root, 'backend/wrangler.jsonc');
const stagingConfig = resolve(root, 'backend/wrangler.staging.jsonc');

function validConfig() {
    return {
        name: 'artfct-engine',
        routes: [{ pattern: 'artfct.dev/v1/*', zone_name: 'artfct.dev' }],
        build: {
            command:
                'cargo install -q worker-build --version 0.8.3 --locked && worker-build --release',
        },
        kv_namespaces: [{ binding: 'ARTIFACTS_KV', id: 'kv' }],
        d1_databases: [
            { binding: 'ARTIFACTS_DB', database_name: 'db', database_id: 'd1' },
        ],
        r2_buckets: [{ binding: 'ARTIFACTS_BUCKET', bucket_name: 'blobs' }],
        vars: {
            ARTFCT_PUBLIC_BASE_URL: 'https://artfct.dev',
            ARTFCT_ARTIFACT_ORIGIN_SUFFIX: '--prod.artfct.dev',
        },
    };
}

function withoutKey(config, key) {
    const copy = structuredClone(config);

    delete copy[key];

    return copy;
}

test('parseArgs defaults to the production config, one-shot 100 percent, and every opt-in off', () => {
    const options = parseArgs([]);

    assert.equal(options.config, productionConfig);
    assert.equal(options.percentage, 100);
    assert.equal(options.dryRun, false);
    assert.equal(options.applyMigrations, false);
    assert.equal(options.deployTriggers, false);
    assert.equal(options.smokeUrl, null);
});

test('parseArgs reads every flag', () => {
    const options = parseArgs([
        '--config',
        '/abs/staging.jsonc',
        '--percentage',
        '25',
        '--dry-run',
        '--apply-migrations',
        '--deploy-triggers',
        '--smoke-url',
        'https://staging.example.test/health',
    ]);

    assert.equal(options.config, '/abs/staging.jsonc');
    assert.equal(options.percentage, 25);
    assert.equal(options.dryRun, true);
    assert.equal(options.applyMigrations, true);
    assert.equal(options.deployTriggers, true);
    assert.equal(options.smokeUrl, 'https://staging.example.test/health');
});

test('parseArgs rejects percentages outside 1 to 100', () => {
    for (const value of ['0', '101', '1.5', 'abc', '']) {
        assert.throws(
            () => parseArgs(['--percentage', value]),
            /whole number from 1 to 100/,
            value,
        );
    }
});

test('parseArgs rejects unknown flags, missing values and non-http smoke URLs', () => {
    assert.throws(() => parseArgs(['--nope']), /Unknown option: --nope/);
    assert.throws(() => parseArgs(['--config']), /--config needs a value/);
    assert.throws(
        () => parseArgs(['--smoke-url', 'ftp://x.test']),
        /http or https URL/,
    );
    assert.throws(
        () => parseArgs(['--smoke-url', 'not a url']),
        /http or https URL/,
    );
});

test('parseJsonc strips comments and trailing commas but keeps // inside strings', () => {
    const parsed = parseJsonc(`{
        // a line comment
        "url": "https://artfct.dev", /* block */
        "list": [1, 2,],
    }`);

    assert.deepEqual(parsed, { url: 'https://artfct.dev', list: [1, 2] });
});

test('the real backend wrangler configs parse', () => {
    for (const file of [
        'wrangler.jsonc',
        'wrangler.staging.jsonc',
        'wrangler.staging-b.jsonc',
    ]) {
        assert.equal(
            typeof readWranglerConfig(resolve(root, 'backend', file)).name,
            'string',
            file,
        );
    }
});

test('the production and staging configs pass validation', () => {
    assert.deepEqual(
        validateWranglerConfig(readWranglerConfig(productionConfig)),
        [],
    );
    assert.deepEqual(
        validateWranglerConfig(readWranglerConfig(stagingConfig)),
        [],
    );
});

test('validateWranglerConfig accepts a complete config', () => {
    assert.deepEqual(validateWranglerConfig(validConfig()), []);
});

test('validateWranglerConfig rejects a config without routes', () => {
    const missing = validateWranglerConfig(withoutKey(validConfig(), 'routes'));
    const empty = validateWranglerConfig({ ...validConfig(), routes: [] });

    assert.match(missing.join('\n'), /routes is empty/);
    assert.match(empty.join('\n'), /routes is empty/);
});

test('validateWranglerConfig rejects each missing required var', () => {
    for (const name of [
        'ARTFCT_PUBLIC_BASE_URL',
        'ARTFCT_ARTIFACT_ORIGIN_SUFFIX',
    ]) {
        const config = validConfig();

        delete config.vars[name];

        assert.deepEqual(validateWranglerConfig(config), [
            `vars.${name} is not set.`,
        ]);
    }

    const blank = validConfig();

    blank.vars.ARTFCT_PUBLIC_BASE_URL = '   ';

    assert.match(
        validateWranglerConfig(blank).join('\n'),
        /vars\.ARTFCT_PUBLIC_BASE_URL is not set/,
    );
});

test('validateWranglerConfig rejects each missing binding', () => {
    const cases = [
        ['kv_namespaces', 'ARTIFACTS_KV'],
        ['d1_databases', 'ARTIFACTS_DB'],
        ['r2_buckets', 'ARTIFACTS_BUCKET'],
    ];

    for (const [field, binding] of cases) {
        const config = validConfig();

        config[field] = config[field].map((entry) => ({
            ...entry,
            binding: `${binding}_OTHER`,
        }));

        assert.deepEqual(validateWranglerConfig(config), [
            `${field} has no "${binding}" binding.`,
        ]);
    }
});

test('validateWranglerConfig rejects a build command without --locked', () => {
    const config = validConfig();

    config.build.command =
        'cargo install -q worker-build --version 0.8.3 && worker-build --release';

    assert.deepEqual(validateWranglerConfig(config), [
        'build.command must install and run worker-build with --locked.',
    ]);
});

test('parseVersionId extracts the uploaded version id or returns null', () => {
    const id = '0b1d7c8e-3f2a-4e5b-9c6d-7a8b9c0d1e2f';

    assert.equal(parseVersionId(`Uploaded\nWorker Version ID: ${id}\n`), id);
    assert.equal(parseVersionId('upload failed'), null);
});

test('parseCurrentVersionId returns the version carrying the most traffic', () => {
    const status = JSON.stringify({
        deployment: {
            versions: [
                { version_id: 'old-version', percentage: 0 },
                { version_id: 'live-version', percentage: 100 },
            ],
        },
    });

    assert.equal(parseCurrentVersionId(status), 'live-version');
});

test('parseCurrentVersionId returns null for unparseable or version-less output', () => {
    assert.equal(parseCurrentVersionId('Error: not logged in'), null);
    assert.equal(parseCurrentVersionId('{"deployment":{}}'), null);
});

test('buildVersionDeployArgs promotes the new version to 100 percent by default', () => {
    assert.deepEqual(
        buildVersionDeployArgs({
            configPath: '/abs/wrangler.jsonc',
            versionId: 'new',
            percentage: 100,
            currentVersionId: 'old',
            message: 'Deploy abc',
        }),
        [
            'versions',
            'deploy',
            'new@100',
            '--yes',
            '--message',
            'Deploy abc',
            '--config',
            '/abs/wrangler.jsonc',
        ],
    );
});

test('buildVersionDeployArgs splits traffic between the new and current version for a gradual rollout', () => {
    const args = buildVersionDeployArgs({
        configPath: '/abs/wrangler.jsonc',
        versionId: 'new',
        percentage: 25,
        currentVersionId: 'old',
        message: 'Deploy abc',
    });

    assert.deepEqual(args.slice(0, 4), [
        'versions',
        'deploy',
        'new@25',
        'old@75',
    ]);
});

test('buildVersionDeployArgs refuses a gradual rollout without a current version', () => {
    assert.throws(
        () =>
            buildVersionDeployArgs({
                configPath: '/abs/wrangler.jsonc',
                versionId: 'new',
                percentage: 10,
                currentVersionId: null,
                message: 'Deploy abc',
            }),
        /needs the current version/,
    );
});

test('buildUploadArgs passes the config and adds the tag only when one is set', () => {
    assert.deepEqual(
        buildUploadArgs({ configPath: '/c.jsonc', message: 'm' }),
        ['versions', 'upload', '--message', 'm', '--config', '/c.jsonc'],
    );
    assert.deepEqual(
        buildUploadArgs({
            configPath: '/c.jsonc',
            message: 'm',
            tag: 'abc',
        }).slice(-2),
        ['--tag', 'abc'],
    );
});

test('build and status helpers target the given config', () => {
    assert.deepEqual(buildStatusArgs('/c.jsonc'), [
        'deployments',
        'status',
        '--json',
        '--config',
        '/c.jsonc',
    ]);
    assert.deepEqual(buildTriggersArgs('/c.jsonc'), [
        'triggers',
        'deploy',
        '--config',
        '/c.jsonc',
    ]);
});

test('rollbackCommand is null without a recorded version and promotes the old version otherwise', () => {
    assert.equal(rollbackCommand('/c.jsonc', null), null);
    assert.match(
        rollbackCommand('/c.jsonc', 'old'),
        /versions deploy old@100 --yes/,
    );
});

test('isSmokeSuccess accepts only 2xx', () => {
    for (const status of [200, 204, 299]) {
        assert.equal(isSmokeSuccess(status), true, String(status));
    }

    for (const status of [199, 301, 404, 500]) {
        assert.equal(isSmokeSuccess(status), false, String(status));
    }
});

test('dry run validates the production config and prints one build step with no execution', () => {
    const result = spawnSync(
        process.execPath,
        [script, '--dry-run', '--apply-migrations', '--deploy-triggers'],
        {
            encoding: 'utf8',
        },
    );

    assert.equal(result.status, 0, result.stderr);
    assert.match(result.stdout, /Dry run: nothing was executed/);
    assert.match(result.stdout, /versions upload .*--config/);
    assert.match(result.stdout, /d1 migrations apply ARTIFACTS_DB --remote/);
    assert.match(result.stdout, /triggers deploy/);
    assert.doesNotMatch(result.stdout, /cargo|worker-build/);
});

test('dry run refuses a config that fails validation', () => {
    const directory = mkdtempSync(join(tmpdir(), 'deploy-worker-test-'));

    try {
        const configPath = join(directory, 'wrangler.jsonc');

        writeFileSync(configPath, JSON.stringify({ name: 'x', vars: {} }));

        const result = spawnSync(
            process.execPath,
            [script, '--dry-run', '--config', configPath],
            {
                encoding: 'utf8',
            },
        );

        assert.equal(result.status, 1);
        assert.match(result.stderr, /failed validation/);
        assert.match(result.stderr, /routes is empty/);
        assert.doesNotMatch(result.stdout, /Dry run/);
    } finally {
        rmSync(directory, { recursive: true, force: true });
    }
});
