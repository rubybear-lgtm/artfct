# Spec 03 verification

Implemented the storage split with D1 schema/migrations, R2 blob paths, permanent
single-file create and upload routes, content-addressed identifiers, provenance
columns plus JSON payload, reference-counted deletion, bounded export URLs, and
CLI raw-blob export verification.

Verification gates:

- `cargo fmt --all -- --check` — passed.
- `cargo clippy --workspace --all-targets -- -D warnings` — passed.
- `cargo test --workspace` — passed (58 MCP/CLI unit tests and 32 backend unit
  tests; environment-gated integration tests are ignored in the default run).
- `worker-build --release` — passed for the actual WebAssembly target.
- Isolated local Wrangler integration — passed all 13 tests against a fresh D1
  migration plus local R2/KV state, including 6 MiB upload, dedupe/refcounts,
  delete lifecycle, provenance JSON, CLI export, unchanged ephemeral flow, and
  concurrent duplicate-create, concurrent-delete, and create-versus-final-delete
  lifecycle races.
- `git diff --check` — passed.
- `npx --yes @redocly/cli@2.15.2 lint openapi/artfct.yaml` — valid with 16
  pre-existing structural warnings for reserved/unimplemented and preflight
  operations.
- Negative mutation checks — both the ephemeral manifest guard and permanent
  authentication guard caused their focused tests to fail when weakened, then
  passed after restoration.

No remote Cloudflare or Railway deployment was performed.
