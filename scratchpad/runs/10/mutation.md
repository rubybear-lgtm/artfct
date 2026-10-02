# Spec 10 mutation evidence

Solo session (no subagent budget). All mutations run and restored
directly, 2026-09-04.

| Guard removed | Exact command | Mutated result | Restored result |
|---|---|---|---|
| "no match -> unlinked" branch in `EnterpriseIdentityResolver::resolveOrgLogin` (replaced with silent user creation) | `php artisan test --compact --filter=authkit_email_mismatch_does_not_create_duplicate_user` | failed: status `matched` instead of `unlinked` — a duplicate user would have been created | passed |
| At-risk-member confirmation gate in `AuthModeTransitioner::transition` (`if (false)`) | `php artisan test --compact --filter=polis_enforcement_lists_members_who_will_lose_access` | failed: no exception thrown, transition proceeded with an unconfirmed at-risk member | passed |

Both mutations independently confirmed genuine; file content matched
pre/post in both cases (no residue left in the tree). Spec 06's existing
`polis_requires_admin_with_polis_identity` admin-lockout guard was not
re-mutated here — it is untouched by this spec's changes and already has
mutation evidence from the spec 06 run.

Not independently mutation-checked: `saml_login_completes_via_socialite_provider`/
`oidc_login_completes` (pure round-trip through `FakePolisClient`, same
shape as spec 06's already-verified `FakeAuthKitClient`), and
`scim_provisions_user`/`scim_deprovision_revokes_sessions` (straightforward
create/idempotency and deactivate/revoke logic, verified by direct
assertion rather than mutation given time constraints).
