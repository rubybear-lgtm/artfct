import assert from 'node:assert/strict';
import { spawn, spawnSync } from 'node:child_process';
import { mkdtempSync, readFileSync, writeFileSync, rmSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import test from 'node:test';
import { fileURLToPath } from 'node:url';

const startScript = fileURLToPath(new URL('./start-web.sh', import.meta.url));

/**
 * The mock `php` shifts off the `-d variables_order=EGPCS` prefix the
 * script passes, then dispatches on the artisan command. `checkSsr` may be
 * exercising the bounded retry loop. `octane:start` exits at once with
 * OCTANE_EXIT (default 0) so the script's `wait` returns promptly.
 *
 * @param {{ failOptimize?: boolean, checkSsr?: 'healthy' | 'never' | number, octaneExit?: number, env?: Record<string, string>, timeout?: number }} options
 */
function runStartup({ failOptimize = false, checkSsr = 'healthy', octaneExit = 0, env = {}, timeout = 15000 } = {}) {
    const directory = mkdtempSync(join(tmpdir(), 'artfct-start-web-'));
    const log = join(directory, 'commands');
    const attempts = join(directory, 'attempts');

    try {
        writeFileSync(attempts, '0');
        writeFileSync(
            join(directory, 'php'),
            `#!/bin/sh
printf 'php %s\n' "$*" >> "$STARTUP_LOG"
CMD="$1"
if [ "$CMD" = "-d" ]; then CMD="$4"; fi
if [ "$CMD" = "optimize" ] && [ "$FAIL_OPTIMIZE" = "true" ]; then
    exit 17
fi
if [ "$CMD" = "inertia:check-ssr" ]; then
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
if [ "$CMD" = "inertia:start-ssr" ]; then
    # Stay alive until the script kills us, like the real start-ssr which
    # blocks streaming the Node SSR server. Reap tail on TERM so no orphan
    # holds the harness stdio pipes open (spawnSync waits for EOF).
    tail -f /dev/null & TPID=$!
    trap 'kill $TPID' TERM
    wait
fi
if [ "$CMD" = "octane:start" ]; then
    exit "$OCTANE_EXIT"
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
                OCTANE_EXIT: String(octaneExit),
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
    assert.match(result.commands, /php -d variables_order=EGPCS artisan optimize --no-interaction/);
    assert.match(result.commands, /php -d variables_order=EGPCS artisan storage:link --no-interaction/);
    assert.match(
        result.commands,
        /php -d variables_order=EGPCS artisan inertia:start-ssr --no-interaction/,
    );
    assert.match(result.commands, /php -d variables_order=EGPCS artisan inertia:check-ssr/);
    assert.match(
        result.commands,
        /php -d variables_order=EGPCS artisan octane:start --server=frankenphp --host=0\.0\.0\.0 --port=8080 --workers=4 --max-requests=500 --no-interaction/,
    );
    assert.doesNotMatch(result.commands, /artisan (serve|migrate)/);
});

test('SSR that becomes healthy after boot is waited for, not failed', () => {
    const result = runStartup({ checkSsr: 3 });
    assert.equal(result.status, 0);
    assert.equal(result.checkAttempts, 4);
    assert.match(result.commands, /php -d variables_order=EGPCS artisan octane:start/);
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
    assert.equal(result.commands, 'php -d variables_order=EGPCS artisan optimize --no-interaction\n');
});

test('an SSR server that never becomes healthy prevents Octane from starting', () => {
    const result = runStartup({ checkSsr: 'never' });
    assert.equal(result.status, 1);
    assert.match(result.commands, /inertia:check-ssr/);
    assert.doesNotMatch(result.commands, /octane:start/);
});

test('a failing Octane server propagates its exit status', () => {
    const result = runStartup({ octaneExit: 3 });
    assert.equal(result.status, 3);
    assert.match(result.commands, /php -d variables_order=EGPCS artisan octane:start/);
});

test('every php invocation sees the process environment', () => {
    const result = runStartup();

    for (const line of result.commands.trim().split('\n')) {
        assert.match(line, /^php -d variables_order=EGPCS artisan/);
    }
});

test('staging and production web services use the Octane startup', () => {
    for (const environment of ['staging', 'production']) {
        const config = readFileSync(
            new URL(`../.railway/${environment}.ts`, import.meta.url),
            'utf8',
        );
        assert.match(config, /start: "sh scripts\/start-web\.sh"/);
        assert.doesNotMatch(config, /php -d variables_order=EGPCS artisan serve/);
    }
});

test('a stop signal waits for Octane to finish draining before exiting', async () => {
    const directory = mkdtempSync(join(tmpdir(), 'artfct-start-web-signal-'));
    const log = join(directory, 'commands');

    try {
        writeFileSync(log, '');
        writeFileSync(
            join(directory, 'php'),
            `#!/bin/sh
CMD="$1"
if [ "$CMD" = "-d" ]; then CMD="$4"; fi
if [ "$CMD" = "inertia:start-ssr" ]; then
    tail -f /dev/null & TPID=$!
    trap 'kill $TPID; exit 0' TERM
    wait
fi
if [ "$CMD" = "octane:start" ]; then
    echo started >> "$STARTUP_LOG"
    tail -f /dev/null & TPID=$!
    trap '/bin/sleep 0.5; echo drained >> "$STARTUP_LOG"; kill $TPID; exit 0' TERM
    wait
fi
exit 0
`,
            { mode: 0o755 },
        );
        writeFileSync(join(directory, 'sleep'), '#!/bin/sh\nexit 0\n', { mode: 0o755 });

        const child = spawn('sh', [startScript], {
            env: {
                ...process.env,
                PATH: `${directory}:${process.env.PATH}`,
                STARTUP_LOG: log,
                PORT: '8080',
            },
            stdio: 'ignore',
        });
        const closed = new Promise((resolve) => child.on('close', (code) => resolve(code)));

        for (let waited = 0; !readFileSync(log, 'utf8').includes('started'); waited += 50) {
            assert.ok(waited < 10000, 'Octane never started');
            await new Promise((resolve) => setTimeout(resolve, 50));
        }

        child.kill('SIGTERM');
        await closed;

        assert.match(readFileSync(log, 'utf8'), /drained/);
    } finally {
        rmSync(directory, { recursive: true, force: true });
    }
});
