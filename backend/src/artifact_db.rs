//! Spec 20 — artifact database: the pure decisions.
//!
//! Everything here is a pure function over strings and numbers so the
//! security-relevant rules (who may write, from where, with what credential)
//! are unit-testable without a live Worker. The D1 reads and writes and the
//! HTTP handler live elsewhere and must call these, never re-derive them.
//!
//! The db endpoint is the first write path on an artifact origin. Spec 05's
//! guarantee only stops a script on origin B *reading* origin A; it does not
//! stop B causing a write, because `SameSite=Strict` is scoped to the site and
//! every `*--prod.artfct.dev` host is one site. So no rule below may rely on
//! the `artfct_access` cookie being unreachable from another artifact.

use super::*;
use std::collections::BTreeMap;

use crate::artifact_origin::{token_opens_private, TokenViewer};
use crate::artifact_routes::constant_time_equal;
use crate::sharing::{EditAccess, Sharing};

/// Every db request path starts with this. The prefix is reserved: a bundle
/// path under `__artfct/` is refused at deploy.
pub(crate) const DB_PATH_PREFIX: &str = "/__artfct/db/";
/// Directory name a bundle may never contain at its root.
pub(crate) const RESERVED_BUNDLE_PREFIX: &str = "__artfct/";
/// A request without this header is refused. A custom header makes a
/// cross-origin request non-simple, so it needs a preflight the Worker refuses.
pub(crate) const DB_REQUEST_HEADER: &str = "X-Artfct-Db";
pub(crate) const MAX_BODY_BYTES: usize = 64 * 1024;
pub(crate) const MAX_BATCH_WRITES: usize = 25;
pub(crate) const MAX_PAGE_LIMIT: u32 = 100;
pub(crate) const DEFAULT_PAGE_LIMIT: u32 = 50;
const MAX_NAME_LEN: usize = 64;
const MAX_DOC_ID_LEN: usize = 128;
const MAX_COLLECTIONS: usize = 16;
const MAX_SEED_ROWS: usize = 200;
/// Total encoded seed size. The seed travels inside the manifest, which is
/// stored on the artifact row and parsed on every db request, so it is capped
/// far below the per-row limit times the row count.
const MAX_SEED_BYTES: usize = 256 * 1024;
/// Defaults used when an org has no pushed db limits (the columns are NULL).
pub(crate) const DEFAULT_DOCS_PER_ARTIFACT: u64 = 5_000;
pub(crate) const DEFAULT_BYTES_PER_ORG: u64 = 64 * 1024 * 1024;

/// The db quotas that apply to one org's writes.
#[derive(Clone, Copy, Debug, PartialEq, Eq)]
pub(crate) struct DbLimits {
    pub(crate) docs_per_artifact: u64,
    pub(crate) bytes_per_org: u64,
}

impl Default for DbLimits {
    fn default() -> Self {
        Self {
            docs_per_artifact: DEFAULT_DOCS_PER_ARTIFACT,
            bytes_per_org: DEFAULT_BYTES_PER_ORG,
        }
    }
}

impl DbLimits {
    /// A pushed value wins; a missing (NULL) one falls back to the default.
    pub(crate) fn from_pushed(docs_per_artifact: Option<i64>, bytes_per_org: Option<i64>) -> Self {
        let default = Self::default();
        Self {
            docs_per_artifact: docs_per_artifact
                .map_or(default.docs_per_artifact, |value| value.max(0) as u64),
            bytes_per_org: bytes_per_org.map_or(default.bytes_per_org, |value| value.max(0) as u64),
        }
    }
}

/// Whether adding `add_docs` documents and `add_bytes` bytes would pass a
/// quota, given what the artifact (documents) and the org (bytes) already
/// hold. Only new documents add to the count; reads are never gated.
pub(crate) fn exceeds_db_quota(
    limits: &DbLimits,
    artifact_docs: u64,
    org_bytes: u64,
    add_docs: u64,
    add_bytes: u64,
) -> bool {
    artifact_docs.saturating_add(add_docs) > limits.docs_per_artifact
        || org_bytes.saturating_add(add_bytes) > limits.bytes_per_org
}

/// How far the artifact database is released in this environment. Set with
/// `ARTFCT_ARTIFACT_DB_ENABLED`; anything unrecognised, or unset, is `Off`, so
/// a missing variable fails closed.
#[derive(Clone, Copy, Debug, PartialEq, Eq)]
pub(crate) enum DbRollout {
    /// Nobody: the endpoint answers 404 and a deploy may not declare `db`.
    Off,
    /// Every org, except one that is explicitly turned off.
    All,
    /// Only orgs explicitly turned on, which Laravel's feature release
    /// controls decide.
    Orgs,
}

impl DbRollout {
    pub(crate) fn parse(value: &str) -> Self {
        match value.trim().to_ascii_lowercase().as_str() {
            "true" | "1" | "all" | "on" => Self::All,
            "orgs" => Self::Orgs,
            _ => Self::Off,
        }
    }
}

/// Whether the artifact database is available to one org, given the
/// environment's rollout and the org's explicit setting (`None` when the org
/// has made no choice).
pub(crate) fn db_allowed(rollout: DbRollout, org_setting: Option<bool>) -> bool {
    match rollout {
        DbRollout::Off => false,
        DbRollout::All => org_setting != Some(false),
        DbRollout::Orgs => org_setting == Some(true),
    }
}

/// Writes one viewer may make to one artifact in a minute.
pub(crate) const DB_WRITES_PER_MINUTE: u64 = 120;

/// Whether a write count (including the writes just charged) is within the
/// per-minute limit.
pub(crate) fn within_write_rate(writes_this_minute: u64) -> bool {
    writes_this_minute <= DB_WRITES_PER_MINUTE
}

/// The bytes a write adds to the org: the new body's size less what the
/// document already held. A shrinking overwrite adds nothing.
pub(crate) fn bytes_added(new_len: u64, existing_len: Option<u64>) -> u64 {
    new_len.saturating_sub(existing_len.unwrap_or(0))
}

/// The server-side renderer's viewer id (`system`). It loads an artifact's page
/// to render it and must never write that artifact's data.
const SYSTEM_VIEWER: &str = "system";

/// Seconds a write token is valid. Short, because it is a bearer credential.
pub(crate) const DB_TOKEN_TTL_SECS: i64 = 300;
/// Keys the server owns; a client body carrying one is refused rather than
/// silently overwritten, so a page cannot forge authorship.
const SERVER_FIELDS: [&str; 3] = ["version", "updated_at", "updated_by"];

/// What a db request path names.
#[derive(Debug, PartialEq, Eq)]
pub(crate) enum DbRoute {
    Session,
    Batch,
    Collection { collection: String },
    Doc { collection: String, doc_id: String },
}

/// Names a collection or document: `[A-Za-z0-9._-]`, no leading dot (so `.`
/// and `..` are never ids).
fn valid_identifier(value: &str, max_len: usize) -> bool {
    !value.is_empty()
        && value.len() <= max_len
        && !value.starts_with('.')
        && value
            .bytes()
            .all(|byte| byte.is_ascii_alphanumeric() || matches!(byte, b'.' | b'_' | b'-'))
}

pub(crate) fn valid_collection_name(value: &str) -> bool {
    valid_identifier(value, MAX_NAME_LEN)
}

pub(crate) fn valid_doc_id(value: &str) -> bool {
    valid_identifier(value, MAX_DOC_ID_LEN)
}

/// Parses a request path under [`DB_PATH_PREFIX`]. `None` for anything else,
/// including extra segments, empty segments and unsafe characters. The two
/// literal names `session` and `batch` are reserved and cannot be collections.
pub(crate) fn parse_db_path(path: &str) -> Option<DbRoute> {
    let rest = path.strip_prefix(DB_PATH_PREFIX)?;
    let segments: Vec<&str> = rest.split('/').collect();
    match segments.as_slice() {
        ["session"] => Some(DbRoute::Session),
        ["batch"] => Some(DbRoute::Batch),
        [collection] if valid_collection_name(collection) => Some(DbRoute::Collection {
            collection: (*collection).to_string(),
        }),
        [collection, doc_id] if valid_collection_name(collection) && valid_doc_id(doc_id) => {
            Some(DbRoute::Doc {
                collection: (*collection).to_string(),
                doc_id: (*doc_id).to_string(),
            })
        }
        _ => None,
    }
}

/// Whether a request path is in the reserved db namespace at all, valid or
/// not. The handler uses this to answer 404 instead of falling through to the
/// artifact file server.
pub(crate) fn is_db_path(path: &str) -> bool {
    path.starts_with(DB_PATH_PREFIX) || path == DB_PATH_PREFIX.trim_end_matches('/')
}

/// Why a db request was refused before any credential was looked at.
#[derive(Debug, PartialEq, Eq)]
pub(crate) enum GuardRefusal {
    /// `Sec-Fetch-Site` was present and not `same-origin`, or `Origin` named
    /// another host.
    NotSameOrigin,
    /// A write arrived with neither fetch metadata nor a matching `Origin`.
    WriteWithoutOrigin,
    /// `Content-Type` was not `application/json`.
    NotJson,
    /// The custom request header was missing.
    MissingRequestHeader,
}

/// The request facts the guard reads.
pub(crate) struct GuardFacts<'a> {
    pub(crate) is_write: bool,
    /// The scheme of the connection the request arrived on (`https` at the
    /// edge). Taken from the request URL, never from a header.
    pub(crate) scheme: &'a str,
    /// The request `Host` header.
    pub(crate) host: Option<&'a str>,
    pub(crate) sec_fetch_site: Option<&'a str>,
    pub(crate) origin: Option<&'a str>,
    pub(crate) content_type: Option<&'a str>,
    pub(crate) request_header: Option<&'a str>,
}

/// The cross-origin write defence (spec 20, "The threat this adds"). All of
/// these must hold; none depends on cookie scoping.
///
/// 1. `Sec-Fetch-Site` must be exactly `same-origin` when the browser sends
///    it. `same-site`, `cross-site` and `none` are refused. Browsers that
///    omit it fall back to `Origin`, which must equal this artifact's own
///    origin; a write with neither is refused.
/// 2. The request must carry the custom header, so a cross-origin caller
///    needs a preflight.
/// 3. A write or a body-bearing request must be `application/json`, which a
///    plain form post or `text/plain` `no-cors` fetch cannot produce.
pub(crate) fn guard_request(facts: &GuardFacts<'_>) -> Result<(), GuardRefusal> {
    let own_origin = facts.host.map(|host| format!("{}://{host}", facts.scheme));
    match facts.sec_fetch_site {
        Some(site) => {
            if !site.eq_ignore_ascii_case("same-origin") {
                return Err(GuardRefusal::NotSameOrigin);
            }
        }
        None => match (facts.origin, own_origin.as_deref()) {
            (Some(origin), Some(own)) if origin == own => {}
            (Some(_), _) => return Err(GuardRefusal::NotSameOrigin),
            (None, _) if facts.is_write => return Err(GuardRefusal::WriteWithoutOrigin),
            (None, _) => {}
        },
    }
    // With fetch metadata present, a stray `Origin` that names another host is
    // still refused: a same-origin request never carries one.
    if let (Some(origin), Some(own)) = (facts.origin, own_origin.as_deref()) {
        if origin != own {
            return Err(GuardRefusal::NotSameOrigin);
        }
    }
    if facts.request_header != Some("1") {
        return Err(GuardRefusal::MissingRequestHeader);
    }
    if facts.is_write {
        let is_json = facts.content_type.is_some_and(|value| {
            value
                .split(';')
                .next()
                .is_some_and(|mime| mime.trim().eq_ignore_ascii_case("application/json"))
        });
        if !is_json {
            return Err(GuardRefusal::NotJson);
        }
    }
    Ok(())
}

/// What a viewer may do in an artifact's database.
#[derive(Clone, Copy, Debug, PartialEq, Eq)]
pub(crate) enum DbRole {
    Reader,
    Writer,
}

impl DbRole {
    pub(crate) fn as_str(self) -> &'static str {
        match self {
            Self::Reader => "reader",
            Self::Writer => "writer",
        }
    }

    pub(crate) fn parse(value: &str) -> Option<Self> {
        match value {
            "reader" => Some(Self::Reader),
            "writer" => Some(Self::Writer),
            _ => None,
        }
    }

    pub(crate) fn can_write(self) -> bool {
        self == Self::Writer
    }
}

/// Derives the db role from a verified access token and the artifact's
/// sharing state. `None` is no access. Fails closed on every input it does
/// not recognise.
///
/// - A private artifact needs a token that opens it, as everywhere else.
/// - A public artifact is readable with no token (the link role) but writable
///   only by its owner or an admin: anonymous write is deferred.
/// - A team artifact needs a token. Its owner and admins may write; any other
///   named member may write only when `edit_access` is `edit`. A legacy token
///   that names nobody may read and never writes.
pub(crate) fn db_role(
    token: Option<&TokenViewer>,
    owner_user_id: Option<&str>,
    sharing: Sharing,
    edit_access: EditAccess,
) -> Option<DbRole> {
    let is_owner_or_admin = token.is_some_and(|token| {
        token.viewer.as_deref() != Some(SYSTEM_VIEWER)
            && (token.sees_private
                || matches!(
                    (token.viewer.as_deref(), owner_user_id),
                    (Some(viewer), Some(owner)) if !owner.is_empty() && viewer == owner
                ))
    });
    match sharing {
        Sharing::Private => {
            let token = token?;
            if !token_opens_private(token, owner_user_id) {
                return None;
            }
            Some(if is_owner_or_admin {
                DbRole::Writer
            } else {
                DbRole::Reader
            })
        }
        Sharing::Public => {
            if is_owner_or_admin {
                Some(DbRole::Writer)
            } else {
                Some(DbRole::Reader)
            }
        }
        _ => {
            let token = token?;
            let named_member = token
                .viewer
                .as_deref()
                .is_some_and(|viewer| viewer != SYSTEM_VIEWER);
            if is_owner_or_admin || (named_member && edit_access == EditAccess::Edit) {
                Some(DbRole::Writer)
            } else {
                Some(DbRole::Reader)
            }
        }
    }
}

/// A verified db write grant: who, and what they may do, for one artifact.
#[derive(Clone, Debug, PartialEq, Eq)]
pub(crate) struct DbGrant {
    pub(crate) viewer: String,
    pub(crate) role: DbRole,
}

fn valid_grant_viewer(viewer: &str) -> bool {
    !viewer.is_empty()
        && viewer.len() <= 64
        && viewer
            .bytes()
            .all(|byte| byte.is_ascii_alphanumeric() || byte == b'_' || byte == b'-')
}

/// HMAC over a domain-separated message. The leading `db` keeps a db token
/// from ever verifying as an access token or the reverse: access-token
/// messages never start with `db.`.
fn db_token_signature(
    secret: &str,
    artifact_id: &str,
    expires_at_unix: i64,
    viewer: &str,
    role: DbRole,
) -> String {
    let message = format!(
        "db.{artifact_id}.{expires_at_unix}.{viewer}.{}",
        role.as_str()
    );
    store::hmac_sha256(secret.as_bytes(), message.as_bytes())
        .iter()
        .map(|byte| format!("{byte:02x}"))
        .collect()
}

/// Mints a write token: `{exp}.{viewer}.{role}.{sig}`. The artifact id is in
/// the signature but not the token, so a token cannot be replayed on another
/// artifact: the verifier supplies the id from the request host, never from
/// the token. Returns `None` for an invalid viewer so a bad id never reaches
/// the signed message.
pub(crate) fn mint_db_token(
    secret: &str,
    artifact_id: &str,
    expires_at: chrono::DateTime<Utc>,
    viewer: &str,
    role: DbRole,
) -> Option<String> {
    if !valid_grant_viewer(viewer) {
        return None;
    }
    let expires_at_unix = expires_at.timestamp();
    let signature = db_token_signature(secret, artifact_id, expires_at_unix, viewer, role);
    Some(format!(
        "{expires_at_unix}.{viewer}.{}.{signature}",
        role.as_str()
    ))
}

/// Verifies a write token for one artifact at a caller-supplied "now". Fails
/// closed for a missing secret, a malformed or expired token, an unknown
/// role, or a signature minted for another artifact.
pub(crate) fn verify_db_token(
    secret: Option<&str>,
    token: &str,
    artifact_id: &str,
    now: chrono::DateTime<Utc>,
) -> Option<DbGrant> {
    let secret = secret.filter(|value| !value.is_empty())?;
    let parts: Vec<&str> = token.split('.').collect();
    let [expiry, viewer, role, signature] = parts.as_slice() else {
        return None;
    };
    let expires_at_unix = expiry.parse::<i64>().ok()?;
    if now.timestamp() >= expires_at_unix {
        return None;
    }
    if !valid_grant_viewer(viewer) {
        return None;
    }
    let role = DbRole::parse(role)?;
    let expected = db_token_signature(secret, artifact_id, expires_at_unix, viewer, role);
    if !constant_time_equal(signature.as_bytes(), expected.as_bytes()) {
        return None;
    }
    Some(DbGrant {
        viewer: (*viewer).to_string(),
        role,
    })
}

/// Why a document body was refused.
#[derive(Debug, PartialEq, Eq)]
pub(crate) enum BodyRefusal {
    TooLarge,
    NotJson,
    NotAnObject,
    ServerFieldPresent(&'static str),
}

/// Validates a client document body: at most [`MAX_BODY_BYTES`], a JSON
/// object, and none of the server-owned fields.
pub(crate) fn parse_document_body(
    bytes: &[u8],
) -> Result<serde_json::Map<String, Value>, BodyRefusal> {
    if bytes.len() > MAX_BODY_BYTES {
        return Err(BodyRefusal::TooLarge);
    }
    let value: Value = serde_json::from_slice(bytes).map_err(|_| BodyRefusal::NotJson)?;
    let Value::Object(map) = value else {
        return Err(BodyRefusal::NotAnObject);
    };
    for field in SERVER_FIELDS {
        if map.contains_key(field) {
            return Err(BodyRefusal::ServerFieldPresent(field));
        }
    }
    Ok(map)
}

/// The value of a collection's declared index field, as the string stored in
/// `index_value`. Only a string, number or boolean is indexable; anything
/// else (object, array, null, absent) stores no index value.
pub(crate) fn index_value(body: &serde_json::Map<String, Value>, field: &str) -> Option<String> {
    match body.get(field)? {
        Value::String(value) if value.len() <= 256 => Some(value.clone()),
        Value::Number(value) => Some(value.to_string()),
        Value::Bool(value) => Some(value.to_string()),
        _ => None,
    }
}

/// A collection a manifest declares.
#[derive(Debug, Clone, Default, Deserialize, Serialize, PartialEq, Eq)]
pub(crate) struct DbCollectionDecl {
    /// The one field filtered on by `index_eq`.
    #[serde(default)]
    pub(crate) index: Option<String>,
}

/// A starter row that travels with a template.
#[derive(Debug, Clone, Deserialize, Serialize, PartialEq, Eq)]
pub(crate) struct DbSeedRow {
    pub(crate) collection: String,
    pub(crate) doc_id: String,
    pub(crate) body: serde_json::Map<String, Value>,
}

/// The manifest's optional `db` section. An artifact that declares none has no
/// db endpoint at all.
#[derive(Debug, Clone, Default, Deserialize, Serialize, PartialEq, Eq)]
pub(crate) struct DbManifest {
    #[serde(default)]
    pub(crate) collections: BTreeMap<String, DbCollectionDecl>,
    #[serde(default)]
    pub(crate) seed: Vec<DbSeedRow>,
}

impl DbManifest {
    /// The declared index field of a collection, or `None` when the
    /// collection is undeclared. `Some(None)` means declared with no index.
    pub(crate) fn collection(&self, name: &str) -> Option<&DbCollectionDecl> {
        self.collections.get(name)
    }
}

/// Why a manifest `db` section was refused at deploy.
#[derive(Debug, PartialEq, Eq)]
pub(crate) enum ManifestRefusal {
    NoCollections,
    TooManyCollections,
    InvalidCollectionName(String),
    InvalidIndexField(String),
    TooManySeedRows,
    SeedTooLarge,
    SeedCollectionUndeclared(String),
    InvalidSeedDocId(String),
    SeedBodyInvalid(String),
    DuplicateSeedRow(String),
}

/// Validates a manifest `db` section. A section that declares collections is
/// the only way an artifact gets db access, so this is strict.
pub(crate) fn validate_db_manifest(manifest: &DbManifest) -> Result<(), ManifestRefusal> {
    if manifest.collections.is_empty() {
        return Err(ManifestRefusal::NoCollections);
    }
    if manifest.collections.len() > MAX_COLLECTIONS {
        return Err(ManifestRefusal::TooManyCollections);
    }
    for (name, declaration) in &manifest.collections {
        if !valid_collection_name(name) || matches!(name.as_str(), "session" | "batch") {
            return Err(ManifestRefusal::InvalidCollectionName(name.clone()));
        }
        if let Some(index) = &declaration.index {
            if !valid_identifier(index, MAX_NAME_LEN) {
                return Err(ManifestRefusal::InvalidIndexField(index.clone()));
            }
        }
    }
    if manifest.seed.len() > MAX_SEED_ROWS {
        return Err(ManifestRefusal::TooManySeedRows);
    }
    let mut seen = std::collections::BTreeSet::new();
    let mut seed_bytes = 0usize;
    for row in &manifest.seed {
        if !manifest.collections.contains_key(&row.collection) {
            return Err(ManifestRefusal::SeedCollectionUndeclared(
                row.collection.clone(),
            ));
        }
        if !valid_doc_id(&row.doc_id) {
            return Err(ManifestRefusal::InvalidSeedDocId(row.doc_id.clone()));
        }
        if !seen.insert((row.collection.clone(), row.doc_id.clone())) {
            return Err(ManifestRefusal::DuplicateSeedRow(format!(
                "{}/{}",
                row.collection, row.doc_id
            )));
        }
        let encoded = serde_json::to_vec(&row.body).unwrap_or_default();
        let key = format!("{}/{}", row.collection, row.doc_id);
        seed_bytes = seed_bytes.saturating_add(encoded.len());
        if seed_bytes > MAX_SEED_BYTES {
            return Err(ManifestRefusal::SeedTooLarge);
        }
        if encoded.len() > MAX_BODY_BYTES {
            return Err(ManifestRefusal::SeedBodyInvalid(key));
        }
        if SERVER_FIELDS
            .iter()
            .any(|field| row.body.contains_key(*field))
        {
            return Err(ManifestRefusal::SeedBodyInvalid(key));
        }
    }
    Ok(())
}

/// Whether a bundle file path sits in the reserved db namespace. Checked at
/// deploy so no artifact can ship a file the db handler would shadow.
pub(crate) fn is_reserved_bundle_path(path: &str) -> bool {
    let trimmed = path.trim_start_matches('/');
    trimmed.starts_with(RESERVED_BUNDLE_PREFIX)
        || trimmed == RESERVED_BUNDLE_PREFIX.trim_end_matches('/')
}

#[cfg(test)]
mod tests {
    use super::*;
    use chrono::Duration;

    const SECRET: &str = "test-secret";
    const HOST: &str = "acme--abc123def456x--prod.artfct.dev";

    fn facts<'a>() -> GuardFacts<'a> {
        GuardFacts {
            is_write: true,
            scheme: "https",
            host: Some(HOST),
            sec_fetch_site: Some("same-origin"),
            origin: Some("https://acme--abc123def456x--prod.artfct.dev"),
            content_type: Some("application/json"),
            request_header: Some("1"),
        }
    }

    fn named(viewer: &str, sees_private: bool) -> TokenViewer {
        TokenViewer {
            viewer: Some(viewer.to_string()),
            sees_private,
        }
    }

    // ----- path parsing -----

    #[test]
    fn parses_every_route_shape() {
        assert_eq!(
            parse_db_path("/__artfct/db/session"),
            Some(DbRoute::Session)
        );
        assert_eq!(parse_db_path("/__artfct/db/batch"), Some(DbRoute::Batch));
        assert_eq!(
            parse_db_path("/__artfct/db/cards"),
            Some(DbRoute::Collection {
                collection: "cards".into()
            })
        );
        assert_eq!(
            parse_db_path("/__artfct/db/cards/card-1.v2"),
            Some(DbRoute::Doc {
                collection: "cards".into(),
                doc_id: "card-1.v2".into()
            })
        );
    }

    #[test]
    fn rejects_unsafe_or_extra_path_segments() {
        for path in [
            "/__artfct/db/",
            "/__artfct/db/cards/",
            "/__artfct/db/cards/a/b",
            "/__artfct/db/../cards",
            "/__artfct/db/./cards",
            "/__artfct/db/cards/..",
            "/__artfct/db/.hidden",
            "/__artfct/db/cards/a%2Fb",
            "/__artfct/db/car ds",
            "/__artfct/db/cards/a?b",
            "/__artfct/dbx/cards",
            "/p/abc/__artfct/db/cards",
        ] {
            assert_eq!(parse_db_path(path), None, "{path}");
        }
    }

    #[test]
    fn identifiers_are_length_bounded() {
        assert!(valid_collection_name(&"a".repeat(64)));
        assert!(!valid_collection_name(&"a".repeat(65)));
        assert!(valid_doc_id(&"a".repeat(128)));
        assert!(!valid_doc_id(&"a".repeat(129)));
    }

    // ----- the cross-origin write defence -----

    #[test]
    fn same_origin_json_write_with_header_passes() {
        assert_eq!(guard_request(&facts()), Ok(()));
    }

    #[test]
    fn same_site_fetch_metadata_rejected() {
        for site in ["same-site", "cross-site", "none"] {
            let mut request = facts();
            request.sec_fetch_site = Some(site);
            assert_eq!(
                guard_request(&request),
                Err(GuardRefusal::NotSameOrigin),
                "{site}"
            );
        }
    }

    #[test]
    fn sibling_artifact_origin_rejected_even_with_same_origin_metadata_missing() {
        let mut request = facts();
        request.sec_fetch_site = None;
        request.origin = Some("https://acme--zzzzzzzzzzzzz--prod.artfct.dev");
        assert_eq!(guard_request(&request), Err(GuardRefusal::NotSameOrigin));
    }

    #[test]
    fn stray_foreign_origin_rejected_even_with_same_origin_metadata() {
        let mut request = facts();
        request.origin = Some("https://acme--zzzzzzzzzzzzz--prod.artfct.dev");
        assert_eq!(guard_request(&request), Err(GuardRefusal::NotSameOrigin));
    }

    #[test]
    fn origin_scheme_must_match_the_connection_scheme() {
        let mut request = facts();
        request.scheme = "http";
        request.origin = Some("http://acme--abc123def456x--prod.artfct.dev");
        assert_eq!(guard_request(&request), Ok(()));
        // An https origin on an http connection is not this origin.
        request.origin = Some("https://acme--abc123def456x--prod.artfct.dev");
        assert_eq!(guard_request(&request), Err(GuardRefusal::NotSameOrigin));
    }

    #[test]
    fn null_origin_rejected() {
        let mut request = facts();
        request.sec_fetch_site = None;
        request.origin = Some("null");
        assert_eq!(guard_request(&request), Err(GuardRefusal::NotSameOrigin));
    }

    #[test]
    fn write_with_no_fetch_metadata_and_no_origin_rejected() {
        let mut request = facts();
        request.sec_fetch_site = None;
        request.origin = None;
        assert_eq!(
            guard_request(&request),
            Err(GuardRefusal::WriteWithoutOrigin)
        );
    }

    #[test]
    fn read_with_no_fetch_metadata_and_no_origin_allowed_with_header() {
        let mut request = facts();
        request.is_write = false;
        request.sec_fetch_site = None;
        request.origin = None;
        request.content_type = None;
        assert_eq!(guard_request(&request), Ok(()));
    }

    #[test]
    fn missing_custom_header_rejected() {
        let mut request = facts();
        request.request_header = None;
        assert_eq!(
            guard_request(&request),
            Err(GuardRefusal::MissingRequestHeader)
        );
        request.request_header = Some("0");
        assert_eq!(
            guard_request(&request),
            Err(GuardRefusal::MissingRequestHeader)
        );
    }

    #[test]
    fn non_json_write_rejected() {
        for content_type in [
            None,
            Some("text/plain"),
            Some("text/plain;charset=UTF-8"),
            Some("application/x-www-form-urlencoded"),
            Some("multipart/form-data; boundary=x"),
        ] {
            let mut request = facts();
            request.content_type = content_type;
            assert_eq!(
                guard_request(&request),
                Err(GuardRefusal::NotJson),
                "{content_type:?}"
            );
        }
    }

    #[test]
    fn json_content_type_with_charset_passes() {
        let mut request = facts();
        request.content_type = Some("application/json; charset=utf-8");
        assert_eq!(guard_request(&request), Ok(()));
    }

    // ----- roles -----

    #[test]
    fn viewer_role_can_read_cannot_write() {
        let member = named("user-2", false);
        assert_eq!(
            db_role(
                Some(&member),
                Some("user-1"),
                Sharing::Team,
                EditAccess::View
            ),
            Some(DbRole::Reader)
        );
    }

    #[test]
    fn team_member_writes_only_when_edit_access_is_edit() {
        let member = named("user-2", false);
        assert_eq!(
            db_role(
                Some(&member),
                Some("user-1"),
                Sharing::Team,
                EditAccess::Edit
            ),
            Some(DbRole::Writer)
        );
    }

    #[test]
    fn owner_and_admin_write_regardless_of_edit_access() {
        let owner = named("user-1", false);
        let admin = named("user-9", true);
        for token in [&owner, &admin] {
            assert_eq!(
                db_role(Some(token), Some("user-1"), Sharing::Team, EditAccess::View),
                Some(DbRole::Writer)
            );
        }
    }

    #[test]
    fn legacy_token_naming_nobody_never_writes() {
        let legacy = TokenViewer {
            viewer: None,
            sees_private: false,
        };
        assert_eq!(
            db_role(
                Some(&legacy),
                Some("user-1"),
                Sharing::Team,
                EditAccess::Edit
            ),
            Some(DbRole::Reader)
        );
    }

    #[test]
    fn team_artifact_without_token_has_no_access() {
        assert_eq!(
            db_role(None, Some("user-1"), Sharing::Team, EditAccess::Edit),
            None
        );
    }

    #[test]
    fn private_artifact_needs_a_token_that_opens_it() {
        let stranger = named("user-2", false);
        assert_eq!(
            db_role(
                Some(&stranger),
                Some("user-1"),
                Sharing::Private,
                EditAccess::Edit
            ),
            None
        );
        assert_eq!(
            db_role(None, Some("user-1"), Sharing::Private, EditAccess::Edit),
            None
        );
        let owner = named("user-1", false);
        assert_eq!(
            db_role(
                Some(&owner),
                Some("user-1"),
                Sharing::Private,
                EditAccess::View
            ),
            Some(DbRole::Writer)
        );
    }

    #[test]
    fn public_artifact_is_readable_anonymously_and_never_writable_by_link() {
        assert_eq!(
            db_role(None, Some("user-1"), Sharing::Public, EditAccess::Edit),
            Some(DbRole::Reader)
        );
        let member = named("user-2", false);
        assert_eq!(
            db_role(
                Some(&member),
                Some("user-1"),
                Sharing::Public,
                EditAccess::Edit
            ),
            Some(DbRole::Reader)
        );
    }

    // ----- write tokens -----

    #[test]
    fn token_round_trips_for_its_artifact() {
        let now = Utc::now();
        let token = mint_db_token(
            SECRET,
            "art1",
            now + Duration::seconds(60),
            "user-2",
            DbRole::Writer,
        )
        .expect("mint");
        assert_eq!(
            verify_db_token(Some(SECRET), &token, "art1", now),
            Some(DbGrant {
                viewer: "user-2".into(),
                role: DbRole::Writer
            })
        );
    }

    #[test]
    fn token_for_other_artifact_rejected() {
        let now = Utc::now();
        let token = mint_db_token(
            SECRET,
            "art1",
            now + Duration::seconds(60),
            "user-2",
            DbRole::Writer,
        )
        .expect("mint");
        assert_eq!(verify_db_token(Some(SECRET), &token, "art2", now), None);
    }

    #[test]
    fn expired_token_rejected() {
        let now = Utc::now();
        let token = mint_db_token(
            SECRET,
            "art1",
            now - Duration::seconds(1),
            "user-2",
            DbRole::Writer,
        )
        .expect("mint");
        assert_eq!(verify_db_token(Some(SECRET), &token, "art1", now), None);
    }

    #[test]
    fn role_cannot_be_upgraded_by_editing_the_token() {
        let now = Utc::now();
        let token = mint_db_token(
            SECRET,
            "art1",
            now + Duration::seconds(60),
            "user-2",
            DbRole::Reader,
        )
        .expect("mint");
        let forged = token.replace(".reader.", ".writer.");
        assert_ne!(token, forged);
        assert_eq!(verify_db_token(Some(SECRET), &forged, "art1", now), None);
    }

    #[test]
    fn viewer_cannot_be_swapped_in_the_token() {
        let now = Utc::now();
        let token = mint_db_token(
            SECRET,
            "art1",
            now + Duration::seconds(60),
            "user-2",
            DbRole::Writer,
        )
        .expect("mint");
        let forged = token.replace(".user-2.", ".user-1.");
        assert_eq!(verify_db_token(Some(SECRET), &forged, "art1", now), None);
    }

    #[test]
    fn missing_or_empty_secret_rejects() {
        let now = Utc::now();
        let token = mint_db_token(
            SECRET,
            "art1",
            now + Duration::seconds(60),
            "user-2",
            DbRole::Writer,
        )
        .expect("mint");
        assert_eq!(verify_db_token(None, &token, "art1", now), None);
        assert_eq!(verify_db_token(Some(""), &token, "art1", now), None);
        assert_eq!(verify_db_token(Some("other"), &token, "art1", now), None);
    }

    #[test]
    fn malformed_tokens_rejected() {
        let now = Utc::now();
        for token in [
            "",
            "a.b.c",
            "1.2.3.4.5",
            "x.user.writer.sig",
            "9999999999.user.admin.sig",
        ] {
            assert_eq!(
                verify_db_token(Some(SECRET), token, "art1", now),
                None,
                "{token}"
            );
        }
    }

    #[test]
    fn invalid_viewer_is_never_minted() {
        let now = Utc::now();
        for viewer in ["", "a.b", "a b", &"x".repeat(65)] {
            assert_eq!(
                mint_db_token(SECRET, "art1", now, viewer, DbRole::Writer),
                None,
                "{viewer}"
            );
        }
    }

    #[test]
    fn access_token_does_not_verify_as_db_token_and_reverse() {
        let now = Utc::now();
        let expires = now + Duration::seconds(60);
        let access = crate::artifact_origin::mint_viewer_access_token(
            SECRET, "art1", expires, "user-2", true,
        );
        assert_eq!(verify_db_token(Some(SECRET), &access, "art1", now), None);
        let db = mint_db_token(SECRET, "art1", expires, "user-2", DbRole::Writer).expect("mint");
        assert!(!crate::artifact_origin::verify_access_token(
            Some(SECRET),
            &db,
            "art1",
            now
        ));
    }

    // ----- quotas -----

    #[test]
    fn quota_blocks_the_document_that_passes_the_limit() {
        let limits = DbLimits {
            docs_per_artifact: 10,
            bytes_per_org: 1000,
        };
        assert!(!exceeds_db_quota(&limits, 9, 0, 1, 10));
        assert!(exceeds_db_quota(&limits, 10, 0, 1, 10));
        assert!(
            !exceeds_db_quota(&limits, 10, 0, 0, 10),
            "an overwrite adds no document"
        );
        assert!(exceeds_db_quota(&limits, 0, 995, 1, 10));
        assert!(!exceeds_db_quota(&limits, 0, 990, 1, 10));
    }

    #[test]
    fn pushed_limits_override_defaults_and_null_falls_back() {
        assert_eq!(DbLimits::from_pushed(None, None), DbLimits::default());
        assert_eq!(
            DbLimits::from_pushed(Some(7), Some(99)),
            DbLimits {
                docs_per_artifact: 7,
                bytes_per_org: 99
            }
        );
        assert_eq!(DbLimits::from_pushed(Some(-5), None).docs_per_artifact, 0);
    }

    #[test]
    fn rollout_parses_and_fails_closed() {
        for on in ["true", "TRUE", " 1 ", "all", "on"] {
            assert_eq!(DbRollout::parse(on), DbRollout::All, "{on}");
        }
        assert_eq!(DbRollout::parse("orgs"), DbRollout::Orgs);
        for off in ["", "false", "0", "off", "yes please", "enabled"] {
            assert_eq!(DbRollout::parse(off), DbRollout::Off, "{off}");
        }
    }

    #[test]
    fn rollout_decides_per_org() {
        use DbRollout::{All, Off, Orgs};
        for setting in [None, Some(true), Some(false)] {
            assert!(!db_allowed(Off, setting), "off ignores every org setting");
        }
        assert!(db_allowed(All, None));
        assert!(db_allowed(All, Some(true)));
        assert!(!db_allowed(All, Some(false)), "an org can opt out of all");
        assert!(!db_allowed(Orgs, None), "orgs mode needs an explicit yes");
        assert!(!db_allowed(Orgs, Some(false)));
        assert!(db_allowed(Orgs, Some(true)));
    }

    #[test]
    fn bytes_added_counts_growth_only() {
        assert_eq!(bytes_added(100, None), 100);
        assert_eq!(bytes_added(100, Some(40)), 60);
        assert_eq!(bytes_added(40, Some(100)), 0);
    }

    #[test]
    fn write_rate_allows_up_to_the_limit() {
        assert!(within_write_rate(DB_WRITES_PER_MINUTE));
        assert!(!within_write_rate(DB_WRITES_PER_MINUTE + 1));
    }

    #[test]
    fn system_renderer_never_writes() {
        let system = named("system", true);
        for sharing in [Sharing::Team, Sharing::Private, Sharing::Public] {
            assert_ne!(
                db_role(Some(&system), Some("system"), sharing, EditAccess::Edit),
                Some(DbRole::Writer),
                "{sharing:?}"
            );
        }
        // It can still read what it renders.
        assert_eq!(
            db_role(
                Some(&system),
                Some("user-1"),
                Sharing::Private,
                EditAccess::Edit
            ),
            Some(DbRole::Reader)
        );
    }

    #[test]
    fn manifest_caps_total_seed_size() {
        let big = "x".repeat(40 * 1024);
        let seeds = DbManifest {
            collections: BTreeMap::from([("c".to_string(), DbCollectionDecl::default())]),
            seed: (0..8)
                .map(|i| DbSeedRow {
                    collection: "c".into(),
                    doc_id: format!("d{i}"),
                    body: serde_json::Map::from_iter([(
                        "v".to_string(),
                        Value::String(big.clone()),
                    )]),
                })
                .collect(),
        };
        assert_eq!(
            validate_db_manifest(&seeds),
            Err(ManifestRefusal::SeedTooLarge)
        );
    }

    // ----- bodies -----

    #[test]
    fn body_must_be_a_json_object_within_the_limit() {
        assert!(parse_document_body(br#"{"title":"x"}"#).is_ok());
        assert_eq!(parse_document_body(b"[1]"), Err(BodyRefusal::NotAnObject));
        assert_eq!(parse_document_body(b"\"x\""), Err(BodyRefusal::NotAnObject));
        assert_eq!(parse_document_body(b"{nope"), Err(BodyRefusal::NotJson));
        let oversized = vec![b' '; MAX_BODY_BYTES + 1];
        assert_eq!(parse_document_body(&oversized), Err(BodyRefusal::TooLarge));
    }

    #[test]
    fn body_cannot_forge_server_fields() {
        for field in SERVER_FIELDS {
            let body = format!(r#"{{"{field}": 1}}"#);
            assert_eq!(
                parse_document_body(body.as_bytes()),
                Err(BodyRefusal::ServerFieldPresent(field)),
                "{field}"
            );
        }
    }

    #[test]
    fn index_value_extracts_scalars_only() {
        let body = |json: &str| parse_document_body(json.as_bytes()).expect("body");
        assert_eq!(
            index_value(&body(r#"{"c":"todo"}"#), "c"),
            Some("todo".into())
        );
        assert_eq!(index_value(&body(r#"{"c":3}"#), "c"), Some("3".into()));
        assert_eq!(
            index_value(&body(r#"{"c":true}"#), "c"),
            Some("true".into())
        );
        assert_eq!(index_value(&body(r#"{"c":null}"#), "c"), None);
        assert_eq!(index_value(&body(r#"{"c":{"a":1}}"#), "c"), None);
        assert_eq!(index_value(&body(r#"{"c":[1]}"#), "c"), None);
        assert_eq!(index_value(&body(r#"{}"#), "c"), None);
    }

    // ----- manifest -----

    fn manifest(json: &str) -> DbManifest {
        serde_json::from_str(json).expect("manifest json")
    }

    #[test]
    fn manifest_with_declared_collections_and_seed_validates() {
        let manifest = manifest(
            r#"{"collections":{"cards":{"index":"column"}},
                "seed":[{"collection":"cards","doc_id":"c1","body":{"column":"todo"}}]}"#,
        );
        assert_eq!(validate_db_manifest(&manifest), Ok(()));
    }

    #[test]
    fn manifest_without_collections_refused() {
        assert_eq!(
            validate_db_manifest(&DbManifest::default()),
            Err(ManifestRefusal::NoCollections)
        );
    }

    #[test]
    fn manifest_refuses_reserved_and_invalid_collection_names() {
        for name in ["session", "batch", ".x", "a/b", ""] {
            let manifest = DbManifest {
                collections: BTreeMap::from([(name.to_string(), DbCollectionDecl::default())]),
                seed: Vec::new(),
            };
            assert!(
                matches!(
                    validate_db_manifest(&manifest),
                    Err(ManifestRefusal::InvalidCollectionName(_))
                ),
                "{name}"
            );
        }
    }

    #[test]
    fn manifest_refuses_seed_for_undeclared_collection() {
        let manifest = manifest(
            r#"{"collections":{"cards":{}},
                "seed":[{"collection":"notes","doc_id":"n1","body":{}}]}"#,
        );
        assert_eq!(
            validate_db_manifest(&manifest),
            Err(ManifestRefusal::SeedCollectionUndeclared("notes".into()))
        );
    }

    #[test]
    fn manifest_refuses_duplicate_seed_rows_and_forged_server_fields() {
        let duplicate = manifest(
            r#"{"collections":{"cards":{}},
                "seed":[{"collection":"cards","doc_id":"c1","body":{}},
                        {"collection":"cards","doc_id":"c1","body":{}}]}"#,
        );
        assert!(matches!(
            validate_db_manifest(&duplicate),
            Err(ManifestRefusal::DuplicateSeedRow(_))
        ));
        let forged = manifest(
            r#"{"collections":{"cards":{}},
                "seed":[{"collection":"cards","doc_id":"c1","body":{"updated_by":"me"}}]}"#,
        );
        assert!(matches!(
            validate_db_manifest(&forged),
            Err(ManifestRefusal::SeedBodyInvalid(_))
        ));
    }

    #[test]
    fn manifest_caps_collections_and_seed_rows() {
        let many = DbManifest {
            collections: (0..=MAX_COLLECTIONS)
                .map(|i| (format!("c{i}"), DbCollectionDecl::default()))
                .collect(),
            seed: Vec::new(),
        };
        assert_eq!(
            validate_db_manifest(&many),
            Err(ManifestRefusal::TooManyCollections)
        );
        let seeds = DbManifest {
            collections: BTreeMap::from([("c".to_string(), DbCollectionDecl::default())]),
            seed: (0..=MAX_SEED_ROWS)
                .map(|i| DbSeedRow {
                    collection: "c".into(),
                    doc_id: format!("d{i}"),
                    body: serde_json::Map::new(),
                })
                .collect(),
        };
        assert_eq!(
            validate_db_manifest(&seeds),
            Err(ManifestRefusal::TooManySeedRows)
        );
    }

    // ----- deploy-time wiring -----

    fn deploy_body(extra: &str, path: &str) -> Value {
        serde_json::from_str(&format!(
            r#"{{"manifest":{{"entrypoint":"index.html","external_origins":[],
                "files":[{{"path":"{path}","size_bytes":1,"content_type":"text/html",
                "sha256":"{}"}}]{extra}}}}}"#,
            "a".repeat(64)
        ))
        .expect("deploy json")
    }

    #[test]
    fn manifest_without_db_keeps_its_canonical_bytes_and_hash() {
        let (manifest, hash) =
            crate::validation::validate_permanent_manifest(&deploy_body("", "index.html"))
                .expect("valid");
        assert_eq!(manifest.db, None);
        let canonical = serde_json::to_string(&manifest).expect("json");
        assert!(!canonical.contains("\"db\""), "{canonical}");
        assert_eq!(hash, store::content_hash(canonical.as_bytes()));
    }

    #[test]
    fn deploy_accepts_a_declared_db_with_seed() {
        let extra = r#","db":{"collections":{"cards":{"index":"column"}},
            "seed":[{"collection":"cards","doc_id":"c1","body":{"column":"todo"}}]}"#;
        let (manifest, _) =
            crate::validation::validate_permanent_manifest(&deploy_body(extra, "index.html"))
                .expect("valid");
        let declared = manifest.db.expect("db kept");
        assert_eq!(declared.seed.len(), 1);
        assert_eq!(
            declared
                .collection("cards")
                .and_then(|c| c.index.as_deref()),
            Some("column")
        );
    }

    #[test]
    fn deploy_refuses_an_invalid_db_section() {
        for extra in [
            r#","db":{"collections":{}}"#,
            r#","db":{"collections":{"session":{}}}"#,
            r#","db":{"collections":{"a":{}},"seed":[{"collection":"b","doc_id":"x","body":{}}]}"#,
            r#","db":"yes""#,
        ] {
            assert!(
                crate::validation::validate_permanent_manifest(&deploy_body(extra, "index.html"))
                    .is_err(),
                "{extra}"
            );
        }
    }

    #[test]
    fn deploy_refuses_a_bundle_file_in_the_reserved_namespace() {
        let result =
            crate::validation::validate_permanent_manifest(&deploy_body("", "__artfct/db/x.html"));
        assert!(result.is_err());
    }

    // ----- reserved bundle prefix -----

    #[test]
    fn reserved_bundle_paths_detected() {
        for path in ["__artfct/db/x", "/__artfct/anything", "__artfct"] {
            assert!(is_reserved_bundle_path(path), "{path}");
        }
        for path in ["index.html", "assets/__artfct/x", "artfct/x", "__artfct.js"] {
            assert!(!is_reserved_bundle_path(path), "{path}");
        }
    }

    #[test]
    fn db_namespace_detection() {
        assert!(is_db_path("/__artfct/db/cards"));
        assert!(is_db_path("/__artfct/db"));
        assert!(!is_db_path("/__artfct/dbx"));
        assert!(!is_db_path("/p/abc/"));
    }
}
