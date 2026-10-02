# Spec 10 partition

Solo, single unit (no subagent budget). Mock policy already agreed with
the user for this spec (external-credential/infra spec, same category as
09/14/15) — the real Ory Polis deployment isn't reachable here.

## Why most of this spec IS closable, unlike 09

Polis abstracts SAML/OIDC into a standard OAuth 2.0 authorization code
flow (the spec's own words) — so the login/linking/upgrade logic is pure
Laravel, reusing spec 06's `IdentityResolver`/`AuthModeTransitioner`
almost directly (its `provider` field is already generic; its
`POLIS_PROVIDER` constant already exists). The three failure modes,
SCIM, and the upgrade/downgrade state machine are genuine engineering
work closable against a fake Polis client — unlike spec 09, nothing here
requires a live external system to prove the guarantee itself.

## DoD split

Closable now, against `FakePolisClient` (reuses `AuthKitProfile`'s shape
— `provider` is already a plain string field) and spec 06's existing
identity infrastructure:
- SAML/OIDC login completes via the injectable client, returns a profile
- SCIM provisioning creates a user; de-provisioning deactivates them and
  revokes their sessions (reuses spec 07's `RevocationWriter`)
- Email match: links to existing user by verified email (spec 06's
  resolver already does this for AuthKit; extended for org-scoped Polis
  logins that must never auto-create)
- Email mismatch: does NOT create a duplicate; surfaces for admin linking
  (new `EnterpriseIdentityResolver`, org-scoped, unlike the AuthKit
  resolver which creates on first login)
- Absent from IdP: `polis` transition lists every member without a Polis
  identity, requires explicit confirmation (extends
  `AuthModeTransitioner`)
- Lockout: already built in spec 06 (`hasAdminWithPolisIdentity`)
- Artifact URL survives tier upgrade: an auth_mode transition never
  touches artifact storage/serving — testable as "the transitioner makes
  no Worker-facing call," honest about what this actually proves
- Downgrade restores AuthKit login: `external_identities` are never
  deleted, `AuthModeTransitioner` already allows unrestricted backward
  movement
- Dual mode accepts both providers

Not closable — needs a real deployed Polis instance:
- "Polis runs on Railway, /api/health returns 200" — not verified
- Image version pinning IS closable as a config artifact
  (`docker-compose.polis.yml`, pinned tag) without a live deployment
- Security advisory subscription + upgrade runbook: closable as content
  (a DOCUMENTATION.md section, not a new doc file — CLAUDE.md restricts
  new documentation files to what this skill run authorizes)

## Reuse, not duplication

`AuthKitProfile` is reused as the shared profile shape for Polis logins
(its `provider`/`externalId` fields are already generic) — no new
`PolisProfile` class. `FakePolisClient` mirrors `FakeAuthKitClient`'s
self-describing-code pattern exactly.
