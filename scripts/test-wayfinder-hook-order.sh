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

for hook in .githooks/pre-commit .githooks/pre-push; do
    assert_before "$hook" \
        'php artisan wayfinder:generate --no-interaction' \
        'npm run format:check'
    assert_before "$hook" \
        'php artisan wayfinder:generate --no-interaction' \
        'npm run lint:check'
    assert_before "$hook" \
        'php artisan wayfinder:generate --no-interaction' \
        'npm run types:check'
done

echo "Wayfinder generation precedes local frontend hook checks."
