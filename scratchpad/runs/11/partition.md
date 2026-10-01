# Spec 11 partition

Solo session (no subagent budget since spec 08). One unit — Laravel
governance orchestration and Worker-side pure logic touch disjoint files
but are small enough, and interdependent enough conceptually, to implement
directly rather than fan out.

## Closable honestly in this environment

- Audit log: `AuditEvent` model (append-only, three independent guard
  layers: `save()`, `update()`, and `boot()`'s `updating` event), `AuditLogger`
  service, wired into every Laravel-controlled lifecycle action (member
  add/remove, role change, token create/revoke, auth_mode change, artifact
  revoke via console, retention/erasure/legal-hold, export).
- SIEM export: `SiemExportService` — JSON Lines, self-audits
  `export.performed` before returning.
- Retention: `RetentionService` — dry-run-by-default, `governance:retention`
  Artisan command; held artifacts always survive.
- Legal hold: `LegalHoldService` — place/release, refuses hard delete
  (named conflict), audited either way.
- GDPR erasure: `ErasureService` — whole-org, refuses (never partial) on
  any held artifact, names the conflict.
- Region immutability: `region` column on `teams`, set once by
  `TenantProvisioningService::provision()`, enforced immutable by `Team`'s
  `updating` guard regardless of call path (mass-assignment, `forceFill`).
- Worker-side pure logic (`backend/src/governance.rs`): `AuditEventType`/
  `AuditEvent`/JSON Lines rendering, `plan_retention`, `plan_erasure`,
  `check_share_access` (passcode/domain/expiry/revocation), all unit-tested
  without a live Worker.
- Blob refcount dedupe (`MemoryArtifactStore::hard_delete`/
  `blob_ref_count`): shared blob survives one referencing artifact's
  deletion, is removed only at refcount zero — this is the mechanism
  `gdpr_erasure_removes_bytes_from_r2` depends on.

## Structural-only (documented, not measured)

- `audit_queue_backpressure_does_not_slow_serving`: proven as an ordering
  guarantee (the response is constructed before the deferred audit write
  runs), matching the `ctx.waitUntil()` shape the real handler would use.
  No real request latency was measured under a backed-up queue.

## Current follow-up evidence (2026-10-01)

- Worker D1/R2 governance routes and Laravel's `HttpArtifactGovernance` are
  implemented; the earlier statement that `RealArtifactGovernance` still
  fails closed is stale.
- `scripts/governance-local-e2e.sh` is the executable local D1/R2 proof. It
  applies local migrations, checks missing-secret refusal, listing and
  pagination, cross-org delete refusal with the foreign artifact still
  listable from its owning org, legal-hold refusal on both delete paths,
  continued serving while held, shared-blob refcounts, direct
  `wrangler r2 object get --local` reads before and after deletion, and the
  corresponding D1 audit events. It passed from a fresh Wrangler local state
  on 2026-10-01. The former named Rust
  `gdpr_erasure_removes_bytes_from_r2` test is not present in the current tree;
  the direct-storage proof lives in this integration script instead.
- Live staging acceptance remains open: exercise Laravel retention dry-run and
  apply against synthetic fixtures, whole-run refusal when a fixture is held,
  erasure after hold release, wrong-secret and cross-org denial, and the
  unreachable-Worker/no-partial-delete case. These need the staging governance
  credentials and must not use production data.
- Full share-link HTTP surface (creating and serving links through Worker
  request dispatch) is still deferred; only pure validation logic
  (`check_share_access`) is built and tested.
