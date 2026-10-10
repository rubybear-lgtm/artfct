# Spec 03 partition

| Unit | Owns these files | DoD items | Depends on |
|---|---|---|---|
| Integrated storage split | `backend/Cargo.toml`, `Cargo.lock`, `backend/src/lib.rs`, `backend/src/store.rs`, `backend/migrations/0001_storage.sql`, `backend/wrangler.jsonc`, `mcp-server/src/api.rs`, `mcp-server/src/artifact_crypto.rs`, `mcp-server/src/cli.rs`, `mcp-server/src/main.rs`, `mcp-server/tests/storage_integration.rs`, `openapi/artfct.yaml`, `README.md`, `DOCUMENTATION.md`, `CHANGELOG.md`, `scratchpad/runs/03/verification.md`, `scratchpad/runs/03/mutation.md` | All ten Definition-of-Done items and all sixteen named tests | Specs 00 and 02 |

This is intentionally one serialized unit. Permanent request serialization, the
Worker's explicit mode branch, D1 metadata, R2 blob lifecycle, content-addressed
IDs, authentication seam, preview reads, deletion, and export all share the same
wire contract and storage invariants. Splitting those paths would create overlapping
ownership of the request and handler types.

The user approved enabling the existing `worker/d1` feature in
`backend/Cargo.toml`; no new package is planned. `Cargo.lock` is owned only in
case Cargo records a feature-resolution change. The CLI already has `ring` for
SHA-256. The Worker will validate the client-declared digest using R2's SHA-256
checksum support and derive the 32-character public ID from the full digest.
