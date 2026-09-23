use super::*;

#[derive(Debug, Deserialize)]
struct ContentRow {
    content_hash: String,
    content_type: String,
    tier: String,
    agent: Option<String>,
    repo_url: Option<String>,
    commit_sha: Option<String>,
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
    let credential_org = match require_org_credential(authorization.as_deref(), env).await? {
        Ok(credential) => Some(credential.org_id),
        Err(_) => None,
    };
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
        .prepare("SELECT f.content_hash, f.content_type, a.tier AS tier, p.agent, p.repo_url, p.commit_sha FROM artifacts a JOIN files f ON f.artifact_row_id = a.row_id AND f.path = a.entrypoint JOIN orgs o ON o.id = a.org_id LEFT JOIN provenance p ON p.artifact_row_id = a.row_id WHERE a.id = ? AND o.slug = ? AND a.revoked_at IS NULL ORDER BY a.row_id LIMIT 1")
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
        serde_json::json!({
            "id": artifact_id,
            "content_type": row.content_type,
            "tier": row.tier,
            "provenance": {
                "agent": row.agent,
                "repo_url": row.repo_url,
                "commit_sha": row.commit_sha,
            },
            "content": String::from_utf8_lossy(&bytes),
        }),
        200,
    )
    .into_worker_response()
}

#[derive(Debug, Deserialize)]
struct ArtifactMetadataRow {
    pub(crate) id: String,
    pub(crate) org_id: String,
    tier: String,
    entrypoint: String,
    created_at: String,
    expires_at: Option<String>,
    title: Option<String>,
    description: Option<String>,
}
#[derive(Debug, Deserialize)]
struct UploadArtifactRow {
    row_id: String,
    content_type: String,
    expected_size: i64,
    expires_at: Option<String>,
    manifest: String,
}

pub(crate) async fn upload_permanent_file(
    path: &str,
    req: &mut Request,
    env: &Env,
) -> Result<Response> {
    let authorization = req.headers().get("Authorization")?;
    let credential = match require_org_credential(authorization.as_deref(), env).await? {
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
    let row_statement = match database
        .prepare("SELECT a.row_id, json_extract(mf.value, '$.content_type') AS content_type, json_extract(mf.value, '$.size_bytes') AS expected_size, a.expires_at, a.manifest FROM artifacts a, json_each(a.manifest, '$.files') mf JOIN blobs b ON b.content_hash = ? JOIN orgs o ON o.id = a.org_id WHERE a.id = ? AND o.slug = ? AND json_extract(mf.value, '$.sha256') = ? LIMIT 1")
        .bind(&[
            JsValue::from_str(content_hash),
            JsValue::from_str(artifact_id),
            JsValue::from_str(&org),
            JsValue::from_str(content_hash),
        ]) {
        Ok(statement) => statement,
        Err(error) => {
            return Err(error);
        }
    };
    let row = match row_statement.first::<UploadArtifactRow>(None).await {
        Ok(row) => row,
        Err(error) => {
            return Err(error);
        }
    };
    let Some(row) = row else {
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
    let lock_hashes = serde_json::from_str::<PermanentManifest>(&row.manifest)
        .map(|manifest| {
            manifest
                .files
                .into_iter()
                .map(|file| file.sha256)
                .collect::<Vec<_>>()
        })
        .map_err(|error| worker::Error::RustError(error.to_string()))?;
    let locks = match storage.acquire_content_locks(&lock_hashes).await {
        Ok(locks) => locks,
        Err(store::StoreError::Contention) => return retryable_contention_response(),
        Err(error) => return Err(worker::Error::RustError(error.to_string())),
    };
    if upload_expired(row.expires_at.as_deref(), Utc::now()) {
        let cleanup: Result<Response> = async {
            let manifest = serde_json::from_str::<PermanentManifest>(&row.manifest)?;
            database
                .prepare("DELETE FROM artifacts WHERE row_id = ?")
                .bind(&[JsValue::from_str(&row.row_id)])?
                .run()
                .await?;
            let hashes = manifest
                .files
                .iter()
                .map(|file| file.sha256.as_str())
                .collect::<Vec<_>>();
            for hash in &hashes {
                database
                    .prepare(
                        "UPDATE blobs SET ref_count = MAX(ref_count - 1, 0) WHERE content_hash = ?",
                    )
                    .bind(&[JsValue::from_str(hash)])?
                    .run()
                    .await?;
            }
            for hash in hashes.into_iter().collect::<std::collections::HashSet<_>>() {
                let refs = database
                    .prepare("SELECT ref_count AS count FROM blobs WHERE content_hash = ?")
                    .bind(&[JsValue::from_str(hash)])?
                    .first::<BlobReferenceRow>(None)
                    .await?
                    .map(|value| value.count)
                    .unwrap_or(0);
                if refs == 0 {
                    database
                        .prepare("DELETE FROM blobs WHERE content_hash = ?")
                        .bind(&[JsValue::from_str(hash)])?
                        .run()
                        .await?;
                    storage.bucket.delete(format!("blobs/{hash}")).await?;
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
    let file_result = match database
        .prepare("INSERT OR REPLACE INTO files (artifact_row_id, path, content_hash, content_type, size_bytes) SELECT a.row_id, json_extract(mf.value, '$.path'), ?, json_extract(mf.value, '$.content_type'), json_extract(mf.value, '$.size_bytes') FROM artifacts a, json_each(a.manifest, '$.files') mf JOIN orgs o ON o.id = a.org_id WHERE a.id = ? AND o.slug = ? AND json_extract(mf.value, '$.sha256') = ? AND (a.expires_at IS NULL OR a.expires_at > ?)")
        .bind(&[
            JsValue::from_str(content_hash),
            JsValue::from_str(artifact_id),
            JsValue::from_str(&org),
            JsValue::from_str(content_hash),
            JsValue::from_str(&Utc::now().to_rfc3339()),
        ]) {
        Ok(statement) => statement.run().await,
        Err(error) => {
            let _ = storage.release_content_locks(&locks).await;
            return Err(error);
        }
    };
    let complete_result = match database
        .prepare("UPDATE artifacts SET expires_at = NULL WHERE id = ? AND org_id = ? AND (expires_at IS NULL OR expires_at > ?) AND NOT EXISTS (SELECT 1 FROM json_each(manifest, '$.files') mf WHERE NOT EXISTS (SELECT 1 FROM files f WHERE f.artifact_row_id = artifacts.row_id AND f.path = json_extract(mf.value, '$.path')))" )
        .bind(&[
            JsValue::from_str(artifact_id),
            JsValue::from_str(&org),
            JsValue::from_str(&Utc::now().to_rfc3339()),
        ]) {
        Ok(statement) => statement.run().await,
        Err(error) => {
            let _ = storage.release_content_locks(&locks).await;
            return Err(error);
        }
    };
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
    let credential = match require_org_credential(authorization.as_deref(), env).await? {
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
            "SELECT a.id AS id, o.slug AS org_id, a.tier AS tier, a.entrypoint AS entrypoint, a.created_at AS created_at, a.expires_at AS expires_at, a.title AS title, a.description AS description FROM artifacts a JOIN orgs o ON o.id = a.org_id WHERE a.id = ? ORDER BY a.row_id LIMIT 1",
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
            JsonResponseDefinition::json(
                serde_json::json!({
                    "id": row.id,
                    "tier": row.tier,
                    "entrypoint": row.entrypoint,
                    "created_at": row.created_at,
                    "expires_at": row.expires_at,
                    "title": row.title,
                    "description": row.description,
                }),
                200,
            )
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

pub(crate) async fn resolve_permanent_artifact(
    artifact_id: &str,
    requested_path: Option<&str>,
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
    let row = database
        .prepare("SELECT f.content_hash, a.entrypoint, a.tier, f.content_type, a.expires_at, a.manifest, o.slug AS org FROM artifacts a JOIN files f ON f.artifact_row_id = a.row_id JOIN orgs o ON o.id = a.org_id WHERE a.id = ? AND f.path = COALESCE(?, a.entrypoint) AND (a.expires_at IS NULL OR a.expires_at > ?) AND a.revoked_at IS NULL AND NOT EXISTS (SELECT 1 FROM json_each(a.manifest, '$.files') mf WHERE NOT EXISTS (SELECT 1 FROM files complete WHERE complete.artifact_row_id = a.row_id AND complete.path = json_extract(mf.value, '$.path'))) ORDER BY a.row_id LIMIT 1")
        .bind(&[
            JsValue::from_str(artifact_id),
            requested_path.map(JsValue::from_str).unwrap_or_else(JsValue::null),
            JsValue::from_str(&Utc::now().to_rfc3339()),
        ])?
        .first::<PermanentArtifactRow>(None)
        .await?;
    let Some(row) = row else {
        return expired_response();
    };
    let host = req.headers().get("Host")?;
    let authorization = req.headers().get("Authorization")?;
    let token = authorization
        .as_deref()
        .and_then(|value| value.strip_prefix("Bearer "))
        .map(str::trim)
        .filter(|value| !value.is_empty())
        .map(str::to_string)
        .or_else(|| {
            req.url()
                .ok()?
                .query_pairs()
                .find(|(key, _)| key == "token")
                .map(|(_, value)| value.into_owned())
        });
    let isolated_access = isolated_access_check(
        host.as_deref(),
        token.as_deref(),
        artifact_id,
        &row.org,
        artifact_token_secret(env).as_deref(),
        Utc::now(),
        &artifact_origin_suffix(env),
    );
    let mut viewer_user_id = None;
    match isolated_access {
        IsolatedAccess::Forbidden => return isolated_forbidden_response(),
        IsolatedAccess::Authorized => {}
        IsolatedAccess::NotIsolated => {
            if row.tier == "secure" {
                match require_org_credential(authorization.as_deref(), env).await? {
                    Ok(credential) if credential.org_id == row.org => {
                        viewer_user_id = Some(credential.user_id);
                    }
                    Ok(_) | Err(_) => {
                        return json_error(
                            ErrorCode::Unauthorized,
                            "Invalid organization token.",
                            401,
                        )
                    }
                }
            }
        }
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
    if requested_path.is_none() {
        emit_artifact_viewed(ctx, env, &row.org, artifact_id, viewer_user_id.as_deref());
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

pub(crate) async fn delete_artifact(path: &str, req: &Request, env: &Env) -> Result<Response> {
    let artifact_id = path.trim_start_matches("/v1/artifacts/");
    if artifact_id.len() == store::PUBLIC_ID_LENGTH {
        return delete_permanent_artifact(artifact_id, req, env).await;
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
    let credential = match require_org_credential(authorization.as_deref(), env).await? {
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
    manifest: String,
}

/// Builds the response for a permanent artifact that already exists.
///
/// Used twice: on the ordinary re-post, and when the unique index refuses an
/// insert because a concurrent create won the race. Both must look the same to
/// the caller, so `missing_files` is computed from blob existence rather than
/// assumed empty — an artifact whose earlier upload was interrupted still tells
/// the client what to send.
///
pub(crate) async fn existing_permanent_response(
    storage: &store::D1R2ArtifactStore,
    env: &Env,
    org: &str,
    artifact_id: &str,
    tier: ArtifactTier,
) -> Result<Option<Response>> {
    let Some(existing) = storage
        .database
        .prepare("SELECT manifest FROM artifacts WHERE org_id = ? AND id = ? AND revoked_at IS NULL ORDER BY row_id LIMIT 1")
        .bind(&[JsValue::from_str(org), JsValue::from_str(artifact_id)])?
        .first::<ExistingArtifactRow>(None)
        .await?
    else {
        return Ok(None);
    };

    let existing_manifest: PermanentManifest = serde_json::from_str(&existing.manifest)?;
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

    Ok(Some(
        JsonResponseDefinition::json(
            PermanentCreateArtifactResponse {
                id: artifact_id.to_string(),
                url: format!("{}/p/{artifact_id}", base_url.trim_end_matches('/')),
                tier,
                missing_files: missing_manifest_files(&existing_manifest, &present),
            },
            201,
        )
        .into_worker_response()?,
    ))
}

pub(crate) async fn create_permanent_artifact(
    raw: &Value,
    authorization: Option<&str>,
    env: &Env,
    ctx: &worker::Context,
) -> Result<Response> {
    let credential = match require_org_credential(authorization, env).await? {
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
    let artifact_id = store::public_id(&org, content_hash);
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
    // Idempotency (RUB-371). The public id is derived from (org, content_hash),
    // so re-posting identical bytes names the artifact that already exists.
    // Returning it is the point: inserting again added a second row, a second
    // file set and another refcount bump per file, which left blobs
    // unreclaimable and let a DELETE answer 204 while a duplicate row kept
    // serving.
    //
    // Live rows only: a revoked artifact does not serve, so re-publishing the
    // same bytes after a revocation must create a new row rather than resurrect
    // the revoked one.
    //
    // Checked before the content locks are taken so the early return cannot
    // leak one.
    if let Some(response) =
        existing_permanent_response(&storage, env, &org, &artifact_id, tier).await?
    {
        return Ok(response);
    }

    let locks = match storage.acquire_content_locks(&file_hashes).await {
        Ok(locks) => locks,
        Err(store::StoreError::Contention) => return retryable_contention_response(),
        Err(error) => return Err(worker::Error::RustError(error.to_string())),
    };
    let database = &storage.database;
    let row_id = Uuid::new_v4().simple().to_string();
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
        let mut statements = vec![
            database
                .prepare("INSERT OR IGNORE INTO orgs (id, slug, created_at) VALUES (?, ?, ?)")
                .bind(&[
                    JsValue::from_str(&org),
                    JsValue::from_str(&org),
                    JsValue::from_str(&now),
                ])?,
            database
                .prepare("INSERT INTO artifacts (row_id, id, org_id, content_hash, entrypoint, created_at, expires_at, tier, manifest, title, description) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)")
                .bind(&[
                    JsValue::from_str(&row_id),
                    JsValue::from_str(&artifact_id),
                    JsValue::from_str(&org),
                    JsValue::from_str(content_hash),
                    JsValue::from_str(entrypoint),
                    JsValue::from_str(&now),
                    expires_value,
                    JsValue::from_str(match tier {
                        ArtifactTier::Public => "public",
                        ArtifactTier::Secure => "secure",
                        ArtifactTier::Ephemeral => "ephemeral",
                    }),
                    JsValue::from_str(&serde_json::to_string(&manifest)?),
                    artifact_title.as_deref().map(JsValue::from_str).unwrap_or_else(JsValue::null),
                    artifact_description.as_deref().map(JsValue::from_str).unwrap_or_else(JsValue::null),
                ])?,
            database
                .prepare("INSERT INTO provenance (artifact_row_id, agent, repo_url, commit_sha, payload) VALUES (?, ?, ?, ?, ?)")
                .bind(&[
                    JsValue::from_str(&row_id),
                    agent.map(JsValue::from_str).unwrap_or_else(JsValue::null),
                    repo_url.map(JsValue::from_str).unwrap_or_else(JsValue::null),
                    commit_sha.map(JsValue::from_str).unwrap_or_else(JsValue::null),
                    JsValue::from_str(&provenance.to_string()),
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
            }
        }
        if let Err(error) = storage.execute_batch(statements).await {
            let message = error.to_string();
            // The partial unique index refuses a second live row for the same
            // (org, id). That is the race the pre-flight above cannot close on
            // its own: two concurrent creates of one bundle both pass it, one
            // wins the insert, and the loser has to return the artifact that won
            // -- a 500 here would mean a re-post fails depending on timing.
            if is_artifact_id_conflict(&message) {
                if let Some(response) =
                    existing_permanent_response(&storage, env, &org, &artifact_id, tier).await?
                {
                    created = false;

                    return Ok(response);
                }
            }

            return Err(worker::Error::RustError(message));
        }
        let missing_files = missing_manifest_files(&manifest, &existing);
        let base_url = env_string(env, "ARTFCT_PUBLIC_BASE_URL", DEFAULT_BASE_URL);
        JsonResponseDefinition::json(
            PermanentCreateArtifactResponse {
                id: artifact_id.clone(),
                url: format!("{}/p/{artifact_id}", base_url.trim_end_matches('/')),
                tier,
                missing_files,
            },
            201,
        )
        .into_worker_response()
    }
    .await;
    let release_result = storage.release_content_locks(&locks).await;
    let response = operation?;
    release_result.map_err(|error| worker::Error::RustError(error.to_string()))?;
    if created && response.status_code() == 201 {
        emit_artifact_created(ctx, env, &org, &artifact_id, tier);
    }
    Ok(response)
}
