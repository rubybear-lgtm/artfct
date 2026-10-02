# Spec 02 verification

Verified on 2026-09-03 from the repository root.

| Gate | Result |
|---|---|
| `cargo fmt --all -- --check` | Passed |
| `cargo clippy --workspace --all-targets -- -D warnings` | Passed |
| `cargo test --workspace` | Passed: 55 CLI/MCP tests, 18 Worker tests, and the local integration test safely ignored by default |
| Explicit local Worker integration | Passed: 1 test |
| `npx --yes @redocly/cli@2.15.2 lint openapi/artfct.yaml` | Exit 0; valid OpenAPI 3.1 with the existing 20 advisory response-class warnings |
| `git diff --check` | Passed |

The explicit integration command was:

```text
ARTFCT_INTEGRATION_BASE_URL=http://127.0.0.1:8788 \
  cargo test -p artfct --test provenance_integration -- \
  --ignored --exact deploy_against_current_worker_succeeds_with_provenance --nocapture
```

It ran against Wrangler 4.101.0 in `dev --local` mode with local KV and
`ARTFCT_PUBLIC_BASE_URL` bound to `http://127.0.0.1:8788`. The built CLI sent an
enriched create request, the current Worker returned `201`, and the returned
preview URL returned `200` with the encrypted preview shell. The local Wrangler
session was then stopped. No production endpoint or deployment was used.

No tests in Spec 02 are marked negative, so no mutation gate applies. Per the
user's instruction, application deployment remains deferred until every spec is
complete.
