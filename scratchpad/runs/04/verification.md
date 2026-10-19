# Spec 04 verification

Implemented manifest validation, deterministic canonical bundle IDs, per-file D1/R2
metadata, missing-file upload fan-out, nested preview paths, one-hour incomplete
expiry metadata, and recursive CLI directory deployment.

Final independent gates run on 2026-09-03:

- `cargo fmt --all -- --check` — passed.
- `cargo check --workspace` — passed.
- `cargo clippy --workspace --all-targets -- -D warnings` — passed.
- `cargo test --workspace` — passed: 62 CLI/MCP tests and 44 backend tests;
  production integrations are intentionally ignored in the default suite.
- `./target/worker-tools/bin/worker-build --release` from `backend/` — passed,
  including `wasm-opt` and final Worker JavaScript packaging.
- `npm exec -- vite build --outDir /tmp/artfct-spec04-vite-check` from the fixture —
  passed and emitted HTML, JavaScript, CSS, and WOFF2 assets.
- Fresh local D1 migration through Wrangler 4.101.0 — passed, 19 commands.
- Full production-path suite against a fresh local Wrangler Worker with isolated
  KV, D1, and R2: `cargo test -p artfct --test storage_integration -- --ignored
  --test-threads=1` — passed, 19/19 tests.
- `npx --yes @redocly/cli@2.15.2 lint openapi/artfct.yaml` — valid with 16
  pre-existing recommended-rule warnings for deferred `501` and OPTIONS routes.
- `git diff --check` — passed.

The fresh runtime suite covers real Vite assets and compiled React marker,
one-file redeploy upload/skip counts, incomplete preview 404, exact expired-row
cleanup, shared-hash paths and per-path content types, D1/R2 refcounts, export,
ephemeral compatibility, and concurrent create/delete regressions from Spec 03.

Seven independent negative mutations were executed and restored; see
`scratchpad/runs/04/mutation.md`. No Cloudflare or Railway deployment was run.
