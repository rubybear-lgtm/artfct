#!/usr/bin/env bash
# RUB-317 live check against `wrangler dev --local`: shared-blob refcounting, legal hold (409 on both delete routes), hard delete and audit rows.
# Start the Worker first: cd backend && ../node_modules/.bin/wrangler dev --local --port 8799 --var ARTFCT_ORG_TOKEN:tok --var ARTFCT_ORG_SLUG:acme --var ARTFCT_GOVERNANCE_SECRET:gov
B=http://127.0.0.1:8799
X='<h1>shared</h1>'; Y='only-in-b'
SX=$(printf '%s' "$X" | shasum -a 256 | cut -d' ' -f1); SY=$(printf '%s' "$Y" | shasum -a 256 | cut -d' ' -f1)
create() { curl -s -X POST -H 'Authorization: Bearer tok' -H 'content-type: application/json' -d "$1" $B/v1/artifacts; }
base='"mode":"permanent","tier":"secure","title":"t","description":"d","thumbnail":"x","preview_blurred":false,"provenance":{"agent":"claude-code"}'
A=$(create "{$base,\"manifest\":{\"entrypoint\":\"index.html\",\"external_origins\":[],\"files\":[{\"path\":\"index.html\",\"sha256\":\"$SX\",\"size_bytes\":${#X},\"content_type\":\"text/html\"}]}}" | python3 -c 'import sys,json;print(json.load(sys.stdin)["id"])')
Bid=$(create "{$base,\"manifest\":{\"entrypoint\":\"index.html\",\"external_origins\":[],\"files\":[{\"path\":\"index.html\",\"sha256\":\"$SX\",\"size_bytes\":${#X},\"content_type\":\"text/html\"},{\"path\":\"y.txt\",\"sha256\":\"$SY\",\"size_bytes\":${#Y},\"content_type\":\"text/plain\"}]}}" | python3 -c 'import sys,json;print(json.load(sys.stdin)["id"])')
echo "A=$A B=$Bid"
up() { curl -s -o /dev/null -w "upload $2: %{http_code}\n" -X PUT -H 'Authorization: Bearer tok' --data-binary "$3" $B/v1/artifacts/$1/files/$2; }
up $A $SX "$X"; up $Bid $SX "$X"; up $Bid $SY "$Y"
G="-H 'Authorization: Bearer gov'"
g() { curl -s -w " [%{http_code}]" -H 'Authorization: Bearer gov' "$@"; echo; }
echo "--- no secret:"; curl -s -o /dev/null -w "%{http_code}\n" $B/v1/internal/orgs/acme/governance/artifacts
echo "--- list:"; g $B/v1/internal/orgs/acme/governance/artifacts
echo "--- list unknown org:"; g $B/v1/internal/orgs/nope/governance/artifacts
echo "--- list older_than 2000 (none):"; g "$B/v1/internal/orgs/acme/governance/artifacts?older_than=2000-01-01T00:00:00Z"
echo "--- pagination limit=1:"; g "$B/v1/internal/orgs/acme/governance/artifacts?limit=1"
echo "--- place hold on A:"; g -X PUT $B/v1/internal/orgs/acme/governance/artifacts/$A/legal-hold
echo "--- governance DELETE held A (409):"; g -X DELETE $B/v1/internal/orgs/acme/governance/artifacts/$A
echo "--- public DELETE held A (409):"; curl -s -w " [%{http_code}]\n" -X DELETE -H 'Authorization: Bearer tok' $B/v1/artifacts/$A
echo "--- A still serves:"; curl -s -o /dev/null -w "%{http_code}\n" -H 'Authorization: Bearer tok' $B/p/$A
echo "--- release hold, delete B (shared blob X must survive):"; g -X DELETE $B/v1/internal/orgs/acme/governance/artifacts/$Bid
echo "blob X (shared) still fetchable:"; curl -s -o /dev/null -w "%{http_code}\n" -H 'Authorization: Bearer tok' $B/v1/blobs/$SX
echo "blob Y (unique to B) gone:"; curl -s -o /dev/null -w "%{http_code}\n" -H 'Authorization: Bearer tok' $B/v1/blobs/$SY
echo "B 404:"; curl -s -o /dev/null -w "%{http_code}\n" -H 'Authorization: Bearer tok' $B/p/$Bid
g -X DELETE $B/v1/internal/orgs/acme/governance/artifacts/$A/legal-hold
echo "--- delete A (last referrer of X):"; g -X DELETE $B/v1/internal/orgs/acme/governance/artifacts/$A
echo "blob X gone:"; curl -s -o /dev/null -w "%{http_code}\n" -H 'Authorization: Bearer tok' $B/v1/blobs/$SX
echo "--- sweep:"; g -X POST $B/v1/internal/orgs/acme/governance/sweep-orphans
echo SX=$SX SY=$SY > /dev/null
