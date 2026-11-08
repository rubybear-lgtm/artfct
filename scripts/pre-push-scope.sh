#!/usr/bin/env bash
set -euo pipefail

# Decides how much of .githooks/pre-push has to run for the refs being pushed.
#
# Reads the pre-push stdin (<local ref> <local sha> <remote ref> <remote sha>)
# and prints exactly one word:
#   light  every changed file is release/CI plumbing that no PHP, Rust or
#          frontend test reads (CI workflows, scripts/release, scripts/staging,
#          RELEASING.md), so the full workspace run adds nothing.
#   full   anything else, or anything this script cannot resolve.
#
# The allowlist is deliberately small and explicit. Markdown is NOT treated as
# non-code: README.md and docs/mcp-runbook.md are read by Pest tests.

zero_sha='0000000000000000000000000000000000000000'
changed_files=""

while read -r local_ref local_sha remote_ref remote_sha; do
    if [ -z "${local_sha:-}" ] || [ "$local_sha" = "$zero_sha" ]; then
        continue
    fi

    base=""

    if [ "$remote_sha" = "$zero_sha" ]; then
        for candidate in origin/develop origin/main; do
            if base="$(git merge-base "$local_sha" "$candidate" 2>/dev/null)"; then
                break
            fi

            base=""
        done
    elif git cat-file -e "${remote_sha}^{commit}" 2>/dev/null; then
        base="$remote_sha"
    fi

    if [ -z "$base" ]; then
        echo full
        exit 0
    fi

    if ! ref_files="$(git diff --no-renames --name-only "$base" "$local_sha" 2>/dev/null)"; then
        echo full
        exit 0
    fi

    changed_files+="${ref_files}"$'\n'
done

while IFS= read -r file; do
    if [ -z "$file" ]; then
        continue
    fi

    case "$file" in
        .github/workflows/* | scripts/release/* | scripts/staging/* | RELEASING.md) ;;
        *)
            echo full
            exit 0
            ;;
    esac
done <<< "$changed_files"

echo light
