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

## Governance (spec 11)

**Audit log.** `App\Models\AuditEvent` is append-only, enforced at three
independent layers so it holds even if one is bypassed: `save()` refuses
once the row exists, `update()` throws unconditionally, and a `boot()`
`updating`/`deleting` listener throws too — the last catches call paths
that reach Eloquent's event pipeline without going through the two method
overrides. Every event type spec 11 names lives in `App\Enums\AuditEventType`,
mirrored on the Worker side by `backend/src/governance.rs::AuditEventType`
for the wire string (`artifact.viewed`, not `ArtifactViewed`).

Two separate audit trails exist, deliberately:

- **Laravel's `audit_events` table** — every lifecycle action Laravel
  itself controls: member add/remove, role change, token mint/revoke,
  `auth_mode` change, console artifact revoke, retention/legal-hold/
  erasure runs, and SIEM exports (which audit themselves —
  `SiemExportService::export()` writes `export.performed` before it reads
  the log it's about to return, so the export it produces always includes
  the fact of its own export).
- **The Worker's own `audit_events` D1 table** (`backend/migrations/0001_storage.sql`)
  — for `artifact.created`/`artifact.viewed`/`artifact.shared`, which
  happen on the Worker's hot serving path and never route through Laravel.
  **Not wired in this environment**: writing to it from
  `create_permanent_artifact`/`resolve_permanent_artifact` needs
  `ctx.waitUntil()` threaded through those handlers (currently `_ctx` is
  unused in `main()`), which this session did not do. The pure event
  shape, JSON Lines rendering, and the structural proof that such a write
  would never block the response (`audit_queue_backpressure_does_not_slow_serving`)
  are built and tested; the live D1 `INSERT` call sites are a follow-up.

**Retention and legal hold.** `App\Services\Governance\RetentionService`
and `ErasureService` both default to `dryRun: true` — `governance:retention`
and `governance:erase` require `--apply` to actually delete anything, per
the Rollback section's "every destructive path is dry-runnable first, and
the dry run is itself part of the DoD." A legal hold (`LegalHoldService`)
always survives a retention run and always blocks a hard delete; a GDPR
erasure (whole-org) is refused — never partially executed — naming the
first held artifact it finds.

**The seam to storage.** `ArtifactGovernanceContract` is the same shape as
spec 09's `TenantProvisionerContract`: `FakeArtifactGovernance` (an
in-memory double) is bound in `testing`; `RealArtifactGovernance` fails
closed everywhere else. **Not wired in this environment**: the Worker has
D1 schema for `legal_hold`/`retention_class` (spec 3's migration) and the
pure decision logic in `backend/src/governance.rs`
(`plan_retention`/`plan_erasure`) plus dedupe accounting
(`MemoryArtifactStore::hard_delete`, `blob_ref_count`), but no live HTTP
route exposes "list artifacts older than X" / "hard-delete one" / "place a
hold" to Laravel — building and verifying those against a live Wrangler
dev instance is a follow-up, the same gap spec 9 left for tenant
provisioning and for the same reason (no live account here).
`gdpr_erasure_removes_bytes_from_r2` is an `#[ignore]`d Rust test stub for
the same reason: proving a direct R2 read 404s after erasure needs a live
bucket.

**Sharing controls.** `governance::check_share_access` (passcode match,
expiry, revocation, domain restriction — all collapsing to a 404, never a
403, matching spec 7's cross-org precedent) is pure and fully unit-tested.
Wiring share-link creation and validation into the Worker's request
dispatch (a new `shares` D1 table already exists in the spec-3 migration;
schema for passcode/domain/expiry columns does not yet) is a follow-up
alongside the governance HTTP routes above.

**Residency.** `teams.region` is set once, by
`TenantProvisioningService::provision()`, and is immutable afterwards —
`Team`'s `updating` guard throws on any attempted change regardless of
call path (mass-assignment, since `region` is deliberately excluded from
`#[Fillable]`, or `forceFill`). Moving a tenant between regions requires an
actual migration, which this project does not implement (out of scope for
spec 11 — see the spec's Deferred section for what's intentionally not
built).

## Indexing pipeline (spec 12)

**Pipeline.** `artifact.created` → `IndexArtifactJob` (a real Laravel
queued job, not a simulation) → `IndexingService::indexArtifact()`:
render only if `ExtractionHeuristics::needsRender()` says the raw HTML has
no usable body text (a hydration shell strips down to almost nothing;
static content doesn't) → extract → persist (`ArtifactIndexEntry`) →
chunk with overlap (`Chunker`) → embed → upsert into the org's vector
index (`VectorIndexContract`). A render timeout (or any other failure)
retries per `$tries = 3` with `backoff() = [10, 30, 60]`, then `failed()`
writes an `ArtifactIndexingFailure` row with the reason — its own table,
never touching `artifacts` or the serving path, so a dead-lettered
artifact keeps serving normally.

**Not wired in this environment**: the Worker has no live webhook calling
`IndexArtifactJob::dispatch()` on `artifact.created` — the same
cross-boundary gap spec 11 left for Worker-originated audit events.
`php artisan indexing:index {org} {artifact} {html-file}` is the manual
entry point in the meantime, and exercises the identical queued job a
webhook would dispatch. `RealRenderer`/`RealEmbeddings`/`RealVectorIndex`
all fail closed (`RealTenantProvisioner`'s pattern) — no live Cloudflare
Browser Rendering, Workers AI, or Vectorize account here.

**Console surfacing.** `ConsoleController::index()` passes a real,
queried `indexingFailures` prop (team-scoped, latest 20) to the Inertia
page. The React panel that displays it was not built this session — the
data is real and tested, the UI for it is a follow-up.

**Tenant isolation.** `VectorIndexContract` is `$orgId`-scoped at every
method; `FakeVectorIndex` keys its storage by org at the top level.
Mutation-checked: merging all orgs' vectors together in
`allVectorsForOrg()` makes `tenant_index_contains_no_foreign_vectors` fail
immediately (see `scratchpad/runs/12/mutation.md`).

**The Vectorize cost caveat (spec 12 DoD item 10).** The spec itself
flags this as unresolved before shipping: Cloudflare bills Vectorize on
"queried vector dimensions" = `(vectors in index + query vectors) ×
dimensions` per query. Modeled against a **1M-vector corpus** (roughly
artfct's estimate for ~50k artifacts × ~20 chunks each), 768 dimensions
(a common Workers AI embedding size), at 10 queries/day:

- Per query: `(1,000,000 + 1) × 768 ≈ 768M` "queried dimensions."
- Per month (300 queries): `768M × 300 ≈ 230B` queried dimensions.
- At Cloudflare's published $0.01 / 1M queried dimensions (their
  worked-example rate), that's **≈ $2,300/month** — not the ≈$2/month
  Cloudflare's own worked example implies for what reads like a similar
  scenario. The spec is right that these two readings do not reconcile;
  the likely resolution is that Cloudflare's cheap worked example uses a
  much smaller index or query volume than "1M vectors, 10 queries/day"
  implies, but the public pricing page does not fully disambiguate which.

**This is written down, not resolved.** "Reconciles with observed
billing over a week" (the second half of the DoD item) cannot be produced
without a live Vectorize account and a week of real traffic — neither
exists in this environment. Before this ships: run the actual corpus
against a real Vectorize index for a representative week and compare the
invoice against this model. If the pessimistic ($2,300/mo) reading holds
at real scale, the spec's own fallback applies — swap `VectorIndexContract`
for an alternative vector store; the pipeline above is unchanged, since
nothing outside `RealVectorIndex` knows Vectorize specifically.

## Retrieval (spec 13)

**The second MCP tool.** `search_artifacts` is listed alongside
`deploy_to_canvas` in `mcp-server`'s `tools/list`. Its description
explicitly tells an agent *when* to call it — before generating a
dashboard/page/report the user references — because, per the spec, a
tool agents don't know when to call is a tool that gets ignored in favor
of regenerating from scratch. Request/response shaping
(`search_request_payload`/`format_search_response`) is pure and tested
without a live call, the same precedent `deploy_to_canvas`'s own tests
already set.

**`/api/search` — a new, real endpoint.** Unlike specs 11/12's deferred
HTTP wiring, this one is built: `AuthenticateOrgToken` middleware
verifies the bearer org JWT via a new `OrgJwtService::verify()`, deriving
the RS256 public key from the private key Laravel already holds (no new
secret to configure). The org is resolved strictly from the token's
`org_id` claim — DoD: "resolved from the credential, never from a
parameter." This is a deliberate, narrow exception to "Laravel signs; it
never verifies" (spec 07's original architecture note on
`OrgJwtService`) — the Worker's independent edge verification for the
artifact-serving path is completely unchanged; this is a second verifier
for a second, Laravel-owned endpoint. **Assumption not verified here**:
production Cloudflare routing sends `/api/*` to Laravel's origin — only
`/v1/*` and `/p/*` are documented as Worker-routed (see the 0.0.1
changelog entry). Confirm this against the real zone before relying on
it.

**Ranking.** `SearchRanking::rank()` is pure: semantic similarity (from
`VectorIndexContract::query()`) plus a small recency term plus a 0.5
provenance-match boost when the query names the artifact's repo by its
last path segment. Mutation-checked: removing the boost application
flips the ranking in the adversarial test case where raw semantic
similarity alone picks the wrong artifact.

**Authorization.** `SearchService` cross-references every vector match
against `ArtifactDirectory::listArtifacts()` for the same org; a match
with no corresponding directory entry (deleted, never existed) or a
`revoked_at` is dropped silently — same 404-shaped "indistinguishable
from non-existence" precedent as spec 7's cross-org reads. Every search
writes `search.performed` via spec 11's `AuditLogger`.

**Console search.** Spec 8's `q` free-text filter already covers the
full-text half of "semantic and full-text" search. A dedicated semantic
console panel calling `SearchService` was not built this session.

## Billing, quotas and branded console (spec 14)

**Plan gate.** `PlanGate::requireEnterprise(Team, feature)` is the one
place that ever compares `$team->plan` — `plan_gate_has_single_call_site`
enforces this structurally (a grep across `app/`, not a behavior test).
Every enterprise feature — SIEM export, retention policy, and moving
`auth_mode` past `authkit` — calls it and nothing else. Setting
`plan = enterprise` is genuinely the only change needed to unlock all of
them, since there is no second flag anywhere.

**Quotas.** `QuotaService::assertCanCreateArtifact()` gates new creation
on three independent conditions: bundle size over the tenant's ceiling
(`BundleTooLargeException`), storage/artifact-count over 100%
(`QuotaExceededException`), or a past-due payment (also
`QuotaExceededException` — sharing the exception type keeps "can this org
create right now" one question). **A caught bug worth knowing about**:
both exceptions originally declared `public string $code`, which
collides fatally with `Exception`'s own untyped `$code` property — PHP
refuses to compile the subclass. The failure mode was a `php artisan
test` run that exited 1 with **zero output on stdout or stderr**, because
the fatal happens at class-declaration time, before any test output can
be buffered. Renamed to `$errorCode` in both classes; see
`scratchpad/runs/14/mutation.md` for the full diagnosis.

**Billing.** `BillingService` counts seats from active memberships
(`activeSeatCount()`) and applies Stripe's three relevant webhook events
as plain methods: `applyCheckoutCompleted` (sets `plan = team`),
`applyPaymentFailed`/`applyPaymentSucceeded` (the read-only degrade and
its restore). **Not wired in this environment**: a route that verifies
Stripe's webhook signature and calls these methods was not built — no
live Stripe account exists here to sign a real webhook payload against,
so there was nothing to verify the verification against. `RealBilling`
and `RealUsage` (Analytics Engine) both fail closed.

**The recurring gap, named once, plainly.** By this spec, the same
structural gap has appeared four times: spec 11's audit events for
Worker-side artifact actions, spec 12's `artifact.created` trigger for
indexing, and now quota enforcement on artifact creation — all three need
a call from the Worker's `create_permanent_artifact`/
`resolve_permanent_artifact` handlers into logic that, in this session's
architecture, lives in Laravel. (Spec 13 is the one exception: search
required a *new* endpoint Laravel could own outright, so it got built for
real rather than deferred.) The honest fix is one piece of work, not
three: thread `worker::Context` through those handlers so
`ctx.waitUntil()` can fire an async call — to the Worker's own D1 for
audit/quota bookkeeping, or to Laravel's API for anything that needs
Laravel's state — without blocking the response. Every one of the three
gaps above is closable the same way once that plumbing exists; none of
them needed a different design.

**Custom hostname.** `teams.custom_hostname` has a database-level unique
constraint, not just an application check — DoD: "a tenant cannot claim a
hostname already claimed by another tenant." Verified by mutation: with
the validation rule removed, a duplicate claim surfaces as a raw
`PDOException`, proving the constraint alone isn't what the DoD's "clear
message, not a 500" half depends on — the validation rule is. No billing
settings UI exists yet to set this from the console; the route and
controller are real and tested.

## Slack (spec 15)

**Unfurl richness has no viewer parameter.** `UnfurlService::unfurl(Team,
artifactId)` decides purely from `ArtifactSharingContract::sharingLevelFor()`
— public gets full metadata, domain-restricted gets title only,
org-private (and an unrecognized/missing artifact) always gets a bare
card. This is deliberate, not a shortcut: Slack fetches a URL's unfurl
once and caches it for the whole channel, so there is no per-viewer
identity to check in the first place — building one would be dead code
that looked like a security control without being one.

**Workspace-to-org resolution.** `teams.slack_workspace_id` is unique,
and `SlashCommandService` requires the Slack user's linked
`external_identities` row to belong to a user who is a *member of the
team that workspace maps to* — not just linked anywhere. That's what
makes "linked to org A, command run in org B's workspace" fail closed
(DoD: "gets no results from org B") rather than accidentally resolving
through some other membership.

**Not wired in this environment: Slack request-signature verification.**
Every inbound Slack request (slash commands today; the Events API
`link_shared` webhook this session didn't build) is normally verified
against an HMAC over the raw body and timestamp, keyed by a live app's
signing secret. There is no live Slack app here to verify a real
signature against, so `SlashCommandController` accepts any well-formed
POST. This is a real gap for a production deployment, not a cosmetic
one — the fix is a middleware computing and comparing the HMAC once a
signing secret exists, gating the route above.

**No install/OAuth flow.** `slack_workspace_id` and `external_identities`
(provider `slack`) are populated directly in tests; the OAuth flow that
would populate them from a real Slack app install was not built. This is
a separate integration from spec 6's WorkOS AuthKit OAuth flow (which
*is* built) — Slack's own OAuth was out of scope per the mock-only
decision for this spec.

## Collections and usage ranking (spec 16)

**Usage scoring is pure.** `UsageScorer::score(events, now)` takes an
array of `ArtifactUsageEvent` and returns a float — no database, no
container, so every one of its rules (distinct-viewer counting, Slack-
share weight, retrieved-then-opened weight, 30-day-half-life decay, a
hard zero for a superseded artifact) is independently testable and was
mutation-checked where it has an actual boolean branch to remove.

**Collections stay thin on purpose.** Creating one, and adding or
removing artifacts, has no permission check at all — any member can.
Only `pinCanonical()`/`unpinCanonical()` are gated
(`pinCanonicalCollection`, Admin-only under the existing role table),
because canonical status is the one thing with real consequences (a
strong ranking boost everyone in the org sees).

**Ranking composition.** `SearchRanking::combinedScore()` (spec 13) now
adds two more terms: `USAGE_WEIGHT * usageScores[artifactId]` and a flat
`CANONICAL_BOOST = 2.0` when the artifact is in any canonical collection
— larger than the repo-match boost (0.5) and recency term combined, so
a team's explicit "this is the canonical one" always wins over a
semantically closer but unmarked artifact. `SearchService::search()`
computes both inputs per query (usage scores from
`artifact_usage_events`, canonical membership from `collections`) and
passes them through; the `collection` filter (when present) narrows the
candidate set *before* ranking runs, so an out-of-collection artifact
never reaches the scorer at all.

**Zero-collections path still works.** Spec 13's own `SearchTest` suite
runs unchanged and green alongside spec 16's — proof the automatic
signal (semantic + recency + repo-match + usage) is useful on its own,
since `usageScores`/`canonicalArtifactIds` default to empty arrays and
every new term simply contributes zero when there's nothing to score.

**The recurring gap, instances six and seven.** `SlackPostService` now
calls `UsageEventLogger::recordSlackShare()` for real — the one usage
signal fully wired end to end this session, since Slack posting was
already Laravel-owned (spec 15). The other two automatic signals need
the same Worker↔Laravel plumbing named after spec 14: a `/p/{id}` view
on the Worker would need to call `UsageEventLogger::recordView()`, and
correlating "an agent called `search_artifacts`, then the returned URL
was actually opened" needs a signal from wherever "opened" happens (the
Worker's serving path again, or an MCP client reporting back) matched
against the search that returned it. `UsageEventLogger`'s methods for
both are real and tested; nothing currently calls them for real traffic.

## Product decisions (2026-09-20)

Three open questions were decided by taking the recommended option. Each is
reversible through configuration or a follow-up change, as noted.

### Retention and legal hold (RUB-333)

* **Retention** means age: an artifact older than the team's retention period
  is deleted for good by the scheduled retention job. The period is
  `teams.retention_days` (Enterprise only) or `governance.default_retention_days`
  (90 days) when unset.
* **Legal hold** always wins: a held artifact survives retention and erasure
  until the hold is released. Placing a hold is Enterprise only; releasing one
  is always allowed.
* **The UI never deletes.** The Governance page (`/settings/teams/{team}/governance`,
  admins only) shows the policy, previews what a retention run would remove
  (a dry run that deletes nothing and is audited), and places or releases
  holds. Running the job and GDPR erasure stay operator commands
  (`governance:retention`, `governance:erase`), so a destructive action always
  has a human operator and a dry run first.
* **Why:** the destructive paths were already built dry-runnable; exposing them
  as buttons would trade that safety for convenience. Revisit if teams ask to
  run erasure themselves.

### Abuse controls and consent (RUB-350)

* **Rate limits** (config, all reversible): sign-in routes 20 per minute per IP
  (`auth.throttle_per_minute`); sending or resending invitations 30 per hour
  per user and per team (`auth.invitations_per_hour`); creating teams 10 per
  hour per user.
* **Invite policy:** members can invite by default, because teams grow
  bottom-up, but a team may hold at most 25 unaccepted invitations
  (`TEAMS_MAX_PENDING_INVITATIONS`), and an invitation can never grant a role
  above the inviter's own. Set `TEAMS_MEMBERS_CAN_INVITE=false` to make
  invitations admin-only, which is the stricter option if the sending domain is
  abused.
* **Consent:** when `LEGAL_CONSENT_REQUIRED=true` (on for staging) a signed-in
  user must accept the current terms before reaching the app. The accepted
  version and time are stored on the user (`terms_version`,
  `terms_accepted_at`). Change `LEGAL_TERMS_VERSION` to ask everyone again.
* **Terms and privacy text** (`/terms`, `/privacy`) are drafts written from how
  the service actually works (data collected, processors, retention, deletion).
  **They have had no legal review and the contact address is a placeholder;
  both must be fixed before a public launch.**
* **Crawlers:** every non-production environment sends `X-Robots-Tag: noindex`
  and disallows all in `robots.txt`.
* **Not done:** CAPTCHA (add only if abuse is seen) and full DPA tooling.

### Public artifact ids are scoped to the org (RUB-351)

* **Decision:** the public id is now the first 32 hex characters of
  `sha256("{org}:{content_hash}")` instead of a bare content-hash prefix, so two
  orgs publishing byte-identical bundles no longer share one `/p/{id}`.
* **Unchanged:** blobs stay keyed by content hash, so storage dedupe and
  refcounts are untouched; the same org publishing the same bundle again still
  gets the same id; the id is still 32 characters, so every id-shape check and
  hostname label keeps working.
* **Compatibility:** artifacts created earlier keep their old ids, which keep
  resolving because lookup is by stored id. Two legacy artifacts that already
  collided stay collided; the secure tier already failed closed for those.
* **Alternative rejected:** org-prefixed paths (`/p/{org}/{id}`) would change
  every shared link and every client that builds one.
* **Status:** deployed to the two staging Workers and covered by a Rust test
  and by `scripts/multi-org-isolation-local.sh`. The production Worker has not
  been deployed.

### Polis on Railway staging (RUB-319)

* **Where:** Railway project `artfct`, environment `staging`: service
  `staging-polis` (image `boxyhq/jackson:26.2.0`, public domain
  `staging-polis-staging.up.railway.app`, health `/api/health`) and its own
  Postgres `Postgres-XPCc` (not Laravel's database). Production is not deployed:
  it needs your approval to promote.
* **Pinned tag:** `26.2.0`. The old pin `1.27.5` never existed on Docker Hub, so
  the first deploy failed with "image could not be found"; the compose file and
  this section now name the tag that runs.
* **Variables that matter:** the compose file was missing `DB_ENGINE`,
  `DB_TYPE`, `EXTERNAL_URL`, `NEXTAUTH_URL`, `SAML_AUDIENCE`,
  `DB_ENCRYPTION_KEY` and `CLIENT_SECRET_VERIFIER`; they are now listed there.
  Version 26.2.0 answered 401 to the API key until `JACKSON_API_KEYS` was set,
  although the docs name `API_KEYS`; both are set. All secrets live only in
  Railway, and Laravel gets `POLIS_BASE_URL`, `POLIS_API_KEY` and
  `POLIS_CLIENT_SECRET_VERIFIER`.
* **Laravel side:** `RealPolisClient` now implements the OAuth flow
  (`/api/oauth/authorize`, `/api/oauth/token`, `/api/oauth/userinfo`) and refuses
  a login issued for another tenant. `GET /teams/{team}/sso/login` starts a
  sign-in and stores a one-time `state`; the callback rejects a missing, wrong or
  reused `state`.
* **Verified live on staging:** a SAML login through MockSAML returned a code,
  `RealPolisClient` exchanged it for the right profile, and the connection
  survived a restart of the Polis service.
* **Also verified live:** a full browser sign-in that starts at
  `/teams/zz-northwind/sso/login`, goes through Polis and MockSAML, and returns
  to the Laravel callback. It signed in the existing member (one user, one
  `polis` identity, no duplicate) and then hit the terms gate. The connection
  for tenant `zz-northwind` is left in place as a test fixture.
* **Not verified yet:** an OIDC login and a SCIM create and deactivate. The
  advisory watch on `boxyhq/jackson` is yours to enable, and production needs
  your approval.

### Running commands on staging

`railway ssh` needs a registered key and a trusted host key. To avoid touching
`~/.ssh`, generate a scoped config with
`railway ssh config --service staging-web --environment staging --path <file> --alias staging-web -i ~/.ssh/id_ed25519`
and connect with `ssh -F <file> -o UserKnownHostsFile=<file2> -o StrictHostKeyChecking=accept-new staging-web '<command>'`.
Commands run inside the deployment, so they reach the staging database and see
its secrets. `php artisan staging:verify-usage` (with `STAGING_VERIFY_USAGE=true`)
cycles the synthetic org through the quota and payment states and prints the
meters.

### Live verification results (2026-09-20)

* **Usage meters (RUB-335):** near-quota 85% (warning), over-quota 100%
  (exceeded), past-due flips the payment state, healthy clears them. This found
  that the meter used the plan's limits, not the ones the Worker enforces; the
  Worker's usage endpoint now returns its limits and the meter prefers them.
* **Billing lifecycle (RUB-336), real Stripe test mode and the staging webhook:**
  subscription created and applied; cancel set the scheduled-cancel flag and the
  renewal date from the real `customer.subscription.updated` webhook; resume
  cleared it; a failing card produced `invoice.payment_failed` and the team went
  past due; paying with a good card produced `invoice.payment_succeeded` and it
  recovered; deleting the subscription returned the team to Free.

