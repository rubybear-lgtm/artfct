# Spec 10 — Enterprise SSO

**Track:** E (enterprise) · **Depends on:** 6, 7, 9 · **Blocks:** 11

## Scope

**In.** Ory Polis deployed and operated. SAML and OIDC enterprise SSO. SCIM directory sync. The Team→Enterprise upgrade exercised end to end, and the downgrade.

**Out.** Audit and retention (spec 11). The schema this depends on — `external_identities`, `auth_mode` — was built in spec 6, deliberately, before any Team user existed.

**This spec exists to make a guarantee. The three named failure modes each need a test; a happy-path upgrade does not close it.**

## Decisions implemented

- **10** — AuthKit for Free and Team, self-hosted Polis for Enterprise SSO and SCIM. Flat cost instead of $125/connection/month twice over; break-even at the first enterprise customer.
- **13** — the upgrade is a flag flip, not a migration.

## Design

### Deployment

Docker `boxyhq/jackson`, port 5225, Postgres, `/api/health` for Railway healthchecks. A second Railway service beside Laravel — extending a stateful surface that already exists rather than creating one.

Deployed **from day one, including pre-revenue**, so the integration is validated and the operational learning happens while nothing depends on it.

Required env: `DB_URL`, `API_KEYS`, `NEXTAUTH_SECRET`, `NEXTAUTH_ADMIN_CREDENTIALS`.

### Integration

Polis abstracts SAML/OIDC into a **standard OAuth 2.0 authorization code flow**, so this is a Laravel Socialite custom provider — no exotic SDK, and no first-party PHP SDK needed. AuthKit is OAuth too, so both providers normalize into a single resolve-or-create-user action, routed by the org's `auth_mode`.

Polis keys connections by `tenant` + `product`. Map `tenant` → org id, `product` → `artfct`.

### The upgrade

`authkit` → `dual` → `polis`, gated as spec 6 defined:

1. Org verifies its domain (spec 6 precondition).
2. Admin configures the SAML connection in Polis for their tenant.
3. Org moves to `dual`. Both providers accepted. Members logging in via SAML get an `external_identities` row linked to their existing user by **verified email**.
4. Console shows who has and has not linked.
5. Move to `polis` — refused unless at least one admin holds a Polis identity.

Nothing moves: same script, same D1, same R2, same hostnames. Every artifact link already shared keeps working. Feature differences are gates on `plan`.

### The three failure modes

- **Email mismatch.** Personal AuthKit address, corporate IdP assertion. Matching on email alone silently orphans them. Handling: the `dual` window surfaces unlinked members; an admin can link an identity manually; an unmatched SAML login **never** silently creates a second user in an org that already has one for that person.
- **Absent from the IdP.** Contractors and personal accounts. Handling: `dual` reports them before enforcement; moving to `polis` lists exactly who will lose access and requires confirmation.
- **Admin lockout.** Prevented by the precondition, not a warning.

### Downgrade

`polis` → `dual` → `authkit`. Users keep their AuthKit identities because `external_identities` never deleted them. Build and test it now: only ever testing one direction is how you break someone's login the first time a contract lapses.

### The accepted risk

Ory gates CVE patching SLAs and advanced multi-tenancy behind its enterprise license, so this is community-cadence patching on the auth path — in a product whose pitch is governance. Mitigations are part of this spec's DoD, not aspirations: pinned image version, subscription to Ory security advisories, a written upgrade runbook. Managed Ory Network is the escape hatch and the integration is identical, so switching is configuration.

## Definition of done

- [ ] Polis runs on Railway, `/api/health` returns 200, and the image version is pinned — not `latest`.
- [ ] A SAML connection created for tenant `acme`/product `artfct` completes a login through the Socialite provider and returns a profile.
- [ ] An OIDC connection does the same.
- [ ] SCIM provisioning creates a user in the org; de-provisioning deactivates them and revokes their sessions.
- [ ] **Email match:** an existing Team user logging in via SAML with the same verified email links to their existing user — one `users` row, two `external_identities`, artifacts still theirs.
- [ ] **Email mismatch:** a SAML login whose email matches no existing member does not silently create a duplicate; it surfaces for admin linking.
- [ ] **Absent from IdP:** moving to `polis` lists every member without a Polis identity and requires explicit confirmation.
- [ ] **Lockout:** moving to `polis` with no admin holding a Polis identity is refused.
- [ ] After upgrade, an artifact URL issued while the org was on Team still resolves.
- [ ] Downgrade to `authkit` restores AuthKit login for every user who had one.
- [ ] A security advisory subscription and an upgrade runbook exist in the repo, not just in someone's head.

## Tests

**Pest — feature**
- `saml_login_completes_via_socialite_provider`
- `oidc_login_completes`
- `scim_provisions_user`
- `scim_deprovision_revokes_sessions`
- `authkit_email_match_links_by_verified_email`
- `authkit_email_mismatch_does_not_create_duplicate_user` *(negative)*
- `unlinked_members_are_listed_before_enforcement`
- `polis_enforcement_requires_admin_polis_identity` *(negative)*
- `polis_enforcement_lists_members_who_will_lose_access`
- `artifact_url_survives_tier_upgrade`
- `downgrade_restores_authkit_login`
- `dual_mode_accepts_both_providers`

**Integration**
- `team_org_upgrades_to_enterprise_end_to_end` — the full path, no duplicates, no orphans, no lockout

## Rollback

Reversible by design — the downgrade path is part of the spec and tested. Identity rows are never deleted, only linked.

## Deferred

- Multiple simultaneous IdPs per org. Rare; adds real complexity to identity resolution.
- IdP-initiated SSO. Add when a customer asks.
