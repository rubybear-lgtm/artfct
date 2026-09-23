# Spec 6 — Identity foundation

**Track:** C (control plane) · **Depends on:** 0 · **Blocks:** 7, 8, 10

## Scope

**In.** The Laravel control plane's identity layer: starter-kit Teams for orgs and memberships, WorkOS AuthKit for self-serve auth, the `external_identities` table, `auth_mode` as a state machine, roles, and domain verification.

**Out.** Minting JWTs for the Worker (spec 7). Enterprise SSO itself (spec 10). **But the schema that makes spec 10 possible is built here** — after Team users exist it is a migration, which is exactly what this spec avoids.

## Decisions implemented

- **09** — Laravel is the control plane; orgs and identity are inherited from the starter kit, not built.
- **10** — AuthKit for Free and Team; Polis for Enterprise. This spec builds the AuthKit half and the seam Polis plugs into.
- **13** — the upgrade path's schema: `external_identities`, `auth_mode`, domain verification.

## Design

### Inherited, not built

Laravel's starter kits ship Teams natively: users belong to one or more teams, a personal team on registration, invitations, switching, management screens, team-scoped routes (`/{current_team}/dashboard`), and reserved-name collision handling on slugs. The WorkOS AuthKit variant ships social auth, passkeys and Magic Auth, free to 1M MAU.

Use both. The org model is starter-kit Teams with artfct's constraints layered on. In particular, **slug validation from spec 3 applies here** — `--` forbidden, ASCII only, ≤24 chars — because the slug becomes a DNS label in spec 5.

### `external_identities`, not a `workos_id` column

The starter kit will scaffold a WorkOS identifier on `users`. **Replace it.**

```
external_identities
  id, user_id, provider, external_id, email, verified_at, created_at
  unique (provider, external_id)
  index (email)
```

One human, many identities, across providers. This is the single most important line in the spec: after Team users exist, adding it is a data migration performed on live accounts, and getting it wrong orphans people from their own artifacts.

### `auth_mode` as a state machine

On the org, not a boolean:

`authkit` → `dual` → `polis`, and back.

- **`authkit`** — AuthKit only. Every Team org.
- **`dual`** — both accepted. Identities link on first Polis login by verified email. The window where SAML gets tested, unmigrated members are visible, and nobody is locked out.
- **`polis`** — SAML enforced.

The transition to `polis` is blocked unless at least one admin has an active Polis identity. **This is what prevents an admin flipping the switch and locking themselves out of the screen where SAML is configured** — the classic footgun, prevented by a precondition rather than a warning.

### Domain verification

An org cannot move past `authkit` until it has proven control of a domain (DNS TXT record). Without it, "enable SSO for @acme.com" is an account-takeover vector: anyone could claim a domain and capture logins for it.

### Roles

`admin` / `member` / `viewer`. Enough to start; every role gate gets a policy test.

## Definition of done

- [ ] Register via AuthKit, land on a personal team, create a second org, invite a member by email, accept, switch between orgs.
- [ ] A user who logs in via Google and later via passkey resolves to **one** `users` row with two `external_identities`.
- [ ] `users` has no `workos_id` column.
- [ ] Creating an org with slug `acme--corp` is rejected.
- [ ] Creating an org with a non-ASCII or >24-char slug is rejected.
- [ ] A new org has `auth_mode = authkit`.
- [ ] Moving an org to `dual` without a verified domain is refused.
- [ ] Moving an org to `polis` with no admin holding a Polis identity is refused, with an error naming the reason.
- [ ] Domain verification succeeds only when the DNS TXT record is present, and re-verification is possible after removal.
- [ ] A `viewer` cannot invite, and a `member` cannot change `auth_mode` — both return 403.

## Tests

**Pest — feature**
- `user_registers_and_gets_personal_team`
- `user_creates_and_switches_orgs`
- `invitation_flow_completes`
- `google_and_passkey_resolve_to_one_user`
- `second_provider_creates_second_external_identity`
- `org_slug_with_double_hyphen_rejected` *(negative)*
- `org_slug_non_ascii_rejected` *(negative)*
- `org_slug_over_length_rejected` *(negative)*
- `auth_mode_defaults_to_authkit`
- `dual_requires_verified_domain` *(negative)*
- `polis_requires_admin_with_polis_identity` *(negative)*
- `domain_verification_requires_dns_txt` *(negative)*

**Pest — policy**
- `viewer_cannot_invite` *(negative)*
- `member_cannot_change_auth_mode` *(negative)*
- `admin_can_change_auth_mode`
- `member_of_org_a_cannot_read_org_b` *(negative)*

**Pest — browser**
- `registration_and_org_creation_flow`
- `console_pages_have_no_js_errors`

## Rollback

Reversible while no production accounts exist. Once Team users register, schema changes here become live migrations — the reason `external_identities` and `auth_mode` are in this spec rather than spec 10.

## Deferred

- SCIM — spec 10.
- Custom roles beyond the three — no demand.
- Cross-org identity federation — out of scope.
