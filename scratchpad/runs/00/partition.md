# Spec 00 partition

| Unit | Owns these files | DoD items | Depends on |
|---|---|---|---|
| Contract and CI | `openapi/artfct.yaml`; `.github/workflows/ci.yml` | OpenAPI 3.1 lint; all current Worker routes documented; all reserved paths marked unimplemented with 501; deliberate drift fails CI; provenance source enum exactly matches Spec 1 | None |
| Worker conformance | `backend/src/lib.rs` | Backend success responses validate; all error codes use the documented envelope; reserved paths return 501 | Contract and CI contract shape |
| CLI and MCP conformance | `mcp-server/src/api.rs`; `mcp-server/src/mcp.rs` | CLI and MCP create requests validate against `EphemeralArtifactRequest` | Contract and CI contract shape |
| Laravel docs | `routes/web.php`; `resources/js/pages/docs.tsx`; `tests/Feature/DocsTest.php` | `/docs` renders from the OpenAPI document through the existing Inertia page | Contract and CI contract shape |
| Serialized coordinator remainder | `CHANGELOG.md`; `docs/specs/00-api-contract.md`; `scratchpad/runs/00/drift.md`; `scratchpad/runs/00/verification.md`; `scratchpad/runs/00/mutation.md` (only if a negative test is introduced) | Spec-level changelog entry, correction of contradictory acceptance wording, and release evidence | All implementation units |

Each path has one owner. The contract file is read-only to consumer units; only the Contract and CI unit may edit it.
