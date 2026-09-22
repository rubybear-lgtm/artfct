#!/usr/bin/env bash
# RUB-363: boots a full local stack for MCP end-to-end testing — Postgres
# (docker-compose.e2e.yml), Laravel (migrated against it, dev-login enabled,
# queue worker running), and a local `wrangler dev` Worker with Laravel's
# org-JWT signing key published to it (the real `auth:publish-jwks` command,
# not a test-only shim). Two synthetic orgs are seeded via the existing
# `synthetic:seed --laravel-only` command so a single admin user is a member
# of both, matching the pattern scripts/mcp-live-smoke.mjs already expects.
#
# Usage:
#   scripts/mcp-e2e-stack.sh up      # start everything, print exported env
#   scripts/mcp-e2e-stack.sh down    # stop everything, including Postgres
#   scripts/mcp-e2e-stack.sh run     # up, run mcp-live-smoke.mjs against it, down
#
# Never touches the developer's real .env or database: every Laravel process
# this script starts gets its config from exported environment variables
# (which phpdotenv does not override), and Postgres/Worker state lives under
# a throwaway state directory removed on `down`.
set -euo pipefail
cd "$(dirname "$0")/.."

STATE_DIR="$(pwd)/.mcp-e2e"
COMPOSE="docker compose -f docker-compose.e2e.yml"
WRANGLER="./node_modules/.bin/wrangler"
LARAVEL_PORT="${MCP_E2E_LARAVEL_PORT:-8990}"
WORKER_PORT="${MCP_E2E_WORKER_PORT:-8991}"
ORG_A_SLUG="zz-mcp-e2e"
ORG_B_SLUG="zz-mcp-e2e-b"
ADMIN_EMAIL="admin@northwind.example"

up() {
    mkdir -p "$STATE_DIR"

    # CI provides Postgres as a native GitHub Actions service container
    # (faster than Docker-in-Docker); set MCP_E2E_EXTERNAL_POSTGRES=true and
    # the DB_* vars below to point at it instead of starting the local one.
    if [ "${MCP_E2E_EXTERNAL_POSTGRES:-false}" != "true" ]; then
        echo "==> Starting Postgres" >&2
        $COMPOSE up -d --wait
    fi

    # Ephemeral RSA key for org-JWT signing; never written to disk, never
    # reused between runs.
    ORG_JWT_PRIVATE_KEY_B64=$(openssl genpkey -algorithm RSA -pkeyopt rsa_keygen_bits:2048 2>/dev/null | base64 | tr -d '\n')
    export ORG_JWT_PRIVATE_KEY_B64
    export ORG_JWT_KID="mcp-e2e"
    export ORG_JWT_ISSUER="http://127.0.0.1:${LARAVEL_PORT}"
    export ORG_JWT_AUDIENCE="artfct-engine"
    export ARTFCT_WORKER_BASE_URL="http://127.0.0.1:${WORKER_PORT}"
    export ARTFCT_JWKS_WRITE_SECRET="mcp-e2e-jwks-$(openssl rand -hex 8)"
    export ARTFCT_REVOCATION_WRITE_SECRET="mcp-e2e-revocation-$(openssl rand -hex 8)"
    export ARTFCT_LIMITS_WRITE_SECRET="mcp-e2e-limits-$(openssl rand -hex 8)"
    export ARTFCT_GOVERNANCE_SECRET="mcp-e2e-governance-$(openssl rand -hex 8)"
    export DB_CONNECTION="pgsql"
    export DB_HOST="${MCP_E2E_DB_HOST:-127.0.0.1}"
    export DB_PORT="${MCP_E2E_DB_PORT:-55432}"
    export DB_DATABASE="${MCP_E2E_DB_DATABASE:-artfct_e2e}"
    export DB_USERNAME="${MCP_E2E_DB_USERNAME:-artfct}"
    export DB_PASSWORD="${MCP_E2E_DB_PASSWORD:-artfct}"
    export QUEUE_CONNECTION="database"
    export SESSION_DRIVER="database"
    export CACHE_STORE="database"
    export APP_ENV="local"
    export APP_URL="http://127.0.0.1:${LARAVEL_PORT}"
    export AUTHKIT_DEV_LOGIN_ENABLED="true"
    # Force the AuthKitClientContract binding to FakeAuthKitClient
    # (AppServiceProvider only picks RealAuthKitClient when these are all
    # set, and only skips that for APP_ENV=testing, not =local): a real
    # developer .env commonly has real WorkOS credentials, and without this
    # override /authenticate hands the dev-login stand-in's fake code to the
    # real WorkOS client, which rejects it with a 403.
    export WORKOS_CLIENT_ID=""
    export WORKOS_API_KEY=""
    export WORKOS_REDIRECT_URL=""
    : > "$STATE_DIR/env" # record the env this run used, for `run`'s child processes
    env | grep -E '^(ORG_JWT_|ARTFCT_|DB_|QUEUE_CONNECTION|SESSION_DRIVER|CACHE_STORE|APP_ENV|APP_URL|AUTHKIT_|WORKOS_)' > "$STATE_DIR/env"

    [ -n "${APP_KEY:-}" ] || export APP_KEY="base64:$(openssl rand -base64 32)"
    echo "APP_KEY=$APP_KEY" >> "$STATE_DIR/env"

    echo "==> Migrating Laravel against Postgres" >&2
    php artisan migrate --force --no-interaction

    echo "==> Starting local Worker (wrangler dev)" >&2
    (cd backend && ../"$WRANGLER" d1 migrations apply ARTIFACTS_DB --local --persist-to "$STATE_DIR/wrangler" >/dev/null)
    (
        cd backend
        ../"$WRANGLER" dev --local --port "$WORKER_PORT" --persist-to "$STATE_DIR/wrangler" \
            --var "ARTFCT_JWT_ISSUER:$ORG_JWT_ISSUER" \
            --var "ARTFCT_JWT_AUDIENCE:$ORG_JWT_AUDIENCE" \
            --var "ARTFCT_JWKS_WRITE_SECRET:$ARTFCT_JWKS_WRITE_SECRET" \
            --var "ARTFCT_REVOCATION_WRITE_SECRET:$ARTFCT_REVOCATION_WRITE_SECRET" \
            --var "ARTFCT_LIMITS_WRITE_SECRET:$ARTFCT_LIMITS_WRITE_SECRET" \
            --var "ARTFCT_GOVERNANCE_SECRET:$ARTFCT_GOVERNANCE_SECRET" \
            --var "ARTFCT_PUBLIC_BASE_URL:http://127.0.0.1:$WORKER_PORT" \
            >"$STATE_DIR/wrangler.log" 2>&1 &
        echo $! > "$STATE_DIR/wrangler.pid"
    )
    until curl -s -o /dev/null "http://127.0.0.1:${WORKER_PORT}/p/x"; do sleep 0.5; done

    echo "==> Publishing the org-JWT signing key to the Worker" >&2
    php artisan auth:publish-jwks

    echo "==> Seeding synthetic orgs ($ORG_A_SLUG, $ORG_B_SLUG)" >&2
    php artisan synthetic:seed --slug="$ORG_A_SLUG" --laravel-only --target=local --no-interaction

    # synthetic:seed's second org is isolation-testing content, not a second
    # admin membership: the OAuth consent flow needs one signed-in user who
    # belongs to *both* orgs (same as the staging live-smoke setup) so a
    # single dev-login session can consent into either by `team` slug.
    php artisan tinker --execute "
        \$admin = App\Models\User::where('email', '${ADMIN_EMAIL}')->firstOrFail();
        \$orgB = App\Models\Team::where('slug', '${ORG_B_SLUG}')->firstOrFail();
        if (! \$admin->belongsToTeam(\$orgB)) {
            \$orgB->memberships()->create(['user_id' => \$admin->id, 'role' => App\Enums\TeamRole::Admin]);
        }
    " >/dev/null

    # A long-lived org JWT for the Rust storage/provenance integration
    # tests (mcp-server/tests/storage_integration.rs,
    # provenance_integration.rs) — real production-path checks against a
    # live Worker that `cargo test` otherwise silently skips
    # (#[ignore]d) because nothing sets up a Worker + token for them.
    INTEGRATION_TOKEN=$(php artisan tinker --execute "
        \$team = App\Models\Team::where('slug', '${ORG_A_SLUG}')->firstOrFail();
        \$admin = App\Models\User::where('email', '${ADMIN_EMAIL}')->firstOrFail();
        echo App\Services\Auth\OrgJwtService::default()->mint(\$team, \$admin, App\Enums\TeamRole::Admin, 3600)['token'];
    " 2>/dev/null | tail -1)
    echo "ARTFCT_INTEGRATION_TOKEN=$INTEGRATION_TOKEN" >> "$STATE_DIR/env"
    {
        echo "ARTFCT_INTEGRATION_BASE_URL=http://127.0.0.1:${WORKER_PORT}"
        echo "ARTFCT_INTEGRATION_ORG=${ORG_A_SLUG}"
        echo "ARTFCT_INTEGRATION_PERSIST_TO=${STATE_DIR}/wrangler"
        echo "ARTFCT_WRANGLER_BIN=$(pwd)/${WRANGLER#./}"
    } >> "$STATE_DIR/env"

    echo "==> Starting Laravel (serve + queue worker)" >&2
    # Not `php artisan serve`: its ServeCommand re-execs the actual worker
    # with a filtered environment (only PATH/APP_ENV survive — verified by
    # inspecting the spawned process's env directly), so none of the
    # overrides above would reach it and it would silently fall back to the
    # real .env (sqlite, real WorkOS credentials). PHP's built-in server
    # invoked directly is this script's own child and inherits our exports
    # normally, same as the artisan commands above.
    php -S "127.0.0.1:${LARAVEL_PORT}" -t public public/index.php >"$STATE_DIR/laravel.log" 2>&1 &
    echo $! > "$STATE_DIR/laravel.pid"
    # LOG_CHANNEL=stderr for the worker only, so the worker's own log lines
    # (the queue probe's among them) land in queue.log where they can be
    # attributed to the worker process, instead of mixing into the shared
    # storage/logs file that the web process also writes to.
    LOG_CHANNEL=stderr php artisan queue:work --queue=indexing,default --tries=1 >"$STATE_DIR/queue.log" 2>&1 &
    echo $! > "$STATE_DIR/queue.pid"
    until curl -s -o /dev/null "http://127.0.0.1:${LARAVEL_PORT}/up"; do sleep 0.5; done

    cat >&2 <<EOF

==> Stack is up.
    Laravel:  http://127.0.0.1:${LARAVEL_PORT}
    Worker:   http://127.0.0.1:${WORKER_PORT}
    Postgres: localhost:55432/artfct_e2e

    Run the MCP live smoke suite against it with:

    MCP_LIVE_BASE_URL=http://127.0.0.1:${LARAVEL_PORT} \\
    MCP_LIVE_EXPECTED_ORG_A=${ORG_A_SLUG} \\
    MCP_LIVE_EXPECTED_ORG_B=${ORG_B_SLUG} \\
    MCP_LIVE_DEV_LOGIN_EMAIL=${ADMIN_EMAIL} \\
    node scripts/mcp-live-smoke.mjs

    Run the Rust storage/provenance integration tests against it with:
    scripts/mcp-e2e-stack.sh rust

    Tear down with: scripts/mcp-e2e-stack.sh down
EOF
}

down() {
    echo "==> Stopping Laravel, queue worker, and Worker" >&2
    for pidfile in laravel.pid queue.pid wrangler.pid; do
        if [ -f "$STATE_DIR/$pidfile" ]; then
            kill "$(cat "$STATE_DIR/$pidfile")" 2>/dev/null || true
        fi
    done
    pkill -f "wrangler dev --local --port ${WORKER_PORT}" 2>/dev/null || true

    if [ "${MCP_E2E_EXTERNAL_POSTGRES:-false}" != "true" ]; then
        echo "==> Stopping Postgres" >&2
        $COMPOSE down -v
    fi

    rm -rf "$STATE_DIR"
    echo "==> Stack torn down." >&2
}

rust() {
    set -a
    # shellcheck disable=SC1090
    . "$STATE_DIR/env"
    set +a
    # --test-threads=1: these tests share one org on one live Worker
    # (unlike unit tests, there's no per-test isolated state), and
    # storage_integration.rs mixes tests that create/delete blobs with
    # export_metadata_round_trips, which exports every artifact currently in
    # the org — run concurrently, a delete from one test can race a
    # concurrently-running export in another and 404. Confirmed by running
    # the failing test alone: passes every time in isolation.
    cargo test -p artfct --locked \
        --test storage_integration --test provenance_integration \
        -- --ignored --test-threads=1
}

# The probe runs on the queue worker, so what it reports is the origin that
# worker would put in a user-facing link. The queue holds its own copy of
# APP_URL, and a drifted copy is invisible from the web service -- staging
# served invitation links on a Railway service domain while every web-facing
# check passed. Assert the copy the worker actually resolves, rather than the
# one a dashboard claims it has.
assert_queue_worker_uses_the_public_origin() {
    local expected="http://127.0.0.1:${LARAVEL_PORT}"
    local marker="origin-check-$$"
    local line

    php artisan tinker --execute "App\Jobs\QueueProbeJob::dispatch('${marker}');" >/dev/null

    for _ in $(seq 1 120); do
        if grep -q "QUEUE_PROBE ${marker}" "$STATE_DIR/queue.log" 2>/dev/null; then
            line=$(grep "QUEUE_PROBE ${marker}" "$STATE_DIR/queue.log" | tail -1)

            if [[ "$line" == *"app_url ${expected}"* ]]; then
                echo "==> Queue worker resolves the public origin (${expected})" >&2
                return 0
            fi

            echo "❌ The queue worker resolved a different origin than the web service:" >&2
            echo "   ${line}" >&2
            return 1
        fi

        sleep 0.5
    done

    echo "❌ The queue probe was never handled; see ${STATE_DIR}/queue.log" >&2
    return 1
}

run() {
    trap down EXIT
    up
    set -a
    # shellcheck disable=SC1090
    . "$STATE_DIR/env"
    set +a
    assert_queue_worker_uses_the_public_origin
    MCP_LIVE_BASE_URL="http://127.0.0.1:${LARAVEL_PORT}" \
        MCP_LIVE_EXPECTED_ORG_A="$ORG_A_SLUG" \
        MCP_LIVE_EXPECTED_ORG_B="$ORG_B_SLUG" \
        MCP_LIVE_DEV_LOGIN_EMAIL="$ADMIN_EMAIL" \
        node scripts/mcp-live-smoke.mjs
    rust
}

case "${1:-}" in
    up) up ;;
    down) down ;;
    run) run ;;
    rust) rust ;;
    *)
        echo "Usage: $0 {up|down|run|rust}" >&2
        exit 1
        ;;
esac
