# Changelog

All notable changes to Artifact Engine will be recorded in this file.

## [Unreleased]

### Added

- Added the OpenAPI 3.1 contract, blocking contract-drift CI checks, and a generated API reference on `/docs` for Spec 0.
- Added stateful MCP client identity, deterministic host-source resolution, per-process session IDs, and host-aware setup configuration for Spec 1.
- Added required CLI/MCP provenance with Git discovery, per-field source attribution, credential-safe remotes, and optional self-reported model identity for Spec 2.
- Added Spec 3 permanent single-file storage with D1 metadata, R2 raw blobs, content-addressed IDs, deduplication, and CLI export; ephemeral KV behavior remains unchanged.
- Added Spec 4 deterministic multi-file permanent bundles, manifest validation, missing-file uploads, nested asset previews, and directory deployment with `--entrypoint`.
- Added Spec 5 server-side origin isolation: per-artifact `<tenant-slug>--<artifact-id>.artfct.dev` hostnames, cookieless HMAC-signed access tokens with artifact scoping and expiry, and per-artifact CSP derived from manifest `external_origins`/`unsafe_eval`; free-tier `/p/{id}` links are unchanged.
- Added Spec 6 Laravel identity foundation: starter-kit Teams (admin/member/viewer roles) for orgs, WorkOS AuthKit login backed by an injectable client, `external_identities` linking one user to many provider identities, an `auth_mode` (`authkit`/`dual`/`polis`) state machine with domain-verification and Polis-identity preconditions, and DNS TXT domain verification against an injectable resolver.
- Added Spec 7 auth seam: Laravel mints RS256 `sessionJwt`/`orgToken` credentials (org/user/role/exp/jti claims) via `OrgJwtService`, revoked by writing to the Worker's internal KV denylist through `RevocationWriter`; the Worker verifies JWTs at the edge against a KV-cached JWKS with no origin round-trip, checks the denylist, rate-limits per token, and resolves tenancy from the credential only (a body-supplied `org_id` is ignored); a credential-scoped `GET /v1/artifacts/{id}` returns 404 (never 403) for cross-org reads.
- Added Spec 8 admin console and export: cursor-paginated `GET /v1/orgs/{org}/artifacts` with repo/agent/date filtering, `PATCH /v1/orgs/{org}/artifacts/{id}` soft-delete revocation (row and blob retained, provenance intact), and a revoked-artifact check on the `/p/{id}` serving path; a Laravel/Inertia console lists and filters artifacts, gates revoke and export to admins (403 for others, 404 rather than 403 across org boundaries), and shows a created API token's value exactly once.
- Added Spec 9 tenant provisioning orchestration: `tenant:provision`/`tenant:migrate --all`/`tenant:status`/`tenant:deprovision` Artisan commands against an injectable `TenantProvisionerContract`, with idempotent, resumable provisioning (a killed run records its failed step and resumes from there, never repeating a completed step) and a fleet migration runner that continues past a single tenant's failure, reports every failure by name, and skips tenants already at the target schema version on rerun. The real Cloudflare Workers for Platforms provisioner is not implemented — this environment has no live dispatch-namespace account — so provisioning, isolation, and rate-limit DoD items that need one are deferred; see DOCUMENTATION.md.
- Added Spec 10 enterprise SSO: org-scoped SAML/OIDC login (`EnterpriseIdentityResolver`) that links by verified email among an org's existing members and never silently creates a duplicate account on a mismatch; `AuthModeTransitioner` now lists every member without a Polis identity and requires explicit confirmation before enforcing `polis`; SCIM provisioning/de-provisioning (deactivates the user and revokes every one of their org tokens via spec 07's denylist). The real Ory Polis deployment is not implemented — pinned in `docker-compose.polis.yml` as a config artifact but not deployed or health-checked from this environment; see DOCUMENTATION.md.
- Added Spec 11 governance: an append-only Laravel audit log (`AuditEvent`, guarded against update/delete at three independent layers) covering every Laravel-controlled lifecycle action — member add/remove, role change, token mint/revoke, `auth_mode` change, console artifact revoke, retention, legal hold, and GDPR erasure — plus on-demand JSON Lines SIEM export that self-audits `export.performed`. Retention (`governance:retention`) and GDPR erasure (`governance:erase`) both default to a dry run and require `--apply` to execute; both refuse — wholly, never partially, and naming the conflict — when a referencing artifact is under legal hold. `Team.region` is set once at spec-9 provisioning and immutable afterwards, enforced regardless of call path. The Worker gained content-addressed dedupe accounting (`MemoryArtifactStore::hard_delete`, blob refcounting) and pure retention/erasure/share-link decision logic (`backend/src/governance.rs`); the live D1/R2 HTTP routes those decisions would run against, and `gdpr_erasure_removes_bytes_from_r2`'s R2 read-after-delete proof, are not wired in this environment — see DOCUMENTATION.md.
- Added Spec 12 indexing pipeline: `IndexArtifactJob` (a real Laravel queued job — `$tries`, `backoff()`, and `failed()` dead-lettering to `ArtifactIndexingFailure` with the reason, surfaced via the console's `indexingFailures` prop) drives render (skipped when the raw HTML already has visible text — `ExtractionHeuristics::needsRender`) → extract → chunk-with-overlap (`Chunker`) → embed → per-tenant-scoped upsert (`VectorIndexContract`, `$orgId`-keyed at every method). Extracted text is persisted (`ArtifactIndexEntry`) so `IndexingService::reembed()` never re-renders. Every chunk carries `org_id`/`agent`/`repo_url`/`commit_sha`. Deleting an artifact (via spec 11's retention/erasure/legal-hold hard-delete paths) removes its vectors. `RealRenderer`/`RealEmbeddings`/`RealVectorIndex` fail closed — no live Cloudflare Browser Rendering, Workers AI, or Vectorize account in this environment — and the Worker has no webhook wired to dispatch this job on `artifact.created`; `indexing:index` is the manual entry point in the meantime. See DOCUMENTATION.md for the Vectorize cost model, which is written down but not reconciled against observed billing (no live account here).

## 0.0.1 - 2026-06-03

### Added

- Started the `artfct` CLI with standalone `deploy`, `doctor`, and `mcp serve` commands.
- Added CLI parsing and API client tests for artifact deployment.
- Added a public shell installer script for GitHub Release binaries.
- Added a GitHub Release workflow for publishing CLI binaries.
- Added Rust and CLI CI jobs plus matching pre-commit checks.
- Expanded CLI help with command descriptions, examples, and environment variable guidance.
- Added a Rust Cloudflare Worker backend for creating, deleting, and rendering ephemeral HTML artifacts.
- Added Workers KV storage with Brotli-compressed artifact payloads and TTL-based expiration.
- Added `artfct.dev/v1/*` and `artfct.dev/p/*` Worker routes.
- Added a stdio MCP server exposing the `deploy_to_canvas` tool.
- Added Cloudflare rate-limit automation for artifact creation and preview routes.
- Added CI checks for Laravel, Node, and Rust.
- Added Worker deployment workflow with WAF rate-limit application.
