import assert from 'node:assert/strict';
import { spawnSync } from 'node:child_process';
import { mkdtempSync, readFileSync, writeFileSync, rmSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import test from 'node:test';
import { fileURLToPath } from 'node:url';

const startScript = fileURLToPath(new URL('./start-web.sh', import.meta.url));

/**
 * The mock `php` shifts off the `-d variables_order=EGPCS` prefix the
 * script passes, then dispatches on the artisan command. `checkSsr` may be
 * 'healthy', 'never', or a number N: fail the first N checks, then succeed,
 * exercising the bounded retry loop. `octane:start` exits 0 at once so the
 * script's `wait` returns (as if Octane ran and exited cleanly).
 *
 * @param {{ failOptimize?: boolean, checkSsr?: 'healthy' | 'never' | number, env?: Record<string, string>, timeout?: number }} options
 */
function runStartup({ failOptimize = false, checkSsr = 'healthy', env = {}, timeout = 15000 } = {}) {
    const directory = mkdtempSync(join(tmpdir(), 'artfct-start-web-'));
    const log = join(directory, 'commands');
    const attempts = join(directory, 'attempts');

    try {
        writeFileSync(attempts, '0');
        writeFileSync(
            join(directory, 'php'),
            `#!/bin/sh
if [ "$1" = "-d" ]; then shift 2; fi
printf 'php %s\n' "$*" >> "$STARTUP_LOG"
if [ "$2" = "optimize" ] && [ "$FAIL_OPTIMIZE" = "true" ]; then
    exit 17
fi
if [ "$2" = "inertia:check-ssr" ]; then
    n=$(($(cat "$CHECK_ATTEMPTS") + 1))
    echo "$n" > "$CHECK_ATTEMPTS"
    if [ "$SSR_HEALTH" = "never" ]; then
        exit 19
    fi
    if [ "$SSR_HEALTH" = "healthy" ] || [ "$n" -gt "$SSR_HEALTH" ]; then
        exit 0
    fi
    exit 19
fi
if [ "$2" = "inertia:start-ssr" ]; then
    # Stay alive until the script kills us, like the real start-ssr which
    # blocks streaming the Node SSR server. Reap tail on TERM so no orphan
    # holds the harness stdio pipes open (spawnSync waits for EOF).
    tail -f /dev/null & TPID=$!
    trap 'kill $TPID' TERM
    wait
fi
exit 0
`,
            { mode: 0o755 },
        );
        writeFileSync(join(directory, 'sleep'), '#!/bin/sh\nexit 0\n', {
            mode: 0o755,
        });
        const result = spawnSync('sh', [startScript], {
            env: {
                ...process.env,
                PATH: `${directory}:${process.env.PATH}`,
                STARTUP_LOG: log,
                CHECK_ATTEMPTS: attempts,
                FAIL_OPTIMIZE: String(failOptimize),
                SSR_HEALTH: String(checkSsr),
                PORT: '8080',
                ...env,
            },
            encoding: 'utf8',
            timeout,
        });
        assert.ifError(result.error);

        return {
            status: result.status,
            commands: readFileSync(log, 'utf8'),
            checkAttempts: Number(readFileSync(attempts, 'utf8')),
        };
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

test('SSR that becomes healthy after boot is waited for, not failed', () => {
    const result = runStartup({ checkSsr: 3 });
    assert.equal(result.status, 0);
    assert.equal(result.checkAttempts, 4);
    assert.match(result.commands, /php artisan octane:start/);
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

test('an SSR server that never becomes healthy prevents Octane from starting', () => {
    const result = runStartup({ checkSsr: 'never' });
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
