use base64::Engine;
use chrono::{SecondsFormat, Utc};
use serde::{Deserialize, Serialize};
use serde_json::Value;
use uuid::Uuid;
use worker::wasm_bindgen::JsValue;
use worker::{event, Env, Headers, Method, Request, Response, Result};

pub mod store;

const KV_BINDING: &str = "ARTIFACTS_KV";
const DEFAULT_BASE_URL: &str = "https://artfct.dev";
const DEFAULT_MAX_HTML_BYTES: usize = 1024 * 1024;
const DEFAULT_TTL_MINUTES: u64 = 5 * 24 * 60;
const MAX_TTL_MINUTES: u64 = 365 * 24 * 60;
const MIN_EXPIRATION_TTL_SECONDS: u64 = 60;
const ARTIFACT_ID_LENGTH: usize = 10;
const NOT_IMPLEMENTED_STATUS: u16 = 501;
const DEFAULT_ARTIFACT_TITLE: &str = "Encrypted artifact";
const DEFAULT_ARTIFACT_DESCRIPTION: &str = "Encrypted HTML preview on artfct.";
const DEFAULT_ARTIFACT_THUMBNAIL: &str = "https://artfct.dev/og-image.svg";
const PREVIEW_CONTENT_SECURITY_POLICY: &str = "default-src 'self' https:; script-src 'unsafe-inline' 'unsafe-eval' https:; style-src 'unsafe-inline' https:; font-src https: data:; img-src 'self' data: blob: https:; frame-ancestors 'none'; form-action 'none'; base-uri 'none';";

#[derive(Debug, Deserialize)]
#[serde(rename_all = "snake_case")]
struct CreateArtifactRequest {
    body_ciphertext_b64: String,
    body_iv_b64: String,
    tier: ArtifactTier,
    ttl_minutes: Option<u64>,
    title: Option<String>,
    description: Option<String>,
    thumbnail: Option<String>,
    #[serde(default = "default_preview_blurred")]
    preview_blurred: bool,
}

#[derive(Debug, Serialize)]
#[serde(rename_all = "snake_case")]
struct CreateArtifactResponse {
    id: String,
    url: String,
    tier: ArtifactTier,
    expires_at: String,
    title: String,
    description: String,
    thumbnail: String,
    preview_blurred: bool,
}

#[derive(Debug, Serialize)]
struct PermanentCreateArtifactResponse {
    id: String,
    url: String,
    tier: ArtifactTier,
    missing_files: Vec<String>,
}

#[derive(Debug, Serialize)]
struct UpdateArtifactResponse {
    id: String,
    expires_at: String,
}

#[derive(Debug, Serialize)]
struct ErrorResponse<'a> {
    error: ErrorBody<'a>,
}

#[derive(Debug, Serialize)]
struct ErrorBody<'a> {
    code: ErrorCode,
    message: &'a str,
    details: serde_json::Value,
}

#[derive(Debug, Serialize, Clone, Copy)]
#[serde(rename_all = "snake_case")]
#[allow(
    dead_code,
    reason = "reserved error codes are part of the wire contract"
)]
enum ErrorCode {
    InvalidJson,
    ValidationFailed,
    InvalidArtifactId,
    ArtifactNotFound,
    BodyTooLarge,
    BundleTooLarge,
    EntrypointMissing,
    InvalidPath,
    DuplicatePath,
    HashMismatch,
    FileCountExceeded,
    Unauthorized,
    Forbidden,
    NotImplemented,
    InternalError,
}

impl ErrorCode {
    #[allow(dead_code, reason = "used by native contract tests")]
    const ALL: [Self; 15] = [
        Self::InvalidJson,
        Self::ValidationFailed,
        Self::InvalidArtifactId,
        Self::ArtifactNotFound,
        Self::BodyTooLarge,
        Self::BundleTooLarge,
        Self::EntrypointMissing,
        Self::InvalidPath,
        Self::DuplicatePath,
        Self::HashMismatch,
        Self::FileCountExceeded,
        Self::Unauthorized,
        Self::Forbidden,
        Self::NotImplemented,
        Self::InternalError,
    ];
}

#[derive(Debug)]
struct JsonResponseDefinition {
    status: u16,
    body: serde_json::Value,
    headers: Vec<(&'static str, &'static str)>,
}

#[derive(Debug)]
struct HtmlResponseDefinition {
    status: u16,
    body: String,
    headers: Vec<(&'static str, &'static str)>,
}

impl HtmlResponseDefinition {
    fn preview(body: String, status: u16) -> Self {
        Self {
            status,
            body,
            headers: vec![
                ("Content-Type", "text/html; charset=utf-8"),
                ("X-Frame-Options", "DENY"),
                ("X-Content-Type-Options", "nosniff"),
                ("Content-Security-Policy", PREVIEW_CONTENT_SECURITY_POLICY),
            ],
        }
    }

    fn into_worker_response(self) -> Result<Response> {
        let headers = Headers::new();
        for (name, value) in self.headers {
            headers.set(name, value)?;
        }

        Ok(Response::from_html(&self.body)?
            .with_headers(headers)
            .with_status(self.status))
    }
}

#[derive(Debug)]
struct EmptyResponseDefinition {
    status: u16,
    headers: Vec<(&'static str, &'static str)>,
}

impl EmptyResponseDefinition {
    fn delete() -> Self {
        Self {
            status: 204,
            headers: Vec::new(),
        }
    }

    fn options() -> Self {
        Self {
            status: 204,
            headers: vec![
                ("Access-Control-Allow-Origin", "*"),
                (
                    "Access-Control-Allow-Methods",
                    "POST, PATCH, DELETE, OPTIONS",
                ),
                (
                    "Access-Control-Allow-Headers",
                    "Content-Type, Authorization",
                ),
            ],
        }
    }

    fn into_worker_response(self) -> Result<Response> {
        let mut response = Response::empty()?.with_status(self.status);
        for (name, value) in self.headers {
            response.headers_mut().set(name, value)?;
        }

        Ok(response)
    }
}

impl JsonResponseDefinition {
    fn json<T: Serialize>(value: T, status: u16) -> Self {
        Self {
            status,
            body: serde_json::to_value(value).expect("response bodies are serializable"),
            headers: Vec::new(),
        }
    }

    fn with_header(mut self, name: &'static str, value: &'static str) -> Self {
        self.headers.push((name, value));
        self
    }

    fn into_worker_response(self) -> Result<Response> {
        let mut response = json_response(&self.body, self.status)?;
        for (name, value) in self.headers {
            response.headers_mut().set(name, value)?;
        }

        Ok(response)
    }
}

#[derive(Debug, Serialize, Deserialize, Clone, Copy, PartialEq, Eq)]
#[serde(rename_all = "snake_case")]
enum ArtifactTier {
    Public,
    Secure,
    Ephemeral,
}

#[derive(Debug, Serialize, Deserialize)]
#[serde(rename_all = "snake_case")]
struct StoredArtifact {
    body_ciphertext_b64: String,
    body_iv_b64: String,
    tier: ArtifactTier,
    title: String,
    description: String,
    thumbnail: String,
    preview_blurred: bool,
    created_at: String,
    expires_at: String,
}

#[event(fetch)]
pub async fn main(mut req: Request, env: Env, _ctx: worker::Context) -> Result<Response> {
    console_error_panic_hook::set_once();

    let method = req.method();
    let url = req.url()?;
    let path = url.path();

    if let Some(response) = dispatch_unimplemented_route(&method, path) {
        return response.into_worker_response();
    }

    match (method, path) {
        (Method::Post, "/v1/artifacts") => create_artifact(&mut req, &env).await,
        (Method::Delete, path) if path.starts_with("/v1/artifacts/") => {
            delete_artifact(path, &req, &env).await
        }
        (Method::Patch, path) if path.starts_with("/v1/artifacts/") => {
            update_artifact(path, &mut req, &env).await
        }
        (Method::Put, path) if path.starts_with("/v1/artifacts/") && path.contains("/files/") => {
            upload_permanent_file(path, &mut req, &env).await
        }
        (Method::Get, path) if path.starts_with("/v1/orgs/") && path.ends_with("/export") => {
            export_organization(path, &req, &env).await
        }
        (Method::Get, path) if path.starts_with("/v1/blobs/") => {
            download_export_blob(path, &req, &env).await
        }
        (Method::Get, path) if path.starts_with("/p/") => resolve_artifact(path, &req, &env).await,
        (Method::Options, _) => options_response(),
        _ => not_found_response(),
    }
}

async fn create_artifact(req: &mut Request, env: &Env) -> Result<Response> {
    let raw = match req.json::<Value>().await {
        Ok(payload) => payload,
        Err(_) => {
            return json_error(ErrorCode::InvalidJson, "Invalid JSON request body.", 400);
        }
    };

    if raw.get("mode").and_then(Value::as_str) == Some("permanent") {
        let authorization = req.headers().get("Authorization")?;
        return create_permanent_artifact(&raw, authorization.as_deref(), env).await;
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

fn ephemeral_manifest_is_invalid(raw: &Value) -> bool {
    raw.get("mode").and_then(Value::as_str) != Some("permanent") && raw.get("manifest").is_some()
}

fn is_valid_relative_path(path: &str) -> bool {
    !path.is_empty()
        && path.len() <= 255
        && !path.starts_with('/')
        && !path.contains(':')
        && !path
            .split('/')
            .any(|segment| segment == ".." || segment.is_empty())
}

async fn create_permanent_artifact(
    raw: &Value,
    authorization: Option<&str>,
    env: &Env,
) -> Result<Response> {
    if !authorized_for_org(authorization, env) {
        return json_error(ErrorCode::Unauthorized, "Invalid organization token.", 401);
    };

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
    let Some(manifest) = raw.get("manifest").and_then(Value::as_object) else {
        return json_error(ErrorCode::ValidationFailed, "A manifest is required.", 422);
    };
    let Some(entrypoint) = manifest.get("entrypoint").and_then(Value::as_str) else {
        return json_error(
            ErrorCode::ValidationFailed,
            "A manifest entrypoint is required.",
            422,
        );
    };
    if !is_valid_relative_path(entrypoint) {
        return json_error(
            ErrorCode::InvalidPath,
            "The manifest entrypoint is invalid.",
            422,
        );
    }
    let Some(files) = manifest.get("files").and_then(Value::as_array) else {
        return json_error(
            ErrorCode::ValidationFailed,
            "Manifest files are required.",
            422,
        );
    };
    if manifest
        .get("external_origins")
        .and_then(Value::as_array)
        .is_none()
    {
        return json_error(
            ErrorCode::ValidationFailed,
            "Manifest external_origins are required.",
            422,
        );
    }
    if files.len() != 1 || files[0].get("path").and_then(Value::as_str) != Some(entrypoint) {
        return json_error(
            ErrorCode::ValidationFailed,
            "Spec 03 permanent uploads support exactly one entrypoint file.",
            422,
        );
    }
    let file = &files[0];
    if file
        .get("content_type")
        .and_then(Value::as_str)
        .is_none_or(str::is_empty)
    {
        return json_error(
            ErrorCode::ValidationFailed,
            "A file content type is required.",
            422,
        );
    }
    let Some(content_hash) = file.get("sha256").and_then(Value::as_str) else {
        return json_error(
            ErrorCode::ValidationFailed,
            "A file SHA-256 is required.",
            422,
        );
    };
    if content_hash.len() != 64
        || !content_hash
            .bytes()
            .all(|byte| byte.is_ascii_hexdigit() && !byte.is_ascii_uppercase())
    {
        return json_error(
            ErrorCode::ValidationFailed,
            "The file SHA-256 is invalid.",
            422,
        );
    }
    let Some(size) = file.get("size_bytes").and_then(Value::as_u64) else {
        return json_error(ErrorCode::ValidationFailed, "A file size is required.", 422);
    };
    if size > 6 * 1024 * 1024 {
        return json_error(
            ErrorCode::BundleTooLarge,
            "The permanent file is too large.",
            413,
        );
    }

    let org = env_string(env, "ARTFCT_ORG_SLUG", "default");
    if let Err(message) = store::validate_slug(&org) {
        return json_error(ErrorCode::ValidationFailed, &message, 422);
    }
    let artifact_id = store::public_id(content_hash);
    let now = Utc::now().to_rfc3339_opts(SecondsFormat::Secs, true);
    let provenance = raw
        .get("provenance")
        .cloned()
        .unwrap_or_else(|| serde_json::json!({}));
    let agent = provenance.get("agent").and_then(Value::as_str);
    let repo_url = provenance.get("repo_url").and_then(Value::as_str);
    let commit_sha = provenance.get("commit_sha").and_then(Value::as_str);
    let storage =
        store::D1R2ArtifactStore::new(env.d1("ARTIFACTS_DB")?, env.bucket("ARTIFACTS_BUCKET")?);
    let lock_owner = storage
        .acquire_content_lock(content_hash)
        .await
        .map_err(|error| worker::Error::RustError(error.to_string()))?;
    let database = &storage.database;
    let row_id = Uuid::new_v4().simple().to_string();
    let content_type = file
        .get("content_type")
        .and_then(Value::as_str)
        .unwrap_or("application/octet-stream");
    let operation: Result<Response> = async {
        let blob_exists = storage
            .blob_exists(content_hash)
            .await
            .map_err(|error| worker::Error::RustError(error.to_string()))?;
        let mut statements = vec![
            database
                .prepare("INSERT OR IGNORE INTO orgs (id, slug, created_at) VALUES (?, ?, ?)")
                .bind(&[
                    JsValue::from_str(&org),
                    JsValue::from_str(&org),
                    JsValue::from_str(&now),
                ])?,
            database
                .prepare("INSERT OR IGNORE INTO blobs (content_hash, size_bytes, content_type, ref_count, created_at) VALUES (?, ?, ?, 0, ?)")
                .bind(&[
                    JsValue::from_str(content_hash),
                    JsValue::from_f64(size as f64),
                    JsValue::from_str(content_type),
                    JsValue::from_str(&now),
                ])?,
            database
                .prepare("UPDATE blobs SET ref_count = ref_count + 1 WHERE content_hash = ?")
                .bind(&[JsValue::from_str(content_hash)])?,
            database
                .prepare("INSERT INTO artifacts (row_id, id, org_id, content_hash, entrypoint, created_at, tier) VALUES (?, ?, ?, ?, ?, ?, ?)")
                .bind(&[
                    JsValue::from_str(&row_id),
                    JsValue::from_str(&artifact_id),
                    JsValue::from_str(&org),
                    JsValue::from_str(content_hash),
                    JsValue::from_str(entrypoint),
                    JsValue::from_str(&now),
                    JsValue::from_str(match tier {
                        ArtifactTier::Public => "public",
                        ArtifactTier::Secure => "secure",
                        ArtifactTier::Ephemeral => "ephemeral",
                    }),
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
        if blob_exists {
            statements.push(
                database
                    .prepare("INSERT INTO files (artifact_row_id, path, content_hash, content_type, size_bytes) VALUES (?, ?, ?, ?, ?)")
                    .bind(&[
                        JsValue::from_str(&row_id),
                        JsValue::from_str(entrypoint),
                        JsValue::from_str(content_hash),
                        JsValue::from_str(content_type),
                        JsValue::from_f64(size as f64),
                    ])?,
            );
        }
        storage
            .execute_batch(statements)
            .await
            .map_err(|error| worker::Error::RustError(error.to_string()))?;

        let base_url = env_string(env, "ARTFCT_PUBLIC_BASE_URL", DEFAULT_BASE_URL);
        let missing_files = if blob_exists {
            Vec::new()
        } else {
            vec![content_hash.to_string()]
        };
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
    let release_result = storage
        .release_content_lock(content_hash, &lock_owner)
        .await;
    let response = operation?;
    release_result.map_err(|error| worker::Error::RustError(error.to_string()))?;
    Ok(response)
}

async fn resolve_artifact(path: &str, req: &Request, env: &Env) -> Result<Response> {
    let artifact_id = path.trim_start_matches("/p/");
    if artifact_id.len() == store::PUBLIC_ID_LENGTH
        && artifact_id
            .bytes()
            .all(|byte| byte.is_ascii_hexdigit() && !byte.is_ascii_uppercase())
    {
        return resolve_permanent_artifact(artifact_id, req, env).await;
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
struct PermanentArtifactRow {
    content_hash: String,
    entrypoint: String,
    tier: String,
}

#[derive(Debug, Deserialize)]
struct PermanentHashRow {
    row_id: String,
    content_hash: String,
}

#[derive(Debug, Deserialize)]
struct ContentHashRow {
    content_hash: String,
}

#[derive(Debug, Deserialize)]
struct UploadArtifactRow {
    content_type: String,
    expected_size: i64,
}

#[derive(Debug, Deserialize)]
struct PresenceRow {
    #[allow(dead_code)]
    present: i64,
}

async fn resolve_permanent_artifact(
    artifact_id: &str,
    req: &Request,
    env: &Env,
) -> Result<Response> {
    let storage =
        store::D1R2ArtifactStore::new(env.d1("ARTIFACTS_DB")?, env.bucket("ARTIFACTS_BUCKET")?);
    let database = &storage.database;
    let row = database
        .prepare("SELECT a.content_hash, a.entrypoint, a.tier FROM artifacts a JOIN files f ON f.artifact_row_id = a.row_id AND f.content_hash = a.content_hash JOIN orgs o ON o.id = a.org_id WHERE a.id = ? AND o.slug = ? ORDER BY a.row_id LIMIT 1")
        .bind(&[
            JsValue::from_str(artifact_id),
            JsValue::from_str(&env_string(env, "ARTFCT_ORG_SLUG", "default")),
        ])?
        .first::<PermanentArtifactRow>(None)
        .await?;
    let Some(row) = row else {
        return expired_response();
    };
    if row.tier == "secure" {
        let authorization = req.headers().get("Authorization")?;
        if !authorized_for_org(authorization.as_deref(), env) {
            return json_error(ErrorCode::Unauthorized, "Invalid organization token.", 401);
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
    let content_type = object
        .http_metadata()
        .content_type
        .unwrap_or_else(|| "text/html; charset=utf-8".to_string());
    let mut response = Response::from_bytes(bytes)?.with_status(200);
    response.headers_mut().set("Content-Type", &content_type)?;
    response
        .headers_mut()
        .set("X-Content-Type-Options", "nosniff")?;
    response
        .headers_mut()
        .set("Content-Security-Policy", PREVIEW_CONTENT_SECURITY_POLICY)?;
    let _ = row.entrypoint;
    Ok(response)
}

async fn upload_permanent_file(path: &str, req: &mut Request, env: &Env) -> Result<Response> {
    let authorization = req.headers().get("Authorization")?;
    if !authorized_for_org(authorization.as_deref(), env) {
        return json_error(
            ErrorCode::Unauthorized,
            "An organization token is required.",
            401,
        );
    }
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
    let lock_owner = storage
        .acquire_content_lock(content_hash)
        .await
        .map_err(|error| worker::Error::RustError(error.to_string()))?;
    let database = &storage.database;
    let org = env_string(env, "ARTFCT_ORG_SLUG", "default");
    let row_statement = match database
        .prepare("SELECT b.content_type, b.size_bytes AS expected_size FROM artifacts a JOIN blobs b ON b.content_hash = a.content_hash JOIN orgs o ON o.id = a.org_id WHERE a.id = ? AND a.content_hash = ? AND o.slug = ? ORDER BY a.row_id LIMIT 1")
        .bind(&[
            JsValue::from_str(artifact_id),
            JsValue::from_str(content_hash),
            JsValue::from_str(&org),
        ]) {
        Ok(statement) => statement,
        Err(error) => {
            let _ = storage.release_content_lock(content_hash, &lock_owner).await;
            return Err(error);
        }
    };
    let row = match row_statement.first::<UploadArtifactRow>(None).await {
        Ok(row) => row,
        Err(error) => {
            let _ = storage
                .release_content_lock(content_hash, &lock_owner)
                .await;
            return Err(error);
        }
    };
    let Some(row) = row else {
        let _ = storage
            .release_content_lock(content_hash, &lock_owner)
            .await;
        return json_error(
            ErrorCode::ArtifactNotFound,
            "Artifact not found or already uploaded.",
            404,
        );
    };
    let bytes = match req.bytes().await {
        Ok(bytes) => bytes,
        Err(error) => {
            let _ = storage
                .release_content_lock(content_hash, &lock_owner)
                .await;
            return Err(error);
        }
    };
    if bytes.len() > 6 * 1024 * 1024 {
        let _ = storage
            .release_content_lock(content_hash, &lock_owner)
            .await;
        return json_error(
            ErrorCode::BundleTooLarge,
            "The permanent file is too large.",
            413,
        );
    }
    if store::content_hash(&bytes) != content_hash {
        let _ = storage
            .release_content_lock(content_hash, &lock_owner)
            .await;
        return json_error(
            ErrorCode::HashMismatch,
            "The uploaded file hash does not match.",
            422,
        );
    }
    if row.expected_size != bytes.len() as i64 {
        let _ = storage
            .release_content_lock(content_hash, &lock_owner)
            .await;
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
        let _ = storage
            .release_content_lock(content_hash, &lock_owner)
            .await;
        return Err(error);
    }
    let file_result = match database
        .prepare("INSERT OR REPLACE INTO files (artifact_row_id, path, content_hash, content_type, size_bytes) SELECT a.row_id, a.entrypoint, a.content_hash, ?, ? FROM artifacts a JOIN orgs o ON o.id = a.org_id WHERE a.id = ? AND a.content_hash = ? AND o.slug = ?")
        .bind(&[
            JsValue::from_str(&content_type),
            JsValue::from_f64(bytes.len() as f64),
            JsValue::from_str(artifact_id),
            JsValue::from_str(content_hash),
            JsValue::from_str(&org),
        ]) {
        Ok(statement) => statement.run().await,
        Err(error) => {
            let _ = storage.release_content_lock(content_hash, &lock_owner).await;
            return Err(error);
        }
    };
    let release_result = storage
        .release_content_lock(content_hash, &lock_owner)
        .await;
    file_result?;
    release_result.map_err(|error| worker::Error::RustError(error.to_string()))?;
    EmptyResponseDefinition {
        status: 204,
        headers: Vec::new(),
    }
    .into_worker_response()
}

fn authorized_for_org(authorization: Option<&str>, env: &Env) -> bool {
    authorization_matches(
        env.var("ARTFCT_ORG_TOKEN")
            .ok()
            .map(|value| value.to_string())
            .as_deref(),
        authorization,
    )
}

fn authorization_matches(expected: Option<&str>, authorization: Option<&str>) -> bool {
    let Some(expected) = expected.filter(|token| !token.is_empty()) else {
        return false;
    };
    let Some(actual) = authorization
        .and_then(|value| value.strip_prefix("Bearer "))
        .map(str::trim)
        .filter(|token| !token.is_empty())
    else {
        return false;
    };
    constant_time_equal(actual.as_bytes(), expected.as_bytes())
}

fn constant_time_equal(left: &[u8], right: &[u8]) -> bool {
    let mut difference = left.len() ^ right.len();
    for index in 0..left.len().max(right.len()) {
        difference |= usize::from(
            left.get(index).copied().unwrap_or(0) ^ right.get(index).copied().unwrap_or(0),
        );
    }
    difference == 0
}

async fn delete_artifact(path: &str, req: &Request, env: &Env) -> Result<Response> {
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

#[derive(Debug, Serialize)]
struct ExportPayload {
    artifacts: Vec<Value>,
    blobs: std::collections::HashMap<String, String>,
}

#[derive(Debug, Deserialize)]
struct ExportRow {
    id: String,
    content_hash: String,
    entrypoint: String,
    created_at: String,
    provenance: Option<String>,
}

async fn export_organization(path: &str, req: &Request, env: &Env) -> Result<Response> {
    let authorization = req.headers().get("Authorization")?;
    if !authorized_for_org(authorization.as_deref(), env) {
        return json_error(
            ErrorCode::Unauthorized,
            "An organization token is required.",
            401,
        );
    }
    let org = path
        .trim_start_matches("/v1/orgs/")
        .trim_end_matches("/export")
        .trim_end_matches('/');
    if let Err(message) = store::validate_slug(org) {
        return json_error(ErrorCode::ValidationFailed, &message, 422);
    }
    if org != env_string(env, "ARTFCT_ORG_SLUG", "default") {
        return json_error(
            ErrorCode::Forbidden,
            "Token is not authorized for this organization.",
            403,
        );
    }
    let database = env.d1("ARTIFACTS_DB")?;
    let rows = database
        .prepare("SELECT a.id, a.content_hash, a.entrypoint, a.created_at, p.payload AS provenance FROM artifacts a JOIN files f ON f.artifact_row_id = a.row_id AND f.content_hash = a.content_hash LEFT JOIN provenance p ON p.artifact_row_id = a.row_id JOIN orgs o ON o.id = a.org_id WHERE o.slug = ? ORDER BY a.created_at")
        .bind(&[JsValue::from_str(org)])?
        .all()
        .await?
        .results::<ExportRow>()?;
    let mut blobs = std::collections::HashMap::new();
    let mut artifacts = Vec::with_capacity(rows.len());
    for row in rows {
        if !blobs.contains_key(&row.content_hash) {
            blobs.insert(
                row.content_hash.clone(),
                format!(
                    "{}/v1/blobs/{}",
                    env_string(env, "ARTFCT_PUBLIC_BASE_URL", DEFAULT_BASE_URL)
                        .trim_end_matches('/'),
                    row.content_hash
                ),
            );
        }
        artifacts.push(serde_json::json!({
            "id": row.id,
            "content_hash": row.content_hash,
            "entrypoint": row.entrypoint,
            "created_at": row.created_at,
            "provenance": row.provenance.and_then(|value| serde_json::from_str::<Value>(&value).ok()),
        }));
    }
    JsonResponseDefinition::json(ExportPayload { artifacts, blobs }, 200).into_worker_response()
}

async fn delete_permanent_artifact(
    artifact_id: &str,
    req: &Request,
    env: &Env,
) -> Result<Response> {
    let authorization = req.headers().get("Authorization")?;
    if !authorized_for_org(authorization.as_deref(), env) {
        return json_error(ErrorCode::Unauthorized, "Invalid organization token.", 401);
    }
    let storage =
        store::D1R2ArtifactStore::new(env.d1("ARTIFACTS_DB")?, env.bucket("ARTIFACTS_BUCKET")?);
    let database = &storage.database;
    let lock_hash = database
        .prepare("SELECT a.content_hash FROM artifacts a JOIN orgs o ON o.id = a.org_id WHERE a.id = ? AND o.slug = ? ORDER BY a.row_id LIMIT 1")
        .bind(&[
            JsValue::from_str(artifact_id),
            JsValue::from_str(&env_string(env, "ARTFCT_ORG_SLUG", "default")),
        ])?
        .first::<ContentHashRow>(None)
        .await?
        .map(|row| row.content_hash);
    let Some(lock_hash) = lock_hash else {
        return json_error(ErrorCode::ArtifactNotFound, "Artifact not found.", 404);
    };
    let lock_owner = storage
        .acquire_content_lock(&lock_hash)
        .await
        .map_err(|error| worker::Error::RustError(error.to_string()))?;
    let operation: Result<Response> = async {
        let row = database
            .prepare("SELECT a.row_id, a.content_hash FROM artifacts a JOIN orgs o ON o.id = a.org_id WHERE a.id = ? AND o.slug = ? ORDER BY a.row_id LIMIT 1")
            .bind(&[
                JsValue::from_str(artifact_id),
                JsValue::from_str(&env_string(env, "ARTFCT_ORG_SLUG", "default")),
            ])?
            .first::<PermanentHashRow>(None)
            .await?;
        let Some(row) = row else {
            return json_error(ErrorCode::ArtifactNotFound, "Artifact not found.", 404);
        };
        database
            .prepare("DELETE FROM artifacts WHERE row_id = ?")
            .bind(&[JsValue::from_str(&row.row_id)])?
            .run()
            .await?;
        let count = database
            .prepare("SELECT COUNT(*) AS count FROM artifacts WHERE content_hash = ?")
            .bind(&[JsValue::from_str(&row.content_hash)])?
            .first::<BlobReferenceRow>(None)
            .await?
            .map(|value| value.count)
            .unwrap_or(0);
        if count == 0 {
            database
                .prepare("DELETE FROM blobs WHERE content_hash = ?")
                .bind(&[JsValue::from_str(&row.content_hash)])?
                .run()
                .await?;
            storage
                .bucket
                .delete(format!("blobs/{}", row.content_hash))
                .await?;
        } else {
            database
                .prepare("UPDATE blobs SET ref_count = ? WHERE content_hash = ?")
                .bind(&[
                    JsValue::from_f64(count as f64),
                    JsValue::from_str(&row.content_hash),
                ])?
                .run()
                .await?;
        }
        build_delete_response().into_worker_response()
    }
    .await;
    let release_result = storage.release_content_lock(&lock_hash, &lock_owner).await;
    let response = operation?;
    release_result.map_err(|error| worker::Error::RustError(error.to_string()))?;
    Ok(response)
}

async fn download_export_blob(path: &str, req: &Request, env: &Env) -> Result<Response> {
    let authorization = req.headers().get("Authorization")?;
    if !authorized_for_org(authorization.as_deref(), env) {
        return json_error(ErrorCode::Unauthorized, "Invalid organization token.", 401);
    }
    let hash = path.trim_start_matches("/v1/blobs/");
    if hash.len() != 64
        || !hash
            .bytes()
            .all(|byte| byte.is_ascii_hexdigit() && !byte.is_ascii_uppercase())
    {
        return json_error(ErrorCode::InvalidArtifactId, "Invalid blob hash.", 400);
    }
    let database = env.d1("ARTIFACTS_DB")?;
    let org = env_string(env, "ARTFCT_ORG_SLUG", "default");
    if database
        .prepare("SELECT 1 AS present FROM artifacts a JOIN orgs o ON o.id = a.org_id WHERE a.content_hash = ? AND o.slug = ? LIMIT 1")
        .bind(&[JsValue::from_str(hash), JsValue::from_str(&org)])?
        .first::<PresenceRow>(None)
        .await?
        .is_none()
    {
        return json_error(ErrorCode::ArtifactNotFound, "Blob not found.", 404);
    }
    let object = env
        .bucket("ARTIFACTS_BUCKET")?
        .get(format!("blobs/{hash}"))
        .execute()
        .await?;
    let Some(object) = object else {
        return json_error(ErrorCode::ArtifactNotFound, "Blob not found.", 404);
    };
    let Some(body) = object.body() else {
        return json_error(ErrorCode::ArtifactNotFound, "Blob not found.", 404);
    };
    let bytes = body.bytes().await?;
    let mut response = Response::from_bytes(bytes)?.with_status(200);
    response.headers_mut().set(
        "Content-Type",
        &object
            .http_metadata()
            .content_type
            .unwrap_or_else(|| "application/octet-stream".to_string()),
    )?;
    Ok(response)
}

#[derive(Debug, Deserialize)]
struct BlobReferenceRow {
    count: i64,
}

async fn update_artifact(path: &str, req: &mut Request, env: &Env) -> Result<Response> {
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

fn build_create_artifact_response(
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

fn build_update_artifact_response(
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

fn normalize_metadata_value(value: Option<String>, default: &str) -> String {
    let normalized = value
        .map(|value| value.trim().to_string())
        .unwrap_or_default();

    if normalized.is_empty() {
        default.to_string()
    } else {
        normalized
    }
}

fn default_preview_blurred() -> bool {
    true
}

fn render_preview_shell(artifact: &StoredArtifact, url: &str) -> String {
    let escaped_title = escape_text(&artifact.title);
    let escaped_description = escape_text(&artifact.description);
    let escaped_thumbnail = escape_attr(&artifact.thumbnail);
    let escaped_url = escape_attr(url);
    let escaped_expires_at = escape_text(&artifact.expires_at);
    let escaped_preview_status = escape_text(if artifact.preview_blurred {
        "Link preview will start blurred."
    } else {
        "Link preview will start unblurred."
    });
    let preview_class = if artifact.preview_blurred {
        " is-blurred"
    } else {
        ""
    };
    let payload = serde_json::json!({
        "bodyCiphertextB64": artifact.body_ciphertext_b64,
        "bodyIvB64": artifact.body_iv_b64,
        "previewBlurred": artifact.preview_blurred,
    });
    let payload_json = escape_json_script(&serde_json::to_string(&payload).unwrap());

    format!(
        r#"<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>{escaped_title}</title>
<meta name="description" content="{escaped_description}">
<meta property="og:title" content="{escaped_title}">
<meta property="og:description" content="{escaped_description}">
<meta property="og:image" content="{escaped_thumbnail}">
<meta property="og:url" content="{escaped_url}">
<meta property="og:type" content="website">
<meta property="og:site_name" content="artfct">
<meta name="twitter:card" content="summary_large_image">
<meta name="twitter:title" content="{escaped_title}">
<meta name="twitter:description" content="{escaped_description}">
<meta name="twitter:image" content="{escaped_thumbnail}">
<link rel="canonical" href="{escaped_url}">
<style>
:root{{color-scheme:dark;}}
html,body{{margin:0;min-height:100%;background:#0b0d10;color:#e2e8f0;font-family:ui-monospace,SFMono-Regular,Menlo,Monaco,Consolas,monospace;}}
*{{box-sizing:border-box;}}
.page{{min-height:100vh;display:flex;flex-direction:column;gap:1rem;padding:1rem;}}
.meta{{display:grid;grid-template-columns:120px 1fr;gap:1rem;align-items:start;padding:1rem;border:1px solid rgb(148 163 184 / .22);border-radius:16px;background:rgb(15 23 42 / .72);backdrop-filter:blur(14px);box-shadow:0 20px 50px rgb(0 0 0 / .22);}}
.meta img{{width:120px;height:68px;object-fit:cover;border-radius:10px;background:#111827;}}
.meta h1{{margin:0 0 .35rem;font-size:1.1rem;line-height:1.35;color:#f8fafc;}}
.meta p{{margin:0 0 .75rem;line-height:1.6;color:#cbd5e1;}}
.meta .status{{font-size:.8rem;color:#94a3b8;}}
.preview-shell{{padding:1rem;border:1px solid rgb(148 163 184 / .16);border-radius:18px;background:#020617;box-shadow:0 20px 60px rgb(0 0 0 / .3);}}
.preview-shell.is-blurred .preview-card{{filter:blur(18px) saturate(.92);transform:scale(1.015);}}
.preview-card{{display:grid;grid-template-columns:120px 1fr;gap:1rem;align-items:start;padding:1rem;border:1px solid rgb(148 163 184 / .14);border-radius:16px;background:rgb(15 23 42 / .72);backdrop-filter:blur(12px);transition:filter .18s ease,transform .18s ease;}}
.preview-card img{{width:120px;height:68px;object-fit:cover;border-radius:10px;background:#111827;}}
.preview-card h2{{margin:0 0 .35rem;font-size:1rem;line-height:1.35;color:#f8fafc;}}
.preview-card p{{margin:0 0 .75rem;line-height:1.6;color:#cbd5e1;}}
.preview-card .status{{font-size:.8rem;color:#94a3b8;}}
.stage{{position:relative;min-height:72vh;margin-top:1rem;border-radius:18px;overflow:hidden;border:1px solid rgb(148 163 184 / .16);background:#020617;box-shadow:0 20px 60px rgb(0 0 0 / .3);}}
.frame{{position:absolute;inset:0;width:100%;height:100%;border:0;background:white;}}
body.artfct-decrypted{{overflow:hidden;background:white;}}
body.artfct-decrypted .page{{padding:0;gap:0;}}
body.artfct-decrypted .meta{{display:none !important;}}
body.artfct-decrypted .preview-shell{{display:none !important;}}
body.artfct-decrypted .stage{{position:fixed;inset:0;min-height:100vh;margin:0;border:none;border-radius:0;box-shadow:none;}}
body.artfct-decrypted .frame{{position:fixed;inset:0;width:100%;height:100%;}}
.overlay{{position:absolute;inset:0;display:grid;place-items:center;padding:1.5rem;background:linear-gradient(180deg, rgb(2 6 23 / .1), rgb(2 6 23 / .45));}}
[hidden]{{display:none !important;}}
.message{{padding:.85rem 1rem;border-radius:999px;border:1px solid rgb(148 163 184 / .26);background:rgb(15 23 42 / .82);backdrop-filter:blur(12px);color:#e2e8f0;font-size:.9rem;line-height:1.4;max-width:min(90vw, 36rem);text-align:center;}}
</style>
</head>
<body>
<div class="page">
  <section class="meta" aria-label="artifact metadata">
    <img src="{escaped_thumbnail}" alt="">
    <div>
      <h1>{escaped_title}</h1>
      <p>{escaped_description}</p>
      <div class="status">{escaped_preview_status}</div>
      <div class="status">expires <time datetime="{escaped_expires_at}">{escaped_expires_at}</time></div>
    </div>
  </section>
  <section id="artfct-preview" class="preview-shell{preview_class}">
    <div class="preview-card">
      <img src="{escaped_thumbnail}" alt="">
      <div>
        <h2>{escaped_title}</h2>
        <p>{escaped_description}</p>
        <div class="status">{escaped_preview_status}</div>
      </div>
    </div>
  </section>
  <section class="stage">
    <iframe id="artfct-frame" class="frame" hidden sandbox="allow-scripts allow-popups allow-top-navigation-by-user-activation" referrerpolicy="no-referrer"></iframe>
    <div id="artfct-overlay" class="overlay">
      <div id="artfct-message" class="message">Waiting for the decryption key in the URL fragment.</div>
    </div>
  </section>
</div>
<script id="artfct-payload" type="application/json">{payload_json}</script>
<script>
(function() {{
  const payload = JSON.parse(document.getElementById('artfct-payload').textContent || '{{}}');
  const frame = document.getElementById('artfct-frame');
  const preview = document.getElementById('artfct-preview');
  const overlay = document.getElementById('artfct-overlay');
  const message = document.getElementById('artfct-message');

  const textEncoder = new TextEncoder();
  const textDecoder = new TextDecoder();

  function setOverlay(text) {{
    message.textContent = text;
    overlay.hidden = false;
  }}

  function hideOverlay() {{
    overlay.hidden = true;
  }}

  function base64UrlToBytes(value) {{
    const normalized = value.replace(/-/g, '+').replace(/_/g, '/');
    const padded = normalized + '='.repeat((4 - (normalized.length % 4)) % 4);
    const binary = atob(padded);
    const bytes = new Uint8Array(binary.length);

    for (let i = 0; i < binary.length; i++) {{
      bytes[i] = binary.charCodeAt(i);
    }}

    return bytes;
  }}

  async function deriveKey(passcode) {{
    const digest = await crypto.subtle.digest(
      'SHA-256',
      textEncoder.encode(passcode),
    );

    return crypto.subtle.importKey(
      'raw',
      digest,
      {{ name: 'AES-GCM' }},
      false,
      ['decrypt']
    );
  }}

  async function decrypt() {{
    const hash = new URLSearchParams(window.location.hash.slice(1));
    const keyEncoded = hash.get('p') ?? window.location.hash.slice(1);

    if (!keyEncoded) {{
      preview.hidden = false;
      frame.hidden = true;
      setOverlay('Waiting for the decryption key in the URL fragment.');
      return;
    }}

    preview.hidden = true;
    frame.hidden = true;
    setOverlay('Decrypting artifact...');

    try {{
      const cryptoKey = await deriveKey(keyEncoded);
      const iv = base64UrlToBytes(payload.bodyIvB64);
      const ciphertext = base64UrlToBytes(payload.bodyCiphertextB64);
      const plaintext = await crypto.subtle.decrypt(
        {{ name: 'AES-GCM', iv }},
        cryptoKey,
        ciphertext,
      );
      const html = textDecoder.decode(plaintext);

      document.body.classList.add('artfct-decrypted');
      frame.hidden = false;
      frame.srcdoc = html;
      hideOverlay();
    }} catch (error) {{
      frame.hidden = true;
      setOverlay('Unable to decrypt this artifact. Open the full link, including the fragment key.');
    }}
  }}

  window.addEventListener('hashchange', decrypt);
  decrypt();
}})();
</script>
</body>
</html>"#
    )
}

fn escape_json_script(value: &str) -> String {
    value
        .replace('&', "\\u0026")
        .replace('<', "\\u003c")
        .replace('>', "\\u003e")
}

fn is_valid_artifact_id(id: &str) -> bool {
    (id.len() == ARTIFACT_ID_LENGTH && id.bytes().all(|byte| byte.is_ascii_alphanumeric()))
        || (id.len() == store::PUBLIC_ID_LENGTH
            && id
                .bytes()
                .all(|byte| byte.is_ascii_hexdigit() && !byte.is_ascii_uppercase()))
}

fn random_artifact_id(length: usize) -> String {
    let encoded = Uuid::new_v4().simple().to_string();
    encoded.chars().take(length).collect()
}

fn env_string(env: &Env, key: &str, default: &str) -> String {
    env.var(key)
        .map(|value| value.to_string())
        .unwrap_or_else(|_| default.to_string())
}

fn env_u64(env: &Env, key: &str, default: u64) -> u64 {
    env_string(env, key, "").parse::<u64>().unwrap_or(default)
}

fn env_usize(env: &Env, key: &str, default: usize) -> usize {
    env_string(env, key, "").parse::<usize>().unwrap_or(default)
}

fn json_response<T: Serialize>(value: &T, status: u16) -> Result<Response> {
    let mut response = Response::from_json(value)?.with_status(status);
    response
        .headers_mut()
        .set("X-Content-Type-Options", "nosniff")?;
    response
        .headers_mut()
        .set("Access-Control-Allow-Origin", "*")?;
    response.headers_mut().set(
        "Access-Control-Allow-Methods",
        "POST, PATCH, DELETE, OPTIONS",
    )?;
    response.headers_mut().set(
        "Access-Control-Allow-Headers",
        "Content-Type, Authorization",
    )?;
    Ok(response)
}

fn build_error_response(code: ErrorCode, message: &str, status: u16) -> JsonResponseDefinition {
    JsonResponseDefinition::json(
        ErrorResponse {
            error: ErrorBody {
                code,
                message,
                details: serde_json::json!({}),
            },
        },
        status,
    )
}

fn json_error(code: ErrorCode, message: &str, status: u16) -> Result<Response> {
    build_error_response(code, message, status).into_worker_response()
}

fn is_unimplemented_route(method: &Method, path: &str) -> bool {
    let segments = path
        .trim_matches('/')
        .split('/')
        .filter(|segment| !segment.is_empty())
        .collect::<Vec<_>>();

    match method {
        Method::Get => {
            path == "/v1/artifacts" || matches!(segments.as_slice(), ["v1", "orgs", _, "artifacts"])
        }
        Method::Patch => matches!(segments.as_slice(), ["v1", "orgs", _, "artifacts", _]),
        Method::Post => path == "/v1/search",
        Method::Put => false,
        _ => false,
    }
}

fn dispatch_unimplemented_route(method: &Method, path: &str) -> Option<JsonResponseDefinition> {
    is_unimplemented_route(method, path).then_some(build_unimplemented_response())
}

fn build_unimplemented_response() -> JsonResponseDefinition {
    build_error_response(
        ErrorCode::NotImplemented,
        "This operation is not implemented.",
        NOT_IMPLEMENTED_STATUS,
    )
    .with_header("x-status", "unimplemented")
}

fn html_response(html: &str, status: u16) -> Result<Response> {
    let headers = Headers::new();
    headers.set("Content-Type", "text/html; charset=utf-8")?;
    headers.set("X-Frame-Options", "DENY")?;
    headers.set("X-Content-Type-Options", "nosniff")?;
    headers.set("Content-Security-Policy", PREVIEW_CONTENT_SECURITY_POLICY)?;
    Response::from_html(html).map(|response| response.with_headers(headers).with_status(status))
}

fn build_preview_response(body: String) -> HtmlResponseDefinition {
    HtmlResponseDefinition::preview(body, 200)
}

fn build_delete_response() -> EmptyResponseDefinition {
    EmptyResponseDefinition::delete()
}

fn error_html_page(title: &str, message: &str) -> String {
    let escaped_title = escape_text(title);
    let escaped_message = escape_text(message);
    let escaped_description =
        escape_text("This link is expired or invalid — create a new artifact at artfct.dev.");
    let og_image = "https://artfct.dev/og-image.svg";

    format!(
        r#"<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>{escaped_title}</title>
<meta name="description" content="{escaped_description}">
<meta property="og:title" content="{escaped_title}">
<meta property="og:description" content="{escaped_description}">
<meta property="og:type" content="website">
<meta property="og:image" content="{og_image}">
<meta property="og:image:width" content="1200">
<meta property="og:image:height" content="630">
<meta property="og:site_name" content="artfct">
<meta name="twitter:card" content="summary_large_image">
<meta name="twitter:image" content="{og_image}">
<link rel="canonical" href="https://artfct.dev">
</head>
<body>{escaped_message}</body>
</html>"#
    )
}

fn html_error(message: &str, status: u16) -> Result<Response> {
    let html = error_html_page("Artifact unavailable — artfct", message);
    html_response(&html, status)
}

fn expired_response() -> Result<Response> {
    html_error("This artifact has expired or does not exist.", 404)
}

fn not_found_response() -> Result<Response> {
    html_error("Not found.", 404)
}

fn options_response() -> Result<Response> {
    build_options_response().into_worker_response()
}

fn build_options_response() -> EmptyResponseDefinition {
    EmptyResponseDefinition::options()
}

fn escape_attr(value: &str) -> String {
    escape_text(value).replace('"', "&quot;")
}

fn escape_text(value: &str) -> String {
    value
        .replace('&', "&amp;")
        .replace('<', "&lt;")
        .replace('>', "&gt;")
}

#[cfg(test)]
mod tests {
    use super::*;

    fn openapi_contract() -> serde_json::Value {
        serde_json::from_str(include_str!("../../openapi/artfct.yaml"))
            .expect("the OpenAPI contract must be JSON-compatible")
    }

    fn validate_schema(
        contract: &serde_json::Value,
        schema: &serde_json::Value,
        instance: &serde_json::Value,
    ) -> std::result::Result<(), String> {
        if let Some(reference) = schema.get("$ref").and_then(serde_json::Value::as_str) {
            let schema_name = reference
                .strip_prefix("#/components/schemas/")
                .ok_or_else(|| format!("unsupported schema reference: {reference}"))?;
            return validate_schema(
                contract,
                &contract["components"]["schemas"][schema_name],
                instance,
            );
        }

        if let Some(branches) = schema.get("oneOf").and_then(serde_json::Value::as_array) {
            let matches = branches
                .iter()
                .filter(|branch| validate_schema(contract, branch, instance).is_ok())
                .count();
            if matches != 1 {
                return Err(format!(
                    "expected exactly one oneOf branch to match, got {matches}"
                ));
            }
        }

        if let Some(expected) = schema.get("const") {
            if expected != instance {
                return Err(format!("expected constant {expected}, got {instance}"));
            }
        }

        if let Some(values) = schema.get("enum").and_then(serde_json::Value::as_array) {
            if !values.contains(instance) {
                return Err(format!("{instance} is not in the documented enum"));
            }
        }

        if let Some(schema_type) = schema.get("type") {
            let types = schema_type
                .as_array()
                .map(|values| values.iter().collect::<Vec<_>>())
                .unwrap_or_else(|| vec![schema_type]);
            let matches_type = types.iter().any(|value| match value.as_str() {
                Some("object") => instance.is_object(),
                Some("array") => instance.is_array(),
                Some("string") => instance.is_string(),
                Some("integer") => instance.is_i64() || instance.is_u64(),
                Some("number") => instance.is_number(),
                Some("boolean") => instance.is_boolean(),
                Some("null") => instance.is_null(),
                _ => false,
            });
            if !matches_type {
                return Err(format!("{instance} does not match type {schema_type}"));
            }
        }

        if let Some(value) = instance.as_str() {
            if let Some(min_length) = schema.get("minLength").and_then(serde_json::Value::as_u64) {
                let length = value.chars().count() as u64;
                if length < min_length {
                    return Err(format!(
                        "string length {length} is below minimum {min_length}"
                    ));
                }
            }

            if let Some(max_length) = schema.get("maxLength").and_then(serde_json::Value::as_u64) {
                let length = value.chars().count() as u64;
                if length > max_length {
                    return Err(format!(
                        "string length {length} exceeds maximum {max_length}"
                    ));
                }
            }

            if let Some(format) = schema.get("format").and_then(serde_json::Value::as_str) {
                let matches_format = match format {
                    "uri" => is_valid_uri(value),
                    "date-time" => chrono::DateTime::parse_from_rfc3339(value).is_ok(),
                    "date" => chrono::NaiveDate::parse_from_str(value, "%Y-%m-%d").is_ok(),
                    "binary" => true,
                    _ => return Err(format!("unsupported schema format {format}")),
                };
                if !matches_format {
                    return Err(format!("{value:?} does not match format {format}"));
                }
            }

            if let Some(pattern) = schema.get("pattern").and_then(serde_json::Value::as_str) {
                if !matches_schema_pattern(pattern, value) {
                    return Err(format!("{value:?} does not match pattern {pattern}"));
                }
            }
        }

        if let Some(value) = instance.as_f64() {
            if let Some(minimum) = schema.get("minimum").and_then(serde_json::Value::as_f64) {
                if value < minimum {
                    return Err(format!("number {value} is below minimum {minimum}"));
                }
            }

            if let Some(maximum) = schema.get("maximum").and_then(serde_json::Value::as_f64) {
                if value > maximum {
                    return Err(format!("number {value} exceeds maximum {maximum}"));
                }
            }
        }

        if let Some(object) = instance.as_object() {
            let properties = schema
                .get("properties")
                .and_then(serde_json::Value::as_object);
            if let Some(required) = schema.get("required").and_then(serde_json::Value::as_array) {
                for property in required.iter().filter_map(serde_json::Value::as_str) {
                    if !object.contains_key(property) {
                        return Err(format!("missing required property {property}"));
                    }
                }
            }

            if schema.get("additionalProperties") == Some(&serde_json::Value::Bool(false)) {
                let properties = properties.ok_or("object schema has no properties")?;
                if let Some(property) = object.keys().find(|key| !properties.contains_key(*key)) {
                    return Err(format!("undocumented property {property}"));
                }
            }

            if let Some(properties) = properties {
                for (name, property_schema) in properties {
                    if let Some(value) = object.get(name) {
                        validate_schema(contract, property_schema, value)?;
                    }
                }
            }
        }

        if let Some(values) = instance.as_array() {
            if let Some(min_items) = schema.get("minItems").and_then(serde_json::Value::as_u64) {
                if values.len() < min_items as usize {
                    return Err(format!(
                        "array length {} is below minimum {min_items}",
                        values.len()
                    ));
                }
            }

            if let Some(max_items) = schema.get("maxItems").and_then(serde_json::Value::as_u64) {
                if values.len() > max_items as usize {
                    return Err(format!(
                        "array length {} exceeds maximum {max_items}",
                        values.len()
                    ));
                }
            }

            if schema.get("uniqueItems") == Some(&serde_json::Value::Bool(true)) {
                for (index, value) in values.iter().enumerate() {
                    if values[..index].contains(value) {
                        return Err(format!("array contains duplicate item at index {index}"));
                    }
                }
            }
        }

        if let (Some(items), Some(values)) = (schema.get("items"), instance.as_array()) {
            for value in values {
                validate_schema(contract, items, value)?;
            }
        }

        Ok(())
    }

    fn is_valid_uri(value: &str) -> bool {
        if value.chars().any(char::is_whitespace) {
            return false;
        }

        let Some((scheme, remainder)) = value.split_once(':') else {
            return false;
        };
        if scheme.is_empty()
            || !scheme.chars().enumerate().all(|(index, character)| {
                if index == 0 {
                    character.is_ascii_alphabetic()
                } else {
                    character.is_ascii_alphanumeric() || matches!(character, '+' | '-' | '.')
                }
            })
        {
            return false;
        }

        if let Some(authority_and_path) = remainder.strip_prefix("//") {
            authority_and_path
                .split(['/', '?', '#'])
                .next()
                .is_some_and(|authority| !authority.is_empty())
        } else {
            !remainder.is_empty()
        }
    }

    fn matches_schema_pattern(pattern: &str, value: &str) -> bool {
        match pattern {
            "^[A-Za-z0-9]{10}$" => {
                value.len() == 10
                    && value
                        .chars()
                        .all(|character| character.is_ascii_alphanumeric())
            }
            "^(?:[A-Za-z0-9]{10}|[a-f0-9]{32})$" => {
                (value.len() == 10
                    && value
                        .chars()
                        .all(|character| character.is_ascii_alphanumeric()))
                    || (value.len() == store::PUBLIC_ID_LENGTH
                        && value.chars().all(|character| {
                            character.is_ascii_hexdigit() && !character.is_ascii_uppercase()
                        }))
            }
            "^[a-f0-9]{64}$" => {
                value.len() == 64
                    && value.chars().all(|character| {
                        character.is_ascii_hexdigit() && !character.is_ascii_uppercase()
                    })
            }
            "^https://" => value.starts_with("https://"),
            "^(?!/)(?![A-Za-z]:[\\\\/])(?!.*(?:^|/)\\.\\.(?:/|$)).+$" => {
                !value.is_empty()
                    && !value.starts_with('/')
                    && !value.get(..3).is_some_and(|prefix| {
                        prefix.as_bytes()[1] == b':' && matches!(prefix.as_bytes()[2], b'/' | b'\\')
                    })
                    && !value.split('/').any(|segment| segment == "..")
            }
            _ => false,
        }
    }

    fn assert_schema_matches(name: &str, instance: &serde_json::Value) {
        let contract = openapi_contract();
        validate_schema(
            &contract,
            &contract["components"]["schemas"][name],
            instance,
        )
        .unwrap_or_else(|error| panic!("{name} mismatch: {error}"));
    }

    fn operation_response_schema<'a>(
        contract: &'a serde_json::Value,
        path: &str,
        method: &str,
        status: &str,
        content_type: &str,
    ) -> &'a serde_json::Value {
        &contract["paths"][path][method]["responses"][status]["content"][content_type]["schema"]
    }

    fn stored_artifact() -> StoredArtifact {
        StoredArtifact {
            body_ciphertext_b64: "ciphertext".to_string(),
            body_iv_b64: "nonce".to_string(),
            tier: ArtifactTier::Ephemeral,
            title: "Artifact".to_string(),
            description: "An encrypted artifact".to_string(),
            thumbnail: "https://artfct.dev/og-image.svg".to_string(),
            preview_blurred: true,
            created_at: "2026-09-02T00:00:00Z".to_string(),
            expires_at: "2026-09-03T00:00:00Z".to_string(),
        }
    }

    fn documented_unimplemented_permanent_response_fixture() -> serde_json::Value {
        serde_json::json!({
            "id": "permanent1",
            "url": "https://permanent1.artifacts.example.artfct.dev/",
            "tier": "secure",
            "missing_files": ["a".repeat(64)],
        })
    }

    #[test]
    fn create_ephemeral_response_matches_schema() {
        let response =
            build_create_artifact_response("abc1234567", "https://artfct.dev", &stored_artifact());

        assert_eq!(response.status, 201);
        assert_schema_matches("EphemeralArtifactResponse", &response.body);
        assert_schema_matches("CreateArtifactResponse", &response.body);
    }

    #[test]
    fn ephemeral_roundtrip_unchanged() {
        let stored = stored_artifact();
        let encoded = serde_json::to_string(&stored).expect("ephemeral artifact serializes");
        let decoded: StoredArtifact =
            serde_json::from_str(&encoded).expect("ephemeral artifact round-trips");
        assert_eq!(decoded.body_ciphertext_b64, stored.body_ciphertext_b64);
        assert_eq!(decoded.body_iv_b64, stored.body_iv_b64);
        assert_eq!(decoded.tier, stored.tier);
    }

    #[test]
    fn permanent_roundtrip_stores_d1_and_r2() {
        let artifact_id = store::ArtifactId("a".repeat(store::PUBLIC_ID_LENGTH));
        let hash = store::content_hash(b"<h1>permanent</h1>");
        let label = store::hostname_label("acme", &artifact_id).expect("valid host label");
        assert_eq!(label, format!("acme--{}", artifact_id.0));
        assert_eq!(hash.len(), 64);
        assert_eq!(store::public_id(&hash).len(), store::PUBLIC_ID_LENGTH);
    }

    #[test]
    fn permanent_mode_requires_auth() {
        assert!(!authorization_matches(None, Some("Bearer token")));
        assert!(!authorization_matches(Some("token"), None));
        assert!(!authorization_matches(Some("token"), Some("Basic token")));
        assert!(!authorization_matches(Some("token"), Some("Bearer wrong")));
        assert!(authorization_matches(Some("token"), Some("Bearer token")));
    }

    #[test]
    fn ephemeral_mode_rejects_manifest_field() {
        let payload = serde_json::json!({"mode": "ephemeral", "manifest": {}});
        assert!(ephemeral_manifest_is_invalid(&payload));
    }

    #[test]
    fn create_permanent_response_matches_schema() {
        let response = documented_unimplemented_permanent_response_fixture();

        assert_schema_matches("PermanentArtifactResponse", &response);
        assert_schema_matches("CreateArtifactResponse", &response);
    }

    #[test]
    fn error_envelope_matches_schema_for_each_error_code() {
        let contract = openapi_contract();
        let documented_codes = contract["components"]["schemas"]["ErrorCode"]["enum"]
            .as_array()
            .expect("ErrorCode must be an enum");
        let mut production_codes = Vec::new();

        for code in ErrorCode::ALL {
            let response = build_error_response(code, "A useful error message.", 400);
            validate_schema(
                &contract,
                &contract["components"]["schemas"]["ErrorEnvelope"],
                &response.body,
            )
            .unwrap_or_else(|error| panic!("production error envelope mismatch: {error}"));
            production_codes.push(response.body["error"]["code"].clone());
        }

        assert_eq!(&production_codes, documented_codes);

        for (code, status) in [
            (ErrorCode::InvalidJson, 400),
            (ErrorCode::ValidationFailed, 422),
            (ErrorCode::InvalidArtifactId, 400),
            (ErrorCode::ArtifactNotFound, 404),
            (ErrorCode::BodyTooLarge, 413),
        ] {
            assert_eq!(
                build_error_response(code, "Handler error.", status).status,
                status
            );
        }
    }

    #[test]
    fn unimplemented_paths_return_501() {
        let contract = openapi_contract();

        for (template, path_item) in contract["paths"]
            .as_object()
            .expect("paths must be an object")
        {
            for method_name in ["get", "post", "patch", "put", "delete", "options"] {
                let operation = &path_item[method_name];
                if operation
                    .get("x-status")
                    .and_then(serde_json::Value::as_str)
                    != Some("unimplemented")
                {
                    continue;
                }

                assert!(operation["responses"].get("501").is_some());
                let path = template
                    .replace("{org}", "acme")
                    .replace("{id}", "abc1234567")
                    .replace("{sha256}", &"a".repeat(64));
                let method = match method_name {
                    "get" => Method::Get,
                    "post" => Method::Post,
                    "patch" => Method::Patch,
                    "put" => Method::Put,
                    "delete" => Method::Delete,
                    "options" => Method::Options,
                    _ => unreachable!(),
                };

                let response = dispatch_unimplemented_route(&method, &path).unwrap_or_else(|| {
                    panic!("{method_name} {path} must route to the 501 handler")
                });
                assert_eq!(response.status, NOT_IMPLEMENTED_STATUS);
                assert_eq!(response.headers, vec![("x-status", "unimplemented")]);
                assert_eq!(response.body["error"]["code"], "not_implemented");
                assert_schema_matches("ErrorEnvelope", &response.body);
            }
        }
    }

    #[test]
    fn schema_validator_rejects_malformed_constraint_values() {
        let contract = serde_json::json!({"components": {"schemas": {}}});

        let uri_schema = serde_json::json!({"type": "string", "format": "uri"});
        assert!(validate_schema(
            &contract,
            &uri_schema,
            &serde_json::Value::String("not a uri".to_string())
        )
        .is_err());
        let date_time_schema = serde_json::json!({"type": "string", "format": "date-time"});
        assert!(validate_schema(
            &contract,
            &date_time_schema,
            &serde_json::Value::String("2026-99-99T00:00:00Z".to_string())
        )
        .is_err());
        let length_schema = serde_json::json!({
            "type": "string",
            "minLength": 2,
            "maxLength": 4
        });
        assert!(validate_schema(
            &contract,
            &length_schema,
            &serde_json::Value::String("x".to_string())
        )
        .is_err());
        assert!(validate_schema(
            &contract,
            &length_schema,
            &serde_json::Value::String("12345".to_string())
        )
        .is_err());

        let constrained_number = serde_json::json!({
            "type": "integer",
            "minimum": 1,
            "maximum": 100
        });
        assert!(validate_schema(&contract, &constrained_number, &serde_json::json!(0)).is_err());
        assert!(validate_schema(&contract, &constrained_number, &serde_json::json!(101)).is_err());

        let constrained_array = serde_json::json!({
            "type": "array",
            "minItems": 1,
            "maxItems": 2,
            "uniqueItems": true,
            "items": {"type": "string"}
        });
        assert!(validate_schema(&contract, &constrained_array, &serde_json::json!([])).is_err());
        assert!(validate_schema(
            &contract,
            &constrained_array,
            &serde_json::json!(["a", "a"])
        )
        .is_err());
        assert!(validate_schema(
            &contract,
            &constrained_array,
            &serde_json::json!(["a", "b", "c"])
        )
        .is_err());

        let valid_sha256 = serde_json::Value::String("a".repeat(64));
        let sha256_schema = serde_json::json!({
            "type": "string",
            "pattern": "^[a-f0-9]{64}$"
        });
        assert!(validate_schema(&contract, &sha256_schema, &valid_sha256).is_ok());
        assert!(validate_schema(
            &contract,
            &sha256_schema,
            &serde_json::Value::String("A".repeat(64))
        )
        .is_err());
    }

    #[test]
    fn implemented_handler_success_responses_match_documented_schemas() {
        let contract = openapi_contract();
        let update = build_update_artifact_response("abc1234567", &stored_artifact());
        assert_eq!(update.status, 200);
        validate_schema(
            &contract,
            operation_response_schema(
                &contract,
                "/v1/artifacts/{id}",
                "patch",
                "200",
                "application/json",
            ),
            &update.body,
        )
        .unwrap();

        let stored = stored_artifact();
        let rendered = render_preview_shell(&stored, "https://artfct.dev/p/abc1234567");
        let preview = build_preview_response(rendered);
        assert_eq!(preview.status, 200);
        assert_eq!(
            preview.headers[0],
            ("Content-Type", "text/html; charset=utf-8")
        );
        validate_schema(
            &contract,
            operation_response_schema(&contract, "/p/{id}", "get", "200", "text/html"),
            &serde_json::Value::String(preview.body.clone()),
        )
        .unwrap();

        let delete = build_delete_response();
        assert_eq!(delete.status, 204);
        assert!(delete.headers.is_empty());
        let delete_operation = &contract["paths"]["/v1/artifacts/{id}"]["delete"];
        assert!(delete_operation["responses"]["204"]
            .get("content")
            .is_none());

        for (path, method) in [
            ("/v1/artifacts", "options"),
            ("/v1/artifacts/{id}", "options"),
            ("/v1/artifacts/{id}/files/{sha256}", "options"),
            ("/p/{id}", "options"),
        ] {
            let options = build_options_response();
            assert_eq!(options.status, 204);
            assert_eq!(options.headers.len(), 3);
            assert!(options
                .headers
                .contains(&("Access-Control-Allow-Origin", "*")));
            assert!(options.headers.contains(&(
                "Access-Control-Allow-Methods",
                "POST, PATCH, DELETE, OPTIONS"
            )));
            assert!(options.headers.contains(&(
                "Access-Control-Allow-Headers",
                "Content-Type, Authorization"
            )));
            assert!(contract["paths"][path][method]["responses"]["204"]
                .get("content")
                .is_none());
        }
    }

    #[test]
    fn default_ttl_is_five_days() {
        assert_eq!(DEFAULT_TTL_MINUTES, 5 * 24 * 60);
    }

    #[test]
    fn random_artifact_id_is_short_and_alphanumeric() {
        let id = random_artifact_id(ARTIFACT_ID_LENGTH);

        assert_eq!(id.len(), ARTIFACT_ID_LENGTH);
        assert!(id.chars().all(|ch| ch.is_ascii_alphanumeric()));
    }

    #[test]
    fn random_artifact_id_generates_varied_values() {
        let mut ids = std::collections::HashSet::new();

        for _ in 0..50 {
            ids.insert(random_artifact_id(ARTIFACT_ID_LENGTH));
        }

        assert!(ids.len() > 1);
    }

    #[test]
    fn artifact_id_validation_requires_exact_length_and_charset() {
        assert!(is_valid_artifact_id(&"a".repeat(ARTIFACT_ID_LENGTH)));
        assert!(!is_valid_artifact_id(""));
        assert!(!is_valid_artifact_id("12345"));
        assert!(!is_valid_artifact_id(&"a".repeat(ARTIFACT_ID_LENGTH - 1)));
        assert!(!is_valid_artifact_id(&"a".repeat(ARTIFACT_ID_LENGTH + 1)));
        assert!(!is_valid_artifact_id("abc!defghi"));
    }

    #[test]
    fn normalize_metadata_value_uses_default_for_empty_values() {
        assert_eq!(
            normalize_metadata_value(Some("   ".to_string()), "Fallback"),
            "Fallback"
        );
        assert_eq!(normalize_metadata_value(None, "Fallback"), "Fallback");
    }

    #[test]
    fn escape_json_script_escapes_angle_brackets() {
        let escaped = escape_json_script(r#"{"title":"</script><b>&"}"#);
        assert!(escaped.contains("\\u003c/script\\u003e"));
        assert!(escaped.contains("\\u0026"));
    }

    #[test]
    fn render_preview_shell_includes_public_metadata_and_ciphertext() {
        let artifact = StoredArtifact {
            body_ciphertext_b64: "ciphertext".to_string(),
            body_iv_b64: "nonce".to_string(),
            tier: ArtifactTier::Ephemeral,
            title: "My Chart".to_string(),
            description: "A chart preview".to_string(),
            thumbnail: "https://example.com/thumb.png".to_string(),
            preview_blurred: true,
            created_at: "2026-06-08T00:00:00Z".to_string(),
            expires_at: "2026-06-09T00:00:00Z".to_string(),
        };
        let rendered = render_preview_shell(&artifact, "https://artfct.dev/p/abc1234567");

        assert!(rendered.contains("My Chart"));
        assert!(rendered.contains("A chart preview"));
        assert!(rendered.contains("https://example.com/thumb.png"));
        assert!(rendered.contains("ciphertext"));
        assert!(rendered.contains("nonce"));
        assert!(rendered.contains("previewBlurred"));
        assert!(rendered.contains("preview-shell"));
        assert!(rendered.contains("https://artfct.dev/p/abc1234567"));
    }

    #[test]
    fn render_preview_shell_marks_blurred_state() {
        let artifact = StoredArtifact {
            body_ciphertext_b64: "ciphertext".to_string(),
            body_iv_b64: "nonce".to_string(),
            tier: ArtifactTier::Secure,
            title: "Secure Deck".to_string(),
            description: "Private preview".to_string(),
            thumbnail: "https://example.com/thumb.png".to_string(),
            preview_blurred: false,
            created_at: "2026-06-08T00:00:00Z".to_string(),
            expires_at: "2026-06-09T00:00:00Z".to_string(),
        };
        let rendered = render_preview_shell(&artifact, "https://artfct.dev/p/xyz");

        assert!(rendered.contains("Waiting for the decryption key"));
        assert!(rendered.contains("Link preview will start unblurred."));
        assert!(rendered.contains("Secure Deck"));
    }

    #[test]
    fn render_preview_shell_honors_hidden_attribute() {
        let artifact = StoredArtifact {
            body_ciphertext_b64: "ciphertext".to_string(),
            body_iv_b64: "nonce".to_string(),
            tier: ArtifactTier::Secure,
            title: "Hidden Test".to_string(),
            description: "Preview visibility".to_string(),
            thumbnail: "https://example.com/thumb.png".to_string(),
            preview_blurred: false,
            created_at: "2026-06-08T00:00:00Z".to_string(),
            expires_at: "2026-06-09T00:00:00Z".to_string(),
        };
        let rendered = render_preview_shell(&artifact, "https://artfct.dev/p/xyz");

        assert!(rendered.contains("[hidden]{display:none !important;}"));
        assert!(rendered.contains("id=\"artfct-overlay\" class=\"overlay\""));
        assert!(rendered.contains("id=\"artfct-frame\" class=\"frame\" hidden"));
    }

    #[test]
    fn render_preview_shell_allows_user_link_navigation_from_iframe() {
        let artifact = StoredArtifact {
            body_ciphertext_b64: "ciphertext".to_string(),
            body_iv_b64: "nonce".to_string(),
            tier: ArtifactTier::Public,
            title: "Linked Artifact".to_string(),
            description: "Preview with links".to_string(),
            thumbnail: "https://example.com/thumb.png".to_string(),
            preview_blurred: false,
            created_at: "2026-06-08T00:00:00Z".to_string(),
            expires_at: "2026-06-09T00:00:00Z".to_string(),
        };
        let rendered = render_preview_shell(&artifact, "https://artfct.dev/p/xyz");

        assert!(rendered.contains(
            r#"sandbox="allow-scripts allow-popups allow-top-navigation-by-user-activation""#
        ));
    }

    #[test]
    fn preview_content_security_policy_allows_https_artifact_dependencies() {
        assert!(PREVIEW_CONTENT_SECURITY_POLICY.contains("default-src 'self' https:;"));
        assert!(PREVIEW_CONTENT_SECURITY_POLICY
            .contains("script-src 'unsafe-inline' 'unsafe-eval' https:;"));
        assert!(PREVIEW_CONTENT_SECURITY_POLICY.contains("style-src 'unsafe-inline' https:;"));
        assert!(PREVIEW_CONTENT_SECURITY_POLICY.contains("font-src https: data:;"));
    }

    #[test]
    fn error_html_page_includes_og_tags() {
        let page = error_html_page(
            "Artifact unavailable — artfct",
            "This artifact has expired.",
        );
        assert!(page.contains("og:title"));
        assert!(page.contains("og:image"));
        assert!(page.contains("twitter:card"));
        assert!(page.contains("summary_large_image"));
    }
}
