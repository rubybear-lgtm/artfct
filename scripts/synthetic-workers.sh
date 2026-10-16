#!/usr/bin/env bash
# RUB-325: starts the two local Worker instances the synthetic seed deploys to
# (one org per Worker). Defaults match config/synthetic.php. Ctrl-C stops both.
set -euo pipefail
cd "$(dirname "$0")/../backend"
W=../node_modules/.bin/wrangler
start() { # name port slug token gov limits
  "$W" d1 migrations apply ARTIFACTS_DB --local --persist-to ".wrangler/synthetic-$1" >/dev/null
  "$W" dev --local --port "$2" --inspector-port "$(( $2 + 100 ))" --persist-to ".wrangler/synthetic-$1" \
    --var "ARTFCT_ORG_SLUG:$3" --var "ARTFCT_ORG_TOKEN:$4" --var "ARTFCT_GOVERNANCE_SECRET:$5" --var "ARTFCT_LIMITS_WRITE_SECRET:$6" \
    --var "ARTFCT_PUBLIC_BASE_URL:http://127.0.0.1:$2" &
}
trap 'kill 0' EXIT
start a 8801 zz-northwind "${SYNTHETIC_LOCAL_A_TOKEN:-synthetic-a-token}" "${SYNTHETIC_LOCAL_A_GOVERNANCE:-synthetic-a-gov}" "${SYNTHETIC_LOCAL_A_LIMITS:-synthetic-a-limits}"
start b 8802 zz-northwind-b "${SYNTHETIC_LOCAL_B_TOKEN:-synthetic-b-token}" "${SYNTHETIC_LOCAL_B_GOVERNANCE:-synthetic-b-gov}" "${SYNTHETIC_LOCAL_B_LIMITS:-synthetic-b-limits}"
wait
