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
