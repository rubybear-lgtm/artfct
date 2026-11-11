#!/bin/sh
set -eu

# Serve the app with Laravel Octane on FrankenPHP (worker mode): the
# application boots once and stays resident, so concurrent preview fetches
# overlap instead of queueing behind a single-threaded server.
# Migrations stay in Railway's pre-deploy step.
#
# OCTANE_WORKERS defaults to 4: the web service has 1 vCPU and preview
# requests are I/O-bound on the Worker round trip, so threads wait on the
# network, not the CPU. OCTANE_MAX_REQUESTS bounds memory growth by
# recycling each worker after 500 requests.
#
# Railpack's php.ini ships `variables_order = "GPCS"` (no `E`), but Octane's
# FrankenPHP worker passes APP_* and request limits to the worker through the
# environment (`$_ENV`), the same reason Sail pins EGPCS. Every php invocation
# below carries `-d variables_order=EGPCS` so the worker actually sees them.

PHP="php -d variables_order=EGPCS"

$PHP artisan optimize --no-interaction
$PHP artisan storage:link --no-interaction

$PHP artisan inertia:start-ssr --no-interaction &
SSR_PID=$!

# start-ssr boots Laravel and stops any old SSR server before Node binds
# port 13714, so a single check-ssr races it. Poll while the SSR process is
# alive; fail only when it dies or the deadline passes.
ATTEMPTS=30
until $PHP artisan inertia:check-ssr --no-interaction; do
    ATTEMPTS=$((ATTEMPTS - 1))
    if ! kill -0 "$SSR_PID" 2>/dev/null; then
        echo "❌ Inertia SSR server exited before becoming healthy; refusing to serve without it." >&2
        exit 1
    fi
    if [ "$ATTEMPTS" -le 0 ]; then
        kill "$SSR_PID" 2>/dev/null || true
        echo "❌ Inertia SSR server did not become healthy in time; refusing to serve without it." >&2
        exit 1
    fi
    sleep 1
done

# A shell trap would die with `exec` (POSIX exec discards traps), so SSR
# cleanup cannot live here. Instead Octane runs in the foreground as a child:
# when it exits, the shell regains control and stops SSR, so signals that
# terminate Octane still clean up the background SSR server.
$PHP artisan octane:start --server=frankenphp --host=0.0.0.0 --port="${PORT:?PORT is required}" --workers="${OCTANE_WORKERS:-4}" --max-requests="${OCTANE_MAX_REQUESTS:-500}" --no-interaction &
OCTANE_PID=$!
wait "$OCTANE_PID"
STATUS=$?
kill "$SSR_PID" 2>/dev/null || true
exit "$STATUS"
