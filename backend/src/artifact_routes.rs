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
