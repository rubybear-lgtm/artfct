# Spec 11 — Governance

**Track:** E (enterprise) · **Depends on:** 8, 9, 10 · **Blocks:** nothing

## Scope

**In.** Append-only audit log with SIEM export. Retention policy. Legal hold. Sharing controls. Data residency by deployment region. Deletion under dedupe.

**Out.** Billing (spec 14).

**Checkpoint:** answer a real security questionnaire *from the product*, not from a document. This is the spec that makes Enterprise sellable.

## Decisions implemented

- **01** — server-readable enterprise artifacts, which is what makes auditing possible at all.
- **11** — export, now including the audit trail.

## Design

### Audit log

Append-only. No update or delete path exists in code, not merely by convention.

Events: `artifact.created`, `artifact.viewed`, `artifact.shared`, `artifact.revoked`, `artifact.deleted`, `share.created`, `share.revoked`, `member.added`, `member.removed`, `role.changed`, `token.created`, `token.revoked`, `auth_mode.changed`, `export.performed`, `retention.applied`, `legal_hold.applied`.

Each carries actor (user or token), org, target, IP, user agent, timestamp, and outcome.

`artifact.viewed` is the high-volume one and the one buyers actually want. Written asynchronously via Queues so it never sits in the serving path; the queue is the buffer, and dropped events must be counted rather than silently lost.

### Export to SIEM

Scheduled and on-demand JSON Lines export to the org's bucket or endpoint. Splunk and Datadog ingest it directly. Exports are themselves audited — `export.performed` — because "who pulled the audit log" is the first question after an incident.

### Retention and legal hold

Per-org `retention_class` with a default and per-artifact override. Retention is enforced by a scheduled job, and every action it takes is audited.

**Legal hold overrides everything**, including a user-initiated delete. An artifact under hold cannot be hard-deleted by any path; the attempt is refused and audited. This is the assertion a legal team will test.

### Deletion under dedupe

Content-addressed storage means blobs are shared between artifacts. Deletion is therefore reference-counted:

1. Soft delete — stops serving, retains everything (spec 8).
2. Hard delete after retention — removes the artifact row and decrements the blob refcount.
3. The blob is removed only at refcount zero.
4. A GDPR erasure request must remove the bytes, so it forces hard deletion of every referencing artifact in the org and is refused while any of them is under legal hold — refused explicitly, with the conflict named, never silently partial.

### Sharing controls

Per artifact: org-private (default), link-with-passcode, domain-restricted, expiring, and per-link revoke. Each share is a row with its own audit trail, so revoking one link does not affect another.

### Residency

Per-tenant deployment is the residency answer: provision in the customer's region. `region` is set at provisioning (spec 9) and is immutable afterwards — moving a tenant between regions is a migration, not a setting, and pretending otherwise is how a residency commitment gets broken.

## Definition of done

- [ ] Creating, viewing, sharing and revoking an artifact each produce an audit event with actor, IP and timestamp.
- [ ] No code path updates or deletes an audit row — asserted by a test, not by review.
- [ ] `artifact.viewed` is written asynchronously; serving latency is unchanged with the audit queue backed up.
- [ ] Dropped audit events are counted and surfaced, never silently discarded.
- [ ] SIEM export produces valid JSON Lines ingestible by Datadog.
- [ ] Performing an export writes an `export.performed` event.
- [ ] A retention policy of 30 days hard-deletes a 31-day-old artifact and audits it.
- [ ] An artifact under legal hold survives the retention job.
- [ ] An artifact under legal hold cannot be hard-deleted by an admin — refused and audited.
- [ ] A GDPR erasure across an org removes blob bytes from R2, verified by direct read returning 404.
- [ ] An erasure conflicting with a legal hold is refused with both the hold and the artifact named.
- [ ] A shared blob survives deletion of one referencing artifact and is removed on deletion of the last.
- [ ] A passcode link works, an expired link 404s, a revoked link 404s while a sibling link still works.
- [ ] A domain-restricted link refuses a viewer outside the domain.
- [ ] Tenant `region` cannot be changed after provisioning.

## Tests

**Pest — feature**
- `every_lifecycle_action_writes_an_audit_event`
- `audit_rows_have_no_update_or_delete_path` *(negative)*
- `dropped_audit_events_are_counted`
- `export_writes_export_performed_event`
- `siem_export_is_valid_jsonl`
- `retention_hard_deletes_expired_artifact`
- `legal_hold_survives_retention_job` *(negative)*
- `legal_hold_blocks_admin_hard_delete` *(negative)*
- `erasure_conflicting_with_hold_is_refused` *(negative)*
- `region_is_immutable_after_provisioning` *(negative)*
- `viewer_cannot_change_retention_policy` *(negative)*

**`backend`**
- `shared_blob_survives_single_artifact_delete`
- `blob_removed_at_refcount_zero`
- `gdpr_erasure_removes_bytes_from_r2`
- `expired_share_link_returns_404` *(negative)*
- `revoked_share_link_returns_404_sibling_unaffected` *(negative)*
- `domain_restricted_link_refuses_outsider` *(negative)*
- `audit_queue_backpressure_does_not_slow_serving`

## Rollback

**Irreversible where it deletes.** Retention and erasure destroy data by design. Every destructive path is dry-runnable first, and the dry run is itself part of the DoD for operating it.

## Deferred

- Customer-managed encryption keys. Asked for by the largest buyers; not needed for the first contracts.
- Anomaly detection over the audit log. A product in its own right.
