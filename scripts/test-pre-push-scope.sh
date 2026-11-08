#!/usr/bin/env bash
set -euo pipefail

REPOSITORY_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
TEST_DIRECTORY="$(mktemp -d "${TMPDIR:-/tmp}/artfct-pre-push-scope-test.XXXXXX")"
ZERO_SHA='0000000000000000000000000000000000000000'

cleanup() {
    rm -rf "$TEST_DIRECTORY"
}
trap cleanup EXIT

git init -q "$TEST_DIRECTORY/repo"
cd "$TEST_DIRECTORY/repo"
git config user.email test@example.invalid
git config user.name "Pre-push scope test"

write_file() {
    mkdir -p "$(dirname "$1")"
    printf '%s\n' "$2" > "$1"
}

write_file app/Example.php '<?php'
write_file README.md 'readme'
git add -A
git commit -q -m base
BASE="$(git rev-parse HEAD)"
git update-ref refs/remotes/origin/develop "$BASE"

commit_change() {
    git checkout -q -B "$1" "$BASE"
    shift
    "$@"
    git add -A
    git commit -q -m change
    git rev-parse HEAD
}

scope_for() {
    printf 'refs/heads/topic %s refs/heads/topic %s\n' "$1" "$2" \
        | bash "$REPOSITORY_ROOT/scripts/pre-push-scope.sh"
}

assert_scope() {
    local expected="$1" label="$2" actual="$3"

    if [ "$actual" != "$expected" ]; then
        echo "FAIL: $label: expected '$expected', got '$actual'" >&2
        exit 1
    fi

    echo "ok: $label"
}

sha="$(commit_change plumbing write_file .github/workflows/release-to-production.yml 'x')"
assert_scope light "workflow-only push to an existing branch" "$(scope_for "$sha" "$BASE")"

sha="$(commit_change plumbing2 bash -c "mkdir -p scripts/release scripts/staging && echo a > scripts/release/a.mjs && echo b > scripts/staging/b.mjs && echo c > RELEASING.md")"
assert_scope light "release, staging and RELEASING.md only" "$(scope_for "$sha" "$BASE")"
assert_scope light "new branch resolved against origin/develop" "$(scope_for "$sha" "$ZERO_SHA")"

sha="$(commit_change code write_file app/Other.php '<?php')"
assert_scope full "PHP change" "$(scope_for "$sha" "$BASE")"

sha="$(commit_change mixed bash -c "echo a > RELEASING.md && mkdir -p backend && echo b > backend/lib.rs")"
assert_scope full "plumbing plus Rust change" "$(scope_for "$sha" "$BASE")"

sha="$(commit_change readme write_file README.md 'changed')"
assert_scope full "README.md is read by Pest tests" "$(scope_for "$sha" "$BASE")"

sha="$(commit_change runbook write_file docs/mcp-runbook.md 'changed')"
assert_scope full "docs/ is not treated as non-code" "$(scope_for "$sha" "$BASE")"

sha="$(commit_change moved bash -c "mkdir -p scripts/release && git mv app/Example.php scripts/release/Example.php")"
assert_scope full "rename out of app/ still counts the deleted source path" "$(scope_for "$sha" "$BASE")"

assert_scope full "unresolvable remote sha" "$(scope_for "$sha" "1111111111111111111111111111111111111111")"

git update-ref -d refs/remotes/origin/develop
assert_scope full "new branch with no origin/develop or origin/main" "$(scope_for "$sha" "$ZERO_SHA")"

assert_scope light "branch deletion has nothing to check" \
    "$(printf 'refs/heads/topic %s refs/heads/topic %s\n' "$ZERO_SHA" "$sha" | bash "$REPOSITORY_ROOT/scripts/pre-push-scope.sh")"

echo "Pre-push scope decisions are correct."
