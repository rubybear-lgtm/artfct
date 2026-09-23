# Spec 05 partition

| Unit | Owns these files | DoD items | Depends on |
|---|---|---|---|
| Origin isolation — server-side (single unit) | `backend/src/lib.rs`, `backend/src/store.rs`, `openapi/artfct.yaml`, `README.md`, `DOCUMENTATION.md`, `CHANGELOG.md` | Server-enforceable subset of the 11 DoD items (see below); all `backend` tests | Spec 04 commit `5e62e26` |
| Origin isolation — browser guarantees (serialized after, written but not runnable locally) | `tests/Browser/*` (new Pest file), backend test file for infra-only backend tests | The 4 infra-dependent DoD items, written as tests but explicitly marked skipped/ignored with a comment naming the missing infra | Server-side unit |

One serialized track because token minting, hostname derivation, and CSP
generation share the same response-construction path in `lib.rs` (the same
site spec 04 added `nosniff` to) — splitting would fork that logic.

## DoD split (per advisor guidance, confirmed before implementing)

Closable now, server-side, with unit tests + local Wrangler:
1. Different hostnames per artifact (`hostname_derives_from_slug_and_id`)
4. No `Set-Cookie` on artifact-origin responses
5. Expired access token → 403
6. Token minted for A → 403 on B
7. `default-src 'self'` when nothing declared
9. `unsafe-eval` absent unless declared
11. Free-tier `/p/{id}` unchanged

NOT closable locally — needs real DNS/browser, written as tests but not
executable in this environment, flagged at spec close-out rather than
silently claimed:
2. `fetch()` A→B blocked by CORS ("demonstrated, not assumed" per spec)
3. iframe DOM read blocked
8. Undeclared CDN blocked by CSP (client-enforced, server only emits header)
10. Wildcard cert covers `*.artfct.dev` at max-length hostname, no warning

Both Pest 4 browser tests (`artifact_renders_at_isolated_origin`,
`console_session_does_not_authenticate_artifact_origin`) fall in the second
group and need a deployed `*.artfct.dev` zone to run for real.
