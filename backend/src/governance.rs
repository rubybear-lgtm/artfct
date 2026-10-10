//! Spec 11 — governance: append-only audit events, retention/legal hold,
//! GDPR erasure under content-addressed dedupe, and per-link sharing
//! controls.
//!
//! Kept as pure, `Env`-free logic wherever possible so it is unit-testable
//! without a live Worker — the same shape as `store.rs`'s
//! `MemoryArtifactStore`. D1/R2-backed execution lives alongside
//! `D1R2ArtifactStore` in `store.rs`; this module holds the decisions those
//! call sites apply.

use chrono::{DateTime, Utc};
use serde::{Deserialize, Serialize};

/// Every event type emitted by the Worker governance paths. Kept as an enum
/// (not a bare `&str`) so a typo in a call site is a compile error, not a
/// silently-wrong SIEM row.
#[derive(Clone, Copy, Debug, PartialEq, Eq, Serialize, Deserialize)]
#[serde(rename_all = "snake_case")]
pub enum AuditEventType {
    ArtifactCreated,
    ArtifactViewed,
    ArtifactShared,
    ArtifactRevoked,
    ArtifactDeleted,
    ArtifactHardDeleted,
    ShareCreated,
    ShareRevoked,
    MemberAdded,
    MemberRemoved,
    RoleChanged,
    TokenCreated,
    TokenRevoked,
    AuthModeChanged,
    ExportPerformed,
    RetentionApplied,
    LegalHoldApplied,
    LegalHoldPlaced,
    LegalHoldReleased,
}

impl AuditEventType {
    /// The literal string spec 11's Design section uses for this event
    /// (`artifact.created`, not `ArtifactCreated`) — used for the JSON Lines
    /// SIEM export and any place the wire format matters.
    pub fn wire_name(self) -> &'static str {
        match self {
            Self::ArtifactCreated => "artifact.created",
            Self::ArtifactViewed => "artifact.viewed",
            Self::ArtifactShared => "artifact.shared",
            Self::ArtifactRevoked => "artifact.revoked",
            Self::ArtifactDeleted => "artifact.deleted",
            Self::ArtifactHardDeleted => "artifact.hard_deleted",
            Self::ShareCreated => "share.created",
            Self::ShareRevoked => "share.revoked",
            Self::MemberAdded => "member.added",
            Self::MemberRemoved => "member.removed",
            Self::RoleChanged => "role.changed",
            Self::TokenCreated => "token.created",
            Self::TokenRevoked => "token.revoked",
            Self::AuthModeChanged => "auth_mode.changed",
            Self::ExportPerformed => "export.performed",
            Self::RetentionApplied => "retention.applied",
            Self::LegalHoldApplied => "legal_hold.applied",
            Self::LegalHoldPlaced => "legal_hold.placed",
            Self::LegalHoldReleased => "legal_hold.released",
        }
    }
}

/// One append-only audit row. `outcome` is a free-text success/failure
/// label ("success", "refused: legal_hold") rather than a bool, because a
/// refusal is itself the interesting case spec 11 asks to audit ("the
/// attempt is refused and audited").
#[derive(Clone, Debug, PartialEq, Eq, Serialize, Deserialize)]
pub struct AuditEvent {
    pub event_type: AuditEventType,
    pub org_id: String,
    pub actor: String,
    pub target: String,
    pub ip: String,
    pub user_agent: String,
    pub timestamp: String,
    pub outcome: String,
}

impl AuditEvent {
    pub fn new(
        event_type: AuditEventType,
        org_id: impl Into<String>,
        actor: impl Into<String>,
        target: impl Into<String>,
        ip: impl Into<String>,
        user_agent: impl Into<String>,
        outcome: impl Into<String>,
    ) -> Self {
        Self {
            event_type,
            org_id: org_id.into(),
            actor: actor.into(),
            target: target.into(),
            ip: ip.into(),
            user_agent: user_agent.into(),
            timestamp: Utc::now().to_rfc3339(),
            outcome: outcome.into(),
        }
    }

    /// Renders this event as one JSON Lines record — the shape SIEM export
    /// writes to the org's bucket/endpoint (DoD: "valid JSON Lines
    /// ingestible by Datadog").
    pub fn to_jsonl_record(&self) -> serde_json::Value {
        serde_json::json!({
            "event_type": self.event_type.wire_name(),
            "org_id": self.org_id,
            "actor": self.actor,
            "target": self.target,
            "ip": self.ip,
            "user_agent": self.user_agent,
            "timestamp": self.timestamp,
            "outcome": self.outcome,
        })
    }
}

/// Serializes a batch of events as JSON Lines: one compact JSON object per
/// line, `\n`-joined, trailing newline included (the convention Splunk/
/// Datadog ingestion expects). Pure and Env-free.
pub fn events_to_jsonl(events: &[AuditEvent]) -> String {
    let mut out = String::new();
    for event in events {
        out.push_str(&event.to_jsonl_record().to_string());
        out.push('\n');
    }
    out
}

/// A pure dropped-audit-events counter. The Worker currently sends hot-path
/// events to Laravel rather than writing this D1 audit table, so no live
/// `ctx.waitUntil()` call site owns this counter yet; its deployment wiring is
/// deliberately documented as a follow-up instead of being implied by this
/// type.
#[derive(Clone, Copy, Debug, Default, PartialEq, Eq)]
pub struct DroppedAuditCounter(pub u64);

impl DroppedAuditCounter {
    pub fn record_drop(&mut self) {
        self.0 += 1;
    }
}

/// Reasons a destructive governance action is refused. Distinct from
/// `store::StoreError` — these are governance-layer decisions, not storage
/// I/O failures.
#[derive(Clone, Debug, PartialEq, Eq)]
pub enum GovernanceError {
    /// The target artifact (or, for erasure, at least one artifact in the
    /// org) is under legal hold. Carries the artifact id so the refusal can
    /// name it, per DoD: "refused with both the hold and the artifact
    /// named."
    LegalHold {
        artifact_id: String,
    },
    NotFound,
}

impl std::fmt::Display for GovernanceError {
    fn fmt(&self, f: &mut std::fmt::Formatter<'_>) -> std::fmt::Result {
        match self {
            Self::LegalHold { artifact_id } => write!(
                f,
                "artifact {artifact_id} is under legal hold and cannot be hard-deleted"
            ),
            Self::NotFound => write!(f, "artifact not found"),
        }
    }
}

/// Whether a hard delete may proceed. The Worker enforces this itself, not
/// only Laravel: a Laravel bug must not be able to destroy held data.
#[derive(Debug, PartialEq, Eq)]
pub enum HardDeleteDecision {
    Proceed,
    RefuseLegalHold,
}

pub fn decide_hard_delete(legal_hold: bool) -> HardDeleteDecision {
    if legal_hold {
        HardDeleteDecision::RefuseLegalHold
    } else {
        HardDeleteDecision::Proceed
    }
}

/// One candidate the retention job is considering, decoupled from any
/// particular store's row type so `select_for_retention` and
/// `plan_erasure` stay pure and unit-testable.
#[derive(Clone, Debug, PartialEq, Eq)]
pub struct RetentionCandidate {
    pub artifact_id: String,
    pub created_at: DateTime<Utc>,
    pub legal_hold: bool,
}

/// The retention job's decision for one org, before anything is executed.
/// Deliberately the *only* thing the retention job computes directly —
/// execution (the actual hard delete) is a separate step, so a caller can
/// always request `dry_run` and get this same report without touching
/// storage. Rollback note: "every destructive path is dry-runnable first,
/// and the dry run is itself part of the DoD."
#[derive(Clone, Debug, Default, PartialEq, Eq)]
pub struct RetentionPlan {
    /// Ids the job will hard-delete (or would, in a dry run).
    pub to_delete: Vec<String>,
    /// Ids that are past the retention cutoff but held, and therefore
    /// survive the job untouched.
    pub held_survivors: Vec<String>,
}

/// Computes what a retention run of `retention_days` would do as of `now`,
/// without deleting anything. An artifact older than `retention_days` is a
/// deletion candidate unless it is under legal hold, in which case it
/// survives (DoD: "An artifact under legal hold survives the retention
/// job").
pub fn plan_retention(
    candidates: &[RetentionCandidate],
    retention_days: i64,
    now: DateTime<Utc>,
) -> RetentionPlan {
    let cutoff = now - chrono::Duration::days(retention_days);
    let mut plan = RetentionPlan::default();
    for candidate in candidates {
        if candidate.created_at >= cutoff {
            continue;
        }
        if candidate.legal_hold {
            plan.held_survivors.push(candidate.artifact_id.clone());
        } else {
            plan.to_delete.push(candidate.artifact_id.clone());
        }
    }
    plan
}

/// The result of planning a GDPR erasure across an org's artifacts. Either
/// every artifact is clear to hard-delete, or the whole request is refused
/// naming the first held artifact — DoD: "refused explicitly, with the
/// conflict named, never silently partial." Never returns a partial plan.
#[derive(Clone, Debug, PartialEq, Eq)]
pub enum ErasurePlan {
    /// Clear to proceed: every one of these ids will be hard-deleted.
    Proceed { artifact_ids: Vec<String> },
    /// Refused: at least one referencing artifact is under hold.
    Refused { held_artifact_id: String },
}

/// Plans a GDPR erasure across every artifact referencing the subject's
/// data in an org. If any is under legal hold, the whole request is
/// refused (never a partial erasure) and the held artifact is named.
pub fn plan_erasure(candidates: &[RetentionCandidate]) -> ErasurePlan {
    if let Some(held) = candidates.iter().find(|candidate| candidate.legal_hold) {
        return ErasurePlan::Refused {
            held_artifact_id: held.artifact_id.clone(),
        };
    }
    ErasurePlan::Proceed {
        artifact_ids: candidates
            .iter()
            .map(|candidate| candidate.artifact_id.clone())
            .collect(),
    }
}

/// A per-artifact share link (spec 11: "org-private (default),
/// link-with-passcode, domain-restricted, expiring, and per-link revoke").
#[derive(Clone, Debug, PartialEq, Eq)]
pub struct ShareLink {
    pub id: String,
    pub artifact_id: String,
    pub passcode: Option<String>,
    pub allowed_domain: Option<String>,
    pub expires_at: Option<DateTime<Utc>>,
    pub revoked_at: Option<DateTime<Utc>>,
}

/// The outcome of validating one access attempt against a share link.
#[derive(Clone, Debug, PartialEq, Eq)]
pub enum ShareAccessDecision {
    Granted,
    /// Maps to a 404 on the serving path in every case, never a 403 — spec
    /// 11 gives no signal to an outsider about *why* a link didn't work,
    /// matching spec 7's org-boundary 404-not-403 precedent.
    NotFound,
    WrongPasscode,
}

/// Validates one access attempt against a share link's rules, in the exact
/// order the DoD's negative tests exercise: revoked and expired links both
/// 404 (no such link, from the viewer's perspective); a domain restriction
/// checks the viewer's email domain; a passcode requires an exact match.
pub fn check_share_access(
    link: &ShareLink,
    now: DateTime<Utc>,
    provided_passcode: Option<&str>,
    viewer_domain: Option<&str>,
) -> ShareAccessDecision {
    if link.revoked_at.is_some() {
        return ShareAccessDecision::NotFound;
    }
    if let Some(expires_at) = link.expires_at {
        if now >= expires_at {
            return ShareAccessDecision::NotFound;
        }
    }
    if let Some(allowed_domain) = &link.allowed_domain {
        let matches =
            viewer_domain.is_some_and(|domain| domain.eq_ignore_ascii_case(allowed_domain));
        if !matches {
            return ShareAccessDecision::NotFound;
        }
    }
    if let Some(passcode) = &link.passcode {
        if provided_passcode != Some(passcode.as_str()) {
            return ShareAccessDecision::WrongPasscode;
        }
    }
    ShareAccessDecision::Granted
}

#[cfg(test)]
mod tests {
    use super::*;
    use chrono::Duration;

    fn now() -> DateTime<Utc> {
        DateTime::parse_from_rfc3339("2026-09-04T00:00:00Z")
            .unwrap()
            .with_timezone(&Utc)
    }

    #[test]
    fn plan_retention_deletes_only_expired_unheld_artifacts() {
        let candidates = [
            RetentionCandidate {
                artifact_id: "fresh".into(),
                created_at: now() - Duration::days(5),
                legal_hold: false,
            },
            RetentionCandidate {
                artifact_id: "expired".into(),
                created_at: now() - Duration::days(31),
                legal_hold: false,
            },
            RetentionCandidate {
                artifact_id: "expired_held".into(),
                created_at: now() - Duration::days(31),
                legal_hold: true,
            },
        ];
        let plan = plan_retention(&candidates, 30, now());
        assert_eq!(plan.to_delete, vec!["expired".to_string()]);
        assert_eq!(plan.held_survivors, vec!["expired_held".to_string()]);
    }

    #[test]
    fn plan_erasure_refuses_and_names_conflict_when_any_candidate_is_held() {
        let candidates = [
            RetentionCandidate {
                artifact_id: "a".into(),
                created_at: now(),
                legal_hold: false,
            },
            RetentionCandidate {
                artifact_id: "b_held".into(),
                created_at: now(),
                legal_hold: true,
            },
        ];
        let plan = plan_erasure(&candidates);
        assert_eq!(
            plan,
            ErasurePlan::Refused {
                held_artifact_id: "b_held".to_string()
            }
        );
    }

    #[test]
    fn plan_erasure_proceeds_across_all_artifacts_when_none_held() {
        let candidates = [
            RetentionCandidate {
                artifact_id: "a".into(),
                created_at: now(),
                legal_hold: false,
            },
            RetentionCandidate {
                artifact_id: "b".into(),
                created_at: now(),
                legal_hold: false,
            },
        ];
        let plan = plan_erasure(&candidates);
        assert_eq!(
            plan,
            ErasurePlan::Proceed {
                artifact_ids: vec!["a".to_string(), "b".to_string()]
            }
        );
    }

    fn link(overrides: impl FnOnce(ShareLink) -> ShareLink) -> ShareLink {
        overrides(ShareLink {
            id: "link1".into(),
            artifact_id: "art1".into(),
            passcode: None,
            allowed_domain: None,
            expires_at: None,
            revoked_at: None,
        })
    }

    #[test]
    fn expired_link_is_not_found() {
        let link = link(|l| ShareLink {
            expires_at: Some(now() - Duration::seconds(1)),
            ..l
        });
        assert_eq!(
            check_share_access(&link, now(), None, None),
            ShareAccessDecision::NotFound
        );
    }

    #[test]
    fn revoked_link_is_not_found_but_sibling_is_unaffected() {
        let revoked = link(|l| ShareLink {
            revoked_at: Some(now()),
            ..l
        });
        let sibling = link(|l| ShareLink {
            id: "link2".into(),
            ..l
        });
        assert_eq!(
            check_share_access(&revoked, now(), None, None),
            ShareAccessDecision::NotFound
        );
        assert_eq!(
            check_share_access(&sibling, now(), None, None),
            ShareAccessDecision::Granted
        );
    }

    #[test]
    fn domain_restricted_link_refuses_outsider_domain() {
        let link = link(|l| ShareLink {
            allowed_domain: Some("acme.com".into()),
            ..l
        });
        assert_eq!(
            check_share_access(&link, now(), None, Some("evil.com")),
            ShareAccessDecision::NotFound
        );
        assert_eq!(
            check_share_access(&link, now(), None, Some("acme.com")),
            ShareAccessDecision::Granted
        );
    }

    #[test]
    fn passcode_link_requires_exact_match() {
        let link = link(|l| ShareLink {
            passcode: Some("s3cret".into()),
            ..l
        });
        assert_eq!(
            check_share_access(&link, now(), Some("wrong"), None),
            ShareAccessDecision::WrongPasscode
        );
        assert_eq!(
            check_share_access(&link, now(), Some("s3cret"), None),
            ShareAccessDecision::Granted
        );
    }

    #[test]
    fn jsonl_export_is_one_valid_json_object_per_line() {
        let events = vec![
            AuditEvent::new(
                AuditEventType::ArtifactViewed,
                "org1",
                "user1",
                "art1",
                "1.2.3.4",
                "curl/8",
                "success",
            ),
            AuditEvent::new(
                AuditEventType::ExportPerformed,
                "org1",
                "user1",
                "audit_log",
                "1.2.3.4",
                "curl/8",
                "success",
            ),
        ];
        let jsonl = events_to_jsonl(&events);
        let lines: Vec<&str> = jsonl.lines().collect();
        assert_eq!(lines.len(), 2);
        for line in &lines {
            let parsed: serde_json::Value = serde_json::from_str(line)
                .expect("each JSON Lines row must parse as standalone JSON");
            assert!(parsed.get("event_type").is_some());
        }
        assert_eq!(lines[0], events[0].to_jsonl_record().to_string());
        assert!(jsonl.ends_with('\n'));
    }

    #[test]
    fn dropped_audit_counter_counts_every_drop() {
        let mut counter = DroppedAuditCounter::default();
        counter.record_drop();
        counter.record_drop();
        assert_eq!(counter.0, 2);
    }

    #[test]
    fn every_worker_emitted_audit_string_has_a_known_wire_name() {
        let known = [
            AuditEventType::ArtifactCreated,
            AuditEventType::ArtifactViewed,
            AuditEventType::ArtifactShared,
            AuditEventType::ArtifactRevoked,
            AuditEventType::ArtifactDeleted,
            AuditEventType::ArtifactHardDeleted,
            AuditEventType::ShareCreated,
            AuditEventType::ShareRevoked,
            AuditEventType::MemberAdded,
            AuditEventType::MemberRemoved,
            AuditEventType::RoleChanged,
            AuditEventType::TokenCreated,
            AuditEventType::TokenRevoked,
            AuditEventType::AuthModeChanged,
            AuditEventType::ExportPerformed,
            AuditEventType::RetentionApplied,
            AuditEventType::LegalHoldApplied,
            AuditEventType::LegalHoldPlaced,
            AuditEventType::LegalHoldReleased,
        ];
        let emitted = [
            "artifact.created",
            "artifact.viewed",
            "legal_hold.placed",
            "legal_hold.released",
            "artifact.hard_deleted",
        ];

        for event_type in emitted {
            assert!(
                known
                    .iter()
                    .any(|candidate| candidate.wire_name() == event_type),
                "unmapped audit event: {event_type}"
            );
        }
    }

    #[test]
    fn hard_delete_refuses_held_artifact_with_409() {
        assert_eq!(
            decide_hard_delete(true),
            HardDeleteDecision::RefuseLegalHold
        );
        assert_eq!(decide_hard_delete(false), HardDeleteDecision::Proceed);
    }
}
