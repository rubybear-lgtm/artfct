use super::*;

pub(crate) async fn create_artifact(
    req: &mut Request,
    env: &Env,
    ctx: &worker::Context,
) -> Result<Response> {
    let raw = match req.json::<Value>().await {
        Ok(payload) => payload,
        Err(_) => {
            return json_error(ErrorCode::InvalidJson, "Invalid JSON request body.", 400);
        }
    };

    if raw.get("mode").and_then(Value::as_str) == Some("permanent") {
        let authorization = req.headers().get("Authorization")?;
        return create_permanent_artifact(&raw, authorization.as_deref(), env, ctx).await;
    }

    if ephemeral_manifest_is_invalid(&raw) {
        return json_error(
            ErrorCode::ValidationFailed,
            "The manifest field is only valid for permanent artifacts.",
            422,
        );
    }

    let payload = match serde_json::from_value::<CreateArtifactRequest>(raw) {
        Ok(payload) => payload,
        Err(_) => {
            return json_error(ErrorCode::InvalidJson, "Invalid JSON request body.", 400);
        }
    };

    if payload.body_ciphertext_b64.trim().is_empty() {
        return json_error(
            ErrorCode::ValidationFailed,
            "The body_ciphertext_b64 field is required.",
            422,
        );
    }

    if payload.body_iv_b64.trim().is_empty() {
        return json_error(
            ErrorCode::ValidationFailed,
            "The body_iv_b64 field is required.",
            422,
        );
    }

    let max_html_bytes = env_usize(env, "ARTFCT_MAX_HTML_BYTES", DEFAULT_MAX_HTML_BYTES);
    let ciphertext_bytes = match base64::engine::general_purpose::URL_SAFE_NO_PAD
        .decode(payload.body_ciphertext_b64.trim())
    {
        Ok(bytes) => bytes,
        Err(_) => {
            return json_error(
                ErrorCode::ValidationFailed,
                "The body_ciphertext_b64 field must be valid base64url.",
                422,
            );
        }
    };

    if ciphertext_bytes.len() > max_html_bytes + 64 {
        return json_error(
            ErrorCode::BodyTooLarge,
            "The encrypted body exceeds the configured size limit.",
            413,
        );
    }

    let iv_bytes =
        match base64::engine::general_purpose::URL_SAFE_NO_PAD.decode(payload.body_iv_b64.trim()) {
            Ok(bytes) => bytes,
            Err(_) => {
                return json_error(
                    ErrorCode::ValidationFailed,
                    "The body_iv_b64 field must be valid base64url.",
                    422,
                );
            }
        };

    if iv_bytes.len() != 12 {
        return json_error(
            ErrorCode::ValidationFailed,
            "The body_iv_b64 field must decode to a 12-byte nonce.",
            422,
        );
    }

    let ttl_minutes = payload
        .ttl_minutes
        .unwrap_or_else(|| env_u64(env, "ARTFCT_DEFAULT_TTL_MINUTES", DEFAULT_TTL_MINUTES));
    let max_ttl_minutes = env_u64(env, "ARTFCT_MAX_TTL_MINUTES", MAX_TTL_MINUTES);

    if ttl_minutes == 0 || ttl_minutes > max_ttl_minutes {
        return json_error(
            ErrorCode::ValidationFailed,
            "ttl_minutes must be between 1 and the configured maximum.",
            422,
        );
    }

    let ttl_seconds = (ttl_minutes * 60).max(MIN_EXPIRATION_TTL_SECONDS);
    let now = Utc::now();
    let expires_at = now + chrono::Duration::seconds(ttl_seconds as i64);
    let kv_store = store::KvArtifactStore::new(env.kv(KV_BINDING)?);
    let artifact_id = loop {
        let candidate = random_artifact_id(ARTIFACT_ID_LENGTH);

        if kv_store
            .get_record::<String>(&candidate)
            .await
            .map_err(|error| worker::Error::RustError(error.to_string()))?
            .is_none()
        {
            break candidate;
        }
    };

    let title = normalize_metadata_value(payload.title, DEFAULT_ARTIFACT_TITLE);
    let description = normalize_metadata_value(payload.description, DEFAULT_ARTIFACT_DESCRIPTION);
    let thumbnail = normalize_metadata_value(payload.thumbnail, DEFAULT_ARTIFACT_THUMBNAIL);

    let stored = StoredArtifact {
        body_ciphertext_b64: payload.body_ciphertext_b64,
        body_iv_b64: payload.body_iv_b64,
        tier: payload.tier,
        title: title.clone(),
        description: description.clone(),
        thumbnail: thumbnail.clone(),
        preview_blurred: payload.preview_blurred,
        created_at: now.to_rfc3339_opts(SecondsFormat::Secs, true),
        expires_at: expires_at.to_rfc3339_opts(SecondsFormat::Secs, true),
    };

    let body = serde_json::to_string(&stored)?;
    kv_store
        .put_record(&artifact_id, &body, ttl_seconds)
        .await
        .map_err(|error| worker::Error::RustError(error.to_string()))?;

    let base_url = env_string(env, "ARTFCT_PUBLIC_BASE_URL", DEFAULT_BASE_URL);
    build_create_artifact_response(&artifact_id, &base_url, &stored).into_worker_response()
}

/// Queues `artifact.created` after the response is built. Best effort: the
/// send runs in `waitUntil` and can never change the response. `org` is the
/// verified credential's org.
pub(crate) fn emit_artifact_created(
    ctx: &worker::Context,
    env: &Env,
    org: &str,
    artifact_id: &str,
    tier: ArtifactTier,
) {
    let (Ok(secret), Ok(url)) = (
        env.var(events::EVENT_SECRET_ENV),
        env.var(events::EVENT_URL_ENV),
    ) else {
        return;
    };
    let tier = match tier {
        ArtifactTier::Public => "public",
        ArtifactTier::Secure => "secure",
        ArtifactTier::Ephemeral => "ephemeral",
    };
    let now = Utc::now();
    let event = events::build_event(
        &secret.to_string(),
        "artifact.created",
        org,
        &now.to_rfc3339_opts(SecondsFormat::Secs, true),
        now.timestamp(),
        serde_json::json!({"artifact_id": artifact_id, "tier": tier}),
    );
    let url = url.to_string();
    ctx.wait_until(async move { events::send(&url, event).await });
}

/// Queues `artifact.viewed` after a permanent artifact is served. The event
/// carries only the owning org, artifact id, and optional verified viewer id;
/// it never includes content, bearer tokens, or query-string access tokens.
pub(crate) fn emit_artifact_viewed(
    ctx: &worker::Context,
    env: &Env,
    org: &str,
    artifact_id: &str,
    viewer_user_id: Option<&str>,
) {
    let (Ok(secret), Ok(url)) = (
        env.var(events::EVENT_SECRET_ENV),
        env.var(events::EVENT_URL_ENV),
    ) else {
        return;
    };
    let now = Utc::now();
    let event = events::build_event(
        &secret.to_string(),
        "artifact.viewed",
        org,
        &now.to_rfc3339_opts(SecondsFormat::Secs, true),
        now.timestamp(),
        serde_json::json!({
            "artifact_id": artifact_id,
            "viewer_user_id": viewer_user_id,
            "source": "worker_preview",
        }),
    );
    let url = url.to_string();
    ctx.wait_until(async move { events::send(&url, event).await });
}

pub(crate) async fn resolve_artifact(
    path: &str,
    req: &Request,
    env: &Env,
    ctx: &worker::Context,
) -> Result<Response> {
    let suffix = path.trim_start_matches("/p/");
    let (artifact_id, requested_path) = suffix
        .split_once('/')
        .map_or((suffix, None), |(id, file)| (id, Some(file)));
    if artifact_id.len() == store::PUBLIC_ID_LENGTH
        && artifact_id
            .bytes()
            .all(|byte| byte.is_ascii_hexdigit() && !byte.is_ascii_uppercase())
    {
        return resolve_permanent_artifact(artifact_id, requested_path, req, env, ctx).await;
    }
    if !is_valid_artifact_id(artifact_id) {
        return expired_response();
    }

    let kv_store = store::KvArtifactStore::new(env.kv(KV_BINDING)?);
    let Some(mut stored) = kv_store
        .get_record::<StoredArtifact>(artifact_id)
        .await
        .map_err(|error| worker::Error::RustError(error.to_string()))?
    else {
        return expired_response();
    };

    // Sliding expiration: refresh on every access
    let ttl_minutes = env_u64(env, "ARTFCT_DEFAULT_TTL_MINUTES", DEFAULT_TTL_MINUTES).min(env_u64(
        env,
        "ARTFCT_MAX_TTL_MINUTES",
        MAX_TTL_MINUTES,
    ));
    let ttl_seconds = (ttl_minutes * 60).max(MIN_EXPIRATION_TTL_SECONDS);
    let now = Utc::now();
    let expires_at = now + chrono::Duration::seconds(ttl_seconds as i64);

    stored.expires_at = expires_at.to_rfc3339_opts(SecondsFormat::Secs, true);
    let body = serde_json::to_string(&stored)?;
    kv_store
        .put_record(artifact_id, &body, ttl_seconds)
        .await
        .map_err(|error| worker::Error::RustError(error.to_string()))?;

    let base_url = env_string(env, "ARTFCT_PUBLIC_BASE_URL", DEFAULT_BASE_URL);
    let url = format!("{}/p/{}", base_url.trim_end_matches('/'), artifact_id);
    let rendered = render_preview_shell(&stored, &url);

    build_preview_response(rendered).into_worker_response()
}

#[derive(Debug, Deserialize)]
pub(crate) struct PermanentHashRow {
    pub(crate) manifest: String,
    #[serde(default)]
    pub(crate) legal_hold: i64,
}

#[derive(Debug, Deserialize)]
pub(crate) struct PresenceRow {
    #[allow(dead_code)]
    present: i64,
}
/// Resolves the caller's verified credential: a signed org token (its `org_id`
/// claim is the org) or the legacy static token (the one org configured in
/// `ARTFCT_ORG_SLUG`). The org is never taken from a request field. The
/// inner `Err` is the ready-to-send refusal.
pub(crate) async fn update_artifact(path: &str, req: &mut Request, env: &Env) -> Result<Response> {
    let artifact_id = path.trim_start_matches("/v1/artifacts/");
    if !is_valid_artifact_id(artifact_id) {
        return json_error(ErrorCode::InvalidArtifactId, "Invalid artifact id.", 400);
    }

    #[derive(Deserialize)]
    struct UpdateArtifactRequest {
        ttl_minutes: u64,
    }

    let payload = match req.json::<UpdateArtifactRequest>().await {
        Ok(payload) => payload,
        Err(_) => {
            return json_error(ErrorCode::InvalidJson, "Invalid JSON request body.", 400);
        }
    };

    let max_ttl_minutes = env_u64(env, "ARTFCT_MAX_TTL_MINUTES", MAX_TTL_MINUTES);
    if payload.ttl_minutes == 0 || payload.ttl_minutes > max_ttl_minutes {
        return json_error(
            ErrorCode::ValidationFailed,
            "ttl_minutes must be between 1 and the configured maximum.",
            422,
        );
    }

    let Some(mut stored) = env
        .kv(KV_BINDING)?
        .get(artifact_id)
        .json::<StoredArtifact>()
        .await?
    else {
        return json_error(
            ErrorCode::ArtifactNotFound,
            "Artifact not found or expired.",
            404,
        );
    };

    let ttl_seconds = (payload.ttl_minutes * 60).max(MIN_EXPIRATION_TTL_SECONDS);
    let now = Utc::now();
    let expires_at = now + chrono::Duration::seconds(ttl_seconds as i64);

    stored.expires_at = expires_at.to_rfc3339_opts(SecondsFormat::Secs, true);

    let body = serde_json::to_string(&stored)?;
    env.kv(KV_BINDING)?
        .put(artifact_id, body)?
        .expiration_ttl(ttl_seconds)
        .execute()
        .await?;

    build_update_artifact_response(artifact_id, &stored).into_worker_response()
}

pub(crate) fn build_create_artifact_response(
    artifact_id: &str,
    base_url: &str,
    stored: &StoredArtifact,
) -> JsonResponseDefinition {
    JsonResponseDefinition::json(
        CreateArtifactResponse {
            id: artifact_id.to_string(),
            url: format!("{}/p/{}", base_url.trim_end_matches('/'), artifact_id),
            tier: stored.tier,
            expires_at: stored.expires_at.clone(),
            title: stored.title.clone(),
            description: stored.description.clone(),
            thumbnail: stored.thumbnail.clone(),
            preview_blurred: stored.preview_blurred,
        },
        201,
    )
}

pub(crate) fn build_update_artifact_response(
    artifact_id: &str,
    stored: &StoredArtifact,
) -> JsonResponseDefinition {
    JsonResponseDefinition::json(
        UpdateArtifactResponse {
            id: artifact_id.to_string(),
            expires_at: stored.expires_at.clone(),
        },
        200,
    )
}
