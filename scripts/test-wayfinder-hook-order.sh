#!/usr/bin/env bash
set -euo pipefail

assert_before() {
    local file="$1"
    local first="$2"
    local second="$3"
    local first_line
    local second_line

    first_line=$(grep -nF -- "$first" "$file" | head -n 1 | cut -d: -f1 || true)
    second_line=$(grep -nF -- "$second" "$file" | head -n 1 | cut -d: -f1 || true)

    if [[ -z "$first_line" || -z "$second_line" || "$first_line" -ge "$second_line" ]]; then
        echo "Expected '$first' before '$second' in $file." >&2
        exit 1
    fi
}

# pre-commit: generate Wayfinder before any frontend check that imports it.
assert_before .githooks/pre-commit \
    'php artisan wayfinder:generate --no-interaction' \
    'npm run format:check'
assert_before .githooks/pre-commit \
    'php artisan wayfinder:generate --no-interaction' \
    'npm run lint:check'
assert_before .githooks/pre-commit \
    'php artisan wayfinder:generate --no-interaction' \
    'npm run types:check'

# pre-push mirrors CI php_static (RUB-471): production build:ssr generates
# Wayfinder under APP_ENV=production, then lint/types consume those files.
# A local-only wayfinder:generate before lint can pass pre-push and fail CI.
assert_before .githooks/pre-push \
    'APP_ENV=production npm run build:ssr' \
    'npm run lint:check'
assert_before .githooks/pre-push \
    'APP_ENV=production npm run build:ssr' \
    'npm run types:check'
assert_before .githooks/pre-push \
    'APP_ENV=production npm run build:ssr' \
    'npm run routes:check'

echo "Wayfinder generation precedes frontend hook checks (pre-commit generate; pre-push production build)."
