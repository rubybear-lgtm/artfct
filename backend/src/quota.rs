//! Spec 14 quota enforcement, as pure decisions. The Worker reads limits and
//! usage from D1 and asks `assert_can_add`; nothing else decides whether a
//! create or upload may add bytes. Reads never call this.

use chrono::{DateTime, Datelike, SecondsFormat, TimeZone, Utc};
use std::collections::HashMap;

#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub struct OrgLimits {
    pub storage_bytes: u64,
    pub artifacts_per_month: u64,
    pub bundle_ceiling_bytes: u64,
    pub read_only: bool,
}

#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub struct OrgUsage {
    pub storage_bytes: u64,
    pub artifacts_this_period: u64,
}

#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub enum AddKind {
    /// A new artifact: counts against the monthly artifact quota and adds bytes.
    Create,
    /// Bytes for an existing artifact's bundle file: storage only.
    Upload,
}

#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub enum QuotaReason {
    Storage,
    Artifacts,
    PastDue,
}

impl QuotaReason {
    pub fn as_str(self) -> &'static str {
        match self {
            Self::Storage => "storage",
            Self::Artifacts => "artifacts",
            Self::PastDue => "past_due",
        }
    }
}

#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub enum QuotaDecision {
    Allowed,
    QuotaExceeded(QuotaReason),
    BundleTooLarge { limit_bytes: u64 },
}

/// Order is fixed: bundle ceiling, then past-due, then artifacts/month, then
/// storage. At the limit new bytes are refused; nothing here ever deletes or
/// stops serving.
pub fn assert_can_add(
    limits: &OrgLimits,
    usage: &OrgUsage,
    incoming_bytes: u64,
    kind: AddKind,
) -> QuotaDecision {
    if incoming_bytes > limits.bundle_ceiling_bytes {
        return QuotaDecision::BundleTooLarge {
            limit_bytes: limits.bundle_ceiling_bytes,
        };
    }
    if limits.read_only {
        return QuotaDecision::QuotaExceeded(QuotaReason::PastDue);
    }
    if kind == AddKind::Create && usage.artifacts_this_period >= limits.artifacts_per_month {
        return QuotaDecision::QuotaExceeded(QuotaReason::Artifacts);
    }
    if usage.storage_bytes >= limits.storage_bytes
        || usage.storage_bytes.saturating_add(incoming_bytes) > limits.storage_bytes
    {
        return QuotaDecision::QuotaExceeded(QuotaReason::Storage);
    }
    QuotaDecision::Allowed
}

/// Blobs are content-addressed and shared, so each `content_hash` counts once
/// per org however many artifacts reference it.
pub fn storage_from_rows(rows: &[(String, u64)]) -> u64 {
    let mut by_hash: HashMap<&str, u64> = HashMap::new();
    for (hash, size) in rows {
        by_hash.entry(hash.as_str()).or_insert(*size);
    }
    by_hash.values().sum()
}

/// First instant of the current UTC calendar month, RFC 3339 with `Z`, in the
/// same format the create path stores in `artifacts.created_at`.
pub fn month_start(now: DateTime<Utc>) -> String {
    Utc.with_ymd_and_hms(now.year(), now.month(), 1, 0, 0, 0)
        .single()
        .expect("the first of a month at midnight always exists")
        .to_rfc3339_opts(SecondsFormat::Secs, true)
}

/// First instant of the next UTC calendar month, RFC 3339 with `Z`.
pub fn next_month_start(now: DateTime<Utc>) -> String {
    let (year, month) = if now.month() == 12 {
        (now.year() + 1, 1)
    } else {
        (now.year(), now.month() + 1)
    };

    Utc.with_ymd_and_hms(year, month, 1, 0, 0, 0)
        .single()
        .expect("the first of a month at midnight always exists")
        .to_rfc3339_opts(SecondsFormat::Secs, true)
}

/// Sum of the declared sizes in a manifest.
pub fn declared_bytes(sizes: impl IntoIterator<Item = usize>) -> u64 {
    sizes
        .into_iter()
        .fold(0u64, |total, size| total.saturating_add(size as u64))
}

#[cfg(test)]
mod tests {
    use super::*;

    const GB: u64 = 1024 * 1024 * 1024;

    fn limits() -> OrgLimits {
        OrgLimits {
            storage_bytes: 5 * GB,
            artifacts_per_month: 1000,
            bundle_ceiling_bytes: 10 * 1024 * 1024,
            read_only: false,
        }
    }

    fn usage(storage_bytes: u64, artifacts_this_period: u64) -> OrgUsage {
        OrgUsage {
            storage_bytes,
            artifacts_this_period,
        }
    }

    #[test]
    fn create_refused_at_storage_limit() {
        assert_eq!(
            assert_can_add(&limits(), &usage(5 * GB, 1), 1024, AddKind::Create),
            QuotaDecision::QuotaExceeded(QuotaReason::Storage)
        );
    }

    #[test]
    fn create_allowed_at_79_percent_refused_at_100() {
        let limits = limits();
        let at_79 = limits.storage_bytes / 100 * 79;
        assert_eq!(
            assert_can_add(&limits, &usage(at_79, 1), 1024, AddKind::Create),
            QuotaDecision::Allowed
        );
        assert_eq!(
            assert_can_add(&limits, &usage(limits.storage_bytes, 1), 1, AddKind::Create),
            QuotaDecision::QuotaExceeded(QuotaReason::Storage)
        );
    }

    #[test]
    fn create_refused_when_incoming_bytes_cross_the_limit() {
        let limits = limits();
        assert_eq!(
            assert_can_add(
                &limits,
                &usage(limits.storage_bytes - 10, 1),
                11,
                AddKind::Upload
            ),
            QuotaDecision::QuotaExceeded(QuotaReason::Storage)
        );
    }

    #[test]
    fn create_refused_at_artifact_count_limit() {
        assert_eq!(
            assert_can_add(&limits(), &usage(0, 1000), 1, AddKind::Create),
            QuotaDecision::QuotaExceeded(QuotaReason::Artifacts)
        );
    }

    #[test]
    fn upload_ignores_artifact_count_limit() {
        assert_eq!(
            assert_can_add(&limits(), &usage(0, 1000), 1, AddKind::Upload),
            QuotaDecision::Allowed
        );
    }

    #[test]
    fn create_refused_when_read_only() {
        let limits = OrgLimits {
            read_only: true,
            ..limits()
        };
        assert_eq!(
            assert_can_add(&limits, &usage(0, 0), 1, AddKind::Create),
            QuotaDecision::QuotaExceeded(QuotaReason::PastDue)
        );
    }

    #[test]
    fn bundle_over_ceiling_refused_at_manifest_before_upload() {
        let limits = limits();
        // Declared sizes alone decide this, so it can be answered at the
        // manifest step before any file bytes are accepted or blobs written.
        assert_eq!(
            assert_can_add(
                &limits,
                &usage(0, 0),
                limits.bundle_ceiling_bytes + 1,
                AddKind::Create
            ),
            QuotaDecision::BundleTooLarge {
                limit_bytes: limits.bundle_ceiling_bytes
            }
        );
        assert_eq!(
            assert_can_add(
                &limits,
                &usage(0, 0),
                limits.bundle_ceiling_bytes,
                AddKind::Create
            ),
            QuotaDecision::Allowed
        );
    }

    #[test]
    fn ceiling_is_checked_before_read_only_and_counts() {
        let limits = OrgLimits {
            read_only: true,
            ..limits()
        };
        assert_eq!(
            assert_can_add(
                &limits,
                &usage(limits.storage_bytes, 1000),
                limits.bundle_ceiling_bytes + 1,
                AddKind::Create
            ),
            QuotaDecision::BundleTooLarge {
                limit_bytes: limits.bundle_ceiling_bytes
            }
        );
    }

    #[test]
    fn quota_is_per_org_not_global() {
        let full = usage(5 * GB, 1000);
        let empty = usage(0, 0);
        let limits = limits();
        assert_eq!(
            assert_can_add(&limits, &full, 1, AddKind::Create),
            QuotaDecision::QuotaExceeded(QuotaReason::Artifacts)
        );
        assert_eq!(
            assert_can_add(&limits, &empty, 1, AddKind::Create),
            QuotaDecision::Allowed
        );
    }

    #[test]
    fn shared_blob_counted_once_per_org() {
        let rows = vec![
            ("hash-a".to_string(), 100),
            ("hash-a".to_string(), 100),
            ("hash-b".to_string(), 50),
        ];
        assert_eq!(storage_from_rows(&rows), 150);
    }

    #[test]
    fn deleting_artifact_frees_storage_but_not_monthly_count() {
        let limits = limits();
        let before_rows = vec![("hash-a".to_string(), 5 * GB)];
        let before = usage(storage_from_rows(&before_rows), 1000);
        assert_eq!(
            assert_can_add(&limits, &before, 1, AddKind::Upload),
            QuotaDecision::QuotaExceeded(QuotaReason::Storage)
        );
        // Soft-deleted: bytes are gone from the sum, the created_at-based
        // count is unchanged.
        let after = usage(storage_from_rows(&[]), 1000);
        assert_eq!(
            assert_can_add(&limits, &after, 1, AddKind::Upload),
            QuotaDecision::Allowed
        );
        assert_eq!(
            assert_can_add(&limits, &after, 1, AddKind::Create),
            QuotaDecision::QuotaExceeded(QuotaReason::Artifacts)
        );
    }

    #[test]
    fn month_start_is_first_of_utc_month() {
        let now = Utc.with_ymd_and_hms(2026, 9, 19, 21, 43, 0).unwrap();
        assert_eq!(month_start(now), "2026-09-01T00:00:00Z");
    }

    #[test]
    fn next_month_start_handles_year_rollover() {
        let now = Utc.with_ymd_and_hms(2026, 12, 19, 21, 43, 0).unwrap();
        assert_eq!(next_month_start(now), "2027-01-01T00:00:00Z");
    }

    #[test]
    fn declared_bytes_sums_manifest_sizes() {
        assert_eq!(declared_bytes([1, 2, 3]), 6);
        assert_eq!(declared_bytes([]), 0);
    }
}
