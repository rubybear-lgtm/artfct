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

php artisan optimize --no-interaction
php artisan storage:link --no-interaction

php artisan inertia:start-ssr --no-interaction &
SSR_PID=$!

if ! php artisan inertia:check-ssr --no-interaction; then
    kill "$SSR_PID" 2>/dev/null || true
    echo "❌ Inertia SSR server did not start; refusing to serve without it." >&2
    exit 1
fi

trap 'kill "$SSR_PID" 2>/dev/null || true' TERM INT
exec php artisan octane:start --server=frankenphp --host=0.0.0.0 --port="${PORT:?PORT is required}" --workers="${OCTANE_WORKERS:-4}" --max-requests="${OCTANE_MAX_REQUESTS:-500}" --no-interaction
