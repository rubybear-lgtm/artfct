use super::*;
use crate::artifact_origin::{access_token_cookie, access_token_from_cookie};

#[derive(Debug, Deserialize)]
pub(crate) struct ContentRow {
    pub(crate) content_hash: String,
    pub(crate) content_type: String,
    pub(crate) tier: String,
    pub(crate) agent: Option<String>,
    pub(crate) repo_url: Option<String>,
    pub(crate) commit_sha: Option<String>,
    pub(crate) current_version: i64,
}

/// The org-credentialed content read's JSON body. Shared by the handler and
/// its contract test.
pub(crate) fn build_org_content_response(
    artifact_id: &str,
    row: &ContentRow,
    content: &str,
) -> Value {
    serde_json::json!({
        "id": artifact_id,
        "version": row.current_version,
        "content_type": row.content_type,
        "tier": row.tier,
        "provenance": {
            "agent": row.agent,
            "repo_url": row.repo_url,
            "commit_sha": row.commit_sha,
        },
        "content": content,
    })
}

/// Splits `/v1/orgs/{org}/artifacts/{id}/content` into `(org, id)`.
pub(crate) fn parse_content_path(path: &str) -> Option<(&str, &str)> {
    let rest = path.strip_prefix("/v1/orgs/")?.strip_suffix("/content")?;
    let (org, artifact_id) = rest.split_once("/artifacts/")?;
    if org.is_empty() || artifact_id.is_empty() || artifact_id.contains('/') {
        return None;
    }
    Some((org, artifact_id))
}

#[derive(Debug, PartialEq, Eq)]
pub(crate) enum OrgReadDecision {
    Unauthorized,
    NotFound,
    Allowed,
}

/// The single gate for the content read. A bad or missing org credential is
/// 401; an org other than the token's is indistinguishable from a missing
/// artifact (404, not 403) so the read never confirms another org's ids.
pub(crate) fn decide_org_read(credential_org: Option<&str>, path_org: &str) -> OrgReadDecision {
    match credential_org {
        None => OrgReadDecision::Unauthorized,
        Some(org) if org != path_org => OrgReadDecision::NotFound,
        Some(_) => OrgReadDecision::Allowed,
    }
}

/// `GET /v1/orgs/{org}/artifacts/{id}/content` — org-credentialed read of
/// an artifact's entrypoint plus its provenance, for server-side indexing
/// (spec 12). Revoked artifacts and other orgs' artifacts are 404.
pub(crate) async fn get_org_artifact_content(
    path: &str,
    req: &Request,
    env: &Env,
) -> Result<Response> {
    let authorization = req.headers().get("Authorization")?;
    let Some((org, artifact_id)) = parse_content_path(path) else {
        return json_error(ErrorCode::ArtifactNotFound, "Artifact not found.", 404);
    };
    let credential =
        match require_org_scope(authorization.as_deref(), env, "artifacts:read").await? {
            Ok(credential) => credential,
            Err(refusal) => return Ok(refusal),
        };
    let credential_org = Some(credential.org_id);
    match decide_org_read(credential_org.as_deref(), org) {
        OrgReadDecision::Unauthorized => {
            return json_error(
                ErrorCode::Unauthorized,
                "An organization token is required.",
                401,
            );
        }
        OrgReadDecision::NotFound => {
            return json_error(ErrorCode::ArtifactNotFound, "Artifact not found.", 404);
        }
        OrgReadDecision::Allowed => {}
    }
    let storage =
        store::D1R2ArtifactStore::new(env.d1("ARTIFACTS_DB")?, env.bucket("ARTIFACTS_BUCKET")?);
    let row = storage
        .database
        .prepare("SELECT f.content_hash, f.content_type, a.tier AS tier, a.current_version AS current_version, p.agent, p.repo_url, p.commit_sha FROM artifacts a JOIN files f ON f.artifact_row_id = a.row_id AND f.path = a.entrypoint JOIN orgs o ON o.id = a.org_id LEFT JOIN provenance p ON p.artifact_row_id = a.row_id WHERE a.id = ? AND o.slug = ? AND a.revoked_at IS NULL ORDER BY a.row_id LIMIT 1")
        .bind(&[JsValue::from_str(artifact_id), JsValue::from_str(org)])?
        .first::<ContentRow>(None)
        .await?;
    let Some(row) = row else {
        return json_error(ErrorCode::ArtifactNotFound, "Artifact not found.", 404);
    };
    let Some(object) = storage
        .bucket
        .get(format!("blobs/{}", row.content_hash))
        .execute()
        .await?
    else {
        return json_error(ErrorCode::ArtifactNotFound, "Artifact not found.", 404);
    };
    let Some(body) = object.body() else {
        return json_error(ErrorCode::ArtifactNotFound, "Artifact not found.", 404);
    };
    let bytes = body.bytes().await?;
    JsonResponseDefinition::json(
        build_org_content_response(artifact_id, &row, &String::from_utf8_lossy(&bytes)),
        200,
    )
    .into_worker_response()
}

#[derive(Debug, Deserialize)]
pub(crate) struct ArtifactMetadataRow {
    pub(crate) id: String,
    pub(crate) org_id: String,
    pub(crate) tier: String,
    pub(crate) entrypoint: String,
    pub(crate) created_at: String,
    pub(crate) expires_at: Option<String>,
    pub(crate) title: Option<String>,
    pub(crate) description: Option<String>,
    pub(crate) current_version: i64,
    pub(crate) version_count: i64,
    pub(crate) updated_at: String,
}

/// The metadata read's JSON body. `version` is the served version;
/// `version_count` counts completed versions (pending uploads are omitted).
/// Shared by the handler and its contract test.
pub(crate) fn build_artifact_metadata_response(row: &ArtifactMetadataRow) -> Value {
    serde_json::json!({
        "id": row.id,
        "tier": row.tier,
        "version": row.current_version,
        "version_count": row.version_count,
        "updated_at": row.updated_at,
        "entrypoint": row.entrypoint,
        "created_at": row.created_at,
        "expires_at": row.expires_at,
        "title": row.title,
        "description": row.description,
    })
}
#[derive(Debug, Deserialize)]
struct UploadArtifactRow {
    row_id: String,
    content_type: String,
    expected_size: i64,
    expires_at: Option<String>,
    manifest: String,
    /// Present only for a pending v2+ version; `NULL` for the current version.
    version_id: Option<String>,
    version: Option<i64>,
}

#[derive(Debug, Deserialize)]
struct VersionCompletionRow {
    expected: i64,
    present: i64,
    current_version: i64,
    tier: String,
    artifact_id: String,
}

/// Decrements `blobs.ref_count` once per manifest file and releases any blob no
/// longer referenced (invariant 3). Callers hold the content lock for each hash.
async fn decrement_and_release(
    storage: &store::D1R2ArtifactStore,
    manifest: &PermanentManifest,
) -> Result<()> {
    let hashes = manifest
        .files
        .iter()
        .map(|file| file.sha256.as_str())
        .collect::<Vec<_>>();
    for hash in &hashes {
        storage
            .database
            .prepare("UPDATE blobs SET ref_count = MAX(ref_count - 1, 0) WHERE content_hash = ?")
            .bind(&[JsValue::from_str(hash)])?
            .run()
            .await?;
    }
    for hash in hashes.into_iter().collect::<std::collections::HashSet<_>>() {
        release_blob_if_unreferenced(storage, hash).await?;
    }
    Ok(())
}

/// The upload target for `(artifact_id, org, sha)`: a pending v2+ version whose
/// manifest names the sha, else the current version. A pending version's
/// manifest lives on its `artifact_versions` row, so it needs its own lookup;
/// it is preferred because completing it is the point of the upload. Expired
/// pending versions are still returned, so the caller can clean them up.
async fn find_upload_target(
    database: &worker::D1Database,
    org: &str,
    artifact_id: &str,
    content_hash: &str,
) -> Result<Option<UploadArtifactRow>> {
    let pending = database
        .prepare("SELECT a.row_id, json_extract(mf.value, '$.content_type') AS content_type, json_extract(mf.value, '$.size_bytes') AS expected_size, v.expires_at AS expires_at, v.manifest AS manifest, v.id AS version_id, v.version AS version FROM artifacts a JOIN orgs o ON o.id = a.org_id JOIN artifact_versions v ON v.artifact_row_id = a.row_id, json_each(v.manifest, '$.files') mf WHERE a.id = ? AND o.slug = ? AND json_extract(mf.value, '$.sha256') = ? AND v.version > a.current_version AND v.expires_at IS NOT NULL ORDER BY v.version DESC LIMIT 1")
        .bind(&[
            JsValue::from_str(artifact_id),
            JsValue::from_str(org),
            JsValue::from_str(content_hash),
        ])?
        .first::<UploadArtifactRow>(None)
        .await?;
    if pending.is_some() {
        return Ok(pending);
    }
    database
        .prepare("SELECT a.row_id, json_extract(mf.value, '$.content_type') AS content_type, json_extract(mf.value, '$.size_bytes') AS expected_size, a.expires_at AS expires_at, a.manifest AS manifest, NULL AS version_id, NULL AS version FROM artifacts a, json_each(a.manifest, '$.files') mf JOIN blobs b ON b.content_hash = ? JOIN orgs o ON o.id = a.org_id WHERE a.id = ? AND o.slug = ? AND json_extract(mf.value, '$.sha256') = ? LIMIT 1")
        .bind(&[
            JsValue::from_str(content_hash),
            JsValue::from_str(artifact_id),
            JsValue::from_str(org),
            JsValue::from_str(content_hash),
        ])?
        .first::<UploadArtifactRow>(None)
        .await
}

pub(crate) async fn upload_permanent_file(
    path: &str,
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
    let suffix = path.trim_start_matches("/v1/artifacts/");
    let Some((artifact_id, content_hash)) = suffix.split_once("/files/") else {
        return json_error(
            ErrorCode::InvalidArtifactId,
            "Invalid artifact file path.",
            400,
        );
    };
    if artifact_id.is_empty()
        || content_hash.len() != 64
        || !content_hash
            .bytes()
            .all(|byte| byte.is_ascii_hexdigit() && !byte.is_ascii_uppercase())
    {
        return json_error(
            ErrorCode::InvalidArtifactId,
            "Invalid artifact file path.",
            400,
        );
    }
    let storage =
        store::D1R2ArtifactStore::new(env.d1("ARTIFACTS_DB")?, env.bucket("ARTIFACTS_BUCKET")?);
    let database = &storage.database;
    let org = credential.org_id.clone();
    // Target selection: a pending v2+ version that names this sha wins over
    // the current version, because completing it is the point of the upload.
    // An expired pending version is still selected here so it can be cleaned
    // up rather than uploaded to.
    let Some(row) = find_upload_target(database, &org, artifact_id, content_hash).await? else {
        return json_error(
            ErrorCode::ArtifactNotFound,
            "Artifact not found or already uploaded.",
            404,
        );
    };
    // Quota gate (spec 14): the file's declared size against the org's
    // storage, before its bytes are read or written.
    if let Some(refusal) = quota_refusal(
        database,
        env,
        &org,
        row.expected_size.max(0) as u64,
        quota::AddKind::Upload,
    )
    .await?
    {
        return Ok(refusal);
    }
    let mut lock_keys = serde_json::from_str::<PermanentManifest>(&row.manifest)
        .map(|manifest| {
            manifest
                .files
                .into_iter()
                .map(|file| file.sha256)
                .collect::<Vec<_>>()
        })
        .map_err(|error| worker::Error::RustError(error.to_string()))?;
    if row.version_id.is_some() {
        // Completing a pending version promotes it, so serialise with this
        // artifact's other versioning writes.
        lock_keys.push(artifact_lock_key(&row.row_id));
    }
    // A pending v1 that has expired deletes the whole artifact, every version
    // included, so lock the union of every version's manifest hashes.
    let expiring_v1 =
        row.version_id.is_none() && upload_expired(row.expires_at.as_deref(), Utc::now());
    if expiring_v1 {
        lock_keys = org_admin::artifact_version_hashes(database, artifact_id, &org).await?;
    }
    let locks = match storage.acquire_content_locks(&lock_keys).await {
        Ok(locks) => locks,
        Err(store::StoreError::Contention) => return retryable_contention_response(),
        Err(error) => return Err(worker::Error::RustError(error.to_string())),
    };
    if upload_expired(row.expires_at.as_deref(), Utc::now()) {
        let cleanup: Result<Response> = async {
            if let Some(version_id) = &row.version_id {
                // A pending v2+ expiring deletes only that version; the artifact
                // and its current version keep serving (invariant 4). Its
                // manifest's refcounts are decremented once per file.
                let manifest = serde_json::from_str::<PermanentManifest>(&row.manifest)?;
                database
                    .prepare("DELETE FROM version_files WHERE version_id = ?")
                    .bind(&[JsValue::from_str(version_id)])?
                    .run()
                    .await?;
                database
                    .prepare("DELETE FROM artifact_versions WHERE id = ?")
                    .bind(&[JsValue::from_str(version_id)])?
                    .run()
                    .await?;
                decrement_and_release(&storage, &manifest).await?;
            } else {
                // A pending v1 *is* the artifact's own row, so the whole
                // artifact goes. Dependants are deleted explicitly (invariant 3,
                // migration 0005's no-cascade rule); ref_count is then
                // recomputed on the migration's every-version basis rather than
                // decremented — after the deletes the surviving version rows are
                // the source of truth.
                database
                    .prepare("DELETE FROM version_files WHERE version_id IN (SELECT id FROM artifact_versions WHERE artifact_row_id = ?)")
                    .bind(&[JsValue::from_str(&row.row_id)])?
                    .run()
                    .await?;
                database
                    .prepare("DELETE FROM artifact_versions WHERE artifact_row_id = ?")
                    .bind(&[JsValue::from_str(&row.row_id)])?
                    .run()
                    .await?;
                database
                    .prepare("DELETE FROM files WHERE artifact_row_id = ?")
                    .bind(&[JsValue::from_str(&row.row_id)])?
                    .run()
                    .await?;
                database
                    .prepare("DELETE FROM provenance WHERE artifact_row_id = ?")
                    .bind(&[JsValue::from_str(&row.row_id)])?
                    .run()
                    .await?;
                database
                    .prepare("DELETE FROM artifacts WHERE row_id = ?")
                    .bind(&[JsValue::from_str(&row.row_id)])?
                    .run()
                    .await?;
                for hash in &lock_keys {
                    database
                        .prepare("UPDATE blobs SET ref_count = (SELECT COUNT(*) FROM artifact_versions v, json_each(v.manifest, '$.files') mf WHERE json_extract(mf.value, '$.sha256') = blobs.content_hash) WHERE content_hash = ?")
                        .bind(&[JsValue::from_str(hash)])?
                        .run()
                        .await?;
                }
                for hash in &lock_keys {
                    release_blob_if_unreferenced(&storage, hash).await?;
                }
            }
            json_error(ErrorCode::ArtifactNotFound, "Artifact upload expired.", 404)
        }
        .await;
        let release_result = storage.release_content_locks(&locks).await;
        let response = cleanup?;
        release_result.map_err(|error| worker::Error::RustError(error.to_string()))?;
        return Ok(response);
    }
    let bytes = match req.bytes().await {
        Ok(bytes) => bytes,
        Err(error) => {
            let _ = storage.release_content_locks(&locks).await;
            return Err(error);
        }
    };
    if bytes.len() > MAX_FILE_BYTES {
        let _ = storage.release_content_locks(&locks).await;
        return json_error(
            ErrorCode::BundleTooLarge,
            "The permanent file is too large.",
            413,
        );
    }
    if uploaded_file_error(content_hash, row.expected_size as usize, &bytes)
        == Some(ErrorCode::HashMismatch)
    {
        let _ = storage.release_content_locks(&locks).await;
        return json_error(
            ErrorCode::HashMismatch,
            "The uploaded file hash does not match.",
            422,
        );
    }
    if uploaded_file_error(content_hash, row.expected_size as usize, &bytes)
        == Some(ErrorCode::ValidationFailed)
    {
        let _ = storage.release_content_locks(&locks).await;
        return json_error(
            ErrorCode::ValidationFailed,
            "The uploaded file size does not match the manifest.",
            422,
        );
    }
    let bucket = &storage.bucket;
    let content_type = row.content_type;
    let upload_result = bucket
        .put(format!("blobs/{content_hash}"), bytes.clone())
        .http_metadata(worker::HttpMetadata {
            content_type: Some(content_type.clone()),
            ..Default::default()
        })
        .execute()
        .await;
    if let Err(error) = upload_result {
        let _ = storage.release_content_locks(&locks).await;
        return Err(error);
    }
    let now = Utc::now().to_rfc3339();
    if let Some(version_id) = row.version_id.clone() {
        // Pending v2+: write only the version's own file rows. The current copy
        // (`files`, provenance, `artifacts`) is replaced by promotion once every
        // manifest path has a version_files row (invariants 1-2, 5).
        let pending: Result<()> = async {
            database
                .prepare("INSERT OR REPLACE INTO version_files (version_id, path, content_hash, content_type, size_bytes) SELECT v.id, json_extract(mf.value, '$.path'), ?, json_extract(mf.value, '$.content_type'), json_extract(mf.value, '$.size_bytes') FROM artifact_versions v, json_each(v.manifest, '$.files') mf WHERE v.id = ? AND json_extract(mf.value, '$.sha256') = ? AND (v.expires_at IS NULL OR v.expires_at > ?)")
                .bind(&[
                    JsValue::from_str(content_hash),
                    JsValue::from_str(&version_id),
                    JsValue::from_str(content_hash),
                    JsValue::from_str(&now),
                ])?
                .run()
                .await?;
            let completion = database
                .prepare("SELECT (SELECT COUNT(*) FROM json_each(v.manifest, '$.files')) AS expected, (SELECT COUNT(*) FROM version_files vf WHERE vf.version_id = v.id) AS present, a.current_version AS current_version, a.tier AS tier, a.id AS artifact_id FROM artifact_versions v JOIN artifacts a ON a.row_id = v.artifact_row_id WHERE v.id = ?")
                .bind(&[JsValue::from_str(&version_id)])?
                .first::<VersionCompletionRow>(None)
                .await?;
            if let Some(completion) = completion {
                if completion.expected > 0 && completion.present >= completion.expected {
                    let version = row.version.unwrap_or(1).max(1) as u32;
                    let statements = version_routes::promotion_statements(
                        database,
                        &row.row_id,
                        &version_id,
                        version,
                        completion.current_version.max(1) as u32,
                        &now,
                    )?;
                    storage.execute_batch(statements).await.map_err(|error| {
                        worker::Error::RustError(error.to_string())
                    })?;
                    emit_artifact_version_created(
                        ctx,
                        env,
                        &org,
                        &completion.artifact_id,
                        version,
                        permanent_tier_from_database(&completion.tier),
                    );
                }
            }
            Ok(())
        }
        .await;
        let release_result = storage.release_content_locks(&locks).await;
        pending?;
        release_result.map_err(|error| worker::Error::RustError(error.to_string()))?;
        return EmptyResponseDefinition {
            status: 204,
            headers: Vec::new(),
        }
        .into_worker_response();
    }
    // The current copy (`files`) and the version's own file rows move together:
    // invariant 1 says `artifacts` and `files` are a copy of the current
    // version, and invariant 2 says `version_files` holds that version's files.
    // Resolve the version from `current_version`.
    let files_statement = match database
        .prepare("INSERT OR REPLACE INTO files (artifact_row_id, path, content_hash, content_type, size_bytes) SELECT a.row_id, json_extract(mf.value, '$.path'), ?, json_extract(mf.value, '$.content_type'), json_extract(mf.value, '$.size_bytes') FROM artifacts a, json_each(a.manifest, '$.files') mf JOIN orgs o ON o.id = a.org_id WHERE a.id = ? AND o.slug = ? AND json_extract(mf.value, '$.sha256') = ? AND (a.expires_at IS NULL OR a.expires_at > ?)")
        .bind(&[
            JsValue::from_str(content_hash),
            JsValue::from_str(artifact_id),
            JsValue::from_str(&org),
            JsValue::from_str(content_hash),
            JsValue::from_str(&now),
        ]) {
        Ok(statement) => statement,
        Err(error) => {
            let _ = storage.release_content_locks(&locks).await;
            return Err(error);
        }
    };
    let version_files_statement = match database
        .prepare("INSERT OR REPLACE INTO version_files (version_id, path, content_hash, content_type, size_bytes) SELECT v.id, json_extract(mf.value, '$.path'), ?, json_extract(mf.value, '$.content_type'), json_extract(mf.value, '$.size_bytes') FROM artifacts a JOIN orgs o ON o.id = a.org_id JOIN artifact_versions v ON v.artifact_row_id = a.row_id AND v.version = a.current_version, json_each(a.manifest, '$.files') mf WHERE a.id = ? AND o.slug = ? AND json_extract(mf.value, '$.sha256') = ? AND (a.expires_at IS NULL OR a.expires_at > ?)")
        .bind(&[
            JsValue::from_str(content_hash),
            JsValue::from_str(artifact_id),
            JsValue::from_str(&org),
            JsValue::from_str(content_hash),
            JsValue::from_str(&now),
        ]) {
        Ok(statement) => statement,
        Err(error) => {
            let _ = storage.release_content_locks(&locks).await;
            return Err(error);
        }
    };
    let file_result = database
        .batch(vec![files_statement, version_files_statement])
        .await;
    // Clearing the upload deadline clears it on the current version too, so a
    // promoted v1 is not treated as a pending upload. Gated on the same
    // "every manifest path has a file row" condition as the artifact update.
    let artifact_complete_statement = match database
        .prepare("UPDATE artifacts SET expires_at = NULL WHERE id = ? AND org_id = ? AND (expires_at IS NULL OR expires_at > ?) AND NOT EXISTS (SELECT 1 FROM json_each(manifest, '$.files') mf WHERE NOT EXISTS (SELECT 1 FROM files f WHERE f.artifact_row_id = artifacts.row_id AND f.path = json_extract(mf.value, '$.path')))" )
        .bind(&[
            JsValue::from_str(artifact_id),
            JsValue::from_str(&org),
            JsValue::from_str(&now),
        ]) {
        Ok(statement) => statement,
        Err(error) => {
            let _ = storage.release_content_locks(&locks).await;
            return Err(error);
        }
    };
    let version_complete_statement = match database
        .prepare("UPDATE artifact_versions SET expires_at = NULL WHERE artifact_row_id IN (SELECT row_id FROM artifacts WHERE id = ? AND org_id = ? AND (expires_at IS NULL OR expires_at > ?) AND NOT EXISTS (SELECT 1 FROM json_each(artifacts.manifest, '$.files') mf WHERE NOT EXISTS (SELECT 1 FROM files f WHERE f.artifact_row_id = artifacts.row_id AND f.path = json_extract(mf.value, '$.path')))) AND version = (SELECT current_version FROM artifacts WHERE id = ? AND org_id = ?)")
        .bind(&[
            JsValue::from_str(artifact_id),
            JsValue::from_str(&org),
            JsValue::from_str(&now),
            JsValue::from_str(artifact_id),
            JsValue::from_str(&org),
        ]) {
        Ok(statement) => statement,
        Err(error) => {
            let _ = storage.release_content_locks(&locks).await;
            return Err(error);
        }
    };
    let complete_result = database
        .batch(vec![
            artifact_complete_statement,
            version_complete_statement,
        ])
        .await;
    let release_result = storage.release_content_locks(&locks).await;
    file_result?;
    complete_result?;
    release_result.map_err(|error| worker::Error::RustError(error.to_string()))?;
    EmptyResponseDefinition {
        status: 204,
        headers: Vec::new(),
    }
    .into_worker_response()
}

#[derive(Debug, Deserialize)]
#[serde(rename_all = "snake_case")]
struct RevocationWriteRequest {
    jti: String,
    expires_at_unix: i64,
}

#[derive(Debug, Deserialize)]
struct PermanentArtifactRow {
    org: String,
    content_hash: String,
    entrypoint: String,
    tier: String,
    content_type: String,
    expires_at: Option<String>,
    manifest: String,
}

/// `GET /v1/artifacts/{id}` — the credential-scoped metadata read spec 07's
/// DoD item 4 is about. Deliberately fetches the row *without* an org
/// filter in the query, then gates visibility in application code via
/// `decide_artifact_visibility` — that keeps the org-scoping predicate a
/// single, independently mutation-testable check rather than folded into
/// SQL where a mutation test could pass vacuously.
pub(crate) async fn get_artifact_metadata(
    path: &str,
    req: &Request,
    env: &Env,
) -> Result<Response> {
    let authorization = req.headers().get("Authorization")?;
    let credential =
        match require_org_scope(authorization.as_deref(), env, "artifacts:read").await? {
            Ok(credential) => credential,
            Err(refusal) => return Ok(refusal),
        };
    let artifact_id = path
        .trim_start_matches("/v1/artifacts/")
        .trim_end_matches('/');
    if artifact_id.is_empty() {
        return json_error(ErrorCode::ArtifactNotFound, "Artifact not found.", 404);
    }
    let database = env.d1("ARTIFACTS_DB")?;
    let row = database
        .prepare(
            "SELECT a.id AS id, o.slug AS org_id, a.tier AS tier, a.entrypoint AS entrypoint, a.created_at AS created_at, a.expires_at AS expires_at, a.title AS title, a.description AS description, a.current_version AS current_version, (SELECT COUNT(*) FROM artifact_versions v WHERE v.artifact_row_id = a.row_id AND v.expires_at IS NULL) AS version_count, COALESCE(a.updated_at, a.created_at) AS updated_at FROM artifacts a JOIN orgs o ON o.id = a.org_id WHERE a.id = ? ORDER BY a.row_id LIMIT 1",
        )
        .bind(&[JsValue::from_str(artifact_id)])?
        .first::<ArtifactMetadataRow>(None)
        .await?;
    let org_row = row.as_ref().map(|row| ArtifactOrgRow {
        id: row.id.clone(),
        org_id: row.org_id.clone(),
    });
    match decide_artifact_visibility(org_row.as_ref(), &credential.org_id) {
        ArtifactLookupDecision::NotFound => {
            json_error(ErrorCode::ArtifactNotFound, "Artifact not found.", 404)
        }
        ArtifactLookupDecision::Visible => {
            let row = row.expect("Visible is only returned when a row was fetched");
            JsonResponseDefinition::json(build_artifact_metadata_response(&row), 200)
                .into_worker_response()
        }
    }
}

/// `POST /v1/internal/revocations` — Laravel writes here on revoke; the
/// Worker checks this denylist at the edge (spec 07). This endpoint is
/// itself an authorization boundary distinct from `orgToken`/`sessionJwt`
/// (its own shared secret, `ARTFCT_REVOCATION_WRITE_SECRET`) — an
/// unauthenticated or wrongly-credentialed write is rejected before the KV
/// write happens.
pub(crate) async fn write_revocation(req: &mut Request, env: &Env) -> Result<Response> {
    let authorization = req.headers().get("Authorization")?;
    if !authorized_for_revocation_write(authorization.as_deref(), env) {
        return json_error(
            ErrorCode::Unauthorized,
            "Invalid revocation credential.",
            401,
        );
    }
    let payload = match req.json::<RevocationWriteRequest>().await {
        Ok(payload) => payload,
        Err(_) => return json_error(ErrorCode::InvalidJson, "Invalid JSON request body.", 400),
    };
    let ttl_seconds = (payload.expires_at_unix - Utc::now().timestamp())
        .max(MIN_EXPIRATION_TTL_SECONDS as i64) as u64;
    let kv = env.kv(KV_BINDING)?;
    kv.put(&denylist_kv_key(&payload.jti), "1")?
        .expiration_ttl(ttl_seconds)
        .execute()
        .await?;
    JsonResponseDefinition::json(serde_json::json!({"revoked": true}), 200).into_worker_response()
}

/// Derives a daily-salted pseudonymous key for distinct-viewer counting on
/// anonymous `/p/{id}` views — runs whenever no verified `viewer_user_id`
/// was resolved, which covers Slack opens and shared links, not only
/// console-minted tokens. HMAC-SHA256 (the same primitive `events::sign`
/// and access-token signing already use in this crate, via
/// `store::hmac_sha256`) over `(date, ip, user_agent, org)`, keyed by a
/// secret that never leaves the Worker. Folding in the UTC date means the
/// same visitor gets an unrelated key every day — enough to say "the same
/// visitor within one day," never enough to recover the IP or a stable
/// cross-day identity. The input headers are caller-written, so this key is
/// only a heuristic ranking signal, never an authenticated viewer identity.
/// `secret` is `None` when `VISITOR_KEY_SECRET_ENV` is
/// unset, in which case this returns `None` rather than deriving a key from
/// a guessable constant.
pub(crate) fn derive_visitor_key(
    secret: Option<&str>,
    org: &str,
    ip: &str,
    user_agent: &str,
    now: chrono::DateTime<Utc>,
) -> Option<String> {
    let secret = secret?;
    let message = format!("{}|{ip}|{user_agent}|{org}", now.format("%Y-%m-%d"));
    Some(
        store::hmac_sha256(secret.as_bytes(), message.as_bytes())
            .iter()
            .map(|byte| format!("{byte:02x}"))
            .collect(),
    )
}

fn visitor_key_secret(env: &Env) -> Option<String> {
    env.var(VISITOR_KEY_SECRET_ENV)
        .ok()
        .map(|value| value.to_string())
}

/// The row needed to serve one file of an artifact: the current version when
/// `version` is `None`, otherwise the named *completed* version (the version,
/// not the artifact, must be complete). Both shapes resolve to the same
/// [`PermanentArtifactRow`], so the access checks, response headers and
/// access-token cookie below are shared rather than duplicated.
async fn permanent_artifact_row(
    database: &worker::D1Database,
    artifact_id: &str,
    requested_path: Option<&str>,
    version: Option<u32>,
) -> Result<Option<PermanentArtifactRow>> {
    match version {
        None => {
            database
                .prepare("SELECT f.content_hash, a.entrypoint, a.tier, f.content_type, a.expires_at, a.manifest, o.slug AS org FROM artifacts a JOIN files f ON f.artifact_row_id = a.row_id JOIN orgs o ON o.id = a.org_id WHERE a.id = ? AND f.path = COALESCE(?, a.entrypoint) AND (a.expires_at IS NULL OR a.expires_at > ?) AND a.revoked_at IS NULL AND NOT EXISTS (SELECT 1 FROM json_each(a.manifest, '$.files') mf WHERE NOT EXISTS (SELECT 1 FROM files complete WHERE complete.artifact_row_id = a.row_id AND complete.path = json_extract(mf.value, '$.path'))) ORDER BY a.row_id LIMIT 1")
                .bind(&[
                    JsValue::from_str(artifact_id),
                    requested_path.map(JsValue::from_str).unwrap_or_else(JsValue::null),
                    JsValue::from_str(&Utc::now().to_rfc3339()),
                ])?
                .first::<PermanentArtifactRow>(None)
                .await
        }
        Some(version) => {
            database
                .prepare("SELECT vf.content_hash, v.entrypoint, a.tier, vf.content_type, v.expires_at, v.manifest, o.slug AS org FROM artifacts a JOIN orgs o ON o.id = a.org_id JOIN artifact_versions v ON v.artifact_row_id = a.row_id AND v.version = ? JOIN version_files vf ON vf.version_id = v.id AND vf.path = COALESCE(?, v.entrypoint) WHERE a.id = ? AND a.revoked_at IS NULL AND v.expires_at IS NULL AND NOT EXISTS (SELECT 1 FROM json_each(v.manifest, '$.files') mf WHERE NOT EXISTS (SELECT 1 FROM version_files complete WHERE complete.version_id = v.id AND complete.path = json_extract(mf.value, '$.path'))) ORDER BY a.row_id LIMIT 1")
                .bind(&[
                    JsValue::from_f64(version as f64),
                    requested_path.map(JsValue::from_str).unwrap_or_else(JsValue::null),
                    JsValue::from_str(artifact_id),
                ])?
                .first::<PermanentArtifactRow>(None)
                .await
        }
    }
}

pub(crate) async fn resolve_permanent_artifact(
    artifact_id: &str,
    requested_path: Option<&str>,
    version: Option<u32>,
    req: &Request,
    env: &Env,
    ctx: &worker::Context,
) -> Result<Response> {
    if requested_path.is_some_and(|path| !is_valid_relative_path(path)) {
        return expired_response();
    }
    let storage =
        store::D1R2ArtifactStore::new(env.d1("ARTIFACTS_DB")?, env.bucket("ARTIFACTS_BUCKET")?);
    let database = &storage.database;
    let Some(row) = permanent_artifact_row(database, artifact_id, requested_path, version).await?
    else {
        return expired_response();
    };
    let host = req.headers().get("Host")?;
    let authorization = req.headers().get("Authorization")?;
    let query_token = req
        .url()?
        .query_pairs()
        .find(|(key, _)| key == "token")
        .map(|(_, value)| value.into_owned());
    let cookie_token = access_token_from_cookie(req.headers().get("Cookie")?.as_deref());
    let token = authorization
        .as_deref()
        .and_then(|value| value.strip_prefix("Bearer "))
        .map(str::trim)
        .filter(|value| !value.is_empty())
        .map(str::to_string)
        .or(query_token.clone())
        .or(cookie_token);
    let now = Utc::now();
    let isolated_access = isolated_access_check(
        host.as_deref(),
        token.as_deref(),
        artifact_id,
        &row.org,
        artifact_token_secret(env).as_deref(),
        now,
        &artifact_origin_suffix(env),
    );
    let mut viewer_user_id = None;
    match isolated_access {
        IsolatedAccess::Forbidden => return isolated_forbidden_response(),
        IsolatedAccess::Authorized => {}
        IsolatedAccess::NotIsolated => {
            if row.tier == "secure" {
                match require_org_scope(authorization.as_deref(), env, "artifacts:read").await? {
                    Ok(credential) if credential.org_id == row.org => {
                        viewer_user_id = Some(credential.user_id);
                    }
                    Ok(_) => {
                        return json_error(
                            ErrorCode::Unauthorized,
                            "Invalid organization token.",
                            401,
                        )
                    }
                    Err(refusal) => return Ok(refusal),
                }
            }
        }
    }
    // No verified viewer id (anonymous/Slack/shared-link view, or an
    // isolated-origin view authorized by access token rather than org
    // credential): derive a pseudonymous visitor key so distinct-viewer
    // scoring (spec 16) still has something to count. This is a ranking
    // heuristic: callers can vary request headers to vary their key. It is
    // never an authenticated or abuse-resistant viewer identity. A
    // `secure`-tier view that resolved a credential above always has
    // `viewer_user_id`, so this and that are mutually exclusive per view.
    let mut viewer_key = None;
    if viewer_user_id.is_none() {
        let ip = req
            .headers()
            .get("CF-Connecting-IP")?
            .unwrap_or_else(|| "unknown".to_string());
        let user_agent = req
            .headers()
            .get("User-Agent")?
            .unwrap_or_else(|| "unknown".to_string());
        viewer_key = derive_visitor_key(
            visitor_key_secret(env).as_deref(),
            &row.org,
            &ip,
            &user_agent,
            now,
        );
    }

    let bucket = env.bucket("ARTIFACTS_BUCKET")?;
    let Some(object) = bucket
        .get(format!("blobs/{}", row.content_hash))
        .execute()
        .await?
    else {
        return expired_response();
    };
    let Some(body) = object.body() else {
        return expired_response();
    };
    let bytes = body.bytes().await?;
    let manifest = serde_json::from_str::<PermanentManifest>(&row.manifest).unwrap_or_else(|_| {
        PermanentManifest {
            entrypoint: row.entrypoint.clone(),
            files: Vec::new(),
            external_origins: Vec::new(),
            unsafe_eval: false,
        }
    });
    let mut response = Response::from_bytes(bytes)?.with_status(200);
    let is_isolated = isolated_access == IsolatedAccess::Authorized;
    for (name, value) in permanent_file_response_headers(&row.content_type, &manifest, is_isolated)
    {
        response.headers_mut().set(name, &value)?;
    }
    if isolated_access == IsolatedAccess::Authorized && requested_path.is_none() {
        if let Some(token) = query_token.as_deref() {
            if let Some(cookie) = access_token_cookie(token, now) {
                response.headers_mut().set("Set-Cookie", &cookie)?;
            }
        }
    }
    // Only the current-version entrypoint is a "view" for ranking; a version
    // view is not the published URL.
    if version.is_none() && requested_path.is_none() {
        emit_artifact_viewed(
            ctx,
            env,
            &row.org,
            artifact_id,
            viewer_user_id.as_deref(),
            viewer_key.as_deref(),
        );
    }
    let _ = row.expires_at;
    Ok(response)
}
/// The row shape needed to decide whether an artifact is visible to a
/// credential, deliberately narrower than the full artifact record — see
/// `decide_artifact_visibility`.
#[derive(Debug, Clone, PartialEq, Eq)]
pub(crate) struct ArtifactOrgRow {
    #[allow(dead_code, reason = "carried through to the metadata response")]
    pub(crate) id: String,
    pub(crate) org_id: String,
}

#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub(crate) enum ArtifactLookupDecision {
    NotFound,
    Visible,
}

/// Gates a fetched artifact row against the credential's org. A row that
/// doesn't exist and a row that exists but belongs to a different org
/// resolve to the identical `NotFound` — the caller must map both to the
/// same 404, never 403, so a cross-tenant probe can't distinguish "doesn't
/// exist" from "exists, not yours" (spec 07 DoD item 4).
pub(crate) fn decide_artifact_visibility(
    row: Option<&ArtifactOrgRow>,
    credential_org_id: &str,
) -> ArtifactLookupDecision {
    match row {
        Some(row) if row.org_id == credential_org_id => ArtifactLookupDecision::Visible,
        _ => ArtifactLookupDecision::NotFound,
    }
}

/// Named separately from `authorized_for_org`'s check so a test can assert
/// against the revocation-write endpoint's own guard without that
/// assertion being indistinguishable from spec 05's existing
/// `authorization_matches` coverage — see
/// `revocation_write_endpoint_rejects_unauthenticated_or_wrong_credential`.
pub(crate) fn revocation_write_authorized(
    expected_secret: Option<&str>,
    authorization: Option<&str>,
) -> bool {
    authorization_matches(expected_secret, authorization)
}

pub(crate) fn authorized_for_revocation_write(authorization: Option<&str>, env: &Env) -> bool {
    revocation_write_authorized(
        env.var(REVOCATION_WRITE_SECRET_ENV)
            .ok()
            .map(|value| value.to_string())
            .as_deref(),
        authorization,
    )
}

pub(crate) fn constant_time_equal(left: &[u8], right: &[u8]) -> bool {
    let mut difference = left.len() ^ right.len();
    for index in 0..left.len().max(right.len()) {
        difference |= usize::from(
            left.get(index).copied().unwrap_or(0) ^ right.get(index).copied().unwrap_or(0),
        );
    }
    difference == 0
}

pub(crate) async fn delete_artifact(
    path: &str,
    req: &Request,
    env: &Env,
    ctx: &worker::Context,
) -> Result<Response> {
    let artifact_id = path.trim_start_matches("/v1/artifacts/");
    if store::is_permanent_id(artifact_id) {
        return delete_permanent_artifact(artifact_id, req, env, ctx).await;
    }
    if !is_valid_artifact_id(artifact_id) {
        return json_error(ErrorCode::InvalidArtifactId, "Invalid artifact id.", 400);
    }

    store::KvArtifactStore::new(env.kv(KV_BINDING)?)
        .delete_record(artifact_id)
        .await
        .map_err(|error| worker::Error::RustError(error.to_string()))?;
    build_delete_response().into_worker_response()
}

/// Admin console listing (spec 8): `GET /v1/orgs/{org}/artifacts`. Same
/// bearer-token gate as export/delete — role-based UI gating (viewer sees
/// no controls, member can't change auth_mode) is enforced by the Laravel
/// console, not this Worker; this endpoint trusts the credential the same
/// way `export_organization` already does.
pub(crate) async fn list_org_artifacts(path: &str, req: &Request, env: &Env) -> Result<Response> {
    let authorization = req.headers().get("Authorization")?;
    let credential =
        match require_org_scope(authorization.as_deref(), env, "artifacts:read").await? {
            Ok(credential) => credential,
            Err(refusal) => return Ok(refusal),
        };
    let org = path
        .trim_start_matches("/v1/orgs/")
        .trim_end_matches("/artifacts")
        .trim_end_matches('/');
    if let Err(message) = store::validate_slug(org) {
        return json_error(ErrorCode::ValidationFailed, &message, 422);
    }
    if org != credential.org_id {
        return json_error(ErrorCode::ArtifactNotFound, "Organization not found.", 404);
    }
    let query = req.url()?;
    let params: std::collections::HashMap<String, String> =
        query.query_pairs().into_owned().collect();
    let filter = store::ArtifactListFilter {
        org: org.to_string(),
        repo_url: params.get("repo_url").cloned(),
        agent: params.get("agent").cloned(),
        created_after: params.get("created_after").cloned(),
        created_before: params.get("created_before").cloned(),
        query: params.get("q").filter(|value| !value.is_empty()).cloned(),
    };
    let cursor = params
        .get("cursor")
        .and_then(|raw| store::decode_list_cursor(raw));
    let limit = params
        .get("limit")
        .and_then(|raw| raw.parse::<usize>().ok())
        .filter(|value| *value > 0 && *value <= 200)
        .unwrap_or(50);
    let storage =
        store::D1R2ArtifactStore::new(env.d1("ARTIFACTS_DB")?, env.bucket("ARTIFACTS_BUCKET")?);
    let (items, next_cursor) = storage
        .list_org_artifacts(&filter, cursor.as_ref(), limit)
        .await
        .map_err(|error| worker::Error::RustError(error.to_string()))?;
    let artifacts: Vec<Value> = items
        .into_iter()
        .map(|item| {
            serde_json::json!({
                "id": item.id.0,
                "org_id": item.org,
                "content_hash": item.content_hash,
                "size_bytes": item.size_bytes,
                "created_at": item.created_at,
                "revoked_at": item.revoked_at,
                "title": item.title,
                "description": item.description,
                "provenance": {
                    "agent": item.agent,
                    "repo_url": item.repo_url,
                    "commit_sha": item.commit_sha,
                },
            })
        })
        .collect();
    JsonResponseDefinition::json(
        serde_json::json!({
            "artifacts": artifacts,
            "next_cursor": next_cursor.as_ref().map(store::encode_list_cursor),
        }),
        200,
    )
    .into_worker_response()
}

#[derive(Debug, Deserialize)]
struct ExistingArtifactRow {
    id: String,
    manifest: String,
    tier: String,
    current_version: i64,
}

/// The stored tier string back to its enum. Permanent rows only ever carry
/// `public` or `secure`, but the mapping is total so an unexpected value can
/// never panic the response path.
fn permanent_tier_from_database(value: &str) -> ArtifactTier {
    match value {
        "secure" => ArtifactTier::Secure,
        "ephemeral" => ArtifactTier::Ephemeral,
        _ => ArtifactTier::Public,
    }
}

/// Builds the 201 response for a permanent artifact that already exists, from
/// the row that named it. Used by both the content-hash dedupe and the
/// defensive `(org_id, id)` conflict fallback so both look the same to the
/// caller.
///
/// `missing_files` is computed from blob existence rather than assumed empty —
/// an artifact whose earlier upload was interrupted still tells the client what
/// to send. `tier` and `version` come from the stored row, never from the
/// request: re-posting a bundle must not be able to relabel the artifact.
async fn existing_artifact_response(
    storage: &store::D1R2ArtifactStore,
    env: &Env,
    row: &ExistingArtifactRow,
) -> Result<Response> {
    let existing_manifest: PermanentManifest = serde_json::from_str(&row.manifest)?;
    let mut present = Vec::with_capacity(existing_manifest.files.len());
    for file in &existing_manifest.files {
        present.push(
            storage
                .blob_exists(&file.sha256)
                .await
                .map_err(|error| worker::Error::RustError(error.to_string()))?,
        );
    }
    let base_url = env_string(env, "ARTFCT_PUBLIC_BASE_URL", DEFAULT_BASE_URL);

    JsonResponseDefinition::json(
        PermanentCreateArtifactResponse {
            id: row.id.clone(),
            url: format!("{}/p/{}/", base_url.trim_end_matches('/'), row.id),
            tier: permanent_tier_from_database(&row.tier),
            version: row.current_version.max(1) as u32,
            missing_files: missing_manifest_files(&existing_manifest, &present),
        },
        201,
    )
    .into_worker_response()
}

/// The live artifact in `org` whose current content is `bundle_hash`.
///
/// Re-posting identical bytes names the artifact that already exists, whatever
/// id shape it was minted with (13-character stable or 32-character legacy
/// hex). Revoked rows are excluded: re-publishing after a revocation must mint
/// a fresh row rather than resurrect the revoked one. Called while holding the
/// bundle lock, so it cannot race a concurrent create of the same bundle.
async fn existing_permanent_by_content(
    storage: &store::D1R2ArtifactStore,
    org: &str,
    bundle_hash: &str,
) -> Result<Option<ExistingArtifactRow>> {
    storage
        .database
        .prepare("SELECT id, manifest, tier, current_version FROM artifacts WHERE org_id = ? AND content_hash = ? AND revoked_at IS NULL ORDER BY created_at, row_id LIMIT 1")
        .bind(&[JsValue::from_str(org), JsValue::from_str(bundle_hash)])?
        .first::<ExistingArtifactRow>(None)
        .await
}

/// Builds the response for a permanent artifact that already exists, looked up
/// by `(org, id)`. The defensive fallback for the 0005 unique index; the
/// content-hash dedupe uses [`existing_permanent_by_content`] instead.
pub(crate) async fn existing_permanent_response(
    storage: &store::D1R2ArtifactStore,
    env: &Env,
    org: &str,
    artifact_id: &str,
) -> Result<Option<Response>> {
    let Some(existing) = storage
        .database
        .prepare("SELECT id, manifest, tier, current_version FROM artifacts WHERE org_id = ? AND id = ? AND revoked_at IS NULL ORDER BY row_id LIMIT 1")
        .bind(&[JsValue::from_str(org), JsValue::from_str(artifact_id)])?
        .first::<ExistingArtifactRow>(None)
        .await?
    else {
        return Ok(None);
    };

    Ok(Some(
        existing_artifact_response(storage, env, &existing).await?,
    ))
}

pub(crate) async fn create_permanent_artifact(
    raw: &Value,
    authorization: Option<&str>,
    env: &Env,
    ctx: &worker::Context,
) -> Result<Response> {
    let credential = match require_org_scope(authorization, env, "artifacts:deploy").await? {
        Ok(credential) => credential,
        Err(refusal) => return Ok(refusal),
    };
    if !check_and_increment_rate_limit(env, &credential.token_id).await? {
        return json_error(
            ErrorCode::RateLimited,
            "Rate limit exceeded for this token.",
            429,
        );
    }

    for field in [
        "title",
        "description",
        "thumbnail",
        "preview_blurred",
        "provenance",
    ] {
        if raw.get(field).is_none() {
            return json_error(
                ErrorCode::ValidationFailed,
                "Permanent artifact metadata is incomplete.",
                422,
            );
        }
    }

    let tier = match raw.get("tier").and_then(Value::as_str) {
        Some("public") => ArtifactTier::Public,
        Some("secure") => ArtifactTier::Secure,
        _ => {
            return json_error(
                ErrorCode::ValidationFailed,
                "Permanent artifacts must use the public or secure tier.",
                422,
            );
        }
    };
    let (manifest, bundle_hash) = match validate_permanent_manifest(raw) {
        Ok(value) => value,
        Err(code) => {
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
            return json_error(code, message, status);
        }
    };
    let entrypoint = manifest.entrypoint.as_str();
    let content_hash = bundle_hash.as_str();

    // Tenancy comes from the verified credential only. Any `org_id` present
    // in `raw` is never read (spec 07 DoD item 7) — see `resolve_tenant_org`.
    let org = resolve_tenant_org(raw, &credential);
    if let Err(message) = store::validate_slug(&org) {
        return json_error(ErrorCode::ValidationFailed, &message, 422);
    }
    let now = Utc::now().to_rfc3339_opts(SecondsFormat::Secs, true);
    let upload_expires_at =
        (Utc::now() + chrono::Duration::hours(1)).to_rfc3339_opts(SecondsFormat::Secs, true);
    let provenance = raw
        .get("provenance")
        .cloned()
        .unwrap_or_else(|| serde_json::json!({}));
    let artifact_title = clipped_text(raw.get("title"), 200);
    let artifact_description = clipped_text(raw.get("description"), 1000);
    let agent = provenance.get("agent").and_then(Value::as_str);
    let repo_url = provenance.get("repo_url").and_then(Value::as_str);
    let commit_sha = provenance.get("commit_sha").and_then(Value::as_str);
    let storage =
        store::D1R2ArtifactStore::new(env.d1("ARTIFACTS_DB")?, env.bucket("ARTIFACTS_BUCKET")?);
    // Quota gate (spec 14): decided from the manifest's declared sizes,
    // before any lock, row or blob is written.
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
    let file_hashes = manifest
        .files
        .iter()
        .map(|file| file.sha256.clone())
        .collect::<Vec<_>>();
    // Idempotency (RUB-371). Re-posting bytes identical to an artifact's
    // current content in this org names the artifact that already exists,
    // whichever id shape it was minted with. Returning it is the point:
    // inserting again added a second row, a second file set and another
    // refcount bump per file, which left blobs unreclaimable and let a DELETE
    // answer 204 while a duplicate row kept serving.
    //
    // Live rows only: a revoked artifact does not serve, so re-publishing the
    // same bytes after a revocation must create a new row rather than resurrect
    // the revoked one.
    //
    // The lookup runs inside the bundle lock. Ids are random now, so nothing
    // else stops two concurrent creates of one bundle from both missing the
    // lookup and both inserting a row for the same content.
    let bundle_key = bundle_lock_key(&org, content_hash);
    let bundle_lock = match storage.acquire_content_lock(&bundle_key).await {
        Ok(owner) => (bundle_key, owner),
        Err(store::StoreError::Contention) => return retryable_contention_response(),
        Err(error) => return Err(worker::Error::RustError(error.to_string())),
    };
    match existing_permanent_by_content(&storage, &org, content_hash).await {
        Ok(Some(existing)) => {
            let response = existing_artifact_response(&storage, env, &existing).await;
            let release = storage
                .release_content_lock(&bundle_lock.0, &bundle_lock.1)
                .await;
            let response = response?;
            release.map_err(|error| worker::Error::RustError(error.to_string()))?;
            return Ok(response);
        }
        Ok(None) => {}
        Err(error) => {
            let _ = storage
                .release_content_lock(&bundle_lock.0, &bundle_lock.1)
                .await;
            return Err(error);
        }
    }

    let locks = match storage.acquire_content_locks(&file_hashes).await {
        Ok(locks) => locks,
        Err(store::StoreError::Contention) => {
            let _ = storage
                .release_content_lock(&bundle_lock.0, &bundle_lock.1)
                .await;
            return retryable_contention_response();
        }
        Err(error) => {
            let _ = storage
                .release_content_lock(&bundle_lock.0, &bundle_lock.1)
                .await;
            return Err(worker::Error::RustError(error.to_string()));
        }
    };
    let database = &storage.database;
    let row_id = Uuid::new_v4().simple().to_string();
    let version_id = format!("{row_id}-v1");
    let mut artifact_id = store::mint_stable_id();
    // Cleared when the insert was refused because a concurrent create won, so a
    // re-post does not announce an artifact that was not created.
    let mut created = true;
    let operation: Result<Response> = async {
        let mut existing = Vec::with_capacity(manifest.files.len());
        for file in &manifest.files {
            existing.push(
                storage
                    .blob_exists(&file.sha256)
                    .await
                    .map_err(|error| worker::Error::RustError(error.to_string()))?,
            );
        }
        let expires_value = if existing.iter().all(|present| *present) {
            JsValue::null()
        } else {
            JsValue::from_str(&upload_expires_at)
        };
        let manifest_json = serde_json::to_string(&manifest)?;
        let provenance_json = provenance.to_string();
        let tier_value = match tier {
            ArtifactTier::Public => "public",
            ArtifactTier::Secure => "secure",
            ArtifactTier::Ephemeral => "ephemeral",
        };
        // At most three attempts. A conflict on the global stable-id index is
        // retried with a fresh id; the bundle lock means a same-content create
        // cannot race, so the (org_id, id) branch is only defensive.
        let mut attempt = 0;
        loop {
            attempt += 1;
            let mut statements = vec![
                database
                    .prepare("INSERT OR IGNORE INTO orgs (id, slug, created_at) VALUES (?, ?, ?)")
                    .bind(&[
                        JsValue::from_str(&org),
                        JsValue::from_str(&org),
                        JsValue::from_str(&now),
                    ])?,
                database
                    .prepare("INSERT OR IGNORE INTO users (id, created_at) VALUES (?, ?)")
                    .bind(&[
                        JsValue::from_str(&credential.user_id),
                        JsValue::from_str(&now),
                    ])?,
                database
                    .prepare("INSERT INTO artifacts (row_id, id, org_id, user_id, content_hash, entrypoint, created_at, updated_at, expires_at, tier, manifest, title, description, current_version) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1)")
                    .bind(&[
                        JsValue::from_str(&row_id),
                        JsValue::from_str(&artifact_id),
                        JsValue::from_str(&org),
                        JsValue::from_str(&credential.user_id),
                        JsValue::from_str(content_hash),
                        JsValue::from_str(entrypoint),
                        JsValue::from_str(&now),
                        JsValue::from_str(&now),
                        expires_value.clone(),
                        JsValue::from_str(tier_value),
                        JsValue::from_str(&manifest_json),
                        artifact_title.as_deref().map(JsValue::from_str).unwrap_or_else(JsValue::null),
                        artifact_description.as_deref().map(JsValue::from_str).unwrap_or_else(JsValue::null),
                    ])?,
                database
                    .prepare("INSERT INTO artifact_versions (id, artifact_row_id, version, created_at, content_hash, entrypoint, manifest, title, description, created_by, agent, repo_url, commit_sha, provenance, expires_at) VALUES (?, ?, 1, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)")
                    .bind(&[
                        JsValue::from_str(&version_id),
                        JsValue::from_str(&row_id),
                        JsValue::from_str(&now),
                        JsValue::from_str(content_hash),
                        JsValue::from_str(entrypoint),
                        JsValue::from_str(&manifest_json),
                        artifact_title.as_deref().map(JsValue::from_str).unwrap_or_else(JsValue::null),
                        artifact_description.as_deref().map(JsValue::from_str).unwrap_or_else(JsValue::null),
                        JsValue::from_str(&credential.user_id),
                        agent.map(JsValue::from_str).unwrap_or_else(JsValue::null),
                        repo_url.map(JsValue::from_str).unwrap_or_else(JsValue::null),
                        commit_sha.map(JsValue::from_str).unwrap_or_else(JsValue::null),
                        JsValue::from_str(&provenance_json),
                        expires_value.clone(),
                    ])?,
                database
                    .prepare("INSERT INTO provenance (artifact_row_id, agent, repo_url, commit_sha, payload) VALUES (?, ?, ?, ?, ?)")
                    .bind(&[
                        JsValue::from_str(&row_id),
                        agent.map(JsValue::from_str).unwrap_or_else(JsValue::null),
                        repo_url.map(JsValue::from_str).unwrap_or_else(JsValue::null),
                        commit_sha.map(JsValue::from_str).unwrap_or_else(JsValue::null),
                        JsValue::from_str(&provenance_json),
                    ])?,
            ];
            for (file, is_present) in manifest.files.iter().zip(existing.iter().copied()) {
                statements.push(
                    database
                        .prepare("INSERT OR IGNORE INTO blobs (content_hash, size_bytes, content_type, ref_count, created_at) VALUES (?, ?, ?, 0, ?)")
                        .bind(&[
                            JsValue::from_str(&file.sha256),
                            JsValue::from_f64(file.size_bytes as f64),
                            JsValue::from_str(&file.content_type),
                            JsValue::from_str(&now),
                        ])?,
                );
                statements.push(
                    database
                        .prepare("UPDATE blobs SET ref_count = ref_count + 1 WHERE content_hash = ?")
                        .bind(&[JsValue::from_str(&file.sha256)])?,
                );
                if is_present {
                    statements.push(
                        database
                            .prepare("INSERT INTO files (artifact_row_id, path, content_hash, content_type, size_bytes) VALUES (?, ?, ?, ?, ?)")
                            .bind(&[
                                JsValue::from_str(&row_id),
                                JsValue::from_str(&file.path),
                                JsValue::from_str(&file.sha256),
                                JsValue::from_str(&file.content_type),
                                JsValue::from_f64(file.size_bytes as f64),
                            ])?,
                    );
                    statements.push(
                        database
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
            match storage.execute_batch(statements).await {
                Ok(()) => {
                    let missing_files = missing_manifest_files(&manifest, &existing);
                    let base_url = env_string(env, "ARTFCT_PUBLIC_BASE_URL", DEFAULT_BASE_URL);
                    return JsonResponseDefinition::json(
                        PermanentCreateArtifactResponse {
                            id: artifact_id.clone(),
                            url: format!("{}/p/{artifact_id}/", base_url.trim_end_matches('/')),
                            tier,
                            version: 1,
                            missing_files,
                        },
                        201,
                    )
                    .into_worker_response();
                }
                Err(error) => {
                    let message = error.to_string();
                    match artifact_insert_conflict(&message) {
                        // The 0006 global unique index over `artifacts.id`.
                        // Mint a fresh id and retry, so a collision never
                        // fails a create.
                        ArtifactInsertConflict::StableId if attempt < 3 => {
                            artifact_id = store::mint_stable_id();
                        }
                        ArtifactInsertConflict::StableId => {
                            return Err(worker::Error::RustError(message));
                        }
                        // The 0005 `(org_id, id)` index: a live row already has
                        // this id. Return it rather than failing the re-post.
                        ArtifactInsertConflict::OrgPublicId => {
                            if let Some(response) =
                                existing_permanent_response(&storage, env, &org, &artifact_id)
                                    .await?
                            {
                                created = false;
                                return Ok(response);
                            }
                            return Err(worker::Error::RustError(message));
                        }
                        ArtifactInsertConflict::Other => {
                            return Err(worker::Error::RustError(message));
                        }
                    }
                }
            }
        }
    }
    .await;
    let release_result = storage.release_content_locks(&locks).await;
    let bundle_release = storage
        .release_content_lock(&bundle_lock.0, &bundle_lock.1)
        .await;
    let response = operation?;
    release_result.map_err(|error| worker::Error::RustError(error.to_string()))?;
    bundle_release.map_err(|error| worker::Error::RustError(error.to_string()))?;
    if created && response.status_code() == 201 {
        emit_artifact_created(ctx, env, &org, &artifact_id, tier);
    }
    Ok(response)
}
