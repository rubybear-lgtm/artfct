# Spec 07 mutation evidence

Implementer ran the full removal->fail->restore ceremony on all 9 backend
negative-shaped tests plus the Pest negative test (see their report for the
per-test detail); of note, they caught and fixed a genuinely vacuous first
draft of `revocation_write_endpoint_rejects_unauthenticated_or_wrong_credential`
themselves before reporting done (it originally re-tested spec 05's
`authorization_matches` rather than anything spec-07-specific).

Coordinator independent spot-checks, executed 2026-09-04 against
`backend/src/lib.rs`:

| Guard removed | Exact command | Mutated result | Restored result |
|---|---|---|---|
| Org-scoping predicate in `decide_artifact_visibility` (`row.org_id == credential_org_id`) | `cargo test -p artfct-backend --lib` (filtered to the two affected tests) | both `jwt_for_org_a_cannot_read_org_b` and `cross_org_read_returns_404_not_403` failed: `Visible` returned for a row belonging to a different org | both passed |
| `revocation_write_authorized` body (replaced with unconditional `true`) | `cargo test -p artfct-backend revocation_write_endpoint_rejects_unauthenticated_or_wrong_credential` | failed: unauthenticated request authorized | passed |

Both mutations targeted the guard the test *name* claims, not an adjacent
line (per the spec's explicit "cross_org_read must mutate the lookup gate,
not the status-code mapping" requirement) — confirmed neither test is
vacuous.

Also independently confirmed: `authorized_for_revocation_write` (the
Env-reading wrapper) is actually called at the `write_revocation` HTTP
handler's entry (`backend/src/lib.rs:940`) — the guard is wired to the real
endpoint, even though (per the implementer's own flagged caveat) that
specific wiring point isn't independently unit-tested, matching the same
structural limitation as every other `authorized_for_org` call site in this
file (worker::Env can't be constructed outside a live Worker runtime).

## Round 2 — post-review fix

Zero-context review found `missing_credential_rejected` tested only
`bearer_token()` header parsing, never the actual `CredentialError::Missing`
mapping that item 2 (401 `authentication_required`) depends on — it would
have passed even if that mapping were deleted. Fixed by extracting
`extract_bearer_token` (a pure wrapper mapping absent/malformed auth to
`CredentialError::Missing`) out of `resolve_request_credential`, and
strengthening the test to assert against it directly.

| Guard removed | Exact command | Mutated result | Restored result |
|---|---|---|---|
| `extract_bearer_token` body (forced to always return `Ok("forced-token")`) | `cargo test -p artfct-backend --lib missing_credential_rejected` | failed: `Ok("forced-token") != Err(Missing)` | passed |

Gate re-run, independently, after every restore: `cargo fmt --all -- --check`,
`cargo clippy --all-targets -- -D warnings`, `cargo build --target
wasm32-unknown-unknown -p artfct-backend`, `cargo test --workspace` (67
backend unit tests, all passing), `vendor/bin/pint --dirty --format agent`,
`php artisan test --compact` (52/52) — all clean.
