import assert from 'node:assert/strict';
import { spawnSync } from 'node:child_process';
import { mkdtempSync, readFileSync, writeFileSync, rmSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import test from 'node:test';
import { fileURLToPath } from 'node:url';

const startScript = fileURLToPath(new URL('./start-web.sh', import.meta.url));

function runStartup(failOptimize = false, config = '') {
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
`,
            { mode: 0o755 },
        );
        writeFileSync(
            join(directory, 'docker-php-entrypoint'),
            `#!/bin/sh
printf 'server %s\\n' "$*" >> "$STARTUP_LOG"
printf 'threads %s\\n' "$FRANKENPHP_CONFIG" >> "$STARTUP_LOG"
exit 23
`,
            { mode: 0o755 },
        );
        const result = spawnSync('sh', [startScript], {
            env: {
                ...process.env,
                PATH: `${directory}:${process.env.PATH}`,
                STARTUP_LOG: log,
                FAIL_OPTIMIZE: String(failOptimize),
                FRANKENPHP_CONFIG: config,
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

test('web startup preserves SSR and propagates the foreground server exit status', () => {
    const result = runStartup();
    assert.equal(result.status, 23);
    assert.match(result.commands, /php artisan optimize --no-interaction/);
    assert.match(
        result.commands,
        /php artisan inertia:start-ssr --no-interaction/,
    );
    assert.match(
        result.commands,
        /server --config \/Caddyfile --adapter caddyfile/,
    );
    assert.doesNotMatch(result.commands, /artisan (serve|migrate)/);
    assert.match(result.commands, /threads num_threads 4/);
});

test('failed optimization prevents SSR and the web server from starting', () => {
    const result = runStartup(true);
    assert.equal(result.status, 17);
    assert.equal(result.commands, 'php artisan optimize --no-interaction\n');
});

test('staging and production web services use the same concurrent server startup', () => {
    for (const environment of ['staging', 'production']) {
        const config = readFileSync(
            new URL(`../.railway/${environment}.ts`, import.meta.url),
            'utf8',
        );
        assert.match(config, /start: "sh scripts\/start-web\.sh"/);
        assert.doesNotMatch(config, /php artisan serve/);
    }
});

test('an explicit platform thread configuration is preserved', () => {
    assert.match(
        runStartup(false, 'num_threads 2').commands,
        /threads num_threads 2/,
    );
});
