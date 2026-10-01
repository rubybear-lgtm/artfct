#!/usr/bin/env bash
# RUB-317 Worker integration check against `wrangler dev --local`: auth, org isolation, pagination, legal holds and shared-blob refcounting.
# Start the Worker from `backend/` with persistent local storage: `../node_modules/.bin/wrangler dev --local --port 8799 --persist-to .wrangler/state --var ARTFCT_ORG_TOKEN:tok --var ARTFCT_ORG_SLUG:acme --var ARTFCT_GOVERNANCE_SECRET:gov`
set -euo pipefail

B="${ARTFCT_GOVERNANCE_E2E_BASE_URL:-http://127.0.0.1:8799}"
WRANGLER="${ARTFCT_WRANGLER_BIN:-./node_modules/.bin/wrangler}"
WRANGLER_CONFIG="${ARTFCT_WRANGLER_CONFIG:-backend/wrangler.jsonc}"
WRANGLER_PERSIST_TO="${ARTFCT_WRANGLER_PERSIST_TO:-backend/.wrangler/state}"
R2_OBJECT_FILE=$(mktemp)
trap 'rm -f "$R2_OBJECT_FILE"' EXIT

case "$B" in
    http://127.0.0.1:*|http://localhost:*) ;;
    *) printf 'Refusing non-local base URL: this proof must target Wrangler local storage.\n' >&2; exit 2 ;;
esac

"$WRANGLER" d1 migrations apply artfct-artifacts --local --config "$WRANGLER_CONFIG" \
    --persist-to "$WRANGLER_PERSIST_TO" >/dev/null

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

expect_local_r2_object() {
    local hash="$1" expected_present="$2" object_path="artfct-blobs/blobs/$1" actual_hash
    rm -f "$R2_OBJECT_FILE"
    if "$WRANGLER" r2 object get "$object_path" --local --config "$WRANGLER_CONFIG" \
        --persist-to "$WRANGLER_PERSIST_TO" --file "$R2_OBJECT_FILE" >/dev/null 2>&1; then
        if [[ "$expected_present" != true ]]; then
            printf 'FAIL direct local R2 read: %s still exists\n' "$object_path" >&2
            return 1
        fi
        actual_hash=$(shasum -a 256 "$R2_OBJECT_FILE" | cut -d' ' -f1)
        if [[ "$actual_hash" != "$hash" ]]; then
            printf 'FAIL direct local R2 read: %s had unexpected bytes\n' "$object_path" >&2
            return 1
        fi
        printf 'direct local R2 read: %s exists with expected bytes\n' "$object_path"
    elif [[ "$expected_present" == true ]]; then
        printf 'FAIL direct local R2 read: %s is missing\n' "$object_path" >&2
        return 1
    else
        printf 'direct local R2 read: %s returns not found\n' "$object_path"
    fi
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
expect_local_r2_object "$SX" true
expect_local_r2_object "$SY" true

"$WRANGLER" d1 execute artfct-artifacts --local --config "$WRANGLER_CONFIG" \
    --persist-to "$WRANGLER_PERSIST_TO" --command \
    "INSERT INTO orgs (id, slug, created_at) VALUES ('other-org-id', 'other', datetime('now')); INSERT INTO artifacts (row_id, id, org_id, content_hash, entrypoint, created_at, retention_class, tier, manifest) VALUES ('other-row-id', '00000000000000000000000000000002', 'other-org-id', '$SY', 'index.html', datetime('now'), 'permanent', 'secure', '{}');" >/dev/null

expect_status 'governance without secret' 401 "$B/v1/internal/orgs/acme/governance/artifacts" >/dev/null
request_governance 'governance list' 200 "$B/v1/internal/orgs/acme/governance/artifacts" >/dev/null
python3 -c 'import json,sys; data=json.load(sys.stdin); assert {row["id"] for row in data["artifacts"]} == set(sys.argv[1:])' "$A" "$Bid" <<<"$RESPONSE_BODY"
request_governance 'unknown organization' 404 "$B/v1/internal/orgs/nope/governance/artifacts" >/dev/null
request_governance 'cross-org delete is not found' 404 -X DELETE \
    "$B/v1/internal/orgs/acme/governance/artifacts/00000000000000000000000000000002" >/dev/null
request_governance 'foreign artifact remains under its owning org' 200 \
    "$B/v1/internal/orgs/other/governance/artifacts" >/dev/null
python3 -c 'import json,sys; data=json.load(sys.stdin); assert [row["id"] for row in data["artifacts"]] == ["00000000000000000000000000000002"]' <<<"$RESPONSE_BODY"
"$WRANGLER" d1 execute artfct-artifacts --local --config "$WRANGLER_CONFIG" \
    --persist-to "$WRANGLER_PERSIST_TO" --command \
    "DELETE FROM artifacts WHERE org_id = 'other-org-id'; DELETE FROM orgs WHERE id = 'other-org-id';" >/dev/null
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
expect_local_r2_object "$SX" true
expect_local_r2_object "$SY" false
expect_status 'B no longer serves' 404 -H 'Authorization: Bearer tok' -o /dev/null "$B/p/$Bid"
request_governance 'release legal hold on A' 204 -X DELETE "$B/v1/internal/orgs/acme/governance/artifacts/$A/legal-hold" >/dev/null
request_governance 'delete last referrer A' 204 -X DELETE "$B/v1/internal/orgs/acme/governance/artifacts/$A" >/dev/null
expect_status 'shared blob removed with last referrer' 404 -H 'Authorization: Bearer tok' -o /dev/null "$B/v1/blobs/$SX"
expect_local_r2_object "$SX" false
request_governance 'sweep zero-ref blobs' 200 -X POST "$B/v1/internal/orgs/acme/governance/sweep-orphans" >/dev/null
audit_events_json=$("$WRANGLER" d1 execute artfct-artifacts --local --config "$WRANGLER_CONFIG" \
    --persist-to "$WRANGLER_PERSIST_TO" --json --command \
    "SELECT event_type, org_id, payload FROM audit_events WHERE org_id = 'acme';")
python3 -c 'import json,sys; result=json.load(sys.stdin); rows=result[0]["results"] if result else []; events={(row["event_type"], row["org_id"], json.loads(row["payload"])["artifact_id"]) for row in rows}; expected={("legal_hold.placed", "acme", sys.argv[1]), ("legal_hold.released", "acme", sys.argv[1]), ("artifact.hard_deleted", "acme", sys.argv[1]), ("artifact.hard_deleted", "acme", sys.argv[2])}; assert expected <= events, f"missing audit events: {expected - events}"' "$A" "$Bid" <<<"$audit_events_json"
printf 'D1 audit events include both artifact deletions and legal-hold changes.\n'
printf 'Governance Worker integration passed.\n'
