use std::{
    collections::{HashMap, HashSet},
    fmt,
    sync::{
        atomic::{AtomicU64, Ordering},
        Mutex,
    },
    time::Duration,
};

use chrono::Utc;
use getrandom::getrandom;
use serde::{de::DeserializeOwned, Deserialize, Serialize};
use serde_json::Value;
use uuid::Uuid;
use worker::{d1::D1Database, kv::KvStore, Bucket, Delay};

use crate::governance::GovernanceError;

pub const MAX_TENANT_SLUG_LENGTH: usize = 24;
pub const PUBLIC_ID_LENGTH: usize = 32;
/// Length of a stable artifact id. The three id shapes ([`STABLE_ID_LENGTH`]
/// base36, [`PUBLIC_ID_LENGTH`] hex, and the 10-character ephemeral shape)
/// never overlap, so a length alone is enough to tell them apart.
pub const STABLE_ID_LENGTH: usize = 13;
/// The lowercase base36 alphabet (`0-9a-z`) a stable artifact id is drawn
/// from. Lowercase because the id becomes a hostname label in an isolated
/// origin and browsers lowercase hostnames.
const STABLE_ID_ALPHABET: &[u8; 36] = b"0123456789abcdefghijklmnopqrstuvwxyz";

#[derive(Clone, Debug, PartialEq, Eq, Hash, Serialize, Deserialize)]
pub struct ArtifactId(pub String);

#[derive(Clone, Debug, PartialEq, Eq, Serialize, Deserialize)]
pub struct NewArtifact {
    pub org: String,
    pub content: Vec<u8>,
    pub content_type: String,
    pub entrypoint: String,
    pub provenance: Value,
}

#[derive(Clone, Debug, PartialEq, Eq, Serialize, Deserialize)]
pub struct StoredRef {
    pub id: ArtifactId,
    pub content_hash: String,
}

#[derive(Clone, Debug, PartialEq, Eq, Serialize, Deserialize)]
pub struct Artifact {
    pub row_id: u64,
    pub id: ArtifactId,
    pub org: String,
    pub content_hash: String,
    pub content: Vec<u8>,
    pub content_type: String,
    pub entrypoint: String,
    pub provenance: Value,
}

#[derive(Clone, Debug, Default, PartialEq, Eq)]
pub struct ListFilter {
    pub org: Option<String>,
}

#[derive(Clone, Debug, Default, PartialEq, Eq, Serialize, Deserialize)]
pub struct Page<T> {
    pub items: Vec<T>,
}

#[derive(Clone, Debug, PartialEq, Eq, Serialize, Deserialize)]
pub struct ArtifactSummary {
    pub id: ArtifactId,
    pub org: String,
    pub content_hash: String,
}

/// One row of the admin console list view (spec 8). Deliberately narrower
/// than [`Artifact`] — a list request never loads blob content.
#[derive(Clone, Debug, PartialEq, Eq, Serialize, Deserialize)]
pub struct ArtifactListItem {
    pub id: ArtifactId,
    pub org: String,
    pub content_hash: String,
    pub size_bytes: u64,
    pub agent: Option<String>,
    pub repo_url: Option<String>,
    pub commit_sha: Option<String>,
    pub title: Option<String>,
    pub description: Option<String>,
    pub created_at: String,
    pub revoked_at: Option<String>,
    /// The stored sharing tier (`public`/`secure`/`private`). Kept as the raw
    /// string so this stays a plain data row; visibility decisions go through
    /// `sharing::Sharing::from_tier`.
    pub tier: String,
    /// The artifact owner, `None` for rows published before owners existed.
    pub owner_user_id: Option<String>,
    /// The stored edit access (`view`/`edit`).
    pub edit_access: String,
    /// `COALESCE(updated_at, created_at)`: when the current version was
    /// published. A row whose first upload is still pending carries its
    /// `created_at`.
    pub updated_at: String,
    /// `artifacts.current_version`: the served and indexed version number.
    pub version: i64,
    /// The entrypoint's file type ([`entrypoint_kind`]), `None` for an
    /// extension the list doesn't classify.
    pub kind: Option<String>,
    /// Upload deadline of a pending first upload; `None` once the first
    /// version completed. Carried so [`artifact_matches_filter`] can express
    /// the `live` filter in pure logic exactly as the SQL does.
    pub expires_at: Option<String>,
}

/// Filter predicate for the admin console list (spec 8: "filters that
/// matter... by person, by repo, by agent, by date range"). `org` is always
/// required — every other field is optional and additive (AND semantics).
#[derive(Clone, Debug, Default, PartialEq, Eq)]
pub struct ArtifactListFilter {
    pub org: String,
    pub repo_url: Option<String>,
    pub agent: Option<String>,
    /// Inclusive lower bound on `created_at` (RFC 3339, comparable as text).
    pub created_after: Option<String>,
    /// Inclusive upper bound on `created_at`.
    pub created_before: Option<String>,
    /// Free-text search over title and description.
    pub query: Option<String>,
    /// The calling credential's user id, used only to decide whether a private
    /// artifact belongs to the caller.
    pub viewer_user_id: Option<String>,
    /// True when the viewer may see every private artifact in the org (a team
    /// admin, or a server-side read carrying the private scope).
    pub viewer_reads_private: bool,
    /// Inclusive lower bound on `COALESCE(updated_at, created_at)`.
    pub updated_after: Option<String>,
    /// Inclusive upper bound on `COALESCE(updated_at, created_at)`.
    pub updated_before: Option<String>,
    /// Only artifacts owned by this user id.
    pub owner_user_id: Option<String>,
    /// Only artifacts at this stored tier (`private`/`secure`/`public`). The
    /// API name `team` is mapped to `secure` before it reaches the filter.
    pub sharing: Option<String>,
    /// Case-insensitive substring of the title. A `None` or absent title never
    /// matches.
    pub title_contains: Option<String>,
    /// Only artifacts whose entrypoint is this kind ([`entrypoint_kind`]).
    pub kind: Option<String>,
    /// Restrict to this exact set of permanent artifact ids. An empty list
    /// matches nothing.
    pub ids: Option<Vec<String>>,
    /// When true, revoked artifacts and artifacts whose first upload is still
    /// pending are excluded. Defaults to false (the admin console lists
    /// revoked rows).
    pub live: bool,
    /// The requested order. The cursor is bound to the sort it was issued for.
    pub sort: ListSort,
}

/// The orderings the org artifact list accepts. The cursor is bound to the
/// sort it was issued for; a cursor decoded for another sort is ignored so
/// paging restarts from the beginning.
#[derive(Clone, Copy, Debug, PartialEq, Eq, Default)]
pub enum ListSort {
    /// Oldest first, `(created_at, id)` ascending. The historical default.
    #[default]
    CreatedAsc,
    /// Newest first, `(created_at, id)` descending.
    CreatedDesc,
    /// Newest version first, `(COALESCE(updated_at, created_at), id)`
    /// descending.
    UpdatedDesc,
    /// Title A–Z, `(COALESCE(lower(title), sentinel), id)` ascending, with
    /// untitled rows last.
    TitleAsc,
}

impl ListSort {
    /// Parses the API value (`created_asc`, `created_desc`, `updated_desc`,
    /// `title_asc`). Unknown values return `None` so the route answers 422.
    pub fn parse(value: &str) -> Option<Self> {
        match value {
            "created_asc" => Some(Self::CreatedAsc),
            "created_desc" => Some(Self::CreatedDesc),
            "updated_desc" => Some(Self::UpdatedDesc),
            "title_asc" => Some(Self::TitleAsc),
            _ => None,
        }
    }

    /// The API value, also used as the sort tag inside an encoded cursor.
    pub fn as_str(self) -> &'static str {
        match self {
            Self::CreatedAsc => "created_asc",
            Self::CreatedDesc => "created_desc",
            Self::UpdatedDesc => "updated_desc",
            Self::TitleAsc => "title_asc",
        }
    }

    /// Whether the `(key, id)` total order runs descending. Keeps the keyset
    /// comparison and the `ORDER BY` in agreement about direction.
    fn is_descending(self) -> bool {
        matches!(self, Self::CreatedDesc | Self::UpdatedDesc)
    }
}

/// Sort key for an artifact with no title, chosen so untitled rows sort after
/// every real title. U+10FFFF is the highest Unicode scalar value, so its
/// UTF-8 encoding compares greater than any other string under SQLite's
/// BINARY collation; the SQL `ORDER BY`/keyset use the same literal. A title
/// that itself begins with U+10FFFF ties and falls through to the `id`
/// tiebreaker.
pub const NULL_TITLE_SORT_KEY: &str = "\u{10ffff}";

/// A cursor pagination position. Opaque to callers — see
/// [`encode_list_cursor`]/[`decode_list_cursor`]. `sort` binds the cursor to
/// the ordering it was issued for, `key` is that ordering's first key
/// (`created_at`, `COALESCE(updated_at, created_at)`, or the lowercased
/// title, with the sentinel standing in for a missing title), and `id` is the
/// tiebreaker.
#[derive(Clone, Debug, PartialEq, Eq)]
pub struct ListCursor {
    pub sort: ListSort,
    pub key: String,
    pub id: String,
}

/// Field separator used inside an encoded cursor. Neither an RFC 3339
/// timestamp, an artifact id nor the title sentinel can contain this byte, so
/// the split in `decode_list_cursor` is unambiguous.
const LIST_CURSOR_SEPARATOR: char = '\u{1f}';

/// Encodes a cursor as base64url (no padding) of `sort<US>key<US>id`, the
/// same encoding family the rest of this module already uses for opaque
/// tokens. Deliberately pure — no `Env`, no I/O — so it and its inverse are
/// unit-testable without a live Worker.
pub fn encode_list_cursor(cursor: &ListCursor) -> String {
    use base64::Engine;
    base64::engine::general_purpose::URL_SAFE_NO_PAD.encode(format!(
        "{}{LIST_CURSOR_SEPARATOR}{}{LIST_CURSOR_SEPARATOR}{}",
        cursor.sort.as_str(),
        cursor.key,
        cursor.id
    ))
}

/// Inverse of [`encode_list_cursor`]. Returns `None` for anything that
/// isn't a validly-encoded cursor (wrong base64, unknown sort, missing
/// separator) rather than panicking — a malformed `cursor` query parameter
/// is caller input.
pub fn decode_list_cursor(raw: &str) -> Option<ListCursor> {
    use base64::Engine;
    let bytes = base64::engine::general_purpose::URL_SAFE_NO_PAD
        .decode(raw)
        .ok()?;
    let text = String::from_utf8(bytes).ok()?;
    let mut parts = text.split(LIST_CURSOR_SEPARATOR);
    let sort = ListSort::parse(parts.next()?)?;
    let key = parts.next()?;
    let id = parts.next()?;
    if parts.next().is_some() || key.is_empty() || id.is_empty() {
        return None;
    }
    Some(ListCursor {
        sort,
        key: key.to_string(),
        id: id.to_string(),
    })
}

/// The list's classification of an artifact by its entrypoint's file type,
/// case-insensitively: `html` (`.html`, `.htm`), `markdown` (`.md`,
/// `.markdown`) or `table` (`.csv`, `.tsv`, `.json`). `None` for any other
/// extension. Pure so the SQL `.kind` filter and the response field are held
/// to one definition.
pub fn entrypoint_kind(entrypoint: &str) -> Option<&'static str> {
    let lower = entrypoint.to_ascii_lowercase();
    if lower.ends_with(".html") || lower.ends_with(".htm") {
        Some("html")
    } else if lower.ends_with(".md") || lower.ends_with(".markdown") {
        Some("markdown")
    } else if lower.ends_with(".csv") || lower.ends_with(".tsv") || lower.ends_with(".json") {
        Some("table")
    } else {
        None
    }
}

/// Pure filter-predicate matching, factored out of SQL `WHERE`-clause
/// construction so filter correctness (spec 8 DoD: "filtering by `repo_url`
/// returns only artifacts from that repo", same for `agent` and date range)
/// is unit-testable without D1. The real D1-backed list query
/// (`D1R2ArtifactStore::list_org_artifacts`) applies the equivalent
/// conditions in SQL for performance (indexed columns, no full scan); this
/// function is the single specification of what "matches" means that both
/// the SQL and the tests are held to.
pub fn artifact_matches_filter(item: &ArtifactListItem, filter: &ArtifactListFilter) -> bool {
    if item.org != filter.org {
        return false;
    }
    // Sharing is part of "matches": a private row belongs only to its owner,
    // and to a viewer with org-wide private reads. Decided with the same
    // `Sharing::from_tier` every other read path uses, so an unrecognised
    // stored tier counts as private rather than leaking.
    if !crate::sharing::can_view_listing(
        crate::sharing::Sharing::from_tier(&item.tier),
        item.owner_user_id.as_deref(),
        filter.viewer_user_id.as_deref(),
        filter.viewer_reads_private,
    ) {
        return false;
    }
    if let Some(repo_url) = &filter.repo_url {
        if item.repo_url.as_deref() != Some(repo_url.as_str()) {
            return false;
        }
    }
    if let Some(agent) = &filter.agent {
        if item.agent.as_deref() != Some(agent.as_str()) {
            return false;
        }
    }
    if let Some(after) = &filter.created_after {
        if item.created_at.as_str() < after.as_str() {
            return false;
        }
    }
    if let Some(before) = &filter.created_before {
        if item.created_at.as_str() > before.as_str() {
            return false;
        }
    }
    if let Some(after) = &filter.updated_after {
        if item.updated_at.as_str() < after.as_str() {
            return false;
        }
    }
    if let Some(before) = &filter.updated_before {
        if item.updated_at.as_str() > before.as_str() {
            return false;
        }
    }
    if let Some(owner) = &filter.owner_user_id {
        if item.owner_user_id.as_deref() != Some(owner.as_str()) {
            return false;
        }
    }
    if let Some(sharing) = &filter.sharing {
        // The stored tier, compared literally: the route has already mapped the
        // API name `team` to `secure`.
        if item.tier != *sharing {
            return false;
        }
    }
    if let Some(needle) = &filter.title_contains {
        // ASCII-only lowercasing, matching SQLite's built-in `lower()` that
        // the SQL filter uses.
        let needle = needle.to_ascii_lowercase();
        let found = item
            .title
            .as_deref()
            .map(|title| title.to_ascii_lowercase().contains(&needle))
            .unwrap_or(false);
        if !found {
            return false;
        }
    }
    if let Some(kind) = &filter.kind {
        if item.kind.as_deref() != Some(kind.as_str()) {
            return false;
        }
    }
    if let Some(ids) = &filter.ids {
        if !ids.iter().any(|id| id == &item.id.0) {
            return false;
        }
    }
    if filter.live && (item.revoked_at.is_some() || item.expires_at.is_some()) {
        return false;
    }
    true
}

/// Whether an artifact revoked at `revoked_at` should still be served.
/// Trivial, but named and tested on its own so the "revoke stops serving"
/// guarantee has one call site to audit rather than an inline `is_some()`
/// scattered across serving paths.
pub fn artifact_is_revoked(revoked_at: Option<&str>) -> bool {
    revoked_at.is_some()
}

/// The first sort key of `item` for `sort`, matching the SQL `ORDER BY`
/// expression exactly. Titles are lowercased ASCII-only because SQLite's
/// `lower()` is, and an untitled row uses [`NULL_TITLE_SORT_KEY`].
fn list_sort_key(item: &ArtifactListItem, sort: ListSort) -> String {
    match sort {
        ListSort::CreatedAsc | ListSort::CreatedDesc => item.created_at.clone(),
        ListSort::UpdatedDesc => item.updated_at.clone(),
        ListSort::TitleAsc => item
            .title
            .as_deref()
            .map(str::to_ascii_lowercase)
            .unwrap_or_else(|| NULL_TITLE_SORT_KEY.to_string()),
    }
}

/// Total order used for cursor pagination, matching the SQL `ORDER BY` for
/// `sort` including the direction of the `id` tiebreaker.
fn compare_list_items(
    left: &ArtifactListItem,
    right: &ArtifactListItem,
    sort: ListSort,
) -> std::cmp::Ordering {
    let order = list_sort_key(left, sort).cmp(&list_sort_key(right, sort));
    let order = if sort.is_descending() {
        order.reverse()
    } else {
        order
    };
    let id_order = left.id.0.cmp(&right.id.0);
    let id_order = if sort.is_descending() {
        id_order.reverse()
    } else {
        id_order
    };
    order.then(id_order)
}

/// Whether `item` sorts strictly after `cursor` under the cursor's own sort.
/// The keyset comparison mirrors the SQL `WHERE` clause bound to the cursor,
/// including the direction of both the key and the `id` tiebreaker.
fn list_item_after_cursor(item: &ArtifactListItem, cursor: &ListCursor) -> bool {
    use std::cmp::Ordering;
    let order = list_sort_key(item, cursor.sort)
        .as_str()
        .cmp(cursor.key.as_str());
    let order = if cursor.sort.is_descending() {
        order.reverse()
    } else {
        order
    };
    match order {
        Ordering::Greater => true,
        Ordering::Less => false,
        Ordering::Equal => {
            let id_order = item.id.0.as_str().cmp(cursor.id.as_str());
            let id_order = if cursor.sort.is_descending() {
                id_order.reverse()
            } else {
                id_order
            };
            id_order == Ordering::Greater
        }
    }
}

/// The next-page cursor for the last item of a page under `sort`.
pub fn cursor_for_item(item: &ArtifactListItem, sort: ListSort) -> ListCursor {
    ListCursor {
        sort,
        key: list_sort_key(item, sort),
        id: item.id.0.clone(),
    }
}

/// Sorts `items` by `sort` and returns the page starting just after `cursor`
/// (or the first page, if `cursor` is `None` or was issued for another sort),
/// plus the cursor for the next page (`None` once the last page is reached).
/// This is the page-boundary arithmetic the cursor tests exercise directly
/// against synthetic fixtures — no D1 needed to prove pages neither skip nor
/// repeat rows. `D1R2ArtifactStore::list_org_artifacts` performs the
/// equivalent sort and slice in SQL for the live path; this is the
/// specification it's built against.
pub fn paginate_sorted(
    items: &[ArtifactListItem],
    sort: ListSort,
    cursor: Option<&ListCursor>,
    page_size: usize,
) -> (Vec<ArtifactListItem>, Option<ListCursor>) {
    let mut sorted = items.to_vec();
    sorted.sort_by(|left, right| compare_list_items(left, right, sort));
    let start = match cursor {
        // A cursor from another sort is ignored: restart from the beginning.
        Some(cursor) if cursor.sort == sort => sorted
            .iter()
            .position(|item| list_item_after_cursor(item, cursor))
            .unwrap_or(sorted.len()),
        _ => 0,
    };
    let end = sorted.len().min(start.saturating_add(page_size));
    let page = sorted[start..end].to_vec();
    let next_cursor = if end < sorted.len() {
        page.last().map(|item| cursor_for_item(item, sort))
    } else {
        None
    };
    (page, next_cursor)
}

#[derive(Debug, Clone, PartialEq, Eq)]
pub enum StoreError {
    Unsupported(&'static str),
    InvalidSlug(String),
    MissingArtifact,
    Contention,
    Backend(String),
}

impl fmt::Display for StoreError {
    fn fmt(&self, formatter: &mut fmt::Formatter<'_>) -> fmt::Result {
        match self {
            Self::Unsupported(operation) => write!(formatter, "{operation} is not supported"),
            Self::InvalidSlug(message) => formatter.write_str(message),
            Self::MissingArtifact => formatter.write_str("artifact not found"),
            Self::Contention => formatter.write_str("content is busy; retry the operation"),
            Self::Backend(message) => formatter.write_str(message),
        }
    }
}

impl std::error::Error for StoreError {}

#[allow(async_fn_in_trait)]
pub trait ArtifactStore {
    async fn put(&self, artifact: NewArtifact) -> Result<StoredRef, StoreError>;
    async fn get(&self, id: &ArtifactId) -> Result<Option<Artifact>, StoreError>;
    async fn delete(&self, id: &ArtifactId) -> Result<(), StoreError>;
    async fn list(&self, filter: ListFilter) -> Result<Page<ArtifactSummary>, StoreError>;
}

/// ArtifactStore implementation backed by Workers KV for ephemeral records.
pub struct KvArtifactStore {
    kv: Option<KvStore>,
}

impl KvArtifactStore {
    pub fn new(kv: KvStore) -> Self {
        Self { kv: Some(kv) }
    }

    pub fn without_binding() -> Self {
        Self { kv: None }
    }

    pub async fn put_record(
        &self,
        key: &str,
        value: &str,
        ttl_seconds: u64,
    ) -> Result<(), StoreError> {
        let Some(kv) = &self.kv else {
            return Err(StoreError::Unsupported("binding"));
        };
        kv.put(key, value.to_string())
            .map_err(|error| StoreError::Backend(error.to_string()))?
            .expiration_ttl(ttl_seconds)
            .execute()
            .await
            .map_err(|error| StoreError::Backend(error.to_string()))
    }

    pub async fn get_record<T: DeserializeOwned>(
        &self,
        key: &str,
    ) -> Result<Option<T>, StoreError> {
        let Some(kv) = &self.kv else {
            return Err(StoreError::Unsupported("binding"));
        };
        kv.get(key)
            .json()
            .await
            .map_err(|error| StoreError::Backend(error.to_string()))
    }

    pub async fn delete_record(&self, key: &str) -> Result<(), StoreError> {
        let Some(kv) = &self.kv else {
            return Err(StoreError::Unsupported("binding"));
        };
        kv.delete(key)
            .await
            .map_err(|error| StoreError::Backend(error.to_string()))
    }
}

impl ArtifactStore for KvArtifactStore {
    async fn put(&self, artifact: NewArtifact) -> Result<StoredRef, StoreError> {
        let Some(kv) = &self.kv else {
            return Err(StoreError::Unsupported("binding"));
        };
        validate_slug(&artifact.org).map_err(StoreError::InvalidSlug)?;
        let hash = content_hash(&artifact.content);
        let id = ArtifactId(public_id(&artifact.org, &hash));
        let stored = Artifact {
            row_id: 0,
            id: id.clone(),
            org: artifact.org,
            content_hash: hash.clone(),
            content: artifact.content,
            content_type: artifact.content_type,
            entrypoint: artifact.entrypoint,
            provenance: artifact.provenance,
        };
        kv.put(
            &id.0,
            serde_json::to_string(&stored)
                .map_err(|error| StoreError::Backend(error.to_string()))?,
        )
        .map_err(|error| StoreError::Backend(error.to_string()))?
        .execute()
        .await
        .map_err(|error| StoreError::Backend(error.to_string()))?;
        Ok(StoredRef {
            id,
            content_hash: hash,
        })
    }

    async fn get(&self, id: &ArtifactId) -> Result<Option<Artifact>, StoreError> {
        let Some(kv) = &self.kv else {
            return Err(StoreError::Unsupported("binding"));
        };
        kv.get(&id.0)
            .json()
            .await
            .map_err(|error| StoreError::Backend(error.to_string()))
    }

    async fn delete(&self, id: &ArtifactId) -> Result<(), StoreError> {
        let Some(kv) = &self.kv else {
            return Err(StoreError::Unsupported("binding"));
        };
        kv.delete(&id.0)
            .await
            .map_err(|error| StoreError::Backend(error.to_string()))
    }

    async fn list(&self, _filter: ListFilter) -> Result<Page<ArtifactSummary>, StoreError> {
        Err(StoreError::Unsupported("list"))
    }
}

/// ArtifactStore implementation backed by D1 metadata and R2 content blobs.
pub struct D1R2ArtifactStore {
    pub(crate) database: D1Database,
    pub(crate) bucket: Bucket,
}

impl D1R2ArtifactStore {
    pub fn new(database: D1Database, bucket: Bucket) -> Self {
        Self { database, bucket }
    }

    pub async fn acquire_content_lock(&self, content_hash: &str) -> Result<String, StoreError> {
        let owner = Uuid::new_v4().simple().to_string();
        for attempt in 0..100 {
            let expires_at = (Utc::now() + chrono::Duration::minutes(15)).to_rfc3339();
            let now = Utc::now().to_rfc3339();
            self.database
                .batch(vec![
                    self.database
                        .prepare("INSERT OR IGNORE INTO blob_locks (content_hash, owner, expires_at) VALUES (?, ?, ?)")
                        .bind(&[
                            worker::wasm_bindgen::JsValue::from_str(content_hash),
                            worker::wasm_bindgen::JsValue::from_str(&owner),
                            worker::wasm_bindgen::JsValue::from_str(&expires_at),
                        ])
                        .map_err(|error| StoreError::Backend(error.to_string()))?,
                    self.database
                        .prepare("UPDATE blob_locks SET owner = ?, expires_at = ? WHERE content_hash = ? AND (expires_at <= ? OR owner = ?)")
                        .bind(&[
                            worker::wasm_bindgen::JsValue::from_str(&owner),
                            worker::wasm_bindgen::JsValue::from_str(&expires_at),
                            worker::wasm_bindgen::JsValue::from_str(content_hash),
                            worker::wasm_bindgen::JsValue::from_str(&now),
                            worker::wasm_bindgen::JsValue::from_str(&owner),
                        ])
                        .map_err(|error| StoreError::Backend(error.to_string()))?,
                ])
                .await
                .map_err(|error| StoreError::Backend(error.to_string()))?;
            let held_statement = match self
                .database
                .prepare("SELECT owner FROM blob_locks WHERE content_hash = ?")
                .bind(&[worker::wasm_bindgen::JsValue::from_str(content_hash)])
            {
                Ok(statement) => statement,
                Err(error) => {
                    let _ = self.release_content_lock(content_hash, &owner).await;
                    return Err(StoreError::Backend(error.to_string()));
                }
            };
            let held = match held_statement.first::<LockOwnerRow>(None).await {
                Ok(value) => value,
                Err(error) => {
                    let _ = self.release_content_lock(content_hash, &owner).await;
                    return Err(StoreError::Backend(error.to_string()));
                }
            };
            if held.is_some_and(|value| value.owner == owner) {
                return Ok(owner);
            }
            if attempt < 99 {
                Delay::from(Duration::from_millis(25)).await;
            }
        }
        Err(StoreError::Contention)
    }

    pub async fn release_content_lock(
        &self,
        content_hash: &str,
        owner: &str,
    ) -> Result<(), StoreError> {
        self.database
            .prepare("DELETE FROM blob_locks WHERE content_hash = ? AND owner = ?")
            .bind(&[
                worker::wasm_bindgen::JsValue::from_str(content_hash),
                worker::wasm_bindgen::JsValue::from_str(owner),
            ])
            .map_err(|error| StoreError::Backend(error.to_string()))?
            .run()
            .await
            .map_err(|error| StoreError::Backend(error.to_string()))?;
        Ok(())
    }

    pub async fn acquire_content_locks(
        &self,
        content_hashes: &[String],
    ) -> Result<Vec<(String, String)>, StoreError> {
        let mut hashes = content_hashes.to_vec();
        hashes.sort();
        hashes.dedup();
        let mut held = Vec::with_capacity(hashes.len());
        for hash in hashes {
            match self.acquire_content_lock(&hash).await {
                Ok(owner) => held.push((hash, owner)),
                Err(error) => {
                    for (held_hash, owner) in &held {
                        let _ = self.release_content_lock(held_hash, owner).await;
                    }
                    return Err(error);
                }
            }
        }
        Ok(held)
    }

    pub async fn release_content_locks(
        &self,
        locks: &[(String, String)],
    ) -> Result<(), StoreError> {
        let mut first_error = None;
        for (hash, owner) in locks {
            if let Err(error) = self.release_content_lock(hash, owner).await {
                if first_error.is_none() {
                    first_error = Some(error);
                }
            }
        }
        first_error.map_or(Ok(()), Err)
    }

    pub async fn blob_exists(&self, content_hash: &str) -> Result<bool, StoreError> {
        Ok(self
            .bucket
            .head(format!("blobs/{content_hash}"))
            .await
            .map_err(|error| StoreError::Backend(error.to_string()))?
            .is_some())
    }

    pub async fn execute_batch(
        &self,
        statements: Vec<worker::d1::D1PreparedStatement>,
    ) -> Result<(), StoreError> {
        self.database
            .batch(statements)
            .await
            .map_err(|error| StoreError::Backend(error.to_string()))?;
        Ok(())
    }

    /// Admin console list (spec 8). Filters and paginates in SQL against the
    /// indexed `(org_id, created_at, id)` column set added by
    /// `0002_revocation.sql`, plus provenance's `agent`/`repo_url` — bounded
    /// page size, no N+1 query per row (one query fetches the page and its
    /// provenance/size columns via joins/a correlated subquery). See
    /// `ArtifactListFilter`/`paginate_sorted` in this module for the pure
    /// specification this SQL is built to match; that specification is what
    /// the unit tests exercise, since this method itself needs a live D1.
    pub async fn list_org_artifacts(
        &self,
        filter: &ArtifactListFilter,
        cursor: Option<&ListCursor>,
        page_size: usize,
    ) -> Result<(Vec<ArtifactListItem>, Option<ListCursor>), StoreError> {
        let mut clauses = vec!["o.slug = ?".to_string()];
        let mut binds = vec![worker::wasm_bindgen::JsValue::from_str(&filter.org)];
        // Private rows are filtered in SQL so a cursor over the filtered set
        // never points past a hidden row and skips visible ones. When the
        // viewer may read every private artifact the clause is omitted.
        if !filter.viewer_reads_private {
            clauses.push("(a.tier IN ('public', 'secure') OR a.user_id = ?)".to_string());
            binds.push(worker::wasm_bindgen::JsValue::from_str(
                filter.viewer_user_id.as_deref().unwrap_or(""),
            ));
        }
        if let Some(repo_url) = &filter.repo_url {
            clauses.push("p.repo_url = ?".to_string());
            binds.push(worker::wasm_bindgen::JsValue::from_str(repo_url));
        }
        if let Some(agent) = &filter.agent {
            clauses.push("p.agent = ?".to_string());
            binds.push(worker::wasm_bindgen::JsValue::from_str(agent));
        }
        if let Some(after) = &filter.created_after {
            clauses.push("a.created_at >= ?".to_string());
            binds.push(worker::wasm_bindgen::JsValue::from_str(after));
        }
        if let Some(before) = &filter.created_before {
            clauses.push("a.created_at <= ?".to_string());
            binds.push(worker::wasm_bindgen::JsValue::from_str(before));
        }
        if let Some(query) = &filter.query {
            clauses.push(
                "(a.title LIKE ? ESCAPE '\\' OR a.description LIKE ? ESCAPE '\\')".to_string(),
            );
            let pattern = like_pattern(query);
            binds.push(worker::wasm_bindgen::JsValue::from_str(&pattern));
            binds.push(worker::wasm_bindgen::JsValue::from_str(&pattern));
        }
        if let Some(after) = &filter.updated_after {
            clauses.push("COALESCE(a.updated_at, a.created_at) >= ?".to_string());
            binds.push(worker::wasm_bindgen::JsValue::from_str(after));
        }
        if let Some(before) = &filter.updated_before {
            clauses.push("COALESCE(a.updated_at, a.created_at) <= ?".to_string());
            binds.push(worker::wasm_bindgen::JsValue::from_str(before));
        }
        if let Some(owner) = &filter.owner_user_id {
            clauses.push("a.user_id = ?".to_string());
            binds.push(worker::wasm_bindgen::JsValue::from_str(owner));
        }
        if let Some(sharing) = &filter.sharing {
            clauses.push("a.tier = ?".to_string());
            binds.push(worker::wasm_bindgen::JsValue::from_str(sharing));
        }
        if let Some(needle) = &filter.title_contains {
            // ASCII-only lowercasing matches SQLite's built-in `lower()`,
            // which `entrypoint_kind` and the pure filter also mirror.
            clauses.push("lower(a.title) LIKE ? ESCAPE '\\'".to_string());
            let pattern = like_pattern(&needle.to_ascii_lowercase());
            binds.push(worker::wasm_bindgen::JsValue::from_str(&pattern));
        }
        if let Some(kind) = &filter.kind {
            let patterns = kind_like_patterns(kind);
            if patterns.is_empty() {
                // An unknown kind matches nothing rather than producing an
                // empty `()` clause; the route rejects it with 422 first.
                clauses.push("0".to_string());
            } else {
                let comparisons = patterns
                    .iter()
                    .map(|_| "lower(a.entrypoint) LIKE ? ESCAPE '\\'")
                    .collect::<Vec<_>>()
                    .join(" OR ");
                clauses.push(format!("({comparisons})"));
                for pattern in patterns {
                    binds.push(worker::wasm_bindgen::JsValue::from_str(pattern));
                }
            }
        }
        if let Some(ids) = &filter.ids {
            // A JSON array through `json_each` keeps the bind count fixed at
            // one regardless of how many of the (at most 200) ids are given.
            clauses.push("a.id IN (SELECT value FROM json_each(?))".to_string());
            binds.push(worker::wasm_bindgen::JsValue::from_str(
                &serde_json::Value::from(ids.clone()).to_string(),
            ));
        }
        if filter.live {
            clauses.push("(a.revoked_at IS NULL AND a.expires_at IS NULL)".to_string());
        }
        // A cursor is only meaningful for the sort it was issued for. A
        // cursor from another sort is ignored so paging restarts, matching
        // `paginate_sorted`.
        if let Some(cursor) = cursor.filter(|cursor| cursor.sort == filter.sort) {
            match filter.sort {
                ListSort::CreatedAsc => {
                    clauses
                        .push("(a.created_at > ? OR (a.created_at = ? AND a.id > ?))".to_string());
                }
                ListSort::CreatedDesc => {
                    clauses
                        .push("(a.created_at < ? OR (a.created_at = ? AND a.id < ?))".to_string());
                }
                ListSort::UpdatedDesc => {
                    clauses.push(
                        "(COALESCE(a.updated_at, a.created_at) < ? OR \
                         (COALESCE(a.updated_at, a.created_at) = ? AND a.id < ?))"
                            .to_string(),
                    );
                }
                ListSort::TitleAsc => {
                    clauses.push(format!(
                        "(COALESCE(lower(a.title), '{NULL_TITLE_SORT_KEY}') > ? OR \
                         (COALESCE(lower(a.title), '{NULL_TITLE_SORT_KEY}') = ? AND a.id > ?))"
                    ));
                }
            }
            binds.push(worker::wasm_bindgen::JsValue::from_str(&cursor.key));
            binds.push(worker::wasm_bindgen::JsValue::from_str(&cursor.key));
            binds.push(worker::wasm_bindgen::JsValue::from_str(&cursor.id));
        }
        // Fetch one extra row so presence of a next page is known without a
        // second COUNT query.
        let fetch_limit = page_size as f64 + 1.0;
        let order_by = match filter.sort {
            ListSort::CreatedAsc => "a.created_at ASC, a.id ASC".to_string(),
            ListSort::CreatedDesc => "a.created_at DESC, a.id DESC".to_string(),
            ListSort::UpdatedDesc => {
                "COALESCE(a.updated_at, a.created_at) DESC, a.id DESC".to_string()
            }
            // The sentinel is a compile-time constant with no caller input,
            // so embedding it in the SQL text is safe; the keyset clause
            // above uses the same literal.
            ListSort::TitleAsc => {
                format!("COALESCE(lower(a.title), '{NULL_TITLE_SORT_KEY}') ASC, a.id ASC")
            }
        };
        let query = format!(
            "SELECT a.id, o.slug AS org, a.content_hash, a.created_at, a.revoked_at, \
             p.agent, p.repo_url, p.commit_sha, a.title, a.description, \
             a.tier, a.user_id, a.edit_access, a.entrypoint, a.expires_at, \
             a.current_version, COALESCE(a.updated_at, a.created_at) AS updated_at, \
             (SELECT COALESCE(SUM(f.size_bytes), 0) FROM files f WHERE f.artifact_row_id = a.row_id) AS size_bytes \
             FROM artifacts a \
             JOIN orgs o ON o.id = a.org_id \
             LEFT JOIN provenance p ON p.artifact_row_id = a.row_id \
             WHERE {} \
             ORDER BY {} \
             LIMIT ?",
            clauses.join(" AND "),
            order_by
        );
        binds.push(worker::wasm_bindgen::JsValue::from_f64(fetch_limit));
        let mut items = self
            .database
            .prepare(&query)
            .bind(&binds)
            .map_err(|error| StoreError::Backend(error.to_string()))?
            .all()
            .await
            .map_err(|error| StoreError::Backend(error.to_string()))?
            .results::<D1ListRow>()
            .map_err(|error| StoreError::Backend(error.to_string()))?
            .into_iter()
            .map(ArtifactListItem::from)
            .collect::<Vec<_>>();
        let next_cursor = if items.len() > page_size {
            items.truncate(page_size);
            items.last().map(|item| cursor_for_item(item, filter.sort))
        } else {
            None
        };
        Ok((items, next_cursor))
    }

    /// Revocation (spec 8): a soft delete. Sets `revoked_at` if unset —
    /// idempotent, a second revoke of an already-revoked artifact is a
    /// no-op rather than an error — and never touches `artifacts`' other
    /// columns or the `blobs`/`files` rows, so the row and blob are
    /// retained exactly as spec 8's "Revocation" section requires. Returns
    /// the updated row so the caller can hand the console the fresh
    /// `revoked_at`, or `None` if no artifact with that id exists in this
    /// org (the caller maps that to 404).
    pub async fn revoke_artifact(
        &self,
        org: &str,
        id: &ArtifactId,
    ) -> Result<Option<ArtifactListItem>, StoreError> {
        let now = Utc::now().to_rfc3339();
        self.database
            .prepare(
                "UPDATE artifacts SET revoked_at = COALESCE(revoked_at, ?) \
                 WHERE id = ? AND org_id = (SELECT id FROM orgs WHERE slug = ? LIMIT 1)",
            )
            .bind(&[
                worker::wasm_bindgen::JsValue::from_str(&now),
                worker::wasm_bindgen::JsValue::from_str(&id.0),
                worker::wasm_bindgen::JsValue::from_str(org),
            ])
            .map_err(|error| StoreError::Backend(error.to_string()))?
            .run()
            .await
            .map_err(|error| StoreError::Backend(error.to_string()))?;
        let row = self
            .database
            .prepare(
                "SELECT a.id, o.slug AS org, a.content_hash, a.created_at, a.revoked_at, \
                 p.agent, p.repo_url, p.commit_sha, a.tier, a.user_id, a.edit_access, \
                 a.entrypoint, a.expires_at, a.current_version, \
                 COALESCE(a.updated_at, a.created_at) AS updated_at, \
                 (SELECT COALESCE(SUM(f.size_bytes), 0) FROM files f WHERE f.artifact_row_id = a.row_id) AS size_bytes \
                 FROM artifacts a \
                 JOIN orgs o ON o.id = a.org_id \
                 LEFT JOIN provenance p ON p.artifact_row_id = a.row_id \
                 WHERE a.id = ? AND o.slug = ? ORDER BY a.row_id LIMIT 1",
            )
            .bind(&[
                worker::wasm_bindgen::JsValue::from_str(&id.0),
                worker::wasm_bindgen::JsValue::from_str(org),
            ])
            .map_err(|error| StoreError::Backend(error.to_string()))?
            .first::<D1ListRow>(None)
            .await
            .map_err(|error| StoreError::Backend(error.to_string()))?;
        Ok(row.map(ArtifactListItem::from))
    }
}

impl ArtifactStore for D1R2ArtifactStore {
    async fn put(&self, artifact: NewArtifact) -> Result<StoredRef, StoreError> {
        validate_slug(&artifact.org).map_err(StoreError::InvalidSlug)?;
        let hash = content_hash(&artifact.content);
        let id = ArtifactId(public_id(&artifact.org, &hash));
        let row_id = Uuid::new_v4().simple().to_string();
        let size_bytes = artifact.content.len();
        let now = Utc::now().to_rfc3339();
        let agent = artifact.provenance.get("agent").and_then(Value::as_str);
        let repo_url = artifact.provenance.get("repo_url").and_then(Value::as_str);
        let commit_sha = artifact
            .provenance
            .get("commit_sha")
            .and_then(Value::as_str);
        let manifest = serde_json::json!({
            "entrypoint": artifact.entrypoint.clone(),
            "files": [{
                "path": artifact.entrypoint.clone(),
                "content_type": artifact.content_type.clone(),
                "size_bytes": size_bytes,
                "sha256": hash,
            }],
            "external_origins": [],
        });
        let lock_owner = self.acquire_content_lock(&hash).await?;
        let operation: Result<(), StoreError> = async {
            self.bucket
                .put(format!("blobs/{hash}"), artifact.content)
                .http_metadata(worker::HttpMetadata {
                    content_type: Some(artifact.content_type.clone()),
                    ..Default::default()
                })
                .execute()
                .await
                .map_err(|error| StoreError::Backend(error.to_string()))?;
            self.database
            .batch(vec![
                self.database
                    .prepare("INSERT OR IGNORE INTO orgs (id, slug, created_at) VALUES (?, ?, ?)")
                    .bind(&[worker::wasm_bindgen::JsValue::from_str(&artifact.org), worker::wasm_bindgen::JsValue::from_str(&artifact.org), worker::wasm_bindgen::JsValue::from_str(&now)])
                    .map_err(|error| StoreError::Backend(error.to_string()))?,
                self.database
                    .prepare("INSERT OR IGNORE INTO blobs (content_hash, size_bytes, content_type, ref_count, created_at) VALUES (?, ?, ?, 0, ?)")
                    .bind(&[worker::wasm_bindgen::JsValue::from_str(&hash), worker::wasm_bindgen::JsValue::from_f64(size_bytes as f64), worker::wasm_bindgen::JsValue::from_str(&artifact.content_type), worker::wasm_bindgen::JsValue::from_str(&now)])
                    .map_err(|error| StoreError::Backend(error.to_string()))?,
                self.database
                    .prepare("UPDATE blobs SET ref_count = ref_count + 1 WHERE content_hash = ?")
                    .bind(&[worker::wasm_bindgen::JsValue::from_str(&hash)])
                    .map_err(|error| StoreError::Backend(error.to_string()))?,
                self.database
                    .prepare("INSERT INTO artifacts (row_id, id, org_id, content_hash, entrypoint, created_at, manifest) VALUES (?, ?, ?, ?, ?, ?, ?)")
                    .bind(&[worker::wasm_bindgen::JsValue::from_str(&row_id), worker::wasm_bindgen::JsValue::from_str(&id.0), worker::wasm_bindgen::JsValue::from_str(&artifact.org), worker::wasm_bindgen::JsValue::from_str(&hash), worker::wasm_bindgen::JsValue::from_str(&artifact.entrypoint), worker::wasm_bindgen::JsValue::from_str(&now), worker::wasm_bindgen::JsValue::from_str(&manifest.to_string())])
                    .map_err(|error| StoreError::Backend(error.to_string()))?,
                self.database
                    .prepare("INSERT INTO provenance (artifact_row_id, agent, repo_url, commit_sha, payload) VALUES (?, ?, ?, ?, ?)")
                    .bind(&[
                        worker::wasm_bindgen::JsValue::from_str(&row_id),
                        agent.map(worker::wasm_bindgen::JsValue::from_str).unwrap_or_else(worker::wasm_bindgen::JsValue::null),
                        repo_url.map(worker::wasm_bindgen::JsValue::from_str).unwrap_or_else(worker::wasm_bindgen::JsValue::null),
                        commit_sha.map(worker::wasm_bindgen::JsValue::from_str).unwrap_or_else(worker::wasm_bindgen::JsValue::null),
                        worker::wasm_bindgen::JsValue::from_str(&artifact.provenance.to_string()),
                    ])
                    .map_err(|error| StoreError::Backend(error.to_string()))?,
                self.database
                    .prepare("INSERT INTO files (artifact_row_id, path, content_hash, content_type, size_bytes) VALUES (?, ?, ?, ?, ?)")
                    .bind(&[
                        worker::wasm_bindgen::JsValue::from_str(&row_id),
                        worker::wasm_bindgen::JsValue::from_str(&artifact.entrypoint),
                        worker::wasm_bindgen::JsValue::from_str(&hash),
                        worker::wasm_bindgen::JsValue::from_str(&artifact.content_type),
                        worker::wasm_bindgen::JsValue::from_f64(size_bytes as f64),
                    ])
                    .map_err(|error| StoreError::Backend(error.to_string()))?,
                ])
                .await
                .map_err(|error| StoreError::Backend(error.to_string()))?;
            Ok(())
        }
        .await;
        let release_result = self.release_content_lock(&hash, &lock_owner).await;
        operation?;
        release_result?;
        Ok(StoredRef {
            id,
            content_hash: hash,
        })
    }

    async fn get(&self, id: &ArtifactId) -> Result<Option<Artifact>, StoreError> {
        let row = self
            .database
            .prepare("SELECT a.org_id AS org, a.content_hash, f.content_hash AS file_hash, a.entrypoint, f.content_type, p.payload FROM artifacts a JOIN files f ON f.artifact_row_id = a.row_id AND f.path = a.entrypoint JOIN provenance p ON p.artifact_row_id = a.row_id WHERE a.id = ? ORDER BY a.row_id LIMIT 1")
            .bind(&[worker::wasm_bindgen::JsValue::from_str(&id.0)])
            .map_err(|error| StoreError::Backend(error.to_string()))?
            .first::<D1ArtifactRow>(None)
            .await
            .map_err(|error| StoreError::Backend(error.to_string()))?;
        let Some(row) = row else { return Ok(None) };
        let object = self
            .bucket
            .get(format!("blobs/{}", row.file_hash))
            .execute()
            .await
            .map_err(|error| StoreError::Backend(error.to_string()))?;
        let Some(object) = object else {
            return Ok(None);
        };
        let Some(body) = object.body() else {
            return Ok(None);
        };
        let content = body
            .bytes()
            .await
            .map_err(|error| StoreError::Backend(error.to_string()))?;
        Ok(Some(Artifact {
            row_id: 0,
            id: id.clone(),
            org: row.org,
            content_hash: row.content_hash,
            content,
            content_type: row.content_type,
            entrypoint: row.entrypoint,
            provenance: serde_json::from_str(&row.payload)
                .map_err(|error| StoreError::Backend(error.to_string()))?,
        }))
    }

    async fn delete(&self, id: &ArtifactId) -> Result<(), StoreError> {
        let row = self
            .database
            .prepare("SELECT row_id, manifest FROM artifacts WHERE id = ? ORDER BY row_id LIMIT 1")
            .bind(&[worker::wasm_bindgen::JsValue::from_str(&id.0)])
            .map_err(|error| StoreError::Backend(error.to_string()))?
            .first::<D1DeleteRow>(None)
            .await
            .map_err(|error| StoreError::Backend(error.to_string()))?;
        let Some(row) = row else {
            return Err(StoreError::MissingArtifact);
        };
        let manifest: serde_json::Value = serde_json::from_str(&row.manifest)
            .map_err(|error| StoreError::Backend(error.to_string()))?;
        let hashes = manifest
            .get("files")
            .and_then(Value::as_array)
            .map(|files| {
                files
                    .iter()
                    .filter_map(|file| file.get("sha256").and_then(Value::as_str))
                    .map(str::to_string)
                    .collect::<Vec<_>>()
            })
            .unwrap_or_default();
        let locks = self.acquire_content_locks(&hashes).await?;
        let operation: Result<(), StoreError> = async {
            self.database
                .prepare("DELETE FROM artifacts WHERE row_id = ?")
                .bind(&[worker::wasm_bindgen::JsValue::from_str(&row.row_id)])
                .map_err(|error| StoreError::Backend(error.to_string()))?
                .run()
                .await
                .map_err(|error| StoreError::Backend(error.to_string()))?;
            let files = manifest
                .get("files")
                .and_then(Value::as_array)
                .cloned()
                .unwrap_or_default();
            for file in files {
                let Some(hash) = file.get("sha256").and_then(Value::as_str) else {
                    continue;
                };
                self.database
                    .prepare(
                        "UPDATE blobs SET ref_count = MAX(ref_count - 1, 0) WHERE content_hash = ?",
                    )
                    .bind(&[worker::wasm_bindgen::JsValue::from_str(hash)])
                    .map_err(|error| StoreError::Backend(error.to_string()))?
                    .run()
                    .await
                    .map_err(|error| StoreError::Backend(error.to_string()))?;
                let refs = self
                    .database
                    .prepare("SELECT ref_count AS count FROM blobs WHERE content_hash = ?")
                    .bind(&[worker::wasm_bindgen::JsValue::from_str(hash)])
                    .map_err(|error| StoreError::Backend(error.to_string()))?
                    .first::<D1CountRow>(None)
                    .await
                    .map_err(|error| StoreError::Backend(error.to_string()))?
                    .map(|value| value.count)
                    .unwrap_or(0);
                if refs == 0 {
                    self.database
                        .prepare("DELETE FROM blobs WHERE content_hash = ?")
                        .bind(&[worker::wasm_bindgen::JsValue::from_str(hash)])
                        .map_err(|error| StoreError::Backend(error.to_string()))?
                        .run()
                        .await
                        .map_err(|error| StoreError::Backend(error.to_string()))?;
                    self.bucket
                        .delete(format!("blobs/{hash}"))
                        .await
                        .map_err(|error| StoreError::Backend(error.to_string()))?;
                }
            }
            Ok(())
        }
        .await;
        let release_result = self.release_content_locks(&locks).await;
        operation?;
        release_result?;
        Ok(())
    }

    async fn list(&self, filter: ListFilter) -> Result<Page<ArtifactSummary>, StoreError> {
        let mut query = "SELECT a.id, o.slug AS org, a.content_hash FROM artifacts a JOIN orgs o ON o.id = a.org_id".to_string();
        if filter.org.is_some() {
            query.push_str(" WHERE o.slug = ?");
        }
        let statement = self.database.prepare(&query);
        let statement = if let Some(org) = filter.org {
            statement
                .bind(&[worker::wasm_bindgen::JsValue::from_str(&org)])
                .map_err(|error| StoreError::Backend(error.to_string()))?
        } else {
            statement
        };
        let rows = statement
            .all()
            .await
            .map_err(|error| StoreError::Backend(error.to_string()))?
            .results::<D1SummaryRow>()
            .map_err(|error| StoreError::Backend(error.to_string()))?;
        Ok(Page {
            items: rows
                .into_iter()
                .map(|row| ArtifactSummary {
                    id: ArtifactId(row.id),
                    org: row.org,
                    content_hash: row.content_hash,
                })
                .collect(),
        })
    }
}

#[derive(Debug, Deserialize)]
struct D1ArtifactRow {
    org: String,
    content_hash: String,
    file_hash: String,
    entrypoint: String,
    content_type: String,
    payload: String,
}
#[derive(Debug, Deserialize)]
struct D1DeleteRow {
    row_id: String,
    manifest: String,
}
#[derive(Debug, Deserialize)]
struct D1CountRow {
    count: i64,
}
#[derive(Debug, Deserialize)]
struct LockOwnerRow {
    owner: String,
}
#[derive(Debug, Deserialize)]
struct D1SummaryRow {
    id: String,
    org: String,
    content_hash: String,
}

#[derive(Debug, Deserialize)]
struct D1ListRow {
    id: String,
    org: String,
    content_hash: String,
    created_at: String,
    revoked_at: Option<String>,
    agent: Option<String>,
    repo_url: Option<String>,
    commit_sha: Option<String>,
    #[serde(default)]
    title: Option<String>,
    #[serde(default)]
    description: Option<String>,
    size_bytes: i64,
    #[serde(default)]
    tier: String,
    #[serde(default)]
    user_id: Option<String>,
    #[serde(default)]
    edit_access: String,
    #[serde(default)]
    entrypoint: String,
    #[serde(default)]
    expires_at: Option<String>,
    #[serde(default = "default_current_version")]
    current_version: i64,
    #[serde(default)]
    updated_at: String,
}

/// A row written before versioning has no `current_version`; the migration
/// backfills 1, and this keeps deserialization tolerant of a NULL column.
fn default_current_version() -> i64 {
    1
}

impl From<D1ListRow> for ArtifactListItem {
    fn from(row: D1ListRow) -> Self {
        ArtifactListItem {
            id: ArtifactId(row.id),
            org: row.org,
            content_hash: row.content_hash,
            size_bytes: row.size_bytes.max(0) as u64,
            agent: row.agent,
            repo_url: row.repo_url,
            commit_sha: row.commit_sha,
            title: row.title,
            description: row.description,
            created_at: row.created_at,
            revoked_at: row.revoked_at,
            tier: row.tier,
            owner_user_id: row.user_id,
            edit_access: row.edit_access,
            updated_at: row.updated_at,
            version: row.current_version.max(1),
            kind: entrypoint_kind(&row.entrypoint).map(str::to_string),
            expires_at: row.expires_at,
        }
    }
}

/// The `LIKE` patterns (against `lower(a.entrypoint)`) that select a kind's
/// extensions, mirroring [`entrypoint_kind`]. The API validates `kind`, so an
/// unknown value is only reachable from a directly-constructed filter and
/// matches nothing.
fn kind_like_patterns(kind: &str) -> &'static [&'static str] {
    match kind {
        "html" => &["%.html", "%.htm"],
        "markdown" => &["%.md", "%.markdown"],
        "table" => &["%.csv", "%.tsv", "%.json"],
        _ => &[],
    }
}

/// A `LIKE` pattern matching `query` anywhere, with the wildcard characters
/// in the query itself escaped (`\\` is the ESCAPE character).
pub fn like_pattern(query: &str) -> String {
    let escaped = query
        .replace('\\', "\\\\")
        .replace('%', "\\%")
        .replace('_', "\\_");
    format!("%{escaped}%")
}

/// A pure-logic test double for store behavior that does not exercise D1 or R2.
///
/// Production persistence guarantees, including leases, SQL refcounts, and
/// object lifecycle behavior, are covered separately by the ignored local
/// Wrangler integration suite in `backend/tests/storage_integration.rs`.
#[derive(Default)]
pub struct MemoryArtifactStore {
    artifacts: Mutex<HashMap<u64, Artifact>>,
    next_row_id: AtomicU64,
    /// Row ids revoked via [`MemoryArtifactStore::revoke`]. Kept separate
    /// from `Artifact` itself (rather than adding a `revoked_at` field to
    /// that struct) so this in-memory soft-delete proof doesn't ripple into
    /// the `KvArtifactStore`/`D1R2ArtifactStore` `Artifact` shape, which
    /// spec 8 doesn't otherwise touch.
    revoked: Mutex<HashSet<u64>>,
    /// Row ids currently under legal hold (spec 11). Same "kept separate"
    /// rationale as `revoked`.
    legal_hold: Mutex<HashSet<u64>>,
    /// Reference count per content hash, incremented on every `put()` that
    /// resolves to that hash (including a dedup hit) and decremented on
    /// `hard_delete`. Models the dedup accounting spec 11 requires: "a
    /// shared blob survives deletion of one referencing artifact and is
    /// removed on deletion of the last."
    blob_refs: Mutex<HashMap<String, u64>>,
}

impl MemoryArtifactStore {
    pub fn new() -> Self {
        Self::default()
    }

    pub fn export(&self, org: &str) -> Result<Vec<(String, Vec<u8>)>, StoreError> {
        let artifacts = self.artifacts.lock().expect("artifact store lock poisoned");
        let mut seen = HashSet::new();
        Ok(artifacts
            .values()
            .filter(|artifact| artifact.org == org)
            .filter(|artifact| seen.insert(artifact.content_hash.clone()))
            .map(|artifact| (artifact.content_hash.clone(), artifact.content.clone()))
            .collect())
    }

    /// Soft delete (spec 8): marks the artifact revoked without removing
    /// its row or content. Idempotent — revoking twice is not an error.
    pub fn revoke(&self, id: &ArtifactId) -> Result<(), StoreError> {
        let artifacts = self.artifacts.lock().expect("artifact store lock poisoned");
        let row_id = artifacts
            .values()
            .filter(|artifact| &artifact.id == id)
            .min_by_key(|artifact| artifact.row_id)
            .map(|artifact| artifact.row_id)
            .ok_or(StoreError::MissingArtifact)?;
        self.revoked
            .lock()
            .expect("revoked-set lock poisoned")
            .insert(row_id);
        Ok(())
    }

    /// Places a legal hold on an artifact (spec 11: "overrides everything,
    /// including a user-initiated delete"). Idempotent.
    pub fn place_legal_hold(&self, id: &ArtifactId) -> Result<(), StoreError> {
        let row_id = self.row_id_of(id)?;
        self.legal_hold
            .lock()
            .expect("legal-hold lock poisoned")
            .insert(row_id);
        Ok(())
    }

    /// Releases a legal hold. Idempotent; not an error if none was held.
    pub fn release_legal_hold(&self, id: &ArtifactId) -> Result<(), StoreError> {
        let row_id = self.row_id_of(id)?;
        self.legal_hold
            .lock()
            .expect("legal-hold lock poisoned")
            .remove(&row_id);
        Ok(())
    }

    pub fn is_under_legal_hold(&self, id: &ArtifactId) -> bool {
        let Ok(row_id) = self.row_id_of(id) else {
            return false;
        };
        self.legal_hold
            .lock()
            .expect("legal-hold lock poisoned")
            .contains(&row_id)
    }

    fn row_id_of(&self, id: &ArtifactId) -> Result<u64, StoreError> {
        self.artifacts
            .lock()
            .expect("artifact store lock poisoned")
            .values()
            .filter(|artifact| &artifact.id == id)
            .min_by_key(|artifact| artifact.row_id)
            .map(|artifact| artifact.row_id)
            .ok_or(StoreError::MissingArtifact)
    }

    /// Current reference count for a content hash — 0 once the last
    /// referencing artifact has been hard-deleted, at which point the blob
    /// itself is considered removed (spec 11: "the blob is removed only at
    /// refcount zero").
    pub fn blob_ref_count(&self, content_hash: &str) -> u64 {
        *self
            .blob_refs
            .lock()
            .expect("blob-refs lock poisoned")
            .get(content_hash)
            .unwrap_or(&0)
    }

    pub fn blob_exists(&self, content_hash: &str) -> bool {
        self.blob_ref_count(content_hash) > 0
    }

    /// Hard-deletes an artifact row and decrements its blob's refcount,
    /// removing the blob only once the refcount reaches zero. Refuses —
    /// without deleting anything — when the artifact is under legal hold
    /// (spec 11 DoD: "An artifact under legal hold cannot be hard-deleted
    /// by any path; the attempt is refused").
    pub fn hard_delete(&self, id: &ArtifactId) -> Result<(), GovernanceError> {
        if self.is_under_legal_hold(id) {
            return Err(GovernanceError::LegalHold {
                artifact_id: id.0.clone(),
            });
        }
        let mut artifacts = self.artifacts.lock().expect("artifact store lock poisoned");
        let row = artifacts
            .values()
            .find(|artifact| &artifact.id == id)
            .cloned()
            .ok_or(GovernanceError::NotFound)?;
        artifacts.remove(&row.row_id);
        drop(artifacts);
        let mut refs = self.blob_refs.lock().expect("blob-refs lock poisoned");
        if let Some(count) = refs.get_mut(&row.content_hash) {
            *count = count.saturating_sub(1);
            if *count == 0 {
                refs.remove(&row.content_hash);
            }
        }
        Ok(())
    }

    /// Whether the artifact both exists and has not been revoked — the
    /// in-memory equivalent of the `revoked_at IS NULL` gate
    /// `resolve_permanent_artifact` applies on the live serving path.
    pub fn is_servable(&self, id: &ArtifactId) -> bool {
        let artifacts = self.artifacts.lock().expect("artifact store lock poisoned");
        let Some(row_id) = artifacts
            .values()
            .filter(|artifact| &artifact.id == id)
            .min_by_key(|artifact| artifact.row_id)
            .map(|artifact| artifact.row_id)
        else {
            return false;
        };
        !self
            .revoked
            .lock()
            .expect("revoked-set lock poisoned")
            .contains(&row_id)
    }
}

impl ArtifactStore for MemoryArtifactStore {
    async fn put(&self, artifact: NewArtifact) -> Result<StoredRef, StoreError> {
        validate_slug(&artifact.org).map_err(StoreError::InvalidSlug)?;
        let hash = content_hash(&artifact.content);
        let id = ArtifactId(public_id(&artifact.org, &hash));
        let row_id = self.next_row_id.fetch_add(1, Ordering::Relaxed);
        let stored = Artifact {
            row_id,
            id: id.clone(),
            org: artifact.org,
            content_hash: hash.clone(),
            content: artifact.content,
            content_type: artifact.content_type,
            entrypoint: artifact.entrypoint,
            provenance: artifact.provenance,
        };
        self.artifacts
            .lock()
            .expect("artifact store lock poisoned")
            .insert(row_id, stored);
        *self
            .blob_refs
            .lock()
            .expect("blob-refs lock poisoned")
            .entry(hash.clone())
            .or_insert(0) += 1;
        Ok(StoredRef {
            id,
            content_hash: hash,
        })
    }

    async fn get(&self, id: &ArtifactId) -> Result<Option<Artifact>, StoreError> {
        Ok(self
            .artifacts
            .lock()
            .expect("artifact store lock poisoned")
            .values()
            .filter(|artifact| &artifact.id == id)
            .min_by_key(|artifact| artifact.row_id)
            .cloned())
    }

    async fn delete(&self, id: &ArtifactId) -> Result<(), StoreError> {
        let mut artifacts = self.artifacts.lock().expect("artifact store lock poisoned");
        let row_id = artifacts
            .values()
            .filter(|artifact| &artifact.id == id)
            .min_by_key(|artifact| artifact.row_id)
            .map(|artifact| artifact.row_id)
            .ok_or(StoreError::MissingArtifact)?;
        artifacts.remove(&row_id);
        Ok(())
    }

    async fn list(&self, filter: ListFilter) -> Result<Page<ArtifactSummary>, StoreError> {
        let artifacts = self.artifacts.lock().expect("artifact store lock poisoned");
        Ok(Page {
            items: artifacts
                .values()
                .filter(|artifact| filter.org.as_ref().is_none_or(|org| org == &artifact.org))
                .map(|artifact| ArtifactSummary {
                    id: artifact.id.clone(),
                    org: artifact.org.clone(),
                    content_hash: artifact.content_hash.clone(),
                })
                .collect(),
        })
    }
}

pub fn validate_slug(slug: &str) -> Result<(), String> {
    if slug.is_empty() || slug.len() > MAX_TENANT_SLUG_LENGTH {
        return Err(format!(
            "tenant slug must be between 1 and {MAX_TENANT_SLUG_LENGTH} characters"
        ));
    }
    if slug.contains("--") {
        return Err("tenant slug cannot contain '--'".to_string());
    }
    if !slug.is_ascii() {
        return Err("tenant slug must contain only ASCII characters".to_string());
    }
    if slug.starts_with('-') || slug.ends_with('-') {
        return Err("tenant slug cannot start or end with '-'".to_string());
    }
    if !slug
        .bytes()
        .all(|byte| byte.is_ascii_lowercase() || byte.is_ascii_digit() || byte == b'-')
    {
        return Err("tenant slug must contain only lowercase letters, digits, and '-'".to_string());
    }
    Ok(())
}

pub fn hostname_label(slug: &str, id: &ArtifactId) -> Result<String, String> {
    validate_slug(slug)?;
    let label = format!("{slug}--{}", id.0);
    if label.len() > 63 {
        return Err("artifact hostname label exceeds 63 characters".to_string());
    }
    Ok(label)
}

pub fn content_hash(content: &[u8]) -> String {
    sha256(content)
        .iter()
        .map(|byte| format!("{byte:02x}"))
        .collect()
}

/// HMAC-SHA256 (RFC 2104), built on the local `sha256` implementation so
/// access-token signing needs no additional crate.
pub fn hmac_sha256(key: &[u8], message: &[u8]) -> [u8; 32] {
    const BLOCK_SIZE: usize = 64;
    let mut block_key = [0u8; BLOCK_SIZE];
    if key.len() > BLOCK_SIZE {
        block_key[..32].copy_from_slice(&sha256(key));
    } else {
        block_key[..key.len()].copy_from_slice(key);
    }

    let mut inner_pad = [0x36u8; BLOCK_SIZE];
    let mut outer_pad = [0x5cu8; BLOCK_SIZE];
    for index in 0..BLOCK_SIZE {
        inner_pad[index] ^= block_key[index];
        outer_pad[index] ^= block_key[index];
    }

    let mut inner_message = inner_pad.to_vec();
    inner_message.extend_from_slice(message);
    let inner_digest = sha256(&inner_message);

    let mut outer_message = outer_pad.to_vec();
    outer_message.extend_from_slice(&inner_digest);
    sha256(&outer_message)
}

/// The public artifact id: the first `PUBLIC_ID_LENGTH` hex characters of
/// `sha256("{org}:{content_hash}")`. Scoping the id to the org keeps two orgs
/// that publish byte-identical bundles from sharing one `/p/{id}`, while the
/// same org re-publishing the same bundle still gets the same id. Blobs stay
/// keyed by content hash, so storage dedupe is unchanged. Ids minted before
/// this change (bare content-hash prefixes) keep resolving because lookups go
/// by stored id.
pub fn public_id(org: &str, hash: &str) -> String {
    content_hash(format!("{org}:{hash}").as_bytes())
        .chars()
        .take(PUBLIC_ID_LENGTH)
        .collect()
}

/// Mints a new stable artifact id: [`STABLE_ID_LENGTH`] characters of
/// lowercase base36, giving about 67 bits of entropy. Rejection sampling
/// keeps every character uniform instead of folding a biased modulo, and the
/// id is random rather than derived from content so that publishing a new
/// version of an artifact keeps its id.
pub fn mint_stable_id() -> String {
    // Largest multiple of 36 that fits a byte (36 * 7 = 252): values in
    // 252..=255 are rejected so every accepted byte maps uniformly.
    const BYTE_LIMIT: u16 = 36 * (256 / 36);
    let mut id = String::with_capacity(STABLE_ID_LENGTH);
    let mut buffer = [0u8; 16];
    while id.len() < STABLE_ID_LENGTH {
        getrandom(&mut buffer).expect("getrandom failed");
        for byte in buffer {
            if id.len() == STABLE_ID_LENGTH {
                break;
            }
            let value = u16::from(byte);
            if value < BYTE_LIMIT {
                id.push(STABLE_ID_ALPHABET[(value % 36) as usize] as char);
            }
        }
    }
    id
}

/// Whether `id` is a stable artifact id: exactly [`STABLE_ID_LENGTH`]
/// lowercase base36 characters.
pub fn is_stable_id(id: &str) -> bool {
    id.len() == STABLE_ID_LENGTH
        && id
            .bytes()
            .all(|byte| byte.is_ascii_lowercase() || byte.is_ascii_digit())
}

/// Whether `id` is a pre-versioning permanent artifact id: exactly
/// [`PUBLIC_ID_LENGTH`] lowercase hex characters.
pub fn is_legacy_public_id(id: &str) -> bool {
    id.len() == PUBLIC_ID_LENGTH
        && id
            .bytes()
            .all(|byte| byte.is_ascii_hexdigit() && !byte.is_ascii_uppercase())
}

/// Whether `id` names a permanent artifact, whichever id shape it was minted
/// with. Ephemeral (KV) ids are deliberately excluded.
pub fn is_permanent_id(id: &str) -> bool {
    is_stable_id(id) || is_legacy_public_id(id)
}

// `as_chunks` (clippy's suggested replacement) landed after this crate's
// pinned toolchain; keeping `chunks_exact` here so this still builds on
// older stable Rust rather than picking up a newer MSRV for a hash
// computation with no behavior to gain from the rewrite.
#[allow(unknown_lints, clippy::chunks_exact_to_as_chunks)]
fn sha256(input: &[u8]) -> [u8; 32] {
    let mut state: [u32; 8] = [
        0x6a09e667, 0xbb67ae85, 0x3c6ef372, 0xa54ff53a, 0x510e527f, 0x9b05688c, 0x1f83d9ab,
        0x5be0cd19,
    ];
    let mut message = input.to_vec();
    let bit_length = (message.len() as u64) * 8;
    message.push(0x80);
    while message.len() % 64 != 56 {
        message.push(0);
    }
    message.extend_from_slice(&bit_length.to_be_bytes());

    const K: [u32; 64] = [
        0x428a2f98, 0x71374491, 0xb5c0fbcf, 0xe9b5dba5, 0x3956c25b, 0x59f111f1, 0x923f82a4,
        0xab1c5ed5, 0xd807aa98, 0x12835b01, 0x243185be, 0x550c7dc3, 0x72be5d74, 0x80deb1fe,
        0x9bdc06a7, 0xc19bf174, 0xe49b69c1, 0xefbe4786, 0x0fc19dc6, 0x240ca1cc, 0x2de92c6f,
        0x4a7484aa, 0x5cb0a9dc, 0x76f988da, 0x983e5152, 0xa831c66d, 0xb00327c8, 0xbf597fc7,
        0xc6e00bf3, 0xd5a79147, 0x06ca6351, 0x14292967, 0x27b70a85, 0x2e1b2138, 0x4d2c6dfc,
        0x53380d13, 0x650a7354, 0x766a0abb, 0x81c2c92e, 0x92722c85, 0xa2bfe8a1, 0xa81a664b,
        0xc24b8b70, 0xc76c51a3, 0xd192e819, 0xd6990624, 0xf40e3585, 0x106aa070, 0x19a4c116,
        0x1e376c08, 0x2748774c, 0x34b0bcb5, 0x391c0cb3, 0x4ed8aa4a, 0x5b9cca4f, 0x682e6ff3,
        0x748f82ee, 0x78a5636f, 0x84c87814, 0x8cc70208, 0x90befffa, 0xa4506ceb, 0xbef9a3f7,
        0xc67178f2,
    ];

    for chunk in message.chunks_exact(64) {
        let mut words = [0u32; 64];
        for (index, bytes) in chunk.chunks_exact(4).take(16).enumerate() {
            words[index] = u32::from_be_bytes(bytes.try_into().expect("word is four bytes"));
        }
        for index in 16..64 {
            let s0 = words[index - 15].rotate_right(7)
                ^ words[index - 15].rotate_right(18)
                ^ (words[index - 15] >> 3);
            let s1 = words[index - 2].rotate_right(17)
                ^ words[index - 2].rotate_right(19)
                ^ (words[index - 2] >> 10);
            words[index] = words[index - 16]
                .wrapping_add(s0)
                .wrapping_add(words[index - 7])
                .wrapping_add(s1);
        }
        let mut working = state;
        for index in 0..64 {
            let [a, b, c, d, e, f, g, h] = working;
            let s1 = e.rotate_right(6) ^ e.rotate_right(11) ^ e.rotate_right(25);
            let choice = (e & f) ^ ((!e) & g);
            let temp1 = h
                .wrapping_add(s1)
                .wrapping_add(choice)
                .wrapping_add(K[index])
                .wrapping_add(words[index]);
            let s0 = a.rotate_right(2) ^ a.rotate_right(13) ^ a.rotate_right(22);
            let majority = (a & b) ^ (a & c) ^ (b & c);
            let temp2 = s0.wrapping_add(majority);
            working = [
                temp1.wrapping_add(temp2),
                a,
                b,
                c,
                d.wrapping_add(temp1),
                e,
                f,
                g,
            ];
        }
        for index in 0..8 {
            state[index] = state[index].wrapping_add(working[index]);
        }
    }

    let mut output = [0u8; 32];
    for (index, word) in state.iter().enumerate() {
        output[index * 4..index * 4 + 4].copy_from_slice(&word.to_be_bytes());
    }
    output
}

#[cfg(test)]
mod tests {
    use super::*;
    use std::{
        future::Future,
        pin::pin,
        task::{Context, Poll, RawWaker, RawWakerVTable, Waker},
    };

    fn block_on<F: Future>(future: F) -> F::Output {
        fn no_op(_: *const ()) {}
        fn clone(_: *const ()) -> RawWaker {
            RawWaker::new(std::ptr::null(), &VTABLE)
        }
        static VTABLE: RawWakerVTable = RawWakerVTable::new(clone, no_op, no_op, no_op);

        let waker = unsafe { Waker::from_raw(RawWaker::new(std::ptr::null(), &VTABLE)) };
        let mut context = Context::from_waker(&waker);
        let mut future = pin!(future);
        loop {
            if let Poll::Ready(value) = future.as_mut().poll(&mut context) {
                return value;
            }
        }
    }

    fn artifact(org: &str, content: &[u8]) -> NewArtifact {
        NewArtifact {
            org: org.to_string(),
            content: content.to_vec(),
            content_type: "text/html".to_string(),
            entrypoint: "index.html".to_string(),
            provenance: serde_json::json!({"agent": "cli", "unknown": "retained"}),
        }
    }

    #[test]
    fn content_hash_is_32_lowercase_hex() {
        let hash = content_hash(b"hello");
        assert_eq!(
            hash,
            "2cf24dba5fb0a30e26e83b2ac5b9e29e1b161e5c1fa7425e73043362938b9824"
        );
        let id = public_id("acme", &hash);
        assert_eq!(id.len(), 32);
        assert!(id
            .bytes()
            .all(|byte| byte.is_ascii_hexdigit() && !byte.is_ascii_uppercase()));
    }

    #[test]
    fn hmac_sha256_matches_known_test_vector() {
        // RFC 4231 test case 1.
        let key = [0x0bu8; 20];
        let mac = hmac_sha256(&key, b"Hi There");
        let hex = mac
            .iter()
            .map(|byte| format!("{byte:02x}"))
            .collect::<String>();
        assert_eq!(
            hex,
            "b0344c61d8db38535ca8afceaf0bf12b881dc200c9833da726e9376c2e32cff7"
        );
    }

    #[test]
    fn hostname_label_fits_63_chars_at_max_slug() {
        let slug = "a".repeat(MAX_TENANT_SLUG_LENGTH);
        let label = hostname_label(&slug, &ArtifactId("a".repeat(PUBLIC_ID_LENGTH))).unwrap();
        assert!(label.len() <= 63);
    }

    #[test]
    fn hostname_label_fits_63_chars_at_max_slug_with_stable_id() {
        let slug = "a".repeat(MAX_TENANT_SLUG_LENGTH);
        let label = hostname_label(&slug, &ArtifactId(mint_stable_id())).unwrap();
        assert!(label.len() <= 63);
    }

    #[test]
    fn mint_stable_id_is_13_lowercase_base36_chars() {
        let id = mint_stable_id();
        assert_eq!(id.len(), STABLE_ID_LENGTH);
        assert!(id
            .bytes()
            .all(|byte| byte.is_ascii_lowercase() || byte.is_ascii_digit()));
    }

    #[test]
    fn mint_stable_id_mints_distinct_ids() {
        let ids: HashSet<String> = (0..1000).map(|_| mint_stable_id()).collect();
        assert_eq!(ids.len(), 1000);
    }

    #[test]
    fn is_stable_id_accepts_only_13_lowercase_base36() {
        assert!(is_stable_id(&"a".repeat(STABLE_ID_LENGTH)));
        assert!(is_stable_id("0123456789abc"));
        assert!(!is_stable_id(&"A".repeat(STABLE_ID_LENGTH)));
        assert!(!is_stable_id(&"a".repeat(STABLE_ID_LENGTH - 1)));
        assert!(!is_stable_id(&"a".repeat(STABLE_ID_LENGTH + 1)));
        assert!(!is_stable_id("abcdefghijkl!"));
    }

    #[test]
    fn is_legacy_public_id_accepts_only_32_lowercase_hex() {
        assert!(is_legacy_public_id(&"0".repeat(PUBLIC_ID_LENGTH)));
        assert!(is_legacy_public_id("0123456789abcdef0123456789abcdef"));
        assert!(!is_legacy_public_id(&"F".repeat(PUBLIC_ID_LENGTH)));
        assert!(!is_legacy_public_id(&"a".repeat(PUBLIC_ID_LENGTH - 1)));
        assert!(!is_legacy_public_id(&"a".repeat(PUBLIC_ID_LENGTH + 1)));
        assert!(!is_legacy_public_id(&"g".repeat(PUBLIC_ID_LENGTH)));
    }

    #[test]
    fn is_permanent_id_covers_both_shapes_but_not_ephemeral() {
        assert!(is_permanent_id(&mint_stable_id()));
        assert!(is_permanent_id(&"a".repeat(PUBLIC_ID_LENGTH)));
        // A 10-character ephemeral id is not a permanent artifact id.
        assert!(!is_permanent_id(&"a".repeat(10)));
        assert!(!is_permanent_id(&"A".repeat(STABLE_ID_LENGTH)));
        assert!(!is_permanent_id(""));
    }

    #[test]
    fn slug_with_double_hyphen_rejected() {
        assert!(validate_slug("acme--corp").is_err());
    }

    #[test]
    fn slug_with_non_ascii_rejected() {
        assert!(validate_slug("acmé").is_err());
    }

    #[test]
    fn identical_content_dedupes_to_one_blob() {
        let store = MemoryArtifactStore::new();
        let first = block_on(store.put(artifact("acme", b"same"))).unwrap();
        let second = block_on(store.put(artifact("acme", b"same"))).unwrap();
        assert_eq!(first.content_hash, second.content_hash);
        assert_eq!(
            block_on(store.list(ListFilter::default()))
                .unwrap()
                .items
                .len(),
            2
        );
        assert_eq!(store.export("acme").unwrap().len(), 1);
    }

    #[test]
    fn delete_decrements_refcount_not_blob() {
        let store = MemoryArtifactStore::new();
        let first = block_on(store.put(artifact("acme", b"same"))).unwrap();
        let second = block_on(store.put(artifact("acme", b"same"))).unwrap();
        block_on(store.delete(&first.id)).unwrap();
        assert!(block_on(store.get(&second.id)).unwrap().is_some());
    }

    #[test]
    fn delete_last_reference_removes_blob() {
        let store = MemoryArtifactStore::new();
        let first = block_on(store.put(artifact("acme", b"same"))).unwrap();
        block_on(store.delete(&first.id)).unwrap();
        assert!(store.export("acme").unwrap().is_empty());
    }

    #[test]
    fn provenance_columns_populated_from_request() {
        let store = MemoryArtifactStore::new();
        let reference = block_on(store.put(artifact("acme", b"same"))).unwrap();
        let stored = block_on(store.get(&reference.id)).unwrap().unwrap();
        assert_eq!(stored.provenance["agent"], "cli");
    }

    #[test]
    fn provenance_json_tail_survives_unknown_fields() {
        let store = MemoryArtifactStore::new();
        let reference = block_on(store.put(artifact("acme", b"same"))).unwrap();
        let stored = block_on(store.get(&reference.id)).unwrap().unwrap();
        assert_eq!(stored.provenance["unknown"], "retained");
    }

    #[test]
    fn list_unimplemented_for_kv_backend() {
        let store = KvArtifactStore::without_binding();
        assert!(matches!(
            block_on(store.list(ListFilter::default())),
            Err(StoreError::Unsupported("list"))
        ));
    }

    fn list_item(
        id: &str,
        created_at: &str,
        updated_at: &str,
        title: Option<&str>,
    ) -> ArtifactListItem {
        ArtifactListItem {
            id: ArtifactId(id.to_string()),
            org: "acme".to_string(),
            content_hash: "a".repeat(64),
            size_bytes: 1,
            agent: None,
            repo_url: None,
            commit_sha: None,
            title: title.map(str::to_string),
            description: None,
            created_at: created_at.to_string(),
            revoked_at: None,
            tier: "secure".to_string(),
            owner_user_id: None,
            edit_access: "view".to_string(),
            updated_at: updated_at.to_string(),
            version: 1,
            kind: Some("html".to_string()),
            expires_at: None,
        }
    }

    /// 40 items with distinct ids, timestamps and titles, ordered by index.
    fn sample_items(count: usize) -> Vec<ArtifactListItem> {
        (0..count)
            .map(|index| {
                let stamp = format!("2026-01-01T00:00:{:02}Z", index);
                list_item(
                    &format!("id{index:03}"),
                    &stamp,
                    &stamp,
                    Some(&format!("Title {index:03}")),
                )
            })
            .collect()
    }

    #[test]
    fn entrypoint_kind_recognises_known_extensions_case_insensitively() {
        assert_eq!(entrypoint_kind("index.html"), Some("html"));
        assert_eq!(entrypoint_kind("dir/page.HTM"), Some("html"));
        assert_eq!(entrypoint_kind("notes.md"), Some("markdown"));
        assert_eq!(entrypoint_kind("notes.MarkDown"), Some("markdown"));
        assert_eq!(entrypoint_kind("data.csv"), Some("table"));
        assert_eq!(entrypoint_kind("data.tsv"), Some("table"));
        assert_eq!(entrypoint_kind("sheet.JSON"), Some("table"));
        assert_eq!(entrypoint_kind("index"), None);
        assert_eq!(entrypoint_kind("archive.txt"), None);
        assert_eq!(entrypoint_kind(""), None);
        // `htm` must not match the wider `html` suffix.
        assert_eq!(entrypoint_kind("index.htms"), None);
    }

    #[test]
    fn kind_like_patterns_only_cover_validated_kinds() {
        assert_eq!(kind_like_patterns("html"), &["%.html", "%.htm"]);
        assert_eq!(kind_like_patterns("markdown"), &["%.md", "%.markdown"]);
        assert_eq!(kind_like_patterns("table"), &["%.csv", "%.tsv", "%.json"]);
        assert!(kind_like_patterns("bogus").is_empty());
    }

    #[test]
    fn list_cursor_round_trips_for_every_sort() {
        for sort in [
            ListSort::CreatedAsc,
            ListSort::CreatedDesc,
            ListSort::UpdatedDesc,
            ListSort::TitleAsc,
        ] {
            let cursor = ListCursor {
                sort,
                key: "2026-01-01T00:00:00Z".to_string(),
                id: "abcdefghijklm".to_string(),
            };
            let encoded = encode_list_cursor(&cursor);
            assert!(
                !encoded.contains(['=', '+', '/']),
                "cursor must be base64url without padding: {encoded}"
            );
            assert_eq!(decode_list_cursor(&encoded), Some(cursor));
        }
    }

    #[test]
    fn decode_list_cursor_rejects_malformed_input() {
        use base64::Engine;
        let encode =
            |text: &str| base64::engine::general_purpose::URL_SAFE_NO_PAD.encode(text.as_bytes());
        assert_eq!(decode_list_cursor("not base64!"), None);
        // Too few fields.
        assert_eq!(decode_list_cursor(&encode("created_asc\u{1f}key")), None);
        // Unknown sort.
        assert_eq!(
            decode_list_cursor(&encode("sideways\u{1f}key\u{1f}id")),
            None
        );
        // Empty key and empty id.
        assert_eq!(
            decode_list_cursor(&encode("created_asc\u{1f}\u{1f}id")),
            None
        );
        assert_eq!(
            decode_list_cursor(&encode("created_asc\u{1f}key\u{1f}")),
            None
        );
        // Extra fields.
        assert_eq!(
            decode_list_cursor(&encode("created_asc\u{1f}key\u{1f}id\u{1f}extra")),
            None
        );
    }

    #[test]
    fn paginate_sorted_follows_each_sort_and_puts_untitled_last() {
        let items = vec![
            list_item(
                "b",
                "2026-01-01T00:00:02Z",
                "2026-01-01T00:00:01Z",
                Some("Beta"),
            ),
            list_item(
                "a",
                "2026-01-01T00:00:01Z",
                "2026-01-01T00:00:02Z",
                Some("Alpha"),
            ),
            list_item("c", "2026-01-01T00:00:03Z", "2026-01-01T00:00:03Z", None),
        ];
        let ids = |sort| {
            paginate_sorted(&items, sort, None, 10)
                .0
                .into_iter()
                .map(|item| item.id.0)
                .collect::<Vec<_>>()
        };
        assert_eq!(ids(ListSort::CreatedAsc), vec!["a", "b", "c"]);
        assert_eq!(ids(ListSort::CreatedDesc), vec!["c", "b", "a"]);
        // updated_at: b (00:01), a (00:02), c (00:03) -> desc: c, a, b.
        assert_eq!(ids(ListSort::UpdatedDesc), vec!["c", "a", "b"]);
        // Title asc: Alpha, Beta, then the untitled row last.
        assert_eq!(ids(ListSort::TitleAsc), vec!["a", "b", "c"]);
    }

    #[test]
    fn paginate_sorted_ignores_a_cursor_from_another_sort() {
        let items = sample_items(30);
        let first = paginate_sorted(&items, ListSort::CreatedAsc, None, 5).0;
        let cursor = cursor_for_item(
            first.last().expect("a non-empty page"),
            ListSort::CreatedAsc,
        );
        let (restarted, _) = paginate_sorted(&items, ListSort::CreatedDesc, Some(&cursor), 5);
        let (expected, _) = paginate_sorted(&items, ListSort::CreatedDesc, None, 5);
        assert_eq!(restarted, expected);
        assert_ne!(first, restarted);
    }

    #[test]
    fn paginate_sorted_has_no_gaps_or_duplicates_for_every_sort() {
        for sort in [
            ListSort::CreatedAsc,
            ListSort::CreatedDesc,
            ListSort::UpdatedDesc,
            ListSort::TitleAsc,
        ] {
            let items = sample_items(1000);
            let page_size = 37; // deliberately not a divisor of the total
            let mut cursor = None;
            let mut seen: HashMap<String, usize> = HashMap::new();
            loop {
                let (page, next) = paginate_sorted(&items, sort, cursor.as_ref(), page_size);
                if page.is_empty() {
                    assert!(next.is_none(), "an empty page must be the last page");
                    break;
                }
                for item in page {
                    *seen.entry(item.id.0).or_insert(0) += 1;
                }
                match next {
                    Some(next) => cursor = Some(next),
                    None => break,
                }
            }
            assert_eq!(seen.len(), items.len(), "{sort:?} skipped rows");
            assert!(
                seen.values().all(|count| *count == 1),
                "{sort:?} returned a row more than once"
            );
        }
    }

    #[test]
    fn created_and_title_sorts_stay_stable_when_a_row_is_inserted_between_pages() {
        for sort in [
            ListSort::CreatedAsc,
            ListSort::CreatedDesc,
            ListSort::TitleAsc,
        ] {
            let items = sample_items(40);
            let page_size = 6;
            let mut seen: HashMap<String, usize> = HashMap::new();
            let mut cursor = None;
            for _ in 0..3 {
                let (page, next) = paginate_sorted(&items, sort, cursor.as_ref(), page_size);
                for item in page {
                    *seen.entry(item.id.0).or_insert(0) += 1;
                }
                cursor = next;
            }
            // A row created after paging started. Depending on the sort it
            // may land before or after the cursor; either way it must not
            // disturb the rows already in or still ahead of the page window.
            let mut with_insert = items.clone();
            with_insert.push(list_item(
                "inserted",
                "2026-01-01T00:00:20Z",
                "2026-01-01T00:00:20Z",
                Some("Zzz inserted"),
            ));
            loop {
                let (page, next) = paginate_sorted(&with_insert, sort, cursor.as_ref(), page_size);
                if page.is_empty() {
                    break;
                }
                for item in page {
                    *seen.entry(item.id.0).or_insert(0) += 1;
                }
                match next {
                    Some(next) => cursor = Some(next),
                    None => break,
                }
            }
            for item in &items {
                assert_eq!(
                    seen.get(&item.id.0).copied().unwrap_or(0),
                    1,
                    "{sort:?} duplicated or skipped {}",
                    item.id.0
                );
            }
        }
    }

    #[test]
    fn updated_desc_never_duplicates_or_skips_untouched_rows_across_an_update() {
        let sort = ListSort::UpdatedDesc;
        let items = sample_items(40);
        let page_size = 6;
        let mut seen: HashMap<String, usize> = HashMap::new();
        let mut cursor = None;
        for _ in 0..3 {
            let (page, next) = paginate_sorted(&items, sort, cursor.as_ref(), page_size);
            for item in page {
                *seen.entry(item.id.0).or_insert(0) += 1;
            }
            cursor = next;
        }
        // Bump a not-yet-seen row to the front. That row may be skipped, but
        // every other row must still be returned exactly once.
        let moved_id = items
            .iter()
            .find(|item| !seen.contains_key(&item.id.0))
            .expect("a row beyond the cursor")
            .id
            .0
            .clone();
        let mut updated = items.clone();
        for item in &mut updated {
            if item.id.0 == moved_id {
                item.updated_at = "2999-01-01T00:00:00Z".to_string();
            }
        }
        loop {
            let (page, next) = paginate_sorted(&updated, sort, cursor.as_ref(), page_size);
            if page.is_empty() {
                break;
            }
            for item in page {
                *seen.entry(item.id.0).or_insert(0) += 1;
            }
            match next {
                Some(next) => cursor = Some(next),
                None => break,
            }
        }
        for item in &items {
            let count = seen.get(&item.id.0).copied().unwrap_or(0);
            if item.id.0 == moved_id {
                assert!(count <= 1, "the moved row must not be returned twice");
            } else {
                assert_eq!(count, 1, "updated_desc skipped or duplicated {}", item.id.0);
            }
        }
    }
}
