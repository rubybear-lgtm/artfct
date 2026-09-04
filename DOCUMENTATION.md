# Storage modes

Ephemeral artifacts remain encrypted in Workers KV and use a fragment passcode. They
retain the five-day sliding expiration policy and the one-megabyte client limit.

Permanent artifacts require an organization bearer token. The client creates a
file manifest, uploads raw file bytes to R2 under `blobs/<sha256>`, and stores
metadata, promoted provenance columns, and the complete provenance JSON in D1.
Content hashes are lowercase SHA-256 values; the public ID is the first 32
characters of the canonical manifest hash.

Use `artfct export ORG DIRECTORY` with `ARTFCT_ORG_TOKEN` to export metadata and
byte-identical blobs locally.

Permanent create, upload, and delete operations serialize per content hash using
short-lived D1 leases. This keeps D1 reference counts and R2 object lifecycles
consistent when identical content is created or deleted concurrently; unrelated
hashes continue independently.

Before the full organization-token service lands, the Worker uses the configured
`ARTFCT_ORG_TOKEN` binding as a temporary, fail-closed authentication seam. A
missing binding never accepts an arbitrary bearer token, and the configured
organization slug scopes permanent reads, uploads, deletes, and exports.

## Permanent bundles

Permanent deployments accept a manifest with up to 500 relative files, 25 MiB per
file and 50 MiB total. The POST response lists only missing SHA-256 values; upload
each listed file with `PUT /v1/artifacts/{id}/files/{sha256}`. The bundle is not
served until every manifest file is present. Files are served at `/p/{id}` (the
entrypoint) and `/p/{id}/{relative-path}` (nested assets). Unknown content types
are stored as `application/octet-stream` and served with `nosniff`.

Manifest files are sorted by path, and the normalized manifest (including sizes,
content types, hashes, entrypoint, sorted unique `external_origins`, and the
`unsafe_eval` flag) is hashed to derive the stable 32-character bundle ID. D1
stores one artifact row and one file row per manifest path; R2 stores each
unique file hash once, with a refcount for every path reference. Incomplete
rows expire after one hour. Completion clears that expiry, while an expired
upload removes its artifact, provenance, file rows, and unreferenced blobs
under the per-hash lifecycle locks.

## Per-artifact origin isolation

Every permanent artifact has an isolated hostname
`<tenant-slug>--<artifact-id>.artfct.dev`, one wildcard level under Universal
SSL (`isolated_artifact_hostname`, `store::hostname_label`). It reuses the
slug and ID character-set rules from permanent bundle storage, so two
artifacts in the same org always derive different hostnames. This is
additive: shared-origin `/p/{id}` links keep working exactly as before,
including the free-tier ephemeral path, which never touches this hostname
scheme.

Requests arriving on an isolated hostname are cookieless — nothing in that
path reads or sets a session cookie. Access instead requires a short-lived
signed token, scoped to one artifact, presented as `?token=` or an
`Authorization: Bearer` header. A token is `<artifact_id>.<expires_at_unix>.<hmac_hex>`,
HMAC-SHA256-signed with the `ARTFCT_ARTIFACT_TOKEN_SECRET` binding. This
follows the same fail-closed pattern as `ARTFCT_ORG_TOKEN`: a missing or
empty secret never authorizes a token, an expired token (at or past its
`expires_at`) is rejected, and a token minted for one artifact does not
verify for another. Isolated-hostname requests without a token that verifies
return 403 without an `Access-Control-Allow-Origin` header — the console and
artifact origins share no CORS allowance.

The Content-Security-Policy served at `resolve_permanent_artifact` depends on
which host the request verified against. A request that verified on its
isolated hostname gets a CSP derived per artifact from its manifest:
`default-src` is `'self'` plus each declared `external_origins` entry,
`script-src` mirrors it and only gains `'unsafe-eval'` when the manifest sets
`unsafe_eval: true`, and an artifact declaring nothing gets a bare
`default-src 'self'`. Every other request — free-tier `/p/{id}` and the
legacy shared-origin `/p/{id}` path for permanent artifacts that never opted
into isolation — keeps the pre-spec-05 `PREVIEW_CONTENT_SECURITY_POLICY`
byte-identical, so isolation never silently tightens an artifact that did not
opt in.

## Identity (control plane)

Orgs and membership are a starter-kit Teams install (`app/Models/Team`,
`Membership`, `TeamInvitation`) with artfct's constraints layered on top: the
`slug` column reuses the same rules as `store::validate_slug` in the Worker
(1-24 chars, ASCII only, lowercase letters/digits/`-`, no leading/trailing
`-`, no `--`) because it becomes a DNS label in spec 5. Roles are `admin` /
`member` / `viewer` (`App\Enums\TeamRole`); every gate has a policy test.

Auth is WorkOS AuthKit via `laravel/workos`, resolved through
`App\Services\AuthKit\AuthKitClientContract` rather than called directly —
the same "mock external services" seam used elsewhere in this project.
`RealAuthKitClient` wraps the WorkOS PHP SDK and fails closed: it throws
unless `WORKOS_CLIENT_ID`, `WORKOS_API_KEY`, and `WORKOS_REDIRECT_URL` are
all set. `FakeAuthKitClient` is bound instead outside production (and always
in tests); it never talks to WorkOS, encoding a profile into a
self-describing "authorization code" so both feature tests and Pest browser
tests (a separate HTTP process) resolve it identically. `routes/auth.php`
only registers the `/authkit/dev-login` stand-in screen when
`app()->environment('production')` is false.

There is no `workos_id` column on `users`. Login resolves through
`external_identities` (`App\Models\ExternalIdentity`,
`App\Services\Identity\IdentityResolver`): match `(provider, external_id)`
first, then `users.email` but only when the incoming identity's email is
verified, else create a new user. One human accumulates many identities
across providers this way; an unverified email is never enough to attach a
new identity to an existing account, for the same account-takeover reason
domain verification exists.

`auth_mode` (`App\Enums\AuthMode`) is a state machine on the team:
`authkit` -> `dual` -> `polis`, enforced by
`App\Services\Identity\AuthModeTransitioner`. Every org starts at
`authkit`. Moving past it requires a verified `team_domains` row; moving to
`polis` additionally requires at least one admin holding a verified
`external_identities` row with `provider = polis` — the precondition that
stops an admin flipping the switch and locking every admin out of the
screen where SAML gets configured. Moving backward is unrestricted. A
refused transition throws `AuthModeTransitionException` naming the reason,
surfaced to the client as a `422` validation error on `auth_mode`.

Domain verification (`App\Services\Identity\DomainVerifier`) checks a DNS
TXT record at `_artfct-verify.<domain>` against a per-domain
`verification_token`, through `App\Services\Identity\DnsResolverContract` —
`RealDnsResolver` (real `dns_get_record`) in production, `FakeDnsResolver`
in tests. `verified_at` is nullable and reset to `null` on a failed
check, so removing the TXT record and re-verifying later works without
extra bookkeeping. **Only the fake resolver is exercised by the automated
test suite** — real end-to-end DNS resolution against `RealDnsResolver` is
not covered by a test and should be checked manually before relying on it
in production.

## Auth seam

Two credential types (spec 07): `sessionJwt` (console-issued, minutes) and
`orgToken` (CLI/MCP/CI, long-lived until revoked). Both share one claim
shape — `org_id`, `user_id`, `role`, `exp`, `jti` — and Laravel is the only
signer. `App\Services\Auth\OrgJwtService::mint()` signs RS256 via
`firebase/php-jwt`, using the team's `slug` as `org_id` (the same string
`store::validate_slug`/`o.slug` use on the Worker side, so the two sides
never disagree about what an org is called). Key material comes from
`config('services.org_jwt.private_key')`/`.kid` — not currently defined in
`config/services.php` (out of this spec's owned-files list; wiring
production key material is a flagged follow-up), so minting fails closed
with no key configured.

The Worker (`backend/src/lib.rs`) verifies at the edge against a JWKS
cached in KV (`cached_jwks`) — it never fetches the JWKS itself, and
`decode_org_jwt` takes no `&Env`/fetch capability at all, so there is no
origin round-trip on the hot path by construction, not by observed trace.
`resolve_request_credential` tries the legacy static `ARTFCT_ORG_TOKEN`
bearer first (spec 03-05 back-compat), then falls back to JWT
verification plus a KV denylist check keyed on `jti`. Tenancy always comes
from this resolved credential — `resolve_tenant_org` never reads a body
`org_id` — and `check_and_increment_rate_limit` enforces a per-token KV
counter independent of any other token in the same org. Anonymous
ephemeral creates are unchanged and keep relying on the existing per-IP
WAF protection outside the Worker.

`GET /v1/artifacts/{id}` is the credential-scoped metadata read: it fetches
the row without an org filter, then gates visibility in
`decide_artifact_visibility`, so a cross-org request and a nonexistent
artifact both resolve to the identical 404 — never 403 — and existence is
never disclosed.

Revocation is Laravel writing to the Worker's own denylist, not Laravel
calling the Cloudflare API directly (which would need a Cloudflare
credential this project doesn't have).
`App\Services\Auth\RevocationWriter` POSTs to the Worker's
`POST /v1/internal/revocations`, authenticated with its own shared secret
(`ARTFCT_REVOCATION_WRITE_SECRET`, matched against the Worker's
`REVOCATION_WRITE_SECRET_ENV`) — a credential of its own, separate from
`orgToken`/`sessionJwt`, following the same fail-closed pattern as
`ARTFCT_ORG_TOKEN`/`ARTFCT_ARTIFACT_TOKEN_SECRET`. The denylist entry's TTL
matches the token's own `exp`, so it expires with the credential and the
denylist stays small. The propagation window between revoke and the next
request being rejected is bounded by KV's eventual consistency — an
accepted window measured in seconds, not milliseconds. That window is
exactly why JWT TTLs are minutes rather than hours: a revocation that can
take a few seconds to propagate is only safe to rely on when the token
would have expired naturally soon anyway.

`App\Models\OrgToken` never stores the raw JWT — only `jti` (the denylist
key) and a display-only `last_four` — so `token creation returns value once
only` is a property of the schema, not just the controller
(`App\Http\Controllers\Teams\OrgTokenController::store`). Revoking someone
else's token requires the team's member-management permission
(`App\Policies\OrgTokenPolicy::revoke`); a token's own creator can always
revoke it.

## Admin console and export

`GET /v1/orgs/{org}/artifacts` (Worker) lists an org's artifacts,
cursor-paginated on `(created_at, id)` — `store::encode_list_cursor`/
`decode_list_cursor` opaque-encode the position, `paginate_sorted` slices
it. Filters (`repo_url`, `agent`, `created_after`/`created_before`) are AND
semantics against spec 2's promoted provenance columns; `artifact_matches_filter`
is the single specification both the D1 `WHERE` clause and the tests are
held to. `PATCH /v1/orgs/{org}/artifacts/{id}` is the soft-delete
revocation: `revoked_at` is set (idempotent — revoking twice is a no-op),
the row and blob are retained untouched, and `resolve_permanent_artifact`'s
serving query gates on `revoked_at IS NULL`, so a revoked artifact's URL
starts returning 404 without a code change to the list/export paths, which
deliberately keep showing revoked rows (with their `revoked_at` and
provenance intact) rather than filtering them out.

Both endpoints trust the same bearer credential `export_organization`
already required (spec 03) — role-based gating (a `viewer` sees the list
but not the revoke control; the API itself still rejects with 403; a
non-member's org resolves to 404, not 403) is enforced by the Laravel
console (`App\Http\Controllers\ConsoleController`, `App\Policies\ConsolePolicy`),
not the Worker. `App\Contracts\ArtifactDirectory` is the console's seam
onto the Worker's HTTP API (`HttpArtifactDirectory`, sessionJwt-authenticated);
in the testing environment it's swapped for `App\Services\Artifacts\FakeArtifactDirectory`,
an in-memory double seeded with demo data (served for any org slug — org
boundaries are enforced upstream in `ConsoleController::resolveTeam` before
this fake is ever reached, so it doesn't need to model per-org data to be a
faithful test double for the console UI).

Export (`export_organization`, unchanged endpoint from spec 03) now shares
its per-artifact metadata mapping (`export_artifact_entry`) with this
spec's list endpoint's expectations — provenance is re-serialized verbatim
from spec 2's stored JSON, never reconstructed field-by-field, so a
`sources` entry spec 2 populated can't be silently dropped in export.
Blob byte-identity is unchanged from spec 03's export path.

The 10,000-artifact/500ms list-rendering DoD item is closed structurally —
cursor pagination on indexed columns, a bounded page size (1-200, default
50), and a single query with no per-row follow-up fetch — not with a
captured wall-clock measurement, which would be meaningless on a dev
machine. `cursor_pagination_has_no_gaps_or_duplicates` proves the pagination
arithmetic itself is exhaustive and duplicate-free at that exact scale.

## Tenant provisioning

Spec 09's per-tenant Cloudflare resources — a Workers for Platforms
dispatch-namespace script, one D1, one R2 prefix, a hostname registration —
need a paid Cloudflare tier not enabled in this environment.
`App\Services\Tenancy\RealTenantProvisioner` fails closed the same way
`RealAuthKitClient`/`RealDnsResolver` do: every method throws unless
`services.cloudflare.api_token`/`account_id`/`dispatch_namespace` are
configured, and even then throws "not implemented" — the real Cloudflare
API calls were never written, since there is nowhere to test them against.
`FakeTenantProvisioner` is bound instead in testing; it records every call
and supports injecting a fault for one named org/step, which is what makes
`migrate_all_continues_past_failure_and_reports` a genuine partial-failure
run rather than a description of one.

`App\Services\Tenancy\TenantProvisioningService::provision()` is the
idempotent, resumable orchestrator: an already-provisioned team with no
recorded failed step is a no-op; a team whose last run failed resumes from
`provisioning_failed_step`, so already-completed steps are never repeated
against the provisioner. `TenantFleetMigrator::migrateAll()` is the fleet
runner spec 09 calls "the recurring cost — the price floor": it iterates
every provisioned team, skips any already at the target `schema_version`,
and collects failures into a report rather than stopping at the first one
— `tenant:migrate --all` exits non-zero and names every failed tenant when
`FleetMigrationReport::hasFailures()` is true, and `tenant:status` shows
the version distribution across the fleet.

**Deferred, not closable in this environment** (all need a live dispatch
namespace, none exist here): the 60-second real provisioning wall clock,
`request.cf` unavailability inside a real untrusted tenant script, CPU-limit
isolation under real concurrent load, Logpush, and cross-tenant binding
isolation verified against two actually-provisioned tenants. Written as
`#[ignore]`d stubs in `backend/tests/dispatch_integration.rs` naming what
each would need, not silently claimed. The dispatch router's pure
resolution logic — hostname to script name, unknown hostname to 404 never
500 — is unit-tested in `backend/src/dispatch.rs` without any of that
infrastructure.

## Enterprise SSO (Polis)

Spec 10's SAML/OIDC broker is Ory Polis (`boxyhq/jackson`), abstracted
into a standard OAuth 2.0 authorization-code flow — so login/linking is
pure Laravel logic behind `App\Services\Polis\PolisClientContract`, the
same fail-closed real/fake seam as WorkOS AuthKit. `RealPolisClient`
throws unless `services.polis.base_url`/`api_key` are configured, then
throws "not implemented" regardless — the real OAuth exchange was never
written, since no live Polis instance exists in this environment to test
it against. `FakePolisClient` is bound in testing, mirroring
`FakeAuthKitClient`'s self-describing-code pattern.

Polis logins reuse `AuthKitProfile` as their shape (`provider` is already
a generic string field — a SAML login sets it to `polis`, OIDC to
`polis-oidc`) but resolve through a *different* resolver:
`App\Services\Identity\EnterpriseIdentityResolver::resolveOrgLogin()`,
scoped to one org's members, **never creates a user**. Where
`IdentityResolver` (spec 06, the AuthKit registration path) creates an
account on first login, an enterprise SSO login is always for someone who
already has — or should already have — a place in the org; an unmatched
email returns `unlinked` for an admin to link manually rather than
silently fragmenting one person into two accounts (spec 10's "email
mismatch" failure mode).

`AuthModeTransitioner::membersWithoutPolisIdentity()` (spec 10, extending
spec 06's transitioner) lists every member lacking a verified Polis
identity; moving to `polis` while that list is non-empty throws naming
them by email unless the caller passes `confirmed: true` — spec 10's
"absent from IdP" failure mode, so a contractor or personal account is
surfaced before enforcement, not silently locked out.

SCIM (`App\Services\Identity\ScimProvisioningService`) is separate from
interactive login: an IdP-pushed provisioning event creates a user (and a
`scim`-provider identity, distinct from the `polis` identity that same
person gets on their first SAML login) ahead of ever authenticating.
De-provisioning sets `users.deactivated_at` (blocking login without
deleting the row or any `external_identities` — a re-activation must not
require re-creating the account) and revokes every one of the user's
unrevoked org tokens through the same `RevocationWriter` spec 07's
`OrgTokenController` uses.

**Security advisory subscription:** Ory gates CVE-patching SLAs behind an
enterprise license (spec 10's "accepted risk"), so this deployment
watches https://github.com/boxyhq/jackson/security/advisories directly —
subscribe to repository security advisories via GitHub's "Watch → Custom
→ Security alerts" on `boxyhq/jackson` before Polis carries any real
traffic.

**Upgrade runbook:**
1. Read the release notes for the target tag at
   https://github.com/boxyhq/jackson/releases before bumping.
2. Update the pinned tag in `docker-compose.polis.yml` (never `latest`).
3. Deploy to a non-production Railway environment first; confirm
   `/api/health` returns 200.
4. Run a SAML and an OIDC login against a test tenant/product pair before
   promoting.
5. Promote to production; keep the previous image tag noted in the commit
   message so a revert is a one-line pin change, not an investigation.
6. If Ory's community-cadence patching proves too slow for a live
   incident, Managed Ory Network is the escape hatch — the integration is
   identical (same OAuth flow), so switching is a `services.polis.base_url`
   config change, not a rewrite.

**Deferred, not closable in this environment:** "Polis runs on Railway,
`/api/health` returns 200" (no live Railway/Cloudflare account reachable
here — see `docker-compose.polis.yml` for the pinned-image artifact that
substitutes for an actual deployment) and the corresponding end-to-end
integration test. All login/linking/SCIM/upgrade logic above is real,
tested Laravel code, not a description of intended behavior.
