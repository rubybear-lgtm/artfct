use serde::{Deserialize, Serialize};
use super::*;

pub(crate) fn ephemeral_manifest_is_invalid(raw: &Value) -> bool {
    raw.get("mode").and_then(Value::as_str) != Some("permanent") && raw.get("manifest").is_some()
}

pub(crate) fn is_valid_relative_path(path: &str) -> bool {
    !path.is_empty()
        && path.len() <= MAX_PATH_BYTES
        && !path.starts_with('/')
        && !path.contains('\\')
        && !path.contains(':')
        && !path
            .split('/')
            .any(|segment| segment == ".." || segment.is_empty())
}

pub(crate) fn normalized_content_type(value: &str) -> String {
    let value = value.trim().to_ascii_lowercase();
    if matches!(
        value.as_str(),
        "text/html"
            | "text/html; charset=utf-8"
            | "text/markdown"
            | "text/markdown; charset=utf-8"
            | "text/css"
            | "text/javascript"
            | "application/javascript"
            | "application/json"
            | "application/wasm"
            | "image/svg+xml"
            | "image/png"
            | "image/jpeg"
            | "image/gif"
            | "image/webp"
            | "font/woff"
            | "font/woff2"
            | "font/ttf"
            | "font/otf"
    ) {
        value
    } else {
        "application/octet-stream".to_string()
    }
}

pub(crate) fn uploaded_file_error(
    expected_hash: &str,
    expected_size: usize,
    bytes: &[u8],
) -> Option<ErrorCode> {
    if bytes.len() > MAX_FILE_BYTES {
        return Some(ErrorCode::BundleTooLarge);
    }
    if store::content_hash(bytes) != expected_hash {
        return Some(ErrorCode::HashMismatch);
    }
    (bytes.len() != expected_size).then_some(ErrorCode::ValidationFailed)
}

pub(crate) fn missing_manifest_files(
    manifest: &PermanentManifest,
    present: &[bool],
) -> Vec<String> {
    let mut missing = manifest
        .files
        .iter()
        .zip(present.iter().copied())
        .filter(|(_, is_present)| !*is_present)
        .map(|(file, _)| file.sha256.clone())
        .collect::<Vec<_>>();
    missing.sort();
    missing.dedup();
    missing
}

#[allow(dead_code)]
pub(crate) fn manifest_is_complete(manifest: &PermanentManifest, uploaded_paths: &[&str]) -> bool {
    manifest
        .files
        .iter()
        .all(|file| uploaded_paths.contains(&file.path.as_str()))
}

pub(crate) fn upload_expired(expires_at: Option<&str>, now: chrono::DateTime<Utc>) -> bool {
    expires_at
        .and_then(|value| chrono::DateTime::parse_from_rfc3339(value).ok())
        .is_some_and(|value| value.with_timezone(&Utc) <= now)
}

pub(crate) fn validate_permanent_manifest(
    raw: &Value,
) -> Result<(PermanentManifest, String), ErrorCode> {
    let Some(manifest) = raw.get("manifest").and_then(Value::as_object) else {
        return Err(ErrorCode::ValidationFailed);
    };
    let Some(entrypoint) = manifest.get("entrypoint").and_then(Value::as_str) else {
        return Err(ErrorCode::EntrypointMissing);
    };
    if !is_valid_relative_path(entrypoint) {
        return Err(ErrorCode::InvalidPath);
    }
    let Some(files) = manifest.get("files").and_then(Value::as_array) else {
        return Err(ErrorCode::ValidationFailed);
    };
    if files.is_empty() {
        return Err(ErrorCode::ValidationFailed);
    }
    if files.len() > MAX_BUNDLE_FILES {
        return Err(ErrorCode::FileCountExceeded);
    }
    let mut external_origins = manifest
        .get("external_origins")
        .and_then(Value::as_array)
        .ok_or(ErrorCode::ValidationFailed)?
        .iter()
        .map(|origin| {
            origin
                .as_str()
                .filter(|value| {
                    value.starts_with("https://") && !value.chars().any(char::is_whitespace)
                })
                .map(str::to_string)
                .ok_or(ErrorCode::ValidationFailed)
        })
        .collect::<Result<Vec<_>, _>>()?;
    external_origins.sort();
    external_origins.dedup();
    let unsafe_eval = match manifest.get("unsafe_eval") {
        None => false,
        Some(Value::Bool(value)) => *value,
        Some(_) => return Err(ErrorCode::ValidationFailed),
    };
    let mut paths = std::collections::HashSet::new();
    let mut total = 0usize;
    let mut parsed = Vec::with_capacity(files.len());
    for file in files {
        let path = file.get("path").and_then(Value::as_str).unwrap_or_default();
        if !is_valid_relative_path(path) {
            return Err(ErrorCode::InvalidPath);
        }
        // Spec 20: the `__artfct/` prefix is reserved for the artifact origin's
        // own endpoints (`/__artfct/db/`). A bundle path under it is rejected
        // at deploy, so a page can never shadow the database API.
        if path == "__artfct" || path.starts_with("__artfct/") {
            return Err(ErrorCode::InvalidPath);
        }
        if !paths.insert(path.to_string()) {
            return Err(ErrorCode::DuplicatePath);
        }
        let size_bytes = file
            .get("size_bytes")
            .and_then(Value::as_u64)
            .and_then(|size| usize::try_from(size).ok())
            .ok_or(ErrorCode::ValidationFailed)?;
        if size_bytes > MAX_FILE_BYTES {
            return Err(ErrorCode::BundleTooLarge);
        }
        total = total.saturating_add(size_bytes);
        if total > MAX_BUNDLE_BYTES {
            return Err(ErrorCode::BundleTooLarge);
        }
        let sha256 = file
            .get("sha256")
            .and_then(Value::as_str)
            .unwrap_or_default();
        if sha256.len() != 64
            || !sha256
                .bytes()
                .all(|byte| byte.is_ascii_hexdigit() && !byte.is_ascii_uppercase())
        {
            return Err(ErrorCode::ValidationFailed);
        }
        let content_type = file
            .get("content_type")
            .and_then(Value::as_str)
            .unwrap_or("application/octet-stream");
        parsed.push(PermanentManifestFile {
            path: path.to_string(),
            content_type: normalized_content_type(content_type),
            size_bytes,
            sha256: sha256.to_string(),
        });
    }
    if !paths.contains(entrypoint) {
        return Err(ErrorCode::EntrypointMissing);
    }
    parsed.sort_by(|left, right| left.path.cmp(&right.path));
    let manifest = PermanentManifest {
        entrypoint: entrypoint.to_string(),
        files: parsed,
        external_origins,
        unsafe_eval,
        db: validate_db_manifest(&Value::Object(manifest.clone()))?,
    };
    let canonical = serde_json::to_vec(&manifest).map_err(|_| ErrorCode::ValidationFailed)?;
    Ok((manifest, store::content_hash(&canonical)))
}

/// Spec 20 artifact database declaration, validated out of the manifest's
/// `db` key. Stored as part of the canonical manifest (skipped when absent,
/// so manifests without a database hash byte-identically to before), which
/// keeps the declaration versioned with the artifact and makes the seed
/// travel with template pulls.
#[derive(Debug, Deserialize, Serialize, Clone, PartialEq, Eq, Default)]
pub(crate) struct DbManifest {
    #[serde(default)]
    pub(crate) collections: std::collections::BTreeMap<String, DbCollectionDecl>,
    #[serde(default)]
    pub(crate) seed: Vec<DbSeedRow>,
}

/// One declared collection: at most one secondary index field. An empty
/// object declares a collection with no secondary index.
#[derive(Debug, Deserialize, Serialize, Clone, PartialEq, Eq, Default)]
pub(crate) struct DbCollectionDecl {
    #[serde(default, skip_serializing_if = "Option::is_none")]
    pub(crate) index: Option<String>,
}

/// One seed row: written once, on first deploy of the artifact id, never on
/// a republish, and never copied by a template pull (the pull copies the
/// manifest including `db.seed`, not the source's live rows).
#[derive(Debug, Deserialize, Serialize, Clone, PartialEq, Eq)]
pub(crate) struct DbSeedRow {
    pub(crate) collection: String,
    pub(crate) doc_id: String,
    pub(crate) body: Value,
}

/// Collection names: `<= 64` chars of the doc-id set (spec 20 Limits).
pub(crate) const MAX_DB_COLLECTION_CHARS: usize = 64;
/// Doc ids: `<= 128` chars of `[A-Za-z0-9._-]` (spec 20 Limits).
pub(crate) const MAX_DB_DOC_ID_CHARS: usize = 128;
/// `db.seed` bodies may total at most 256 KiB (spec 20 Design).
pub(crate) const MAX_DB_SEED_BYTES: usize = 256 * 1024;
/// At most 64 declared collections per artifact; the manifest is small and
/// every collection is a query surface, so an unbounded map is a DoS smell.
pub(crate) const MAX_DB_COLLECTIONS: usize = 64;

/// Whether `name` is a valid collection or doc-id name: non-empty, within
/// `max` chars, only `[A-Za-z0-9._-]` (spec 20 Limits).
pub(crate) fn is_valid_db_name(name: &str, max: usize) -> bool {
    !name.is_empty()
        && name.len() <= max
        && name
            .bytes()
            .all(|byte| byte.is_ascii_alphanumeric() || matches!(byte, b'.' | b'_' | b'-'))
}

/// Validates the manifest's `db` declaration, mirroring
/// [`validate_permanent_manifest`]: `None` when the manifest declares no
/// database (absent key, or an explicit `db: null` which turns the endpoint
/// off while keeping existing rows). Collections declare at most one index
/// each; unknown collection keys fail validation; every seed row names a
/// declared collection and a valid doc id with an object body, and the seed
/// bodies total at most [`MAX_DB_SEED_BYTES`].
pub(crate) fn validate_db_manifest(manifest: &Value) -> Result<Option<DbManifest>, ErrorCode> {
    let Some(db) = manifest.get("db") else {
        return Ok(None);
    };
    if db.is_null() {
        return Ok(None);
    };
    let db = db.as_object().ok_or(ErrorCode::ValidationFailed)?;
    if !db.keys().all(|key| key == "collections" || key == "seed") {
        return Err(ErrorCode::ValidationFailed);
    }
    let mut collections = std::collections::BTreeMap::new();
    if let Some(declared) = db.get("collections") {
        let declared = declared.as_object().ok_or(ErrorCode::ValidationFailed)?;
        if declared.len() > MAX_DB_COLLECTIONS {
            return Err(ErrorCode::ValidationFailed);
        }
        for (name, decl) in declared {
            if !is_valid_db_name(name, MAX_DB_COLLECTION_CHARS) {
                return Err(ErrorCode::ValidationFailed);
            }
            let decl = decl.as_object().ok_or(ErrorCode::ValidationFailed)?;
            let index = match decl.get("index") {
                None => None,
                Some(Value::String(field)) if !field.is_empty() && field.len() <= MAX_DB_COLLECTION_CHARS => {
                    Some(field.clone())
                }
                Some(_) => return Err(ErrorCode::ValidationFailed),
            };
            if decl.len() != usize::from(index.is_some()) {
                return Err(ErrorCode::ValidationFailed);
            }
            collections.insert(
                name.clone(),
                DbCollectionDecl { index },
            );
        }
    }
    let mut seed = Vec::new();
    if let Some(rows) = db.get("seed") {
        let rows = rows.as_array().ok_or(ErrorCode::ValidationFailed)?;
        let mut seen = std::collections::HashSet::new();
        let mut total_bytes = 0usize;
        for row in rows {
            let row = row.as_object().ok_or(ErrorCode::ValidationFailed)?;
            let collection = row
                .get("collection")
                .and_then(Value::as_str)
                .ok_or(ErrorCode::ValidationFailed)?;
            let doc_id = row
                .get("doc_id")
                .and_then(Value::as_str)
                .ok_or(ErrorCode::ValidationFailed)?;
            let body = row.get("body").ok_or(ErrorCode::ValidationFailed)?;
            if row.len() != 3
                || !collections.contains_key(collection)
                || !is_valid_db_name(doc_id, MAX_DB_DOC_ID_CHARS)
                || !body.is_object()
            {
                return Err(ErrorCode::ValidationFailed);
            }
            if !seen.insert((collection.to_string(), doc_id.to_string())) {
                return Err(ErrorCode::ValidationFailed);
            }
            total_bytes = total_bytes.saturating_add(body.to_string().len());
            if total_bytes > MAX_DB_SEED_BYTES {
                return Err(ErrorCode::BundleTooLarge);
            }
            seed.push(DbSeedRow {
                collection: collection.to_string(),
                doc_id: doc_id.to_string(),
                body: body.clone(),
            });
        }
    }
    // A `db` object carrying neither collections nor seed declares nothing.
    if collections.is_empty() {
        return Ok(None);
    }
    Ok(Some(DbManifest { collections, seed }))
}

/// Version-publish carry-forward (spec 20): a publish whose manifest omits
/// the `db` key keeps the live artifact's declaration; an explicit `db: null`
/// turns the endpoint off (rows stay); a present `db` object replaces the
/// declaration. Pure so the republish/seed tests pin it without D1.
pub(crate) fn apply_db_carry_forward(raw_manifest: &Value, previous_manifest: &Value) -> Value {
    let mut manifest = raw_manifest.clone();
    let Some(object) = manifest.as_object_mut() else {
        return manifest;
    };
    if object.contains_key("db") {
        return manifest;
    }
    if let Some(previous_db) = previous_manifest.get("db") {
        object.insert("db".to_string(), previous_db.clone());
    }
    manifest
 }

/// Title / description maxima shared by permanent and ephemeral creates (RUB-465).
pub(crate) const MAX_TITLE_CHARS: usize = 200;
pub(crate) const MAX_DESCRIPTION_CHARS: usize = 1000;
/// Ephemeral thumbnail URLs have no permanent counterpart; keep them short.
pub(crate) const MAX_THUMBNAIL_CHARS: usize = 2000;

/// A string field trimmed to `max` characters; empty or non-string is `None`.
pub(crate) fn clipped_text(value: Option<&Value>, max: usize) -> Option<String> {
    let text = value?.as_str()?.trim();
    (!text.is_empty()).then(|| text.chars().take(max).collect())
}

/// Same clip as [`clipped_text`] for already-deserialized `Option<String>` fields.
pub(crate) fn clipped_string(value: Option<String>, max: usize) -> Option<String> {
    let text = value?.trim().to_string();
    (!text.is_empty()).then(|| text.chars().take(max).collect())
}

/// Version publish: an omitted / empty request field keeps the previous value
/// so a content-only update does not wipe title or description (RUB-515).
pub(crate) fn coalesce_clipped_text(
    request_value: Option<&Value>,
    max: usize,
    previous: Option<&str>,
) -> Option<String> {
    clipped_text(request_value, max).or_else(|| previous.map(str::to_string))
}
