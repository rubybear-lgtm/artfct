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

## Not closable here — named, not silently skipped

- Real D1/R2 wiring for governance HTTP routes on the Worker
  (`RealArtifactGovernance` fails closed, same pattern as spec 09's
  `RealTenantProvisioner`) — building and verifying live hard-delete/
  legal-hold/list-older-than routes needs a Wrangler dev environment this
  session doesn't have, plus threading `worker::Context` through the
  existing handler call chain for genuine `ctx.waitUntil()` audit writes on
  `create_permanent_artifact`/`resolve_permanent_artifact`. The pure
  decision logic and in-memory proof exist; the live wiring is a follow-up.
- `gdpr_erasure_removes_bytes_from_r2`: `#[ignore]`d stub — needs a live R2
  bucket to prove a direct read 404s.
- Full share-link HTTP surface (creating/serving links through the Worker's
  request dispatch): only the pure validation logic
  (`check_share_access`) is built and tested; wiring it into
  `resolve_permanent_artifact`'s serving path is a follow-up alongside the
  D1R2 governance routes above.
