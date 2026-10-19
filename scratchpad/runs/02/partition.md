# Spec 02 partition

| Unit | Owns these files | DoD items | Depends on |
|---|---|---|---|
| Shared provenance and Git discovery | `mcp-server/src/provenance.rs` | One required provenance struct; Git repo/branch/commit/dirty discovery; credential-scrubbed origin URL; explicit source for every nullable field; CLI and MCP builder inputs | Spec 01 session field contract, read-only |
| CLI request wiring | `mcp-server/src/api.rs`; `mcp-server/src/artifact_crypto.rs`; `mcp-server/src/main.rs` | CLI attaches process-observed Git provenance and source path; session/model remain absent; unnamed preparation boolean becomes a named field; request still validates against Spec 00 | Shared provenance public types/builders |
| MCP request wiring | `mcp-server/src/mcp.rs`; `README.md` | MCP attaches session agent/version/id/tool and optional self-reported model; tool schema accepts `model`; outbound request validates without merging attested and observed fields | Shared provenance public types/builders |
| Serialized integration and evidence | `mcp-server/tests/provenance_integration.rs`; `CHANGELOG.md`; `scratchpad/runs/02/verification.md`; `scratchpad/runs/02/mutation.md` only if a negative test is introduced | Enriched create succeeds against the current Worker and returns a working URL; spec-level changelog and gate evidence | All implementation units |

Each path has one owner. `openapi/artfct.yaml` is read-only because Spec 00 already
defines the required provenance shape and source enum. `backend/src/lib.rs` is
also read-only: its request type deliberately accepts unknown fields until Spec
03 stores provenance.

No dependency changes are planned. The integration test is opt-in and targets a
local Wrangler Worker started from the current `backend` source; it must be run
explicitly during verification and must not contact production.
