# Spec 04 partition

| Unit | Owns these files | DoD items | Depends on |
|---|---|---|---|
| Bundle format and API (serialized single unit) | `backend/src/lib.rs`, `backend/src/store.rs`, `backend/migrations/*.sql`, `mcp-server/src/api.rs`, `mcp-server/src/artifact_crypto.rs`, `mcp-server/src/cli.rs`, `mcp-server/src/main.rs`, `mcp-server/tests/storage_integration.rs`, `mcp-server/tests/fixtures/vite-react/**`, `openapi/artfct.yaml`, `README.md`, `DOCUMENTATION.md`, `CHANGELOG.md`, `scratchpad/runs/04/verification.md`, `scratchpad/runs/04/mutation.md` | All nine Definition of Done items and all 16 named tests in `docs/specs/04-bundle-format.md` | Accepted Spec 03 checkpoint `0482002` |

This is one serialized unit because canonical bundle identity, manifest validation,
per-file D1/R2 reference accounting, preview path resolution, CLI directory
walking, and the upload protocol share request types and lifecycle invariants.
Splitting those files across concurrent implementers would create overlapping
ownership and an unverifiable intermediate protocol.
