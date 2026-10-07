//! Artifact versioning endpoints (RUB-437): publish, list, read and restore
//! versions of a permanent artifact.
//!
//! Data-model invariants live in `migrations/0006_artifact_versions.sql`.
//! `artifacts` (and its `files`/`provenance` rows) is a copy of the *current*
//! version; `artifact_versions` holds every version's own manifest and
//! provenance; `version_files` holds every version's uploaded files. Promotion
//! replaces the current copy from a completed version in one D1 batch, guarded
//! so a slower, older pending version finishing late cannot overwrite a newer
//! current one.

use super::*;
use crate::sharing::{self, can_view, EditAccess, Sharing, Viewer};

/// The 201/200 body for publishing or restoring a version.
#[derive(Debug, Serialize)]
pub(crate) struct ArtifactVersionResponse {
    pub(crate) id: String,
    pub(crate) version: u32,
    pub(crate) url: String,
    pub(crate) created: bool,
    pub(crate) missing_files: Vec<String>,
    pub(crate) tier: ArtifactTier,
    pub(crate) sharing: Sharing,
}

/// One version in a history listing / read.
#[derive(Debug, Serialize)]
pub(crate) struct ArtifactVersion {
    pub(crate) version: u32,
    pub(crate) created_at: String,
    pub(crate) created_by: Option<String>,
    pub(crate) agent: Option<String>,
    pub(crate) title: Option<String>,
    pub(crate) description: Option<String>,
    pub(crate) content_hash: String,
    pub(crate) current: bool,
    pub(crate) restored_from: Option<u32>,
}

#[derive(Debug, Serialize)]
pub(crate) struct ArtifactVersionList {
    pub(crate) id: String,
    pub(crate) current_version: u32,
    pub(crate) versions: Vec<ArtifactVersion>,
}

/// One `artifact_versions` row, in the shape every read path selects.
#[derive(Debug, Deserialize)]
pub(crate) struct VersionRow {
    pub(crate) id: String,
    pub(crate) version: i64,
    pub(crate) created_at: String,
    pub(crate) created_by: Option<String>,
    pub(crate) agent: Option<String>,
    pub(crate) repo_url: Option<String>,
    pub(crate) commit_sha: Option<String>,
    pub(crate) title: Option<String>,
    pub(crate) description: Option<String>,
    pub(crate) content_hash: String,
    pub(crate) manifest: String,
    pub(crate) entrypoint: Option<String>,
    pub(crate) provenance: String,
    pub(crate) restored_from: Option<i64>,
    pub(crate) expires_at: Option<String>,
}

/// The live `artifacts` row a version operation targets.
///
/// Legal hold and revocation are columns on this single row
/// (`artifacts.legal_hold`, `artifacts.revoked_at`), so by construction they
/// cover every version of the artifact. `revoked_at IS NULL` is also why a
/// revoked artifact's versions can be neither published nor restored: the row
/// is not found, and both paths map that to 404 (never 403).
#[derive(Debug, Deserialize)]
struct LiveArtifactRow {
    row_id: String,
    content_hash: String,
    current_version: i64,
    user_id: Option<String>,
    tier: String,
    edit_access: String,
}

#[derive(Debug, Deserialize)]
struct HighestVersionRow {
    highest: i64,
}

/// Parses the three version paths:
///
/// - `/v1/artifacts/{id}/versions` -> `(id, None, false)`
/// - `/v1/artifacts/{id}/versions/{n}` -> `(id, Some(n), false)`
/// - `/v1/artifacts/{id}/versions/{n}/restore` -> `(id, Some(n), true)`
///
/// Pure and strict: an unknown trailing segment, a non-numeric or zero
/// version, or an empty id all return `None`, so the dispatch guard can use
/// it directly and an unimplemented shape falls through to 404 rather than
/// being routed somewhere else.
pub(crate) fn parse_version_path(path: &str) -> Option<(&str, Option<u32>, bool)> {
    let rest = path.strip_prefix("/v1/artifacts/")?;
    let (id, tail) = rest.split_once('/')?;
    if id.is_empty() || !store::is_permanent_id(id) {
        return None;
    }
    let tail = tail
        .strip_prefix("versions")
        .filter(|suffix| suffix.is_empty() || suffix.starts_with('/'))?;
    if tail.is_empty() {
        return Some((id, None, false));
    }
    let segments = tail.trim_start_matches('/').split('/').collect::<Vec<_>>();
    match segments.as_slice() {
        [number] => Some((id, Some(parse_version_number(number)?), false)),
        [number, "restore"] => Some((id, Some(parse_version_number(number)?), true)),
        _ => None,
    }
}

fn parse_version_number(value: &str) -> Option<u32> {
    let parsed = value.parse::<u32>().ok()?;
    (parsed >= 1).then_some(parsed)
}

/// Whether this credential may publish or restore a version of an artifact.
/// Delegates to the one rule set in `sharing`: the owner always may, any
/// member of the org may version a legacy ownerless artifact, team + edit
/// allows the org, and public + edit allows a signed-in account. Callers must
/// have already resolved the artifact within the credential's org.
pub(crate) fn decide_version_publish(
    sharing: Sharing,
    edit_access: EditAccess,
    owner_user_id: Option<&str>,
    viewer: &Viewer<'_>,
    same_org: bool,
) -> bool {
    sharing::can_publish_version(sharing, edit_access, owner_user_id, viewer, same_org)
}

/// Promotion guard: a completing version only replaces the current copy when
/// it is strictly newer than what is already current. A slower, older pending
/// version finishing late just completes in history.
pub(crate) fn should_promote(version: u32, current_version: u32) -> bool {
    version > current_version
}

/// The next version number given the highest existing one (`None` when the
/// artifact somehow has no versions yet).
pub(crate) fn next_version(highest: Option<u32>) -> u32 {
    highest.unwrap_or(0) + 1
}

/// A D1 error message that names the `(artifact_row_id, version)` unique
/// constraint belongs to a concurrent publish of the same next version.
pub(crate) fn is_version_conflict(message: &str) -> bool {
    message.contains("UNIQUE constraint failed") && message.contains("artifact_versions")
}

fn tier_from_database(value: &str) -> ArtifactTier {
    match value {
        "public" => ArtifactTier::Public,
        "secure" => ArtifactTier::Secure,
        _ => ArtifactTier::Private,
    }
}

pub(crate) fn build_version_response(
    id: &str,
    base_url: &str,
    version: u32,
    created: bool,
    missing_files: Vec<String>,
    tier: ArtifactTier,
    sharing: Sharing,
) -> ArtifactVersionResponse {
    ArtifactVersionResponse {
        id: id.to_string(),
        version,
        url: format!("{}/p/{id}/", base_url.trim_end_matches('/')),
        created,
        missing_files,
        tier,
        sharing,
    }
}

pub(crate) fn build_artifact_version(row: &VersionRow, current_version: u32) -> ArtifactVersion {
    let version = row.version.max(1) as u32;
    ArtifactVersion {
        version,
        created_at: row.created_at.clone(),
        created_by: row.created_by.clone(),
        agent: row.agent.clone(),
        title: row.title.clone(),
        description: row.description.clone(),
        content_hash: row.content_hash.clone(),
        current: version == current_version,
        restored_from: row.restored_from.map(|value| value as u32),
    }
}

pub(crate) fn build_version_list(
    id: &str,
    current_version: u32,
    versions: Vec<ArtifactVersion>,
) -> ArtifactVersionList {
    ArtifactVersionList {
        id: id.to_string(),
        current_version,
        versions,
    }
}

/// The statements that make a completed version the current one. Run as a
/// single D1 batch by publish, upload completion and restore.
///
/// Every statement that touches the current copy is gated on
/// `current_version < version`. Because a batch is one transaction and they
/// all run before the `artifacts` update, they all see the same pre-promotion
/// `current_version`: either all of them run, or (a slower, older version) none
/// of them do and only the version's own `expires_at` is cleared.
pub(crate) fn promotion_statements(
    database: &worker::D1Database,
    row_id: &str,
    version_id: &str,
    version: u32,
    current_version: u32,
    updated_at: &str,
) -> Result<Vec<worker::d1::D1PreparedStatement>> {
    // The version still completes in history even when it is not promoted, so
    // clear its upload deadline either way.
    let clear_expiry = database
        .prepare("UPDATE artifact_versions SET expires_at = NULL WHERE id = ?")
        .bind(&[JsValue::from_str(version_id)])?;
    if !should_promote(version, current_version) {
        return Ok(vec![clear_expiry]);
    }
    let version_number = JsValue::from_f64(version as f64);
    Ok(vec![
        database
            .prepare("DELETE FROM files WHERE artifact_row_id = ? AND EXISTS (SELECT 1 FROM artifacts WHERE row_id = ? AND current_version < ?)")
            .bind(&[
                JsValue::from_str(row_id),
                JsValue::from_str(row_id),
                version_number.clone(),
            ])?,
        database
            .prepare("INSERT INTO files (artifact_row_id, path, content_hash, content_type, size_bytes) SELECT ?, vf.path, vf.content_hash, vf.content_type, vf.size_bytes FROM version_files vf WHERE vf.version_id = ? AND EXISTS (SELECT 1 FROM artifacts WHERE row_id = ? AND current_version < ?)")
            .bind(&[
                JsValue::from_str(row_id),
                JsValue::from_str(version_id),
                JsValue::from_str(row_id),
                version_number.clone(),
            ])?,
        database
            .prepare("DELETE FROM provenance WHERE artifact_row_id = ? AND EXISTS (SELECT 1 FROM artifacts WHERE row_id = ? AND current_version < ?)")
            .bind(&[
                JsValue::from_str(row_id),
                JsValue::from_str(row_id),
                version_number.clone(),
            ])?,
        database
            .prepare("INSERT INTO provenance (artifact_row_id, agent, repo_url, commit_sha, payload) SELECT ?, v.agent, v.repo_url, v.commit_sha, v.provenance FROM artifact_versions v WHERE v.id = ? AND EXISTS (SELECT 1 FROM artifacts WHERE row_id = ? AND current_version < ?)")
            .bind(&[
                JsValue::from_str(row_id),
                JsValue::from_str(version_id),
                JsValue::from_str(row_id),
                version_number.clone(),
            ])?,
        database
            .prepare("UPDATE artifacts SET content_hash = (SELECT content_hash FROM artifact_versions WHERE id = ?), entrypoint = (SELECT entrypoint FROM artifact_versions WHERE id = ?), manifest = (SELECT manifest FROM artifact_versions WHERE id = ?), title = (SELECT title FROM artifact_versions WHERE id = ?), description = (SELECT description FROM artifact_versions WHERE id = ?), current_version = (SELECT version FROM artifact_versions WHERE id = ?), updated_at = ? WHERE row_id = ? AND current_version < ?")
            .bind(&[
                JsValue::from_str(version_id),
                JsValue::from_str(version_id),
                JsValue::from_str(version_id),
                JsValue::from_str(version_id),
                JsValue::from_str(version_id),
                JsValue::from_str(version_id),
                JsValue::from_str(updated_at),
                JsValue::from_str(row_id),
                version_number,
            ])?,
        clear_expiry,
    ])
}

async fn live_artifact(
    database: &worker::D1Database,
    org: &str,
    artifact_id: &str,
) -> Result<Option<LiveArtifactRow>> {
    database
        .prepare("SELECT row_id, content_hash, current_version, user_id, tier, edit_access FROM artifacts WHERE id = ? AND org_id = ? AND revoked_at IS NULL ORDER BY row_id LIMIT 1")
        .bind(&[JsValue::from_str(artifact_id), JsValue::from_str(org)])?
        .first::<LiveArtifactRow>(None)
        .await
}

const VERSION_COLUMNS: &str = "id, version, created_at, created_by, agent, repo_url, commit_sha, title, description, content_hash, manifest, entrypoint, provenance, restored_from, expires_at";

pub(crate) async fn get_version(path: &str, req: &Request, env: &Env) -> Result<Response> {
    let Some((artifact_id, version, restore)) = parse_version_path(path) else {
        return not_found_response();
    };
    if restore {
        return not_found_response();
    }
    let authorization = req.headers().get("Authorization")?;
    let credential =
        match require_org_scope(authorization.as_deref(), env, "artifacts:read").await? {
            Ok(credential) => credential,
            Err(refusal) => return Ok(refusal),
        };
    let storage =
        store::D1R2ArtifactStore::new(env.d1("ARTIFACTS_DB")?, env.bucket("ARTIFACTS_BUCKET")?);
    let Some(artifact) = live_artifact(&storage.database, &credential.org_id, artifact_id).await?
    else {
        return json_error(ErrorCode::ArtifactNotFound, "Artifact not found.", 404);
    };
    // Org first (the lookup is scoped to the credential's org), then
    // visibility. A private artifact this member cannot view is the same 404,
    // never 403.
    let viewer = Viewer::from_credential(&credential);
    if !can_view(
        Sharing::from_tier(&artifact.tier),
        artifact.user_id.as_deref(),
        &viewer,
    ) {
        return json_error(ErrorCode::ArtifactNotFound, "Artifact not found.", 404);
    }
    match version {
        None => list_versions(&storage, artifact_id, &artifact).await,
        Some(number) => get_one_version(&storage, number, &artifact).await,
    }
}

async fn list_versions(
    storage: &store::D1R2ArtifactStore,
    artifact_id: &str,
    artifact: &LiveArtifactRow,
) -> Result<Response> {
    let rows = storage
        .database
        .prepare(format!(
            "SELECT {VERSION_COLUMNS} FROM artifact_versions WHERE artifact_row_id = ? AND expires_at IS NULL ORDER BY version DESC"
        ))
        .bind(&[JsValue::from_str(&artifact.row_id)])?
        .all()
        .await?
        .results::<VersionRow>()?;
    let current_version = artifact.current_version.max(1) as u32;
    let versions = rows
        .iter()
        .map(|row| build_artifact_version(row, current_version))
        .collect();
    JsonResponseDefinition::json(
        build_version_list(artifact_id, current_version, versions),
        200,
    )
    .into_worker_response()
}

async fn get_one_version(
    storage: &store::D1R2ArtifactStore,
    version: u32,
    artifact: &LiveArtifactRow,
) -> Result<Response> {
    let row = storage
        .database
        .prepare(format!(
            "SELECT {VERSION_COLUMNS} FROM artifact_versions WHERE artifact_row_id = ? AND version = ? LIMIT 1"
        ))
        .bind(&[
            JsValue::from_str(&artifact.row_id),
            JsValue::from_f64(version as f64),
        ])?
        .first::<VersionRow>(None)
        .await?;
    let Some(row) = row else {
        return json_error(ErrorCode::VersionNotFound, "Version not found.", 404);
    };
    // A pending version is not yet part of history.
    if row.expires_at.is_some() {
        return json_error(ErrorCode::VersionNotFound, "Version not found.", 404);
    }
    let current_version = artifact.current_version.max(1) as u32;
    JsonResponseDefinition::json(build_artifact_version(&row, current_version), 200)
        .into_worker_response()
}

pub(crate) async fn post_version(
    path: &str,
    req: &mut Request,
    env: &Env,
    ctx: &worker::Context,
) -> Result<Response> {
    let Some((artifact_id, version, restore)) = parse_version_path(path) else {
        return not_found_response();
    };
    match (version, restore) {
        (None, false) => create_version(artifact_id, req, env, ctx).await,
        (Some(number), true) => restore_version(artifact_id, number, req, env, ctx).await,
        _ => not_found_response(),
    }
}

/// Required version metadata fields, matching the create body apart from the
/// tier/mode (a version never changes the artifact's sharing tier).
const VERSION_METADATA_FIELDS: [&str; 5] = [
    "title",
    "description",
    "thumbnail",
    "preview_blurred",
    "provenance",
];

fn manifest_error_response(code: ErrorCode) -> Result<Response> {
    let message = match code {
        ErrorCode::EntrypointMissing => "The manifest entrypoint is missing.",
        ErrorCode::InvalidPath => "The manifest contains an invalid path.",
        ErrorCode::DuplicatePath => "The manifest contains a duplicate path.",
        ErrorCode::BundleTooLarge => "The permanent bundle is too large.",
        ErrorCode::FileCountExceeded => "The permanent bundle has too many files.",
        _ => "The permanent manifest is invalid.",
    };
    let status = if code == ErrorCode::BundleTooLarge {
        413
    } else {
        422
    };
    json_error(code, message, status)
}

async fn create_version(
    artifact_id: &str,
    req: &mut Request,
    env: &Env,
    ctx: &worker::Context,
) -> Result<Response> {
    let authorization = req.headers().get("Authorization")?;
    let credential =
        match require_org_scope(authorization.as_deref(), env, "artifacts:deploy").await? {
            Ok(credential) => credential,
            Err(refusal) => return Ok(refusal),
        };
    let raw = match req.json::<Value>().await {
        Ok(payload) => payload,
        Err(_) => {
            return json_error(ErrorCode::InvalidJson, "Invalid JSON request body.", 400);
        }
    };
    for field in VERSION_METADATA_FIELDS {
        if raw.get(field).is_none() {
            return json_error(
                ErrorCode::ValidationFailed,
                "Version metadata is incomplete.",
                422,
            );
        }
    }
    let (manifest, bundle_hash) = match validate_permanent_manifest(&raw) {
        Ok(value) => value,
        Err(code) => return manifest_error_response(code),
    };
    let content_hash = bundle_hash.as_str();
    let storage =
        store::D1R2ArtifactStore::new(env.d1("ARTIFACTS_DB")?, env.bucket("ARTIFACTS_BUCKET")?);
    let org = credential.org_id.clone();
    let Some(artifact) = live_artifact(&storage.database, &org, artifact_id).await? else {
        return json_error(ErrorCode::ArtifactNotFound, "Artifact not found.", 404);
    };
    let sharing = Sharing::from_tier(&artifact.tier);
    let tier = tier_from_database(&artifact.tier);
    let edit_access = EditAccess::from_stored(&artifact.edit_access);
    let viewer = Viewer::from_credential(&credential);
    // Same org only for now: the public + edit cross-org write path is a later
    // slice.
    if !decide_version_publish(
        sharing,
        edit_access,
        artifact.user_id.as_deref(),
        &viewer,
        true,
    ) {
        return json_error(
            ErrorCode::Forbidden,
            "You may not publish a new version of this artifact.",
            403,
        );
    }
    let declared = quota::declared_bytes(manifest.files.iter().map(|file| file.size_bytes));
    if let Some(refusal) = quota_refusal(
        &storage.database,
        env,
        &org,
        declared,
        quota::AddKind::Create,
    )
    .await?
    {
        return Ok(refusal);
    }
    // The bundle lock serialises same-content creates; the per-artifact lock
    // serialises version-numbering and promotion for this artifact.
    let lock_keys = vec![
        artifact_lock_key(&artifact.row_id),
        bundle_lock_key(&org, content_hash),
    ];
    let locks = match storage.acquire_content_locks(&lock_keys).await {
        Ok(locks) => locks,
        Err(store::StoreError::Contention) => return retryable_contention_response(),
        Err(error) => return Err(worker::Error::RustError(error.to_string())),
    };
    let now = Utc::now().to_rfc3339_opts(SecondsFormat::Secs, true);
    let upload_expires_at =
        (Utc::now() + chrono::Duration::hours(1)).to_rfc3339_opts(SecondsFormat::Secs, true);
    let operation: Result<Response> = async {
        let base_url = env_string(env, "ARTFCT_PUBLIC_BASE_URL", DEFAULT_BASE_URL);
        if content_hash == artifact.content_hash {
            return JsonResponseDefinition::json(
                build_version_response(
                    artifact_id,
                    &base_url,
                    artifact.current_version.max(1) as u32,
                    false,
                    Vec::new(),
                    tier,
                    sharing,
                ),
                200,
            )
            .into_worker_response();
        }
        let highest = storage
            .database
            .prepare("SELECT COALESCE(MAX(version), 0) AS highest FROM artifact_versions WHERE artifact_row_id = ?")
            .bind(&[JsValue::from_str(&artifact.row_id)])?
            .first::<HighestVersionRow>(None)
            .await?
            .map(|row| row.highest)
            .unwrap_or(0);
        let version = next_version(u32::try_from(highest).ok());
        let mut present = Vec::with_capacity(manifest.files.len());
        for file in &manifest.files {
            present.push(
                storage
                    .blob_exists(&file.sha256)
                    .await
                    .map_err(|error| worker::Error::RustError(error.to_string()))?,
            );
        }
        let missing_files = missing_manifest_files(&manifest, &present);
        let expires_value = if missing_files.is_empty() {
            JsValue::null()
        } else {
            JsValue::from_str(&upload_expires_at)
        };
        let version_id = Uuid::new_v4().simple().to_string();
        let manifest_json = serde_json::to_string(&manifest)?;
        let provenance = raw
            .get("provenance")
            .cloned()
            .unwrap_or_else(|| serde_json::json!({}));
        let provenance_json = provenance.to_string();
        let agent = provenance.get("agent").and_then(Value::as_str);
        let repo_url = provenance.get("repo_url").and_then(Value::as_str);
        let commit_sha = provenance.get("commit_sha").and_then(Value::as_str);
        let title = clipped_text(raw.get("title"), 200);
        let description = clipped_text(raw.get("description"), 1000);
        let mut statements = vec![
            storage
                .database
                .prepare("INSERT OR IGNORE INTO users (id, created_at) VALUES (?, ?)")
                .bind(&[
                    JsValue::from_str(&credential.user_id),
                    JsValue::from_str(&now),
                ])?,
            storage
                .database
                .prepare("INSERT INTO artifact_versions (id, artifact_row_id, version, created_at, content_hash, entrypoint, manifest, title, description, created_by, agent, repo_url, commit_sha, provenance, expires_at, restored_from) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NULL)")
                .bind(&[
                    JsValue::from_str(&version_id),
                    JsValue::from_str(&artifact.row_id),
                    JsValue::from_f64(version as f64),
                    JsValue::from_str(&now),
                    JsValue::from_str(content_hash),
                    JsValue::from_str(&manifest.entrypoint),
                    JsValue::from_str(&manifest_json),
                    title.as_deref().map(JsValue::from_str).unwrap_or_else(JsValue::null),
                    description.as_deref().map(JsValue::from_str).unwrap_or_else(JsValue::null),
                    JsValue::from_str(&credential.user_id),
                    agent.map(JsValue::from_str).unwrap_or_else(JsValue::null),
                    repo_url.map(JsValue::from_str).unwrap_or_else(JsValue::null),
                    commit_sha.map(JsValue::from_str).unwrap_or_else(JsValue::null),
                    JsValue::from_str(&provenance_json),
                    expires_value.clone(),
                ])?,
        ];
        for (file, is_present) in manifest.files.iter().zip(present.iter().copied()) {
            statements.push(
                storage
                    .database
                    .prepare("INSERT OR IGNORE INTO blobs (content_hash, size_bytes, content_type, ref_count, created_at) VALUES (?, ?, ?, 0, ?)")
                    .bind(&[
                        JsValue::from_str(&file.sha256),
                        JsValue::from_f64(file.size_bytes as f64),
                        JsValue::from_str(&file.content_type),
                        JsValue::from_str(&now),
                    ])?,
            );
            statements.push(
                storage
                    .database
                    .prepare("UPDATE blobs SET ref_count = ref_count + 1 WHERE content_hash = ?")
                    .bind(&[JsValue::from_str(&file.sha256)])?,
            );
            if is_present {
                statements.push(
                    storage
                        .database
                        .prepare("INSERT OR REPLACE INTO version_files (version_id, path, content_hash, content_type, size_bytes) VALUES (?, ?, ?, ?, ?)")
                        .bind(&[
                            JsValue::from_str(&version_id),
                            JsValue::from_str(&file.path),
                            JsValue::from_str(&file.sha256),
                            JsValue::from_str(&file.content_type),
                            JsValue::from_f64(file.size_bytes as f64),
                        ])?,
                );
            }
        }
        if missing_files.is_empty() {
            statements.extend(promotion_statements(
                &storage.database,
                &artifact.row_id,
                &version_id,
                version,
                artifact.current_version.max(1) as u32,
                &now,
            )?);
        }
        if let Err(error) = storage.execute_batch(statements).await {
            let message = error.to_string();
            if is_version_conflict(&message) {
                return json_error(
                    ErrorCode::VersionConflict,
                    "Another version of this artifact is being published.",
                    409,
                );
            }
            return Err(worker::Error::RustError(message));
        }
        if missing_files.is_empty() {
            emit_artifact_version_created(ctx, env, &org, artifact_id, version, tier);
        }
        JsonResponseDefinition::json(
            build_version_response(artifact_id, &base_url, version, true, missing_files, tier, sharing),
            201,
        )
        .into_worker_response()
    }
    .await;
    let release_result = storage.release_content_locks(&locks).await;
    let response = operation?;
    release_result.map_err(|error| worker::Error::RustError(error.to_string()))?;
    Ok(response)
}

async fn restore_version(
    artifact_id: &str,
    source_version: u32,
    req: &mut Request,
    env: &Env,
    ctx: &worker::Context,
) -> Result<Response> {
    let authorization = req.headers().get("Authorization")?;
    let credential =
        match require_org_scope(authorization.as_deref(), env, "artifacts:deploy").await? {
            Ok(credential) => credential,
            Err(refusal) => return Ok(refusal),
        };
    let storage =
        store::D1R2ArtifactStore::new(env.d1("ARTIFACTS_DB")?, env.bucket("ARTIFACTS_BUCKET")?);
    let org = credential.org_id.clone();
    let Some(artifact) = live_artifact(&storage.database, &org, artifact_id).await? else {
        return json_error(ErrorCode::ArtifactNotFound, "Artifact not found.", 404);
    };
    let sharing = Sharing::from_tier(&artifact.tier);
    let tier = tier_from_database(&artifact.tier);
    let edit_access = EditAccess::from_stored(&artifact.edit_access);
    let viewer = Viewer::from_credential(&credential);
    if !decide_version_publish(
        sharing,
        edit_access,
        artifact.user_id.as_deref(),
        &viewer,
        true,
    ) {
        return json_error(
            ErrorCode::Forbidden,
            "You may not restore a version of this artifact.",
            403,
        );
    }
    let locks = match storage
        .acquire_content_locks(&[artifact_lock_key(&artifact.row_id)])
        .await
    {
        Ok(locks) => locks,
        Err(store::StoreError::Contention) => return retryable_contention_response(),
        Err(error) => return Err(worker::Error::RustError(error.to_string())),
    };
    let now = Utc::now().to_rfc3339_opts(SecondsFormat::Secs, true);
    let operation: Result<Response> = async {
        let base_url = env_string(env, "ARTFCT_PUBLIC_BASE_URL", DEFAULT_BASE_URL);
        let source = storage
            .database
            .prepare(format!(
                "SELECT {VERSION_COLUMNS} FROM artifact_versions WHERE artifact_row_id = ? AND version = ? AND expires_at IS NULL LIMIT 1"
            ))
            .bind(&[
                JsValue::from_str(&artifact.row_id),
                JsValue::from_f64(source_version as f64),
            ])?
            .first::<VersionRow>(None)
            .await?;
        let Some(source) = source else {
            return json_error(ErrorCode::VersionNotFound, "Version not found.", 404);
        };
        if source.content_hash == artifact.content_hash {
            return JsonResponseDefinition::json(
                build_version_response(
                    artifact_id,
                    &base_url,
                    artifact.current_version.max(1) as u32,
                    false,
                    Vec::new(),
                    tier,
                    sharing,
                ),
                200,
            )
            .into_worker_response();
        }
        let highest = storage
            .database
            .prepare("SELECT COALESCE(MAX(version), 0) AS highest FROM artifact_versions WHERE artifact_row_id = ?")
            .bind(&[JsValue::from_str(&artifact.row_id)])?
            .first::<HighestVersionRow>(None)
            .await?
            .map(|row| row.highest)
            .unwrap_or(0);
        let version = next_version(u32::try_from(highest).ok());
        let version_id = Uuid::new_v4().simple().to_string();
        let source_manifest: PermanentManifest = serde_json::from_str(&source.manifest)?;
        let mut statements = vec![
            storage
                .database
                .prepare("INSERT OR IGNORE INTO users (id, created_at) VALUES (?, ?)")
                .bind(&[
                    JsValue::from_str(&credential.user_id),
                    JsValue::from_str(&now),
                ])?,
            storage
                .database
                .prepare("INSERT INTO artifact_versions (id, artifact_row_id, version, created_at, content_hash, entrypoint, manifest, title, description, created_by, agent, repo_url, commit_sha, provenance, expires_at, restored_from) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NULL, ?)")
                .bind(&[
                    JsValue::from_str(&version_id),
                    JsValue::from_str(&artifact.row_id),
                    JsValue::from_f64(version as f64),
                    JsValue::from_str(&now),
                    JsValue::from_str(&source.content_hash),
                    source.entrypoint.as_deref().map(JsValue::from_str).unwrap_or_else(JsValue::null),
                    JsValue::from_str(&source.manifest),
                    source.title.as_deref().map(JsValue::from_str).unwrap_or_else(JsValue::null),
                    source.description.as_deref().map(JsValue::from_str).unwrap_or_else(JsValue::null),
                    JsValue::from_str(&credential.user_id),
                    source.agent.as_deref().map(JsValue::from_str).unwrap_or_else(JsValue::null),
                    source.repo_url.as_deref().map(JsValue::from_str).unwrap_or_else(JsValue::null),
                    source.commit_sha.as_deref().map(JsValue::from_str).unwrap_or_else(JsValue::null),
                    JsValue::from_str(&source.provenance),
                    JsValue::from_f64(source.version as f64),
                ])?,
            storage
                .database
                .prepare("INSERT INTO version_files (version_id, path, content_hash, content_type, size_bytes) SELECT ?, path, content_hash, content_type, size_bytes FROM version_files WHERE version_id = ?")
                .bind(&[
                    JsValue::from_str(&version_id),
                    JsValue::from_str(&source.id),
                ])?,
        ];
        for file in &source_manifest.files {
            statements.push(
                storage
                    .database
                    .prepare("UPDATE blobs SET ref_count = ref_count + 1 WHERE content_hash = ?")
                    .bind(&[JsValue::from_str(&file.sha256)])?,
            );
        }
        statements.extend(promotion_statements(
            &storage.database,
            &artifact.row_id,
            &version_id,
            version,
            artifact.current_version.max(1) as u32,
            &now,
        )?);
        if let Err(error) = storage.execute_batch(statements).await {
            let message = error.to_string();
            if is_version_conflict(&message) {
                return json_error(
                    ErrorCode::VersionConflict,
                    "Another version of this artifact is being published.",
                    409,
                );
            }
            return Err(worker::Error::RustError(message));
        }
        emit_artifact_version_created(ctx, env, &org, artifact_id, version, tier);
        JsonResponseDefinition::json(
            build_version_response(artifact_id, &base_url, version, true, Vec::new(), tier, sharing),
            201,
        )
        .into_worker_response()
    }
    .await;
    let release_result = storage.release_content_locks(&locks).await;
    let response = operation?;
    release_result.map_err(|error| worker::Error::RustError(error.to_string()))?;
    Ok(response)
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn parse_version_path_recognises_the_three_shapes() {
        let id = "abcdefghijklm";
        assert_eq!(
            parse_version_path(&format!("/v1/artifacts/{id}/versions")),
            Some((id, None, false))
        );
        assert_eq!(
            parse_version_path(&format!("/v1/artifacts/{id}/versions/3")),
            Some((id, Some(3), false))
        );
        assert_eq!(
            parse_version_path(&format!("/v1/artifacts/{id}/versions/3/restore")),
            Some((id, Some(3), true))
        );
    }

    #[test]
    fn parse_version_path_rejects_other_paths() {
        for path in [
            "/v1/artifacts/abcdefghijklm",
            "/v1/artifacts/abcdefghijklm/files/abc",
            "/v1/artifacts//versions",
            "/v1/artifacts/abcdefghijklm/versions/0",
            "/v1/artifacts/abcdefghijklm/versions/x",
            "/v1/artifacts/abcdefghijklm/versions/3/restore/extra",
            "/v1/artifacts/abcdefghijklm/versionsfoo",
            "/v1/orgs/acme/artifacts/abcdefghijklm/versions",
            "/v1/artifacts/abc1234567/versions",
        ] {
            assert_eq!(parse_version_path(path), None, "path {path}");
        }
    }

    #[test]
    fn decide_version_publish_uses_the_sharing_rules() {
        let owner = Some("user-a");
        let member_a = Viewer {
            user_id: "user-a",
            role: "member",
            scope: None,
        };
        let member_b = Viewer {
            user_id: "user-b",
            role: "member",
            scope: None,
        };
        // A pre-owner (NULL) legacy artifact: anyone in the org may publish.
        assert!(decide_version_publish(
            Sharing::Private,
            EditAccess::View,
            None,
            &member_b,
            true
        ));
        // Owned: only the owner, even on a team + edit artifact for a different
        // member until edit access is granted.
        assert!(decide_version_publish(
            Sharing::Private,
            EditAccess::Edit,
            owner,
            &member_a,
            true
        ));
        assert!(!decide_version_publish(
            Sharing::Private,
            EditAccess::Edit,
            owner,
            &member_b,
            true
        ));
        // Team + edit: another member may publish; view alone does not.
        assert!(decide_version_publish(
            Sharing::Team,
            EditAccess::Edit,
            owner,
            &member_b,
            true
        ));
        assert!(!decide_version_publish(
            Sharing::Team,
            EditAccess::View,
            owner,
            &member_b,
            true
        ));
    }

    #[test]
    fn stored_tiers_fail_closed_to_private() {
        assert_eq!(tier_from_database("public"), ArtifactTier::Public);
        assert_eq!(tier_from_database("secure"), ArtifactTier::Secure);
        assert_eq!(tier_from_database("private"), ArtifactTier::Private);
        for unknown in ["", "bogus", "Public", "ephemeral", "open"] {
            assert_eq!(
                tier_from_database(unknown),
                ArtifactTier::Private,
                "{unknown}"
            );
        }
    }

    #[test]
    fn promotion_guard_only_promotes_newer_versions() {
        assert!(should_promote(4, 3));
        assert!(!should_promote(3, 3));
        assert!(!should_promote(2, 3));
    }

    #[test]
    fn next_version_follows_the_highest() {
        assert_eq!(next_version(None), 1);
        assert_eq!(next_version(Some(1)), 2);
        assert_eq!(next_version(Some(7)), 8);
    }

    #[test]
    fn version_conflicts_are_recognised() {
        assert!(is_version_conflict(
            "UNIQUE constraint failed: artifact_versions.artifact_row_id, artifact_versions.version"
        ));
        assert!(!is_version_conflict(
            "UNIQUE constraint failed: artifacts.id"
        ));
        assert!(!is_version_conflict("FOREIGN KEY constraint failed"));
    }
}
