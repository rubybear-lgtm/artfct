#!/usr/bin/env bash
# RUB-344 live check: two orgs on ONE local Worker using signed org tokens.
# Usage: scripts/multi-org-isolation-local.sh   (starts and stops its own `wrangler dev`)
set -euo pipefail
cd "$(dirname "$0")/.."
W=http://127.0.0.1:8811; TMP=$(mktemp -d); trap 'pkill -f "wrangler dev --local --port 8811" || true; rm -rf backend/.wrangler/multi "$TMP"' EXIT
(cd backend && ../node_modules/.bin/wrangler d1 migrations apply ARTIFACTS_DB --local --persist-to .wrangler/multi >/dev/null 2>&1
 ../node_modules/.bin/wrangler dev --local --port 8811 --persist-to .wrangler/multi --var ARTFCT_JWKS_WRITE_SECRET:jw --var ARTFCT_LIMITS_WRITE_SECRET:lim >"$TMP/wrangler.log" 2>&1 &)
until curl -s -o /dev/null "$W/p/x"; do sleep 1; done
cat > "$TMP/mint.php" <<'PHP'
<?php
require getcwd().'/vendor/autoload.php';
$key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
openssl_pkey_export($key, $pem);
$svc = new App\Services\Auth\OrgJwtService($pem, 'multi-test');
$mk = fn (string $org) => $svc->mint(new App\Models\Team(['slug' => $org]), tap(new App\Models\User, fn ($u) => $u->id = 1), App\Enums\TeamRole::Admin, 900)['token'];
echo json_encode(['jwks' => ['keys' => [$svc->jwk()]], 'a' => $mk('iso-a'), 'b' => $mk('iso-b')]);
PHP
php "$TMP/mint.php" > "$TMP/m.json"
python3 - "$TMP" <<'PY'
import json,sys;t=sys.argv[1];d=json.load(open(t+'/m.json'))
open(t+'/jwks.json','w').write(json.dumps(d['jwks']));open(t+'/a','w').write(d['a']);open(t+'/b','w').write(d['b'])
PY
curl -sf -X POST -H 'Authorization: Bearer jw' -H 'content-type: application/json' -d @"$TMP/jwks.json" "$W/v1/internal/jwks" >/dev/null
TA=$(cat "$TMP/a"); TB=$(cat "$TMP/b"); fail=0
mk() { X="content-$1"; SX=$(printf '%s' "$X" | shasum -a 256 | cut -d' ' -f1)
  ID=$(curl -s -X POST -H "Authorization: Bearer $2" -H 'content-type: application/json' -d "{\"mode\":\"permanent\",\"tier\":\"secure\",\"title\":\"t\",\"description\":\"d\",\"thumbnail\":\"\",\"preview_blurred\":false,\"provenance\":{},\"manifest\":{\"entrypoint\":\"index.html\",\"external_origins\":[],\"files\":[{\"path\":\"index.html\",\"sha256\":\"$SX\",\"size_bytes\":${#X},\"content_type\":\"text/html\"}]}}" "$W/v1/artifacts" | python3 -c 'import sys,json;print(json.load(sys.stdin)["id"])')
  curl -s -o /dev/null -X PUT -H "Authorization: Bearer $2" --data-binary "$X" "$W/v1/artifacts/$ID/files/$SX"; echo "$ID"; }
IDA=$(mk a-secret "$TA"); IDB=$(mk b-secret "$TB")
check() { got=$(curl -s -o /dev/null -w '%{http_code}' "${@:3}"); if [ "$got" = "$2" ]; then echo "ok   $1 ($got)"; else echo "FAIL $1 (expected $2, got $got)"; fail=1; fi; }
check "A lists own org" 200 -H "Authorization: Bearer $TA" "$W/v1/orgs/iso-a/artifacts"
check "A lists B's org" 404 -H "Authorization: Bearer $TA" "$W/v1/orgs/iso-b/artifacts"
check "A reads B's content" 404 -H "Authorization: Bearer $TA" "$W/v1/orgs/iso-b/artifacts/$IDB/content"
check "A reads B's usage" 404 -H "Authorization: Bearer $TA" "$W/v1/orgs/iso-b/usage"
check "A serves own secure artifact" 200 -H "Authorization: Bearer $TA" "$W/p/$IDA"
check "A serves B's secure artifact" 401 -H "Authorization: Bearer $TA" "$W/p/$IDB"
check "anonymous serves secure artifact" 401 "$W/p/$IDA"
check "A deletes B's artifact" 404 -X DELETE -H "Authorization: Bearer $TA" "$W/v1/artifacts/$IDB"
check "A revokes B's artifact" 404 -X PATCH -H "Authorization: Bearer $TA" -H 'content-type: application/json' -d '{"revoked_at":"2026-01-01T00:00:00Z"}' "$W/v1/orgs/iso-b/artifacts/$IDB"
check "B's artifact survives" 200 -H "Authorization: Bearer $TB" "$W/p/$IDB"
check "no credential lists" 401 "$W/v1/orgs/iso-a/artifacts"
# RUB-351: byte-identical bundles from two orgs must not collide on /p/{id}.
SAME_A=$(mk same "$TA"); SAME_B=$(mk same "$TB")
if [ "$SAME_A" != "$SAME_B" ]; then echo "ok   identical bundles get different ids per org"; else echo "FAIL identical bundles share id $SAME_A"; fail=1; fi
check "A serves its own copy of the shared bundle" 200 -H "Authorization: Bearer $TA" "$W/p/$SAME_A"
check "B serves its own copy of the shared bundle" 200 -H "Authorization: Bearer $TB" "$W/p/$SAME_B"
check "A cannot serve B's copy of the shared bundle" 401 -H "Authorization: Bearer $TA" "$W/p/$SAME_B"
# Quota and payment state are per org on the shared Worker: put org A into read-only
# (past due) and check only A's creates are refused.
curl -sf -X POST -H 'Authorization: Bearer lim' -H 'content-type: application/json' \
  -d '{"org":"iso-a","storage_bytes":5368709120,"artifacts_per_month":1000,"bundle_ceiling_bytes":10485760,"read_only":true}' "$W/v1/internal/org-limits" >/dev/null
create() { X="quota-$1"; SX=$(printf '%s' "$X" | shasum -a 256 | cut -d' ' -f1)
  curl -s -o /dev/null -w '%{http_code}' -X POST -H "Authorization: Bearer $2" -H 'content-type: application/json' -d "{\"mode\":\"permanent\",\"tier\":\"secure\",\"title\":\"t\",\"description\":\"d\",\"thumbnail\":\"\",\"preview_blurred\":false,\"provenance\":{},\"manifest\":{\"entrypoint\":\"index.html\",\"external_origins\":[],\"files\":[{\"path\":\"index.html\",\"sha256\":\"$SX\",\"size_bytes\":${#X},\"content_type\":\"text/html\"}]}}" "$W/v1/artifacts"; }
a=$(create a "$TA"); b=$(create b "$TB")
if [ "$a" = "403" ] && [ "$b" = "201" ]; then echo "ok   past-due org A refused (403), org B unaffected (201)"; else echo "FAIL quota isolation (A=$a expected 403, B=$b expected 201)"; fail=1; fi
check "A's existing artifact still serves while past due" 200 -H "Authorization: Bearer $TA" "$W/p/$IDA"
exit $fail
