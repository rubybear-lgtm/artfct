#!/usr/bin/env bash
# RUB-317 Worker integration check against `wrangler dev --local`: auth, org isolation, pagination, legal holds and shared-blob refcounting.
# Start the Worker first: cd backend && ../node_modules/.bin/wrangler dev --local --port 8799 --var ARTFCT_ORG_TOKEN:tok --var ARTFCT_ORG_SLUG:acme --var ARTFCT_GOVERNANCE_SECRET:gov
set -euo pipefail

B="${ARTFCT_GOVERNANCE_E2E_BASE_URL:-http://127.0.0.1:8799}"

expect_status() {
    local label="$1" expected="$2" response status body
    shift 2

    response=$(curl -sS -w $'\n%{http_code}' "$@")
    status="${response##*$'\n'}"
    body="${response%$'\n'*}"
    if [[ "$status" != "$expected" ]]; then
        printf 'FAIL %s: expected HTTP %s, got %s\n%s\n' "$label" "$expected" "$status" "$body" >&2
        return 1
    fi

    printf '%s: HTTP %s\n' "$label" "$status"
    if [[ -n "$body" ]]; then
        printf '%s\n' "$body"
    fi
    RESPONSE_BODY="$body"
}

create() {
    local response status body
    response=$(curl -sS -w $'\n%{http_code}' -X POST \
        -H 'Authorization: Bearer tok' -H 'content-type: application/json' \
        -d "$1" "$B/v1/artifacts")
    status="${response##*$'\n'}"
    body="${response%$'\n'*}"
    if [[ "$status" != 201 ]]; then
        printf 'Artifact create failed: HTTP %s\n%s\n' "$status" "$body" >&2
        return 1
    fi
    printf '%s\n' "$body" | python3 -c 'import json,sys; print(json.load(sys.stdin)["id"])'
}

request_governance() {
    local label="$1" expected="$2"
    shift 2
    expect_status "$label" "$expected" -H 'Authorization: Bearer gov' "$@"
}

X='<h1>shared</h1>'
Y='only-in-b'
SX=$(printf '%s' "$X" | shasum -a 256 | cut -d' ' -f1)
SY=$(printf '%s' "$Y" | shasum -a 256 | cut -d' ' -f1)
base='"mode":"permanent","tier":"secure","title":"t","description":"d","thumbnail":"x","preview_blurred":false,"provenance":{"agent":"claude-code"}'
A=$(create "{$base,\"manifest\":{\"entrypoint\":\"index.html\",\"external_origins\":[],\"files\":[{\"path\":\"index.html\",\"sha256\":\"$SX\",\"size_bytes\":${#X},\"content_type\":\"text/html\"}]}}")
Bid=$(create "{$base,\"manifest\":{\"entrypoint\":\"index.html\",\"external_origins\":[],\"files\":[{\"path\":\"index.html\",\"sha256\":\"$SX\",\"size_bytes\":${#X},\"content_type\":\"text/html\"},{\"path\":\"y.txt\",\"sha256\":\"$SY\",\"size_bytes\":${#Y},\"content_type\":\"text/plain\"}]}}")
[[ "$A" != "$Bid" ]]
printf 'Created A=%s B=%s\n' "$A" "$Bid"

upload() {
    expect_status "upload $2" 204 -X PUT -H 'Authorization: Bearer tok' \
        --data-binary "$3" "$B/v1/artifacts/$1/files/$2" >/dev/null
}
upload "$A" "$SX" "$X"
upload "$Bid" "$SX" "$X"
upload "$Bid" "$SY" "$Y"

expect_status 'governance without secret' 401 "$B/v1/internal/orgs/acme/governance/artifacts" >/dev/null
request_governance 'governance list' 200 "$B/v1/internal/orgs/acme/governance/artifacts" >/dev/null
python3 -c 'import json,sys; data=json.load(sys.stdin); assert {row["id"] for row in data["artifacts"]} == set(sys.argv[1:])' "$A" "$Bid" <<<"$RESPONSE_BODY"
request_governance 'unknown organization' 404 "$B/v1/internal/orgs/nope/governance/artifacts" >/dev/null
request_governance 'older-than list' 200 "$B/v1/internal/orgs/acme/governance/artifacts?older_than=2000-01-01T00:00:00Z" >/dev/null
python3 -c 'import json,sys; assert json.load(sys.stdin)["artifacts"] == []' <<<"$RESPONSE_BODY"
request_governance 'paginated list' 200 "$B/v1/internal/orgs/acme/governance/artifacts?limit=1" >/dev/null
python3 -c 'import json,sys; assert json.load(sys.stdin)["next_cursor"] is not None' <<<"$RESPONSE_BODY"
request_governance 'place legal hold on A' 204 -X PUT "$B/v1/internal/orgs/acme/governance/artifacts/$A/legal-hold" >/dev/null
request_governance 'governance delete held A' 409 -X DELETE "$B/v1/internal/orgs/acme/governance/artifacts/$A" >/dev/null
expect_status 'public delete held A' 409 -X DELETE -H 'Authorization: Bearer tok' "$B/v1/artifacts/$A" >/dev/null
expect_status 'held A still serves' 200 -H 'Authorization: Bearer tok' -o /dev/null "$B/p/$A"
request_governance 'delete unheld B' 204 -X DELETE "$B/v1/internal/orgs/acme/governance/artifacts/$Bid" >/dev/null
expect_status 'shared blob survives first delete' 200 -H 'Authorization: Bearer tok' -o /dev/null "$B/v1/blobs/$SX"
expect_status 'unique blob removed with B' 404 -H 'Authorization: Bearer tok' -o /dev/null "$B/v1/blobs/$SY"
expect_status 'B no longer serves' 404 -H 'Authorization: Bearer tok' -o /dev/null "$B/p/$Bid"
request_governance 'release legal hold on A' 204 -X DELETE "$B/v1/internal/orgs/acme/governance/artifacts/$A/legal-hold" >/dev/null
request_governance 'delete last referrer A' 204 -X DELETE "$B/v1/internal/orgs/acme/governance/artifacts/$A" >/dev/null
expect_status 'shared blob removed with last referrer' 404 -H 'Authorization: Bearer tok' -o /dev/null "$B/v1/blobs/$SX"
request_governance 'sweep zero-ref blobs' 200 -X POST "$B/v1/internal/orgs/acme/governance/sweep-orphans" >/dev/null
printf 'Governance Worker integration passed.\n'
