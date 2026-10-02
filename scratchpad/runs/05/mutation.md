# Spec 05 mutation evidence

Executed 2026-09-03 against `backend/src/lib.rs`. Each guard was removed
independently, the exact named test was run, then the guard was restored and
the same test rerun. No mutation was left in the tree (confirmed via
`git diff --stat` matching pre/post).

| Guard removed | Exact command | Mutated result | Restored result |
|---|---|---|---|
| Token expiry check (`now.timestamp() >= expires_at_unix`) | `cargo test -p artfct-backend expired_access_token_rejected` | failed, exit 101: token 5 minutes past expiry still verified | passed, exit 0 |
| Token artifact-id match (`constant_time_equal(token_artifact_id, artifact_id)`) | `cargo test -p artfct-backend token_for_other_artifact_rejected` | failed, exit 101: token minted for artifact-a verified against artifact-b | passed, exit 0 |
| CSP origin loop (replaced with unconditional `https:` wildcard) | `cargo test -p artfct-backend undeclared_origin_absent_from_csp` | failed, exit 101: undeclared origin's expectation diverged from wildcard `https:` output | passed, exit 0 |
| `unsafe-eval` opt-in `if manifest.unsafe_eval` guard | `cargo test -p artfct-backend unsafe_eval_absent_unless_declared` | failed, exit 101: `'unsafe-eval'` present in script-src even when not declared | passed, exit 0 |

All four mutations independently confirmed: removing the named guard makes
the test fail for the reason the test name claims, not a coincidental one.

## Round 2 — post-review fix

Zero-context review (round 1) found DoD item 7 ("Free-tier `/p/{id}` links
continue to work unchanged") FAIL: the isolated CSP was applied to every
permanent-artifact response, not just isolated-host ones. Implementer added
an `is_isolated` gate plus `free_tier_permanent_artifact_keeps_preview_csp`.

| Guard removed | Exact command | Mutated result | Restored result |
|---|---|---|---|
| `is_isolated` branch in `permanent_file_response_headers` (always used the isolated CSP) | `cargo test -p artfct-backend free_tier_permanent_artifact_keeps_preview_csp` | failed, exit 101: restrictive CSP returned instead of `PREVIEW_CONTENT_SECURITY_POLICY` | passed, exit 0 |
