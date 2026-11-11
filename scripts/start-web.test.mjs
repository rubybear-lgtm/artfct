import assert from 'node:assert/strict';
import { spawnSync } from 'node:child_process';
import { mkdtempSync, readFileSync, writeFileSync, rmSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import test from 'node:test';
import { fileURLToPath } from 'node:url';

const startScript = fileURLToPath(new URL('./start-web.sh', import.meta.url));

/**
 * @param {{ failOptimize?: boolean, ssrHealthy?: boolean, env?: Record<string, string> }} options
 */
function runStartup({ failOptimize = false, ssrHealthy = true, env = {} } = {}) {
    const directory = mkdtempSync(join(tmpdir(), 'artfct-start-web-'));
    const log = join(directory, 'commands');

    try {
        writeFileSync(
            join(directory, 'php'),
            `#!/bin/sh
printf 'php %s\\n' "$*" >> "$STARTUP_LOG"
if [ "$2" = "optimize" ] && [ "$FAIL_OPTIMIZE" = "true" ]; then
    exit 17
fi
if [ "$2" = "inertia:check-ssr" ] && [ "$SSR_HEALTHY" = "false" ]; then
    exit 19
fi
`,
            { mode: 0o755 },
        );
        const result = spawnSync('sh', [startScript], {
            env: {
                ...process.env,
                PATH: `${directory}:${process.env.PATH}`,
                STARTUP_LOG: log,
                FAIL_OPTIMIZE: String(failOptimize),
                SSR_HEALTHY: String(ssrHealthy),
                PORT: '8080',
                ...env,
            },
            encoding: 'utf8',
            timeout: 5000,
        });
        assert.ifError(result.error);

        return { status: result.status, commands: readFileSync(log, 'utf8') };
    } finally {
        rmSync(directory, { recursive: true, force: true });
    }
}

test('web startup serves with Octane on FrankenPHP after SSR is healthy', () => {
    const result = runStartup();
    assert.equal(result.status, 0);
    assert.match(result.commands, /php artisan optimize --no-interaction/);
    assert.match(result.commands, /php artisan storage:link --no-interaction/);
    assert.match(
        result.commands,
        /php artisan inertia:start-ssr --no-interaction/,
    );
    assert.match(result.commands, /php artisan inertia:check-ssr/);
    assert.match(
        result.commands,
        /php artisan octane:start --server=frankenphp --host=0\.0\.0\.0 --port=8080 --workers=4 --max-requests=500 --no-interaction/,
    );
    assert.doesNotMatch(result.commands, /artisan (serve|migrate)/);
});

test('worker and request counts are configurable', () => {
    const result = runStartup({
        env: { OCTANE_WORKERS: '2', OCTANE_MAX_REQUESTS: '250' },
    });
    assert.match(result.commands, /--workers=2 --max-requests=250/);
});

test('failed optimization prevents SSR and Octane from starting', () => {
    const result = runStartup({ failOptimize: true });
    assert.equal(result.status, 17);
    assert.equal(result.commands, 'php artisan optimize --no-interaction\n');
});

test('an unhealthy SSR server prevents Octane from starting', () => {
    const result = runStartup({ ssrHealthy: false });
    assert.equal(result.status, 1);
    assert.match(result.commands, /inertia:check-ssr/);
    assert.doesNotMatch(result.commands, /octane:start/);
});

test('staging and production web services use the Octane startup', () => {
    for (const environment of ['staging', 'production']) {
        const config = readFileSync(
            new URL(`../.railway/${environment}.ts`, import.meta.url),
            'utf8',
        );
        assert.match(config, /start: "sh scripts\/start-web\.sh"/);
        assert.doesNotMatch(config, /php artisan serve/);
    }
});
