# Spec 06 partition

| Unit | Owns these files | DoD items | Depends on |
|---|---|---|---|
| Identity foundation (single unit) | `composer.json`, `composer.lock`, `app/**` (Models, Http/Controllers, Http/Middleware, Policies, Providers), `database/migrations/**` (new files only), `database/factories/**`, `database/seeders/**`, `routes/**`, `resources/js/pages/auth/**`, `resources/js/pages/settings/**` (or wherever the implementer places org/team UI — new files only), `tests/Feature/**`, `tests/Unit/**`, `tests/Browser/**`, `config/**` (new config files or additive keys), `.env.example`, `README.md`, `DOCUMENTATION.md`, `CHANGELOG.md` | All 10 DoD items, split into closable-now vs. infra-blocked (see below) | Spec 05 commit `dca8945` |

One unit: registration, Teams, `external_identities`, `auth_mode`, domain
verification and role policies share the same identity schema and the same
starter-kit scaffold. Splitting would fork the user/org/team model across
concurrent implementers.

## Approved before implementing (asked and confirmed)

- Add a Teams starter kit and the WorkOS PHP SDK as new composer/npm
  dependencies (CLAUDE.md normally requires approval — obtained).
- WorkOS auth and DNS-TXT domain verification are built against
  fake/injectable providers, same mock policy as specs 10/14/15. Real
  WorkOS API keys and real DNS resolution are documented, not exercised.

## DoD split

Closable now, real (fake WorkOS provider, fake DNS resolver, real DB):
1. Register → personal team → create second org → invite → accept → switch
2. Google + passkey (two fake-provider logins) resolve to one `users` row, two `external_identities`
3. `users` has no `workos_id` column
4. Org slug `acme--corp` rejected
5. Non-ASCII / >24-char slug rejected
6. New org defaults to `auth_mode = authkit`
7. `dual` without verified domain refused
8. `polis` with no admin holding a Polis identity refused, named error
10. viewer cannot invite / member cannot change auth_mode → 403

Real DNS required for full end-to-end, closable against an injectable
resolver otherwise (flag the gap, don't silently claim it):
9. Domain verification succeeds only with a real DNS TXT record present,
   re-verification possible after removal

Browser tests (`pestphp/pest-plugin-browser` is already in composer.json's
require-dev — verify these actually run, don't pre-defer them):
- `registration_and_org_creation_flow`
- `console_pages_have_no_js_errors`
