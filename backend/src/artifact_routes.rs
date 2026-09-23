use super::*;

#[derive(Debug, Deserialize)]
struct ArtifactMetadataRow {
    id: String,
    org_id: String,
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
    let locks = storage
        .acquire_content_locks(&lock_hashes)
        .await
        .map_err(|error| worker::Error::RustError(error.to_string()))?;
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
