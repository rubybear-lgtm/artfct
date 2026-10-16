# Spec 7 — Auth seam

**Track:** C (control plane) · **Depends on:** 0, 3, 6 · **Blocks:** 8, 9

## Scope

**In.** Laravel mints short-lived JWTs; the Worker verifies them at the edge. Org-scoped API tokens for the CLI and MCP server. Rate limiting keyed on token rather than IP. Revocation.

**Out.** The console UI that manages tokens (spec 8). Enterprise SSO (spec 10).

**This spec exists to make a guarantee. Every authorization boundary here needs a negative test.**

## Decisions implemented

- **09** — the seam: Laravel mints, the Worker verifies at the edge, no origin round-trip on the hot path.

## Design

### Two credential types

| Credential | Holder | Lifetime | Use |
|---|---|---|---|
| `sessionJwt` | browser, via console | minutes | console-originated artifact reads and writes |
| `orgToken` | CLI, MCP server, CI | until revoked | `POST /v1/artifacts`, delete |

### Verification at the edge

Laravel signs; the Worker verifies against a published JWKS, cached in KV. No per-request call to the origin — that would put Laravel in the hot path and remove the reason to be on the edge in the first place.

Claims: `org_id`, `user_id`, `role`, `exp`, `jti`.

### Revocation

Short TTL plus a **KV denylist** keyed on `jti` and on token id. Laravel writes to the denylist on revoke; the Worker checks it. Denylist entries expire at the credential's natural expiry, so it stays small.

The gap between revocation and propagation is bounded by KV's eventual consistency. That is an accepted, documented window measured in seconds — and it is why JWT TTLs are minutes, not hours.

### Rate limiting

Moves from the Cloudflare WAF rules in `cloudflare-rate-limits.mjs` (per-IP, per-colo) into the Worker, keyed on token then org. Anonymous creates keep the existing per-IP WAF rule — that path is unchanged and still needs IP-based protection.

### Tenant resolution

The org comes from the credential, never from a request parameter. An `org_id` in a body or path is ignored if present. Deriving tenancy from user input is how cross-tenant reads happen.

## Definition of done

- [ ] `artfct deploy ./x.html --tier permanent` with a valid org token creates an artifact owned by that token's org.
- [ ] The same request with no credential is rejected with 401 and `code: authentication_required`.
- [ ] Revoking the token in the console causes the next deploy to fail within the documented propagation window.
- [ ] A JWT for org A requesting an artifact belonging to org B returns 404 — **not 403**, so existence is not disclosed.
- [ ] An expired JWT is rejected at the edge without reaching Laravel.
- [ ] A JWT signed with the wrong key is rejected.
- [ ] A request supplying `org_id` for another org in its body is ignored and resolves to the credential's org.
- [ ] Exceeding the per-token rate limit returns 429 while a different token in the same org is unaffected at its own limit.
- [ ] Anonymous ephemeral creates still work and are still limited per-IP.
- [ ] Worker latency on the authenticated path shows no origin round-trip — verified in a trace.

## Tests

**`backend`**
- `valid_org_token_resolves_org`
- `missing_credential_rejected` *(negative)*
- `revoked_token_is_rejected_at_edge` *(negative)*
- `expired_jwt_rejected` *(negative)*
- `jwt_with_wrong_signature_rejected` *(negative)*
- `jwt_for_org_a_cannot_read_org_b` *(negative)*
- `cross_org_read_returns_404_not_403` *(negative)*
- `org_id_in_body_is_ignored`
- `rate_limit_keyed_on_token_not_ip`
- `anonymous_path_still_rate_limited_by_ip`
- `jwks_cached_in_kv`

**Pest — feature**
- `token_creation_returns_value_once_only`
- `token_revocation_writes_denylist`
- `member_cannot_revoke_another_users_token` *(negative)*

**Integration**
- `end_to_end_authenticated_deploy_lands_in_correct_tenant`

## Rollback

Reversible. Credentials can be invalidated wholesale by rotating the signing key; no stored artifact changes shape.

## Deferred

- mTLS for CI. No demand.
- Fine-grained token scopes beyond org and role. Add when someone asks for a deploy-only token.
