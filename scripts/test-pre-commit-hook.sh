#!/usr/bin/env bash
set -euo pipefail

REPOSITORY_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
TEST_DIRECTORY="$(mktemp -d "${TMPDIR:-/tmp}/artfct-pre-commit-test.XXXXXX")"
MINIMAL_PATH="/usr/bin:/bin:/usr/sbin:/sbin"
SYNTHETIC_TOKEN="AKIA$(printf '%016d' 0)"

cleanup() {
    rm -rf "$TEST_DIRECTORY"
}
trap cleanup EXIT

initialize_repository() {
    local test_repository="$1"

    mkdir -p "$test_repository"
    git -C "$test_repository" init -q
    git -C "$test_repository" config user.email test@example.invalid
    git -C "$test_repository" config user.name "Pre-commit hook test"
}

run_hook() {
    local test_repository="$1"

    (
        cd "$test_repository"
        PATH="$MINIMAL_PATH" /bin/bash "$REPOSITORY_ROOT/.githooks/pre-commit"
    ) >/dev/null 2>&1
}

initialize_repository "$TEST_DIRECTORY/clean"
printf 'ordinary staged content\n' > "$TEST_DIRECTORY/clean/readme.txt"
git -C "$TEST_DIRECTORY/clean" add readme.txt

if ! run_hook "$TEST_DIRECTORY/clean"; then
    echo "The pre-commit hook rejected a clean staged file without gitleaks." >&2
    exit 1
fi

initialize_repository "$TEST_DIRECTORY/ordinary"
printf 'value=%s\n' "$SYNTHETIC_TOKEN" > "$TEST_DIRECTORY/ordinary/sample.txt"
git -C "$TEST_DIRECTORY/ordinary" add sample.txt

if run_hook "$TEST_DIRECTORY/ordinary"; then
    echo "The pre-commit hook missed a synthetic secret without gitleaks." >&2
    exit 1
fi

initialize_repository "$TEST_DIRECTORY/space"
printf 'value=%s\n' "$SYNTHETIC_TOKEN" > "$TEST_DIRECTORY/space/secret file.txt"
git -C "$TEST_DIRECTORY/space" add "secret file.txt"

if run_hook "$TEST_DIRECTORY/space"; then
    echo "The pre-commit hook missed a synthetic secret in a whitespace path." >&2
    exit 1
fi

echo "Pre-commit fallback passed clean-file, missing-gitleaks, and whitespace-path checks."
