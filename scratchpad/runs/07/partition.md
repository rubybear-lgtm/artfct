# Spec 07 partition

| Unit | Owns these files | DoD items | Depends on |
|---|---|---|---|
| Auth seam (single unit, cross-stack) | `backend/src/lib.rs`, `backend/src/store.rs`, `backend/Cargo.toml`, `app/**` (new), `database/migrations/**` (new), `routes/**`, `tests/Feature/**`, `tests/Unit/**` (new), `openapi/artfct.yaml`, `README.md`, `DOCUMENTATION.md`, `CHANGELOG.md` | All 10 DoD items, split below | Spec 06 commit `9ddacb6` |

One unit, matching the skill's own guidance ("Specs 3, 4 and 7 move handlers,
schema and the request type together"): JWT claims, JWKS format, and the
revocation denylist are shared contract between Laravel (mints) and the
Worker (verifies) — splitting forks that contract.

## Approved before implementing

- `jsonwebtoken` (or equivalent) added to `backend/Cargo.toml` for RS256/ES256
  JWKS verification in the Worker — asymmetric verification is not
  hand-rollable safely, unlike spec 05's symmetric HMAC.
- `firebase/php-jwt` promoted from transitive (via `workos/workos-php`) to a
  direct `require` in `composer.json` — not a new dependency, a declaration
  change for a package already vendored for the same purpose.

## Architecture decision (stated, not re-asked)

Laravel does **not** call the Cloudflare API directly to write revocations.
That would need a Cloudflare API token in Laravel — an external credential
this project doesn't have, and would just relocate the mock-policy problem.
Instead: the Worker exposes an internal revocation-write endpoint that only
Laravel can call (its own credential, separate from `orgToken`/`sessionJwt`);
Laravel POSTs to it on revoke, the Worker writes its own KV. Both halves
testable without any real Cloudflare API credential — a fake HTTP client in
Pest, a direct handler test in Rust.

This endpoint is itself an authorization boundary (spec's own words: "every
authorization boundary here needs a negative test") even though it isn't in
the named test list — add one: an unauthenticated or wrongly-authenticated
revocation-write request must be rejected, not silently accepted.

## DoD split

Closable now, in-process (fake JWKS keypair, fake KV, real DB):
1. Valid org token creates an artifact owned by that token's org
2. No credential → 401, `code: authentication_required`
3. Revoked token rejected within the documented propagation window (test the
   KV-denylist check directly with a controlled clock/TTL, not a real
   multi-second wait)
4. JWT for org A requesting an artifact belonging to org B → **404, not 403**
   — the artifact MUST exist and belong to org B in the test, not merely be
   absent, or the test is vacuous (this is the item most likely to be
   accidentally trivial — verified explicitly by the coordinator before
   commit)
5. Expired JWT rejected at the edge
6. JWT signed with the wrong key rejected
7. `org_id` in body ignored, resolves to credential's org
8. Per-token rate limit → 429; a different token in the same org unaffected
9. Anonymous ephemeral creates still work, still IP-limited

Trace/latency-shaped, not closable as a unit test (same category as spec
05's items 2/3/8/10 — flag, don't claim):
10. "No origin round-trip — verified in a trace." Close this structurally
    instead: assert the verification path makes zero outbound fetch calls
    once the JWKS is cached in KV (code-level guarantee), and state plainly
    that a real production trace was not captured.

## Mutation check — required for this spec per the skill

All 9 backend negative tests plus both negative Pest tests get the full
removal→fail→restore ceremony, not a sample. For
`cross_org_read_returns_404_not_403` specifically: the mutation must remove
the org-scoping predicate from the query, not the status-code mapping —
mutating the wrong line would pass vacuously.
