//! Spec 20 artifact database: a small per-artifact document store served from
//! the artifact's own isolated origin under `/__artfct/db/`.
//!
//! This endpoint is the first write path on an artifact origin, and spec 05
//! was written for a read-only origin — so a happy-path test does not close
//! it. Three independent defenses, all required, all enforced before any D1
//! query runs:
//!
//! 1. **Fetch metadata.** `Sec-Fetch-Site: same-origin`, or (old clients) an
//!    `Origin` equal to the artifact's own host. Never `same-site` or `none`.
//! 2. **Non-simple requests only.** Every request carries `X-Artfct-Db: 1` and
//!    every request with a body is `application/json`. Cross-origin callers
//!    then need a preflight, and every `OPTIONS` on this path is answered
//!    with no `Access-Control-Allow-*` headers, so no preflight can succeed.
//! 3. **Bearer, not ambient cookie, for writes.** Reads accept the host-only
//!    cookie or a bearer token; writes require `Authorization: Bearer`, which
//!    a cross-origin form post or `no-cors` fetch cannot set. The cookie
//!    alone is never sufficient to write.
//!
//! Tenancy comes from the verified access token plus the D1 row it names:
//! every doc query binds `org_id` and `artifact_id` from those two
//! server-side sources, never from the request path or body. There is no code
//! path that accepts either from the client.
//!
//! Responses here never use the shared `json_response` helpers: those set
//! `Access-Control-Allow-Origin: *`, and the artifact origin shares no CORS
//! allowance with anyone (spec 05).

use std::collections::{HashMap, HashSet};

use super::*;
use crate::artifact_origin::{self, TokenViewer};
use crate::sharing::{self, EditAccess, Sharing};
use crate::validation::{self, DbManifest};

/// The reserved prefix, on the artifact origin only. The same prefix on any
/// other host is a 404; a bundle path under it is rejected at deploy.
pub(crate) const DB_PATH_PREFIX: &str = "/__artfct/db/";
/// The custom header every database request must carry (defense 2).
pub(crate) const DB_HEADER: &str = "X-Artfct-Db";
/// How long a session-minted write token lasts.
pub(crate) const DB_SESSION_TTL_SECONDS: i64 = 600;
/// Documents are capped at 64 KiB each (spec 20 Limits).
pub(crate) const DB_MAX_BODY_BYTES: usize = 64 * 1024;
/// `POST /batch` applies at most 25 writes atomically (spec 20 Endpoint).
pub(crate) const DB_MAX_BATCH_OPS: usize = 25;
/// List pages default to 50 docs and never exceed 100.
pub(crate) const DB_LIST_DEFAULT_LIMIT: usize = 50;
pub(crate) const DB_LIST_MAX_LIMIT: usize = 100;
/// Per artifact and per writer, per minute (spec 20 Rate limiting).
pub(crate) const DB_WRITE_LIMIT_PER_MINUTE: u32 = 60;
pub(crate) const DB_WRITE_LIMIT_WINDOW_SECONDS: u64 = 60;
/// Conservative defaults when the org has no pushed database quotas.
pub(crate) const DEFAULT_MAX_DB_DOCS_PER_ARTIFACT: u64 = 1000;
pub(crate) const DEFAULT_MAX_DB_BYTES_PER_ORG: u64 = 5 * 1024 * 1024;
/// A stale `if_version` fails with 409 and the current document.
pub(crate) const DB_STALE_STATUS: u16 = 409;
/// Over quota fails with 429 and the limit named in `details.limit`.
pub(crate) const DB_QUOTA_STATUS: u16 = 429;

/// The live artifact row a database request targets. Fetched by the stable
/// id from the isolated hostname, then gated on `org` matching the host.
#[derive(Debug, Deserialize)]
pub(crate) struct DbArtifactRow {
    pub(crate) id: String,
    pub(crate) org: String,
    pub(crate) tier: String,
    pub(crate) user_id: Option<String>,
    pub(crate) edit_access: String,
    pub(crate) manifest: String,
}

/// One stored document, in the shape every doc query selects.
#[derive(Debug, Deserialize, Clone, PartialEq, Eq)]
pub(crate) struct DbDocRow {
    pub(crate) doc_id: String,
    pub(crate) body: String,
    pub(crate) version: i64,
    pub(crate) updated_at: String,
    pub(crate) updated_by: String,
}

impl DbDocRow {
    pub(crate) fn body_value(&self) -> Value {
        serde_json::from_str(&self.body).unwrap_or(Value::Null)
    }
}

/// The artifact a request is routed to: the isolated hostname names it, the
/// D1 row confirms it. Pure so the not-our-artifact cases are unit-testable.
pub(crate) fn db_host_matches_row(host_org: &str, host_artifact: &str, row: &DbArtifactRow) -> bool {
    row.id == host_artifact && row.org == host_org
}

/// Whether the declaration turns the endpoint on: at least one collection.
/// An artifact whose manifest declares no `db` answers 404 on the prefix.
pub(crate) fn db_endpoint_active(db: Option<&DbManifest>) -> bool {
    db.is_some_and(|declaration| !declaration.collections.is_empty())
}

/// Whether `collection` is declared. Writes — and reads — to an undeclared
/// collection return 422, never 404 (the endpoint exists, the collection does
/// not).
pub(crate) fn db_collection_declared(manifest: &DbManifest, collection: &str) -> bool {
    manifest.collections.contains_key(collection)
}

/// Defense 1: `Sec-Fetch-Site: same-origin`, or — when the header is absent
/// (old clients) — an `Origin` equal to this exact artifact host. `same-site`
/// and `none` are never accepted, and a missing header with a missing or
/// foreign origin is rejected.
pub(crate) fn db_fetch_site_ok(
    sec_fetch_site: Option<&str>,
    origin: Option<&str>,
    host: &str,
) -> bool {
    match sec_fetch_site {
        Some(site) => site.trim().eq_ignore_ascii_case("same-origin"),
        None => origin.is_some_and(|value| value == format!("https://{host}")),
    }
}

/// Defense 2: every request carries `X-Artfct-Db: 1`, and every request with
/// a body (`PUT`/`POST`/`PATCH`) is `application/json` (media type match,
/// parameters ignored). A cross-origin `no-cors` `text/plain` fetch and a
/// cross-site form post both fail here before authentication is consulted.
pub(crate) fn db_request_shape_ok(
    method: &Method,
    content_type: Option<&str>,
    db_header: Option<&str>,
) -> bool {
    if db_header.is_none_or(|value| value.trim() != "1") {
        return false;
    }
    match method {
        Method::Put | Method::Post | Method::Patch => content_type.is_some_and(|value| {
            value
                .split(';')
                .next()
                .is_some_and(|media| media.trim().eq_ignore_ascii_case("application/json"))
        }),
        _ => true,
    }
}

/// The database path after the `/__artfct/db/` prefix. Only shapes the spec
/// names: anything else is a 404, never a neighboring file.
#[derive(Debug, Clone, PartialEq, Eq)]
pub(crate) enum DbPath<'a> {
    Session,
    Batch,
    Collection { collection: &'a str },
    Doc { collection: &'a str, doc_id: &'a str },
}

/// Splits the db prefix off a request path. Returns the raw segments —
/// charset/length validation happens in the handler so a malformed name is a
/// 422, distinct from an undeclared (well-formed) collection.
pub(crate) fn parse_db_path(path: &str) -> Option<DbPath<'_>> {
    let rest = path.strip_prefix(DB_PATH_PREFIX)?;
    if rest == "session" {
        return Some(DbPath::Session);
    }
    if rest == "batch" {
        return Some(DbPath::Batch);
    }
    let mut segments = rest.split('/');
    let collection = segments.next()?;
    if collection.is_empty() {
        return None;
    }
    match segments.next() {
        None => Some(DbPath::Collection { collection }),
        Some(doc_id) => {
            if doc_id.is_empty() || segments.next().is_some() {
                return None;
            }
            Some(DbPath::Doc {
                collection,
                doc_id,
            })
        }
    }
}

/// Who the caller is on this artifact's database, from the sharing tiers and
/// `edit_access` (RUB-438) plus the verified token. Unknown sharing tiers
/// fail closed to private and unknown edit access to view via
/// `Sharing::from_tier`/`EditAccess::from_stored`, so this function only ever
/// sees recognised values — anything it does not recognise is no access.
#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub(crate) enum DbRole {
    Owner,
    Admin,
    Member,
    Viewer,
    Link,
}

impl DbRole {
    pub(crate) fn as_str(self) -> &'static str {
        match self {
            Self::Owner => "owner",
            Self::Admin => "admin",
            Self::Member => "member",
            Self::Viewer => "viewer",
            Self::Link => "link",
        }
    }

    pub(crate) fn can_write(self) -> bool {
        matches!(self, Self::Owner | Self::Admin | Self::Member)
    }
}

/// The one Worker function that returns the role. Every read and write path
/// calls it; `None` is no access.
pub(crate) fn db_role_for(
    sharing: Sharing,
    edit_access: EditAccess,
    owner_user_id: Option<&str>,
    viewer: Option<&TokenViewer>,
) -> Option<DbRole> {
    let Some(viewer) = viewer else {
        // Anonymous (or a legacy token naming nobody): a public artifact's
        // link holder may read; anything else is no access.
        return sharing.is_anonymous().then_some(DbRole::Link);
    };
    if let (Some(name), Some(owner)) = (viewer.viewer.as_deref(), owner_user_id) {
        if !owner.is_empty() && name == owner {
            return Some(DbRole::Owner);
        }
    }
    if viewer.sees_private {
        // A team admin, or the `system` renderer: reads and writes everywhere
        // in the team, matching the private-open rule for access tokens.
        return Some(DbRole::Admin);
    }
    // A verified token that names nobody (the legacy three-part form, or a
    // session minted for an anonymous link holder) is anonymous-equivalent:
    // a public artifact's link holder, nothing elsewhere. It can never be a
    // member, so writers stay named.
    let Some(name) = viewer.viewer.as_deref().filter(|name| !name.is_empty()) else {
        return sharing.is_anonymous().then_some(DbRole::Link);
    };
    // A member candidate: must see the artifact, and writes only when its
    // edit access allows — a member without edit access is a viewer.
    let probe = sharing::Viewer {
        user_id: name,
        role: "member",
        scope: None,
    };
    if !sharing::can_view(sharing, owner_user_id, &probe) {
        return None;
    }
    Some(if edit_access == EditAccess::Edit {
        DbRole::Member
    } else {
        DbRole::Viewer
    })
}

/// What the caller presented: a token minted for this artifact (with who it
/// names), a token minted for a *different* artifact, or garbage. The
/// distinction matters: a token for artifact A on artifact B's database is a
/// 403 (wrong place, valid credential); a malformed or expired token is a
/// 401. Both fail; they fail differently on purpose.
#[derive(Debug, Clone, PartialEq, Eq)]
pub(crate) enum PresentedToken {
    This(TokenViewer),
    OtherArtifact,
    Garbage,
}

/// Classifies `presented` against `artifact_id` at `now`. A token that
/// verifies for the claimed id embedded in it, but that id is not this
/// artifact, is `OtherArtifact` — it was minted by us (valid signature, live)
/// for somewhere else.
pub(crate) fn classify_db_token(
    secret: Option<&str>,
    presented: &str,
    artifact_id: &str,
    now: chrono::DateTime<Utc>,
) -> PresentedToken {
    if let Some(viewer) = artifact_origin::verified_token_viewer(secret, presented, artifact_id, now) {
        return PresentedToken::This(viewer);
    }
    let claimed = match presented.split('.').collect::<Vec<_>>().as_slice() {
        [id, _, _] | [id, _, _, _, _] => *id,
        _ => return PresentedToken::Garbage,
    };
    if claimed.is_empty() || claimed == artifact_id {
        // Right shape for this artifact but a bad signature or expired: a
        // forged or stale credential, not a credential for elsewhere.
        return PresentedToken::Garbage;
    }
    match artifact_origin::verified_token_viewer(secret, presented, claimed, now) {
        Some(_) => PresentedToken::OtherArtifact,
        None => PresentedToken::Garbage,
    }
}

/// Why a request is refused, before any status is attached.
#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub(crate) enum DbDeny {
    Unauthorized,
    Forbidden,
}

/// What a successful authorization carries: the role, plus the verified
/// viewer name when the token names one (writers are always named; the
/// `updated_by` column and the rate-limit key use this, never a
/// client-supplied value).
#[derive(Debug, Clone, PartialEq, Eq)]
pub(crate) struct DbAuth {
    pub(crate) role: DbRole,
    pub(crate) viewer: Option<String>,
}

/// Authorizes one database request. `bearer` is the classified
/// `Authorization` header (defense 3); `cookie_viewer` is the verified
/// host-only cookie, if any. Writes require a bearer token minted for this
/// artifact — the cookie alone, even a valid one, answers 401. Reads accept
/// either; an unverifiable cookie is treated as absent (ambient, possibly
/// stale), while a presented-but-invalid bearer fails closed.
#[allow(
    clippy::too_many_arguments,
    reason = "one call site; the parameters are the audit trail for the three defenses"
)]
pub(crate) fn authorize_db(
    sharing: Sharing,
    edit_access: EditAccess,
    owner_user_id: Option<&str>,
    bearer: Option<PresentedToken>,
    cookie_viewer: Option<TokenViewer>,
    is_write: bool,
) -> Result<DbAuth, DbDeny> {
    if is_write {
        let viewer = match bearer {
            Some(PresentedToken::This(viewer)) => viewer,
            Some(PresentedToken::OtherArtifact) => return Err(DbDeny::Forbidden),
            _ => return Err(DbDeny::Unauthorized),
        };
        return match db_role_for(sharing, edit_access, owner_user_id, Some(&viewer)) {
            Some(role) if role.can_write() => Ok(DbAuth {
                role,
                viewer: viewer.viewer.clone(),
            }),
            _ => Err(DbDeny::Forbidden),
        };
    }
    let viewer_ref: Option<&TokenViewer> = match &bearer {
        Some(PresentedToken::This(viewer)) => Some(viewer),
        Some(PresentedToken::Garbage) => return Err(DbDeny::Unauthorized),
        Some(PresentedToken::OtherArtifact) => return Err(DbDeny::Forbidden),
        None => cookie_viewer.as_ref(),
    };
    match db_role_for(sharing, edit_access, owner_user_id, viewer_ref) {
        Some(role) => Ok(DbAuth {
            viewer: viewer_ref.and_then(|viewer| viewer.viewer.clone()),
            role,
        }),
        None if bearer.is_some() => Err(DbDeny::Forbidden),
        None => Err(DbDeny::Unauthorized),
    }
}

/// Extracts the collection's declared index field from a document body.
/// Strings stay verbatim; numbers and booleans stringify; anything else
/// (missing, null, object, array) is not indexed.
pub(crate) fn db_index_value(body: &Value, field: &str) -> Option<String> {
    match body.get(field)? {
        Value::String(value) => Some(value.clone()),
        Value::Number(value) => Some(value.to_string()),
        Value::Bool(value) => Some(value.to_string()),
        _ => None,
    }
}

/// The validated `?limit=&cursor=&index_eq=` parameters for a collection
/// list. `limit` defaults to 50 and clamps to 100; `cursor` is an opaque
/// doc id from a previous page; `index_eq` filters on the collection's
/// declared index field.
#[derive(Debug, Clone, PartialEq, Eq)]
pub(crate) struct DbListParams {
    pub(crate) limit: usize,
    pub(crate) cursor: Option<String>,
    pub(crate) index_eq: Option<String>,
}

pub(crate) fn parse_db_list_params(query: &HashMap<String, String>) -> Result<DbListParams, ErrorCode> {
    let limit = match query.get("limit") {
        None => DB_LIST_DEFAULT_LIMIT,
        Some(raw) => {
            let parsed: usize = raw.parse().map_err(|_| ErrorCode::ValidationFailed)?;
            if parsed == 0 {
                return Err(ErrorCode::ValidationFailed);
            }
            parsed.min(DB_LIST_MAX_LIMIT)
        }
    };
    let cursor = match query.get("cursor") {
        None => None,
        Some(raw)
            if validation::is_valid_db_name(raw, validation::MAX_DB_DOC_ID_CHARS) =>
        {
            Some(raw.clone())
        }
        Some(_) => return Err(ErrorCode::ValidationFailed),
    };
    let index_eq = match query.get("index_eq") {
        None => None,
        Some(raw) if !raw.is_empty() && raw.len() <= 1024 => Some(raw.clone()),
        Some(_) => return Err(ErrorCode::ValidationFailed),
    };
    Ok(DbListParams {
        limit,
        cursor,
        index_eq,
    })
}

/// `?if_version=` for single-document writes: an integer version of at
/// least 1. Absent means last-write-wins.
pub(crate) fn parse_if_version(query: &HashMap<String, String>) -> Result<Option<i64>, ErrorCode> {
    match query.get("if_version") {
        None => Ok(None),
        Some(raw) => {
            let version: i64 = raw.parse().map_err(|_| ErrorCode::ValidationFailed)?;
            if version < 1 {
                return Err(ErrorCode::ValidationFailed);
            }
            Ok(Some(version))
        }
    }
}

/// Whether the write may proceed against `current`. A guarded write against
/// a missing document is `Missing` (404); a version mismatch is `Stale`
/// (409 with the current document).
#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub(crate) enum DbWriteConflict {
    Missing,
    Stale,
}

pub(crate) fn db_guard_version(
    current: Option<i64>,
    if_version: Option<i64>,
) -> Result<(), DbWriteConflict> {
    match (current, if_version) {
        (None, Some(_)) => Err(DbWriteConflict::Missing),
        (Some(version), Some(expected)) if version != expected => Err(DbWriteConflict::Stale),
        _ => Ok(()),
    }
}

/// One validated batch operation. There is deliberately no tenant field: any
/// `artifact_id`/`org_id` keys a client sends are ignored by construction —
/// every query binds them from the verified token and the D1 row.
#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub(crate) enum DbBatchOpKind {
    Put,
    Patch,
    Delete,
}

#[derive(Debug, Clone, PartialEq, Eq)]
pub(crate) struct DbBatchOp {
    pub(crate) kind: DbBatchOpKind,
    pub(crate) collection: String,
    pub(crate) doc_id: String,
    pub(crate) body: Option<Value>,
    pub(crate) if_version: Option<i64>,
}

/// Validates a `POST /batch` body: `{ops: [...]}` with 1–25 operations, no
/// duplicate document within the batch, object bodies for puts and patches,
/// no body for deletes, and `if_version >= 1` when present.
pub(crate) fn parse_db_batch_ops(raw: &Value) -> Result<Vec<DbBatchOp>, ErrorCode> {
    let ops = raw
        .get("ops")
        .and_then(Value::as_array)
        .ok_or(ErrorCode::ValidationFailed)?;
    if ops.is_empty() || ops.len() > DB_MAX_BATCH_OPS {
        return Err(ErrorCode::ValidationFailed);
    }
    let mut seen = HashSet::new();
    let mut parsed = Vec::with_capacity(ops.len());
    for op in ops {
        let op = op.as_object().ok_or(ErrorCode::ValidationFailed)?;
        let kind = match op.get("op").and_then(Value::as_str) {
            Some("put") => DbBatchOpKind::Put,
            Some("patch") => DbBatchOpKind::Patch,
            Some("delete") => DbBatchOpKind::Delete,
            _ => return Err(ErrorCode::ValidationFailed),
        };
        let collection = op
            .get("collection")
            .and_then(Value::as_str)
            .ok_or(ErrorCode::ValidationFailed)?;
        let doc_id = op
            .get("doc_id")
            .and_then(Value::as_str)
            .ok_or(ErrorCode::ValidationFailed)?;
        if !validation::is_valid_db_name(collection, validation::MAX_DB_COLLECTION_CHARS)
            || !validation::is_valid_db_name(doc_id, validation::MAX_DB_DOC_ID_CHARS)
            || !seen.insert((collection.to_string(), doc_id.to_string()))
        {
            return Err(ErrorCode::ValidationFailed);
        }
        let body = op.get("body").cloned();
        let if_version = match op.get("if_version") {
            None | Some(Value::Null) => None,
            Some(version) => {
                let version = version.as_i64().ok_or(ErrorCode::ValidationFailed)?;
                if version < 1 {
                    return Err(ErrorCode::ValidationFailed);
                }
                Some(version)
            }
        };
        match kind {
            DbBatchOpKind::Delete if body.is_some() => return Err(ErrorCode::ValidationFailed),
            DbBatchOpKind::Put | DbBatchOpKind::Patch
                if !body.as_ref().is_some_and(Value::is_object) =>
            {
                return Err(ErrorCode::ValidationFailed)
            }
            _ => {}
        }
        parsed.push(DbBatchOp {
            kind,
            collection: collection.to_string(),
            doc_id: doc_id.to_string(),
            body,
            if_version,
        });
    }
    Ok(parsed)
}

/// Why one batch operation refuses, with its position: the validator runs
/// over every operation before any statement is built, so one failing write
/// applies none.
#[derive(Debug, Clone, PartialEq, Eq)]
pub(crate) enum DbBatchRefusal {
    UnknownCollection { index: usize },
    BodyTooLarge { index: usize },
    Missing { index: usize },
    Stale { index: usize, current: DbDocRow },
}

/// Validates every batch operation against the declaration and a snapshot of
/// the current rows. Quota is checked by the caller from the same snapshot;
/// this function decides per-operation refusal only.
pub(crate) fn validate_db_batch(
    ops: &[DbBatchOp],
    existing: &HashMap<(String, String), DbDocRow>,
    manifest: &DbManifest,
) -> Result<(), DbBatchRefusal> {
    for (index, op) in ops.iter().enumerate() {
        if !db_collection_declared(manifest, &op.collection) {
            return Err(DbBatchRefusal::UnknownCollection { index });
        }
        if let Some(body) = op.body.as_ref() {
            if body.to_string().len() > DB_MAX_BODY_BYTES {
                return Err(DbBatchRefusal::BodyTooLarge { index });
            }
        }
        let current = existing.get(&(op.collection.clone(), op.doc_id.clone()));
        match op.kind {
            DbBatchOpKind::Put => match db_guard_version(current.map(|row| row.version), op.if_version) {
                Err(DbWriteConflict::Missing) => return Err(DbBatchRefusal::Missing { index }),
                Err(DbWriteConflict::Stale) => {
                    return Err(DbBatchRefusal::Stale {
                        index,
                        current: current.cloned().expect("stale implies present"),
                    })
                }
                Ok(()) => {}
            },
            // Patch and delete target an existing document.
            DbBatchOpKind::Patch | DbBatchOpKind::Delete if current.is_none() => {
                return Err(DbBatchRefusal::Missing { index })
            }
            DbBatchOpKind::Patch | DbBatchOpKind::Delete => {
                match db_guard_version(current.map(|row| row.version), op.if_version) {
                    Err(DbWriteConflict::Missing) => return Err(DbBatchRefusal::Missing { index }),
                    Err(DbWriteConflict::Stale) => {
                        return Err(DbBatchRefusal::Stale {
                            index,
                            current: current.cloned().expect("stale implies present"),
                        })
                    }
                    Ok(()) => {}
                }
            }
        }
    }
    Ok(())
}

/// Quota inputs: the pushed (or default) limits for the org.
#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub(crate) struct DbQuotaLimits {
    pub(crate) max_docs: u64,
    pub(crate) max_bytes: u64,
}

/// Which limit a write exceeds. Reads are never blocked by quota: no read
/// path calls [`db_quota_check`].
#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub(crate) enum DbQuotaDeny {
    Docs,
    Bytes,
}

impl DbQuotaDeny {
    pub(crate) fn limit_name(self) -> &'static str {
        match self {
            Self::Docs => "max_db_docs_per_artifact",
            Self::Bytes => "max_db_bytes_per_org",
        }
    }
}

/// Whether a write fits: `used + delta` must stay within each limit.
/// Negative deltas (deletes, shrinks) can only help.
pub(crate) fn db_quota_check(
    limits: DbQuotaLimits,
    used_docs: u64,
    used_bytes: u64,
    add_docs: i64,
    add_bytes: i64,
) -> Result<(), DbQuotaDeny> {
    if used_docs.saturating_add_signed(add_docs) > limits.max_docs {
        return Err(DbQuotaDeny::Docs);
    }
    if used_bytes.saturating_add_signed(add_bytes) > limits.max_bytes {
        return Err(DbQuotaDeny::Bytes);
    }
    Ok(())
}

/// One seed row with its insert-time fields resolved: the declared index
/// extracted, the body serialized. The deploy path binds the artifact id,
/// `version = 1`, `updated_at` and `updated_by`.
pub(crate) struct DbSeedInsert {
    pub(crate) collection: String,
    pub(crate) doc_id: String,
    pub(crate) body: String,
    pub(crate) index_value: Option<String>,
}

/// Derives the seed inserts from a validated declaration. Called only on
/// first deploy of an artifact id, never on a republish — the republish path
/// has no call site for this function.
pub(crate) fn db_seed_inserts(manifest: &DbManifest) -> Vec<DbSeedInsert> {
    manifest
        .seed
        .iter()
        .map(|row| {
            let index_value = manifest
                .collections
                .get(&row.collection)
                .and_then(|declaration| declaration.index.as_deref())
                .and_then(|field| db_index_value(&row.body, field));
            DbSeedInsert {
                collection: row.collection.clone(),
                doc_id: row.doc_id.clone(),
                body: row.body.to_string(),
                index_value,
            }
        })
        .collect()
}

/// The first-deploy seed write. Used only by the create path — version
/// publishes never seed — and binding the stable artifact id, so a
/// republish (new version, same id) leaves rows untouched.
pub(crate) const DB_SEED_INSERT_SQL: &str = "INSERT INTO artifact_docs (org_id, artifact_id, collection, doc_id, body, index_value, version, updated_at, updated_by) VALUES (?, ?, ?, ?, ?, ?, 1, ?, ?)";

/// The KV key for the per-artifact per-writer rate-limit counter (spec 20
/// Rate limiting, using the Worker's existing per-IP limiter pattern).
pub(crate) fn db_rate_limit_key(artifact_id: &str, identity: &str) -> String {
    format!("dbratelimit:{artifact_id}:{identity}")
}

async fn db_write_allowed(env: &Env, artifact_id: &str, identity: &str) -> Result<bool> {
    let kv = env.kv(KV_BINDING)?;
    let key = db_rate_limit_key(artifact_id, identity);
    let current = kv
        .get(&key)
        .text()
        .await?
        .and_then(|value| value.parse::<u32>().ok())
        .unwrap_or(0);
    if !crate::auth::rate_limit_allows(current, DB_WRITE_LIMIT_PER_MINUTE) {
        return Ok(false);
    }
    kv.put(&key, (current + 1).to_string())?
        .expiration_ttl(DB_WRITE_LIMIT_WINDOW_SECONDS)
        .execute()
        .await?;
    Ok(true)
}

// Every doc query binds org_id + artifact_id from the verified token and the
// D1 row — tenancy never comes from the path or body. The constants are
// public so tests pin the binding the way `ORG_STORAGE_USAGE_SQL` pins the
// revocation filter.
pub(crate) const DB_ARTIFACT_SQL: &str = "SELECT a.id AS id, o.slug AS org, a.tier AS tier, a.user_id AS user_id, a.edit_access AS edit_access, a.manifest AS manifest FROM artifacts a JOIN orgs o ON o.id = a.org_id WHERE a.id = ? AND a.revoked_at IS NULL ORDER BY a.row_id LIMIT 1";
pub(crate) const DB_GET_SQL: &str = "SELECT doc_id, body, version, updated_at, updated_by FROM artifact_docs WHERE org_id = ? AND artifact_id = ? AND collection = ? AND doc_id = ?";
pub(crate) const DB_LIST_BASE_SQL: &str = "SELECT doc_id, body, version, updated_at, updated_by FROM artifact_docs WHERE org_id = ? AND artifact_id = ? AND collection = ?";
pub(crate) const DB_UPSERT_SQL: &str = "INSERT INTO artifact_docs (org_id, artifact_id, collection, doc_id, body, index_value, version, updated_at, updated_by) VALUES (?, ?, ?, ?, ?, ?, 1, ?, ?) ON CONFLICT (org_id, artifact_id, collection, doc_id) DO UPDATE SET body = excluded.body, index_value = excluded.index_value, version = artifact_docs.version + 1, updated_at = excluded.updated_at, updated_by = excluded.updated_by";
pub(crate) const DB_UPDATE_SQL: &str = "UPDATE artifact_docs SET body = ?, index_value = ?, version = version + 1, updated_at = ?, updated_by = ? WHERE org_id = ? AND artifact_id = ? AND collection = ? AND doc_id = ?";
pub(crate) const DB_UPDATE_GUARDED_SQL: &str = "UPDATE artifact_docs SET body = ?, index_value = ?, version = version + 1, updated_at = ?, updated_by = ? WHERE org_id = ? AND artifact_id = ? AND collection = ? AND doc_id = ? AND version = ?";
pub(crate) const DB_DELETE_SQL: &str =
    "DELETE FROM artifact_docs WHERE org_id = ? AND artifact_id = ? AND collection = ? AND doc_id = ?";
pub(crate) const DB_DELETE_GUARDED_SQL: &str =
    "DELETE FROM artifact_docs WHERE org_id = ? AND artifact_id = ? AND collection = ? AND doc_id = ? AND version = ?";
/// Guard statements abort the whole batch when a concurrent write moved the
/// row first: the conditional NULL insert violates a NOT NULL column, and —
/// like the cross-org version guard — a violated batch lands no part of the
/// write.
pub(crate) const DB_GUARD_UPDATE_SQL: &str = "INSERT INTO artifact_docs (org_id, artifact_id, collection, doc_id, body, version, updated_at, updated_by) SELECT NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL WHERE NOT EXISTS (SELECT 1 FROM artifact_docs WHERE org_id = ? AND artifact_id = ? AND collection = ? AND doc_id = ? AND version = ?)";
pub(crate) const DB_GUARD_DELETE_SQL: &str = "INSERT INTO artifact_docs (org_id, artifact_id, collection, doc_id, body, version, updated_at, updated_by) SELECT NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL WHERE EXISTS (SELECT 1 FROM artifact_docs WHERE org_id = ? AND artifact_id = ? AND collection = ? AND doc_id = ?)";
pub(crate) const DB_COUNT_SQL: &str = "SELECT COUNT(*) AS docs, COALESCE(SUM(LENGTH(body)), 0) AS bytes FROM artifact_docs WHERE org_id = ? AND artifact_id = ?";
pub(crate) const DB_LIMITS_SQL: &str =
    "SELECT max_db_docs_per_artifact, max_db_bytes_per_org FROM org_limits WHERE org_id = ?";
/// Deleting an artifact removes its rows in the same operation.
pub(crate) const DB_DELETE_ARTIFACT_SQL: &str =
    "DELETE FROM artifact_docs WHERE org_id = (SELECT id FROM orgs WHERE slug = ?) AND artifact_id = ?";

/// Builds the existing-rows lookup for a batch: one `(collection, doc_id)`
/// pair per operation.
pub(crate) fn db_existing_lookup_sql(count: usize) -> String {
    let mut sql = String::from(
        "SELECT collection, doc_id, body, version FROM artifact_docs WHERE org_id = ? AND artifact_id = ? AND (",
    );
    for index in 0..count {
        if index > 0 {
            sql.push_str(" OR ");
        }
        sql.push_str("(collection = ? AND doc_id = ?)");
    }
    sql.push(')');
    sql
}

/// Builds the collection list query: the tenant binding plus the optional
/// declared-index filter and page cursor, ordered by doc id.
pub(crate) fn db_list_sql(has_index_eq: bool, has_cursor: bool) -> String {
    let mut sql = String::from(DB_LIST_BASE_SQL);
    if has_index_eq {
        sql.push_str(" AND index_value = ?");
    }
    if has_cursor {
        sql.push_str(" AND doc_id > ?");
    }
    sql.push_str(" ORDER BY doc_id ASC LIMIT ?");
    sql
}

#[derive(Debug, Deserialize)]
struct DbCountRow {
    docs: i64,
    bytes: i64,
}

#[derive(Debug, Deserialize)]
struct DbLimitsRow {
    max_db_docs_per_artifact: Option<i64>,
    max_db_bytes_per_org: Option<i64>,
}

#[derive(Debug, Deserialize)]
struct DbExistingRow {
    collection: String,
    doc_id: String,
    body: String,
    version: i64,
}

/// The pushed database quotas for `org`, else the conservative defaults. A
/// pushed negative (or missing row/column) falls back to the default rather
/// than blocking every write.
pub(crate) async fn load_db_limits(
    database: &worker::D1Database,
    org: &str,
) -> Result<DbQuotaLimits> {
    let row = database
        .prepare(DB_LIMITS_SQL)
        .bind(&[JsValue::from_str(org)])?
        .first::<DbLimitsRow>(None)
        .await?;
    Ok(DbQuotaLimits {
        max_docs: row
            .as_ref()
            .and_then(|row| row.max_db_docs_per_artifact)
            .filter(|value| *value >= 0)
            .map(|value| value as u64)
            .unwrap_or(DEFAULT_MAX_DB_DOCS_PER_ARTIFACT),
        max_bytes: row
            .as_ref()
            .and_then(|row| row.max_db_bytes_per_org)
            .filter(|value| *value >= 0)
            .map(|value| value as u64)
            .unwrap_or(DEFAULT_MAX_DB_BYTES_PER_ORG),
    })
}

/// The wire shape of one document: server-set fields travel with the body,
/// never merged into tool text (spec 20 MCP surface keeps row content inside
/// `data`; the same envelope serves the page).
pub(crate) fn db_doc_body(collection: &str, row: &DbDocRow) -> Value {
    serde_json::json!({
        "collection": collection,
        "doc_id": row.doc_id,
        "body": row.body_value(),
        "version": row.version,
        "updated_at": row.updated_at,
        "updated_by": row.updated_by,
    })
}

/// The `details` for a 409: the write lost, and here is the current document.
pub(crate) fn db_conflict_details(collection: &str, current: &DbDocRow) -> Value {
    serde_json::json!({ "current": db_doc_body(collection, current) })
}

/// Response headers for the whole prefix. Notably absent: every
/// `Access-Control-Allow-*` header — the artifact origin shares no CORS
/// allowance (spec 05), and a missing allowance is what makes defense 2 a
/// defense rather than a header check.
pub(crate) fn db_response_headers() -> Vec<(&'static str, &'static str)> {
    vec![
        ("X-Content-Type-Options", "nosniff"),
        ("Cache-Control", "private, no-store"),
    ]
}

/// The `OPTIONS` answer: 204 with no CORS headers, so no cross-origin
/// preflight on this path can ever succeed.
pub(crate) fn db_options_headers() -> Vec<(&'static str, &'static str)> {
    Vec::new()
}

pub(crate) fn db_json_response(body: &Value, status: u16) -> Result<Response> {
    let mut response = Response::from_json(body)?.with_status(status);
    for (name, value) in db_response_headers() {
        response.headers_mut().set(name, value)?;
    }
    Ok(response)
}

pub(crate) fn db_error(code: ErrorCode, message: &str, status: u16) -> Result<Response> {
    let definition = build_error_response(code, message, status);
    db_json_response(&definition.body, definition.status)
}

pub(crate) fn db_options_response() -> Result<Response> {
    let mut response = Response::empty()?.with_status(204);
    for (name, value) in db_options_headers() {
        response.headers_mut().set(name, value)?;
    }
    Ok(response)
}

pub(crate) fn db_deny_response(deny: DbDeny) -> Result<Response> {
    match deny {
        DbDeny::Unauthorized => db_error(
            ErrorCode::AuthenticationRequired,
            "A database token is required.",
            401,
        ),
        DbDeny::Forbidden => db_error(
            ErrorCode::Forbidden,
            "You may not access this artifact's database.",
            403,
        ),
    }
}

pub(crate) fn db_quota_response(deny: DbQuotaDeny) -> Result<Response> {
    let mut definition = build_error_response(
        ErrorCode::QuotaExceeded,
        "This artifact's database has reached its limit.",
        DB_QUOTA_STATUS,
    );
    definition.body["error"]["details"] = serde_json::json!({ "limit": deny.limit_name() });
    db_json_response(&definition.body, definition.status)
}

pub(crate) fn db_refusal_response(refusal: &DbBatchRefusal) -> Result<Response> {
    match refusal {
        DbBatchRefusal::UnknownCollection { .. } => db_error(
            ErrorCode::ValidationFailed,
            "Unknown collection.",
            422,
        ),
        DbBatchRefusal::BodyTooLarge { .. } => db_error(
            ErrorCode::BodyTooLarge,
            "Database documents are limited to 64 KiB.",
            413,
        ),
        DbBatchRefusal::Missing { .. } => {
            db_error(ErrorCode::ArtifactNotFound, "Document not found.", 404)
        }
        DbBatchRefusal::Stale { current, .. } => {
            // The batch validator does not know the collection here; the
            // handler maps batch refusals with collection context instead.
            let _ = current;
            db_error(
                ErrorCode::VersionConflict,
                "The document changed since it was read.",
                DB_STALE_STATUS,
            )
        }
    }
}

/// `GET /__artfct/db/session` and the document endpoints, dispatched from
/// `main` only for isolated artifact hosts. Every branch binds `org_id` and
/// `artifact_id` from the verified token and the D1 row — never from the
/// request.
pub(crate) async fn handle_db_request(req: &mut Request, env: &Env, host: &str) -> Result<Response> {
    let now = Utc::now();
    let suffix = artifact_origin::artifact_origin_suffix(env);
    let Some((host_org, host_artifact)) = artifact_origin::parse_isolated_hostname(host, &suffix)
    else {
        return db_error(ErrorCode::ArtifactNotFound, "Artifact not found.", 404);
    };
    let url = req.url()?;
    let path = url.path().to_string();
    let query: HashMap<String, String> = url.query_pairs().into_owned().collect();
    let Some(db_path) = parse_db_path(&path) else {
        return db_error(ErrorCode::ArtifactNotFound, "Artifact not found.", 404);
    };
    if req.method() == Method::Options {
        return db_options_response();
    }
    // Defense 1: fetch metadata.
    let sec_fetch_site = req.headers().get("Sec-Fetch-Site")?;
    let origin = req.headers().get("Origin")?;
    if !db_fetch_site_ok(sec_fetch_site.as_deref(), origin.as_deref(), host) {
        return db_error(
            ErrorCode::Forbidden,
            "Cross-origin database requests are forbidden.",
            403,
        );
    }
    // Defense 2: non-simple requests only.
    let content_type = req.headers().get("Content-Type")?;
    let db_header = req.headers().get(DB_HEADER)?;
    let method = req.method();
    if !db_request_shape_ok(&method, content_type.as_deref(), db_header.as_deref()) {
        return db_error(
            ErrorCode::ValidationFailed,
            "Database requests must use application/json and carry X-Artfct-Db: 1.",
            422,
        );
    }
    let database = env.d1("ARTIFACTS_DB")?;
    let row: Option<DbArtifactRow> = database
        .prepare(DB_ARTIFACT_SQL)
        .bind(&[JsValue::from_str(&host_artifact)])?
        .first(None)
        .await?;
    let Some(row) = row.filter(|row| db_host_matches_row(&host_org, &host_artifact, row)) else {
        // Another org's id, a missing artifact, or a revoked one: the same
        // 404, so the prefix never confirms that an id exists elsewhere.
        return db_error(ErrorCode::ArtifactNotFound, "Artifact not found.", 404);
    };
    let manifest_value: Value = serde_json::from_str(&row.manifest).unwrap_or(Value::Null);
    let db = validation::validate_db_manifest(&manifest_value)
        .ok()
        .flatten();
    if !db_endpoint_active(db.as_ref()) {
        return db_error(ErrorCode::ArtifactNotFound, "Artifact not found.", 404);
    }
    let db = db.expect("endpoint is active, so the declaration exists");
    let sharing = Sharing::from_tier(&row.tier);
    let edit_access = EditAccess::from_stored(&row.edit_access);
    let owner = row.user_id.as_deref();
    let secret = artifact_origin::artifact_token_secret(env);
    let authorization = req.headers().get("Authorization")?;
    let bearer = match authorization.as_deref() {
        None => None,
        Some(header) => Some(match crate::auth::bearer_token(Some(header)) {
            Some(token) => classify_db_token(secret.as_deref(), token, &row.id, now),
            None => PresentedToken::Garbage,
        }),
    };
    let cookie_header = req.headers().get("Cookie")?;
    let cookie_viewer = artifact_origin::access_token_from_cookie(cookie_header.as_deref())
        .and_then(|token| artifact_origin::verified_token_viewer(secret.as_deref(), &token, &row.id, now));
    match (method.clone(), db_path) {
        (Method::Get, DbPath::Session) => {
            let auth = match authorize_db(sharing, edit_access, owner, bearer, cookie_viewer, false) {
                Err(deny) => return db_deny_response(deny),
                Ok(auth) => auth,
            };
            let identity = auth.viewer.clone().unwrap_or_else(|| {
                crate::auth::connecting_ip(req).unwrap_or_else(|_| "unknown".to_string())
            });
            if !db_write_allowed(env, &row.id, &identity).await? {
                return db_error(ErrorCode::RateLimited, "Database requests are rate limited.", 429);
            }
            let expires_at = now + chrono::Duration::seconds(DB_SESSION_TTL_SECONDS);
            let Some(secret) = secret else {
                return db_error(ErrorCode::InternalError, "The database is unavailable.", 500);
            };
            // The session token carries the caller's own identity forward: a
            // named viewer gets a five-part token with their viewer and scope,
            // so the write it authorizes keeps their role. Anonymous link
            // holders get a short-lived token naming nobody.
            let token = match auth.viewer.as_deref() {
                Some(name) => {
                    // An admin keeps their scope in the session token; every
                    // other named viewer is a plain member. Owners need no
                    // private scope: the owner check names them directly.
                    let sees_private = matches!(auth.role, DbRole::Admin);
                    artifact_origin::mint_viewer_access_token(
                        &secret,
                        &row.id,
                        expires_at,
                        name,
                        sees_private,
                    )
                }
                None => artifact_origin::mint_access_token(&secret, &row.id, expires_at),
            };
            db_json_response(
                &serde_json::json!({
                    "token": token,
                    "expires_at": expires_at.to_rfc3339_opts(SecondsFormat::Secs, true),
                    "role": auth.role.as_str(),
                }),
                200,
            )
        }
        (Method::Get, DbPath::Doc { collection, doc_id }) => {
            let _auth = match authorize_db(sharing, edit_access, owner, bearer, cookie_viewer, false) {
                Err(deny) => return db_deny_response(deny),
                Ok(auth) => auth,
            };
            let (collection, doc_id) = (collection.to_string(), doc_id.to_string());
            if !validation::is_valid_db_name(&collection, validation::MAX_DB_COLLECTION_CHARS)
                || !validation::is_valid_db_name(&doc_id, validation::MAX_DB_DOC_ID_CHARS)
            {
                return db_error(ErrorCode::ValidationFailed, "Invalid document name.", 422);
            }
            if !db_collection_declared(&db, &collection) {
                return db_error(ErrorCode::ValidationFailed, "Unknown collection.", 422);
            }
            let existing: Option<DbDocRow> = database
                .prepare(DB_GET_SQL)
                .bind(&[
                    JsValue::from_str(&row.org),
                    JsValue::from_str(&row.id),
                    JsValue::from_str(&collection),
                    JsValue::from_str(&doc_id),
                ])?
                .first(None)
                .await?;
            let Some(existing) = existing else {
                return db_error(ErrorCode::ArtifactNotFound, "Document not found.", 404);
            };
            db_json_response(&db_doc_body(&collection, &existing), 200)
        }
        (Method::Get, DbPath::Collection { collection }) => {
            let _auth = match authorize_db(sharing, edit_access, owner, bearer, cookie_viewer, false) {
                Err(deny) => return db_deny_response(deny),
                Ok(auth) => auth,
            };
            let collection = collection.to_string();
            if !validation::is_valid_db_name(&collection, validation::MAX_DB_COLLECTION_CHARS) {
                return db_error(ErrorCode::ValidationFailed, "Invalid collection name.", 422);
            }
            if !db_collection_declared(&db, &collection) {
                return db_error(ErrorCode::ValidationFailed, "Unknown collection.", 422);
            }
            let params = match parse_db_list_params(&query) {
                Err(code) => {
                    return db_error(code, "Invalid list parameters.", 422);
                }
                Ok(params) => params,
            };
            // An index filter only means something on the declared field; any
            // other value can never match, so it answers the empty page.
            let use_index_eq = params.index_eq.is_some()
                && db.collections.get(&collection).and_then(|decl| decl.index.as_deref()).is_some();
            let index_eq = if use_index_eq { params.index_eq.clone() } else { None };
            let mut binds = vec![
                JsValue::from_str(&row.org),
                JsValue::from_str(&row.id),
                JsValue::from_str(&collection),
            ];
            if let Some(value) = index_eq.as_deref() {
                binds.push(JsValue::from_str(value));
            }
            if let Some(cursor) = params.cursor.as_deref() {
                binds.push(JsValue::from_str(cursor));
            }
            binds.push(JsValue::from_f64(params.limit as f64));
            let sql = db_list_sql(index_eq.is_some(), params.cursor.is_some());
            let rows = database
                .prepare(sql)
                .bind(&binds)?
                .all()
                .await?
                .results::<DbDocRow>()?;
            let cursor = if rows.len() == params.limit {
                rows.last().map(|row| row.doc_id.clone())
            } else {
                None
            };
            db_json_response(
                &serde_json::json!({
                    "docs": rows.iter().map(|row| db_doc_body(&collection, row)).collect::<Vec<_>>(),
                    "cursor": cursor,
                }),
                200,
            )
        }
        (Method::Put | Method::Patch, DbPath::Doc { collection, doc_id }) => {
            let auth = match authorize_db(sharing, edit_access, owner, bearer, cookie_viewer, true) {
                Err(deny) => return db_deny_response(deny),
                Ok(auth) => auth,
            };
            db_write_single(
                req,
                env,
                &database,
                &row,
                &db,
                collection,
                doc_id,
                &query,
                method == Method::Patch,
                auth,
                now,
            )
            .await
        }
        (Method::Delete, DbPath::Doc { collection, doc_id }) => {
            let auth = match authorize_db(sharing, edit_access, owner, bearer, cookie_viewer, true) {
                Err(deny) => return db_deny_response(deny),
                Ok(auth) => auth,
            };
            db_delete_single(env, &database, &row, &db, collection, doc_id, &query, auth, req).await
        }
        (Method::Post, DbPath::Batch) => {
            let auth = match authorize_db(sharing, edit_access, owner, bearer, cookie_viewer, true) {
                Err(deny) => return db_deny_response(deny),
                Ok(auth) => auth,
            };
            db_write_batch(req, env, &database, &row, &db, auth, now).await
        }
        _ => db_error(
            ErrorCode::NotImplemented,
            "This database method is not implemented.",
            405,
        ),
    }
}


#[allow(
    clippy::too_many_arguments,
    reason = "single-document write plumbing; the parameters are the write's audit trail"
)]
async fn db_write_single(
    req: &mut Request,
    env: &Env,
    database: &worker::D1Database,
    row: &DbArtifactRow,
    db: &DbManifest,
    collection: &str,
    doc_id: &str,
    query: &HashMap<String, String>,
    is_patch: bool,
    auth: DbAuth,
    now: chrono::DateTime<Utc>,
) -> Result<Response> {
    let (collection, doc_id) = (collection.to_string(), doc_id.to_string());
    if !validation::is_valid_db_name(&collection, validation::MAX_DB_COLLECTION_CHARS)
        || !validation::is_valid_db_name(&doc_id, validation::MAX_DB_DOC_ID_CHARS)
    {
        return db_error(ErrorCode::ValidationFailed, "Invalid document name.", 422);
    }
    if !db_collection_declared(db, &collection) {
        return db_error(ErrorCode::ValidationFailed, "Unknown collection.", 422);
    }
    let if_version = match parse_if_version(query) {
        Err(_) => return db_error(ErrorCode::ValidationFailed, "Invalid if_version.", 422),
        Ok(version) => version,
    };
    let patch: Value = match req.json().await {
        Err(_) => return db_error(ErrorCode::InvalidJson, "Invalid JSON request body.", 400),
        Ok(value) => value,
    };
    if !patch.is_object() {
        return db_error(ErrorCode::ValidationFailed, "The document body must be a JSON object.", 422);
    }
    let existing: Option<DbDocRow> = database
        .prepare(DB_GET_SQL)
        .bind(&[
            JsValue::from_str(&row.org),
            JsValue::from_str(&row.id),
            JsValue::from_str(&collection),
            JsValue::from_str(&doc_id),
        ])?
        .first(None)
        .await?;
    if is_patch && existing.is_none() {
        return db_error(ErrorCode::ArtifactNotFound, "Document not found.", 404);
    }
    let current_body: Value = existing
        .as_ref()
        .map(|row| row.body_value())
        .unwrap_or_else(|| serde_json::json!({}));
    let mut merged = current_body.as_object().cloned().unwrap_or_default();
    if is_patch {
        for (key, value) in patch.as_object().expect("checked object") {
            merged.insert(key.clone(), value.clone());
        }
    } else {
        merged = patch.as_object().expect("checked object").clone();
    }
    let body = Value::Object(merged);
    let body_text = body.to_string();
    if body_text.len() > DB_MAX_BODY_BYTES {
        return db_error(
            ErrorCode::BodyTooLarge,
            "Database documents are limited to 64 KiB.",
            413,
        );
    }
    match db_guard_version(existing.as_ref().map(|row| row.version), if_version) {
        Err(DbWriteConflict::Missing) => {
            return db_error(ErrorCode::ArtifactNotFound, "Document not found.", 404)
        }
        Err(DbWriteConflict::Stale) => {
            let current = existing.expect("stale implies present");
            let mut definition =
                build_error_response(ErrorCode::VersionConflict, "The document changed since it was read.", DB_STALE_STATUS);
            definition.body["error"]["details"] = db_conflict_details(&collection, &current);
            return db_json_response(&definition.body, definition.status);
        }
        Ok(()) => {}
    }
    let updated_by = auth.viewer.clone().unwrap_or_else(|| "anonymous".to_string());
    let updated_at = now.to_rfc3339_opts(SecondsFormat::Secs, true);
    let index_field = db
        .collections
        .get(&collection)
        .and_then(|declaration| declaration.index.as_deref());
    let index_value = index_field.and_then(|field| db_index_value(&body, field));
    let usage = db_usage(database, &row.org, &row.id).await?;
    let limits = load_db_limits(database, &row.org).await?;
    let old_bytes = existing.as_ref().map_or(0, |row| row.body.len() as i64);
    if let Err(deny) = db_quota_check(
        limits,
        usage.0,
        usage.1,
        if existing.is_some() { 0 } else { 1 },
        body_text.len() as i64 - old_bytes,
    ) {
        return db_quota_response(deny);
    }
    let identity = auth.viewer.clone().unwrap_or_else(|| {
        crate::auth::connecting_ip(req).unwrap_or_else(|_| "unknown".to_string())
    });
    if !db_write_allowed(env, &row.id, &identity).await? {
        return db_error(ErrorCode::RateLimited, "Database requests are rate limited.", 429);
    }
    let index_bind = index_value.as_deref().map(JsValue::from_str).unwrap_or_else(JsValue::null);
    // The guarded UPDATE is the atomic compare-and-set; the blind upsert is
    // last-write-wins. Both run in one batch with the read-back, so the
    // response describes exactly what landed.
    let write = if let Some(expected) = if_version {
        database
            .prepare(DB_UPDATE_GUARDED_SQL)
            .bind(&[
                JsValue::from_str(&body_text),
                index_bind,
                JsValue::from_str(&updated_at),
                JsValue::from_str(&updated_by),
                JsValue::from_str(&row.org),
                JsValue::from_str(&row.id),
                JsValue::from_str(&collection),
                JsValue::from_str(&doc_id),
                JsValue::from_f64(expected as f64),
            ])?
    } else if existing.is_some() {
        database
            .prepare(DB_UPDATE_SQL)
            .bind(&[
                JsValue::from_str(&body_text),
                index_bind,
                JsValue::from_str(&updated_at),
                JsValue::from_str(&updated_by),
                JsValue::from_str(&row.org),
                JsValue::from_str(&row.id),
                JsValue::from_str(&collection),
                JsValue::from_str(&doc_id),
            ])?
    } else {
        database
            .prepare(DB_UPSERT_SQL)
            .bind(&[
                JsValue::from_str(&row.org),
                JsValue::from_str(&row.id),
                JsValue::from_str(&collection),
                JsValue::from_str(&doc_id),
                JsValue::from_str(&body_text),
                index_bind,
                JsValue::from_str(&updated_at),
                JsValue::from_str(&updated_by),
            ])?
    };
    let read_back = database
        .prepare(DB_GET_SQL)
        .bind(&[
            JsValue::from_str(&row.org),
            JsValue::from_str(&row.id),
            JsValue::from_str(&collection),
            JsValue::from_str(&doc_id),
        ])?;
    let results = database.batch(vec![write, read_back]).await?;
    let rows = results
        .get(1)
        .map(|result| result.results::<DbDocRow>())
        .transpose()?
        .unwrap_or_default();
    let Some(landed) = rows.into_iter().next() else {
        // The guarded UPDATE matched no row: deleted concurrently, or the
        // version moved between the pre-read and the batch.
        return db_error(ErrorCode::ArtifactNotFound, "Document not found.", 404);
    };
    let pre_version = existing.as_ref().map(|row| row.version).unwrap_or(0);
    if let Some(expected) = if_version {
        if landed.version != pre_version + 1 || landed.version != expected + 1 {
            let mut definition =
                build_error_response(ErrorCode::VersionConflict, "The document changed since it was read.", DB_STALE_STATUS);
            definition.body["error"]["details"] = db_conflict_details(&collection, &landed);
            return db_json_response(&definition.body, definition.status);
        }
    }
    let created = existing.is_none();
    db_json_response(&db_doc_body(&collection, &landed), if created { 201 } else { 200 })
}

#[allow(
    clippy::too_many_arguments,
    reason = "single-document delete plumbing; the parameters are the write's audit trail"
)]
async fn db_delete_single(
    env: &Env,
    database: &worker::D1Database,
    row: &DbArtifactRow,
    db: &DbManifest,
    collection: &str,
    doc_id: &str,
    query: &HashMap<String, String>,
    auth: DbAuth,
    req: &Request,
) -> Result<Response> {
    let (collection, doc_id) = (collection.to_string(), doc_id.to_string());
    if !validation::is_valid_db_name(&collection, validation::MAX_DB_COLLECTION_CHARS)
        || !validation::is_valid_db_name(&doc_id, validation::MAX_DB_DOC_ID_CHARS)
    {
        return db_error(ErrorCode::ValidationFailed, "Invalid document name.", 422);
    }
    if !db_collection_declared(db, &collection) {
        return db_error(ErrorCode::ValidationFailed, "Unknown collection.", 422);
    }
    let if_version = match parse_if_version(query) {
        Err(_) => return db_error(ErrorCode::ValidationFailed, "Invalid if_version.", 422),
        Ok(version) => version,
    };
    let existing: Option<DbDocRow> = database
        .prepare(DB_GET_SQL)
        .bind(&[
            JsValue::from_str(&row.org),
            JsValue::from_str(&row.id),
            JsValue::from_str(&collection),
            JsValue::from_str(&doc_id),
        ])?
        .first(None)
        .await?;
    let Some(existing) = existing else {
        return db_error(ErrorCode::ArtifactNotFound, "Document not found.", 404);
    };
    if let Err(DbWriteConflict::Stale) = db_guard_version(Some(existing.version), if_version) {
        let mut definition =
            build_error_response(ErrorCode::VersionConflict, "The document changed since it was read.", DB_STALE_STATUS);
        definition.body["error"]["details"] = db_conflict_details(&collection, &existing);
        return db_json_response(&definition.body, definition.status);
    }
    let identity = auth.viewer.clone().unwrap_or_else(|| {
        crate::auth::connecting_ip(req).unwrap_or_else(|_| "unknown".to_string())
    });
    if !db_write_allowed(env, &row.id, &identity).await? {
        return db_error(ErrorCode::RateLimited, "Database requests are rate limited.", 429);
    }
    if let Some(expected) = if_version {
        // Guarded delete: the abort statement rolls the batch back when a
        // concurrent write moved the row first, so no part of a racing batch
        // lands half-applied.
        let delete = database
            .prepare(DB_DELETE_GUARDED_SQL)
            .bind(&[
                JsValue::from_str(&row.org),
                JsValue::from_str(&row.id),
                JsValue::from_str(&collection),
                JsValue::from_str(&doc_id),
                JsValue::from_f64(expected as f64),
            ])?;
        let guard = database.prepare(DB_GUARD_DELETE_SQL).bind(&[
            JsValue::from_str(&row.org),
            JsValue::from_str(&row.id),
            JsValue::from_str(&collection),
            JsValue::from_str(&doc_id),
        ])?;
        if database.batch(vec![delete, guard]).await.is_err() {
            let current: Option<DbDocRow> = database
                .prepare(DB_GET_SQL)
                .bind(&[
                    JsValue::from_str(&row.org),
                    JsValue::from_str(&row.id),
                    JsValue::from_str(&collection),
                    JsValue::from_str(&doc_id),
                ])?
                .first(None)
                .await?;
            match current {
                Some(current) => {
                    let mut definition = build_error_response(
                        ErrorCode::VersionConflict,
                        "The document changed since it was read.",
                        DB_STALE_STATUS,
                    );
                    definition.body["error"]["details"] = db_conflict_details(&collection, &current);
                    return db_json_response(&definition.body, definition.status);
                }
                None => return db_error(ErrorCode::ArtifactNotFound, "Document not found.", 404),
            }
        }
    } else {
        database
            .prepare(DB_DELETE_SQL)
            .bind(&[
                JsValue::from_str(&row.org),
                JsValue::from_str(&row.id),
                JsValue::from_str(&collection),
                JsValue::from_str(&doc_id),
            ])?
            .run()
            .await?;
    }
    build_delete_response().into_worker_response()
}

/// Current usage for quota: document count and body bytes for the artifact.
async fn db_usage(database: &worker::D1Database, org: &str, artifact_id: &str) -> Result<(u64, u64)> {
    let row = database
        .prepare(DB_COUNT_SQL)
        .bind(&[JsValue::from_str(org), JsValue::from_str(artifact_id)])?
        .first::<DbCountRow>(None)
        .await?;
    Ok((
        row.as_ref().map_or(0, |row| row.docs.max(0) as u64),
        row.as_ref().map_or(0, |row| row.bytes.max(0) as u64),
    ))
}

#[allow(
    clippy::too_many_arguments,
    reason = "batch write plumbing; the parameters are the batch's audit trail"
)]
async fn db_write_batch(
    req: &mut Request,
    env: &Env,
    database: &worker::D1Database,
    row: &DbArtifactRow,
    db: &DbManifest,
    auth: DbAuth,
    now: chrono::DateTime<Utc>,
) -> Result<Response> {
    let raw: Value = match req.json().await {
        Err(_) => return db_error(ErrorCode::InvalidJson, "Invalid JSON request body.", 400),
        Ok(value) => value,
    };
    let ops = match parse_db_batch_ops(&raw) {
        Err(_) => return db_error(ErrorCode::ValidationFailed, "Invalid batch operations.", 422),
        Ok(ops) => ops,
    };
    let existing = db_existing_rows(database, &row.org, &row.id, &ops).await?;
    if let Err(refusal) = validate_db_batch(&ops, &existing, db) {
        return db_batch_refusal(&ops, &refusal);
    }
    let updated_by = auth.viewer.clone().unwrap_or_else(|| "anonymous".to_string());
    let updated_at = now.to_rfc3339_opts(SecondsFormat::Secs, true);
    // Quota over the whole batch: new documents and byte deltas together.
    let mut add_docs = 0i64;
    let mut add_bytes = 0i64;
    for op in &ops {
        let key = (op.collection.clone(), op.doc_id.clone());
        match op.kind {
            DbBatchOpKind::Put => {
                let new_len = op.body.as_ref().expect("validated object").to_string().len() as i64;
                match existing.get(&key) {
                    Some(current) => add_bytes += new_len - current.body.len() as i64,
                    None => {
                        add_docs += 1;
                        add_bytes += new_len;
                    }
                }
            }
            DbBatchOpKind::Patch => {
                let current = existing.get(&key).expect("validated present");
                let merged = db_merge_patch(&current.body, op.body.as_ref().expect("validated object"));
                add_bytes += merged.len() as i64 - current.body.len() as i64;
            }
            DbBatchOpKind::Delete => {
                let current = existing.get(&key).expect("validated present");
                add_docs -= 1;
                add_bytes -= current.body.len() as i64;
            }
        }
    }
    let usage = db_usage(database, &row.org, &row.id).await?;
    let limits = load_db_limits(database, &row.org).await?;
    if let Err(deny) = db_quota_check(limits, usage.0, usage.1, add_docs, add_bytes) {
        return db_quota_response(deny);
    }
    let identity = auth.viewer.clone().unwrap_or_else(|| {
        crate::auth::connecting_ip(req).unwrap_or_else(|_| "unknown".to_string())
    });
    if !db_write_allowed(env, &row.id, &identity).await? {
        return db_error(ErrorCode::RateLimited, "Database requests are rate limited.", 429);
    }
    let mut statements = Vec::with_capacity(ops.len() * 2);
    for op in &ops {
        let key = (op.collection.clone(), op.doc_id.clone());
        let current = existing.get(&key);
        let index_field = db
            .collections
            .get(&op.collection)
            .and_then(|declaration| declaration.index.as_deref());
        match op.kind {
            DbBatchOpKind::Put => {
                let body = op.body.as_ref().expect("validated object");
                let body_text = body.to_string();
                let index_bind = index_field
                    .and_then(|field| db_index_value(body, field))
                    .as_deref()
                    .map(JsValue::from_str)
                    .unwrap_or_else(JsValue::null);
                if let Some(expected) = op.if_version {
                    statements.push(
                        database
                            .prepare(DB_UPDATE_GUARDED_SQL)
                            .bind(&[
                                JsValue::from_str(&body_text),
                                index_bind,
                                JsValue::from_str(&updated_at),
                                JsValue::from_str(&updated_by),
                                JsValue::from_str(&row.org),
                                JsValue::from_str(&row.id),
                                JsValue::from_str(&op.collection),
                                JsValue::from_str(&op.doc_id),
                                JsValue::from_f64(expected as f64),
                            ])?,
                    );
                    statements.push(
                        database
                            .prepare(DB_GUARD_UPDATE_SQL)
                            .bind(&[
                                JsValue::from_str(&row.org),
                                JsValue::from_str(&row.id),
                                JsValue::from_str(&op.collection),
                                JsValue::from_str(&op.doc_id),
                                JsValue::from_f64((expected + 1) as f64),
                            ])?,
                    );
                } else {
                    statements.push(
                        database
                            .prepare(DB_UPSERT_SQL)
                            .bind(&[
                                JsValue::from_str(&row.org),
                                JsValue::from_str(&row.id),
                                JsValue::from_str(&op.collection),
                                JsValue::from_str(&op.doc_id),
                                JsValue::from_str(&body_text),
                                index_bind,
                                JsValue::from_str(&updated_at),
                                JsValue::from_str(&updated_by),
                            ])?,
                    );
                }
            }
            DbBatchOpKind::Patch => {
                let current = current.expect("validated present");
                let merged_text = db_merge_patch(&current.body, op.body.as_ref().expect("validated object"));
                let merged: Value =
                    serde_json::from_str(&merged_text).unwrap_or_else(|_| serde_json::json!({}));
                let index_bind = index_field
                    .and_then(|field| db_index_value(&merged, field))
                    .as_deref()
                    .map(JsValue::from_str)
                    .unwrap_or_else(JsValue::null);
                if let Some(expected) = op.if_version {
                    statements.push(
                        database
                            .prepare(DB_UPDATE_GUARDED_SQL)
                            .bind(&[
                                JsValue::from_str(&merged_text),
                                index_bind,
                                JsValue::from_str(&updated_at),
                                JsValue::from_str(&updated_by),
                                JsValue::from_str(&row.org),
                                JsValue::from_str(&row.id),
                                JsValue::from_str(&op.collection),
                                JsValue::from_str(&op.doc_id),
                                JsValue::from_f64(expected as f64),
                            ])?,
                    );
                    statements.push(
                        database
                            .prepare(DB_GUARD_UPDATE_SQL)
                            .bind(&[
                                JsValue::from_str(&row.org),
                                JsValue::from_str(&row.id),
                                JsValue::from_str(&op.collection),
                                JsValue::from_str(&op.doc_id),
                                JsValue::from_f64((expected + 1) as f64),
                            ])?,
                    );
                } else {
                    statements.push(
                        database
                            .prepare(DB_UPDATE_SQL)
                            .bind(&[
                                JsValue::from_str(&merged_text),
                                index_bind,
                                JsValue::from_str(&updated_at),
                                JsValue::from_str(&updated_by),
                                JsValue::from_str(&row.org),
                                JsValue::from_str(&row.id),
                                JsValue::from_str(&op.collection),
                                JsValue::from_str(&op.doc_id),
                            ])?,
                    );
                }
            }
            DbBatchOpKind::Delete => {
                if let Some(expected) = op.if_version {
                    statements.push(
                        database
                            .prepare(DB_DELETE_GUARDED_SQL)
                            .bind(&[
                                JsValue::from_str(&row.org),
                                JsValue::from_str(&row.id),
                                JsValue::from_str(&op.collection),
                                JsValue::from_str(&op.doc_id),
                                JsValue::from_f64(expected as f64),
                            ])?,
                    );
                    statements.push(
                        database
                            .prepare(DB_GUARD_DELETE_SQL)
                            .bind(&[
                                JsValue::from_str(&row.org),
                                JsValue::from_str(&row.id),
                                JsValue::from_str(&op.collection),
                                JsValue::from_str(&op.doc_id),
                            ])?,
                    );
                } else {
                    statements.push(
                        database
                            .prepare(DB_DELETE_SQL)
                            .bind(&[
                                JsValue::from_str(&row.org),
                                JsValue::from_str(&row.id),
                                JsValue::from_str(&op.collection),
                                JsValue::from_str(&op.doc_id),
                            ])?,
                    );
                }
            }
        }
    }
    if database.batch(statements).await.is_err() {
        // A guard aborted the batch: a concurrent write moved a guarded row
        // after the pre-read. Re-read and report the first conflict; no part
        // of the batch landed.
        let fresh = db_existing_rows(database, &row.org, &row.id, &ops).await?;
        if let Err(refusal) = validate_db_batch(&ops, &fresh, db) {
            return db_batch_refusal(&ops, &refusal);
        }
        return db_error(ErrorCode::InternalError, "The database is unavailable.", 500);
    }
    let fresh = db_existing_rows(database, &row.org, &row.id, &ops).await?;
    let mut results = Vec::with_capacity(ops.len());
    for op in &ops {
        match op.kind {
            DbBatchOpKind::Delete => results.push(serde_json::json!({
                "collection": op.collection,
                "doc_id": op.doc_id,
                "deleted": true,
            })),
            _ => match fresh.get(&(op.collection.clone(), op.doc_id.clone())) {
                Some(current) => results.push(db_doc_body(&op.collection, current)),
                None => {
                    return db_error(ErrorCode::InternalError, "The database is unavailable.", 500)
                }
            },
        }
    }
    db_json_response(&serde_json::json!({ "results": results }), 200)
}

/// Maps a batch refusal with its operation's collection, so a stale write
/// carries the current document — the same 409 the single-write path
/// returns.
fn db_batch_refusal(ops: &[DbBatchOp], refusal: &DbBatchRefusal) -> Result<Response> {
    match refusal {
        DbBatchRefusal::Stale { index, current } => {
            let collection = ops.get(*index).map(|op| op.collection.as_str()).unwrap_or("");
            let mut definition = build_error_response(
                ErrorCode::VersionConflict,
                "The document changed since it was read.",
                DB_STALE_STATUS,
            );
            definition.body["error"]["details"] = db_conflict_details(collection, current);
            db_json_response(&definition.body, definition.status)
        }
        _ => db_refusal_response(refusal),
    }
}

async fn db_existing_rows(
    database: &worker::D1Database,
    org: &str,
    artifact_id: &str,
    ops: &[DbBatchOp],
) -> Result<HashMap<(String, String), DbDocRow>> {
    if ops.is_empty() {
        return Ok(HashMap::new());
    }
    let sql = db_existing_lookup_sql(ops.len());
    let mut binds = Vec::with_capacity(2 + ops.len() * 2);
    binds.push(JsValue::from_str(org));
    binds.push(JsValue::from_str(artifact_id));
    for op in ops {
        binds.push(JsValue::from_str(&op.collection));
        binds.push(JsValue::from_str(&op.doc_id));
    }
    let rows = database
        .prepare(sql)
        .bind(&binds)?
        .all()
        .await?
        .results::<DbExistingRow>()?;
    let mut map = HashMap::with_capacity(rows.len());
    for row in rows {
        map.insert(
            (row.collection.clone(), row.doc_id.clone()),
            DbDocRow {
                doc_id: row.doc_id,
                body: row.body,
                version: row.version,
                updated_at: String::new(),
                updated_by: String::new(),
            },
        );
    }
    Ok(map)
}

/// Shallow-merges a patch object over a stored body string. Both are objects
/// by the time this runs (validated upstream); a non-object stored body is
/// replaced wholesale rather than failing the write.
fn db_merge_patch(stored: &str, patch: &Value) -> String {
    let mut merged: serde_json::Map<String, Value> = serde_json::from_str(stored).unwrap_or_default();
    if let Some(patch) = patch.as_object() {
        for (key, value) in patch {
            merged.insert(key.clone(), value.clone());
        }
    }
    Value::Object(merged).to_string()
}

#[cfg(test)]
#[path = "artifact_db_tests.rs"]
mod artifact_db_tests;
