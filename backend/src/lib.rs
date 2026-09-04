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
const MAX_BUNDLE_BYTES: usize = 50 * 1024 * 1024;
const MAX_FILE_BYTES: usize = 25 * 1024 * 1024;
const MAX_BUNDLE_FILES: usize = 500;
const MAX_PATH_BYTES: usize = 255;
const NOT_IMPLEMENTED_STATUS: u16 = 501;
const DEFAULT_ARTIFACT_TITLE: &str = "Encrypted artifact";
const DEFAULT_ARTIFACT_DESCRIPTION: &str = "Encrypted HTML preview on artfct.";
const DEFAULT_ARTIFACT_THUMBNAIL: &str = "https://artfct.dev/og-image.svg";
const PREVIEW_CONTENT_SECURITY_POLICY: &str = "default-src 'self' https:; script-src 'unsafe-inline' 'unsafe-eval' https:; style-src 'unsafe-inline' https:; font-src https: data:; img-src 'self' data: blob: https:; frame-ancestors 'none'; form-action 'none'; base-uri 'none';";
/// Wildcard suffix for the per-artifact isolated origin scheme:
/// `<tenant-slug>--<artifact-id>.artfct.dev`. One wildcard level, covered by
/// Universal SSL (spec 05).
const ARTIFACT_ORIGIN_SUFFIX: &str = ".artfct.dev";
/// Env var carrying the HMAC secret used to sign/verify isolated-origin
/// access tokens. Follows the same fail-closed pattern as `ARTFCT_ORG_TOKEN`:
/// a missing binding never authorizes a token, regardless of signature.
const ARTIFACT_TOKEN_SECRET_ENV: &str = "ARTFCT_ARTIFACT_TOKEN_SECRET";

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

#[derive(Debug, Deserialize, Serialize, Clone, PartialEq, Eq)]
struct PermanentManifest {
    entrypoint: String,
    files: Vec<PermanentManifestFile>,
    external_origins: Vec<String>,
    #[serde(default)]
    unsafe_eval: bool,
}

#[derive(Debug, Deserialize, Serialize, Clone, PartialEq, Eq)]
struct PermanentManifestFile {
    path: String,
    content_type: String,
    size_bytes: usize,
    sha256: String,
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

#[derive(Debug, Serialize, Clone, Copy, PartialEq, Eq)]
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
        && path.len() <= MAX_PATH_BYTES
        && !path.starts_with('/')
        && !path.contains('\\')
        && !path.contains(':')
        && !path
            .split('/')
            .any(|segment| segment == ".." || segment.is_empty())
}

fn normalized_content_type(value: &str) -> String {
    let value = value.trim().to_ascii_lowercase();
    if matches!(
        value.as_str(),
        "text/html"
            | "text/html; charset=utf-8"
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

fn uploaded_file_error(
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

fn missing_manifest_files(manifest: &PermanentManifest, present: &[bool]) -> Vec<String> {
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
fn manifest_is_complete(manifest: &PermanentManifest, uploaded_paths: &[&str]) -> bool {
    manifest
        .files
        .iter()
        .all(|file| uploaded_paths.contains(&file.path.as_str()))
}

fn upload_expired(expires_at: Option<&str>, now: chrono::DateTime<Utc>) -> bool {
    expires_at
        .and_then(|value| chrono::DateTime::parse_from_rfc3339(value).ok())
        .is_some_and(|value| value.with_timezone(&Utc) <= now)
}

fn validate_permanent_manifest(raw: &Value) -> Result<(PermanentManifest, String), ErrorCode> {
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
    };
    let canonical = serde_json::to_vec(&manifest).map_err(|_| ErrorCode::ValidationFailed)?;
    Ok((manifest, store::content_hash(&canonical)))
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

    let org = env_string(env, "ARTFCT_ORG_SLUG", "default");
    if let Err(message) = store::validate_slug(&org) {
        return json_error(ErrorCode::ValidationFailed, &message, 422);
    }
    let artifact_id = store::public_id(content_hash);
    let now = Utc::now().to_rfc3339_opts(SecondsFormat::Secs, true);
    let upload_expires_at =
        (Utc::now() + chrono::Duration::hours(1)).to_rfc3339_opts(SecondsFormat::Secs, true);
    let provenance = raw
        .get("provenance")
        .cloned()
        .unwrap_or_else(|| serde_json::json!({}));
    let agent = provenance.get("agent").and_then(Value::as_str);
    let repo_url = provenance.get("repo_url").and_then(Value::as_str);
    let commit_sha = provenance.get("commit_sha").and_then(Value::as_str);
    let storage =
        store::D1R2ArtifactStore::new(env.d1("ARTIFACTS_DB")?, env.bucket("ARTIFACTS_BUCKET")?);
    let file_hashes = manifest
        .files
        .iter()
        .map(|file| file.sha256.clone())
        .collect::<Vec<_>>();
    let locks = storage
        .acquire_content_locks(&file_hashes)
        .await
        .map_err(|error| worker::Error::RustError(error.to_string()))?;
    let database = &storage.database;
    let row_id = Uuid::new_v4().simple().to_string();
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
                .prepare("INSERT INTO artifacts (row_id, id, org_id, content_hash, entrypoint, created_at, expires_at, tier, manifest) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)")
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
        storage
            .execute_batch(statements)
            .await
            .map_err(|error| worker::Error::RustError(error.to_string()))?;
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
    Ok(response)
}

async fn resolve_artifact(path: &str, req: &Request, env: &Env) -> Result<Response> {
    let suffix = path.trim_start_matches("/p/");
    let (artifact_id, requested_path) = suffix
        .split_once('/')
        .map_or((suffix, None), |(id, file)| (id, Some(file)));
    if artifact_id.len() == store::PUBLIC_ID_LENGTH
        && artifact_id
            .bytes()
            .all(|byte| byte.is_ascii_hexdigit() && !byte.is_ascii_uppercase())
    {
        return resolve_permanent_artifact(artifact_id, requested_path, req, env).await;
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
    content_type: String,
    expires_at: Option<String>,
    manifest: String,
}

#[derive(Debug, Deserialize)]
struct PermanentHashRow {
    row_id: String,
    manifest: String,
}

#[derive(Debug, Deserialize)]
struct UploadArtifactRow {
    row_id: String,
    content_type: String,
    expected_size: i64,
    expires_at: Option<String>,
    manifest: String,
}

#[derive(Debug, Deserialize)]
struct PresenceRow {
    #[allow(dead_code)]
    present: i64,
}

async fn resolve_permanent_artifact(
    artifact_id: &str,
    requested_path: Option<&str>,
    req: &Request,
    env: &Env,
) -> Result<Response> {
    if requested_path.is_some_and(|path| !is_valid_relative_path(path)) {
        return expired_response();
    }
    let storage =
        store::D1R2ArtifactStore::new(env.d1("ARTIFACTS_DB")?, env.bucket("ARTIFACTS_BUCKET")?);
    let database = &storage.database;
    let row = database
        .prepare("SELECT f.content_hash, a.entrypoint, a.tier, f.content_type, a.expires_at, a.manifest FROM artifacts a JOIN files f ON f.artifact_row_id = a.row_id JOIN orgs o ON o.id = a.org_id WHERE a.id = ? AND o.slug = ? AND f.path = COALESCE(?, a.entrypoint) AND (a.expires_at IS NULL OR a.expires_at > ?) AND NOT EXISTS (SELECT 1 FROM json_each(a.manifest, '$.files') mf WHERE NOT EXISTS (SELECT 1 FROM files complete WHERE complete.artifact_row_id = a.row_id AND complete.path = json_extract(mf.value, '$.path'))) ORDER BY a.row_id LIMIT 1")
        .bind(&[
            JsValue::from_str(artifact_id),
            JsValue::from_str(&env_string(env, "ARTFCT_ORG_SLUG", "default")),
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
        artifact_token_secret(env).as_deref(),
        Utc::now(),
    );
    match isolated_access {
        IsolatedAccess::Forbidden => return isolated_forbidden_response(),
        IsolatedAccess::Authorized => {
            // A verified artifact-scoped token replaces the org bearer-token
            // check below for isolated-origin requests.
        }
        IsolatedAccess::NotIsolated => {
            if row.tier == "secure" && !authorized_for_org(authorization.as_deref(), env) {
                return json_error(ErrorCode::Unauthorized, "Invalid organization token.", 401);
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
    let _ = row.expires_at;
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
    let database = &storage.database;
    let org = env_string(env, "ARTFCT_ORG_SLUG", "default");
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

/// Derives the isolated per-artifact hostname
/// `<tenant-slug>--<artifact-id>.artfct.dev` (spec 05). Pure function over
/// the slug/id character-set rules already enforced by spec 3
/// (`store::hostname_label`).
#[allow(
    dead_code,
    reason = "minted by the control plane, not this Worker; exercised directly by hostname_derives_from_slug_and_id"
)]
fn isolated_artifact_hostname(tenant_slug: &str, artifact_id: &str) -> Result<String, String> {
    let label = store::hostname_label(tenant_slug, &store::ArtifactId(artifact_id.to_string()))?;
    Ok(format!("{label}{ARTIFACT_ORIGIN_SUFFIX}"))
}

/// Splits an isolated-origin `Host` header back into `(tenant_slug,
/// artifact_id)`. Returns `None` for any host that isn't under
/// `ARTIFACT_ORIGIN_SUFFIX`, including the shared `artfct.dev` host used by
/// free-tier `/p/{id}` links.
fn parse_isolated_hostname(host: &str) -> Option<(String, String)> {
    let label = host.strip_suffix(ARTIFACT_ORIGIN_SUFFIX)?;
    let (slug, artifact_id) = label.split_once("--")?;
    (!slug.is_empty() && !artifact_id.is_empty())
        .then(|| (slug.to_string(), artifact_id.to_string()))
}

/// HMAC-signed hex signature over `<artifact_id>.<expires_at_unix>`.
fn access_token_signature(secret: &str, artifact_id: &str, expires_at_unix: i64) -> String {
    let message = format!("{artifact_id}.{expires_at_unix}");
    store::hmac_sha256(secret.as_bytes(), message.as_bytes())
        .iter()
        .map(|byte| format!("{byte:02x}"))
        .collect()
}

/// Mints a short-lived access token scoped to one artifact. Pure and
/// deterministic given an explicit expiry, so tests never need to sleep.
#[allow(
    dead_code,
    reason = "minted by the control plane, not this Worker; exercised directly by the token tests"
)]
fn mint_access_token(secret: &str, artifact_id: &str, expires_at: chrono::DateTime<Utc>) -> String {
    let expires_at_unix = expires_at.timestamp();
    let signature = access_token_signature(secret, artifact_id, expires_at_unix);
    format!("{artifact_id}.{expires_at_unix}.{signature}")
}

/// Verifies an access token against the artifact it is presented for, at a
/// caller-supplied "now". Fails closed: a missing/empty secret, a token
/// minted for a different artifact, an unparseable token, or an expired
/// token are all rejected the same way callers should map to 403.
fn verify_access_token(
    secret: Option<&str>,
    token: &str,
    artifact_id: &str,
    now: chrono::DateTime<Utc>,
) -> bool {
    let Some(secret) = secret.filter(|value| !value.is_empty()) else {
        return false;
    };
    let mut parts = token.splitn(3, '.');
    let (Some(token_artifact_id), Some(expires_at_raw), Some(signature)) =
        (parts.next(), parts.next(), parts.next())
    else {
        return false;
    };
    if !constant_time_equal(token_artifact_id.as_bytes(), artifact_id.as_bytes()) {
        return false;
    }
    let Ok(expires_at_unix) = expires_at_raw.parse::<i64>() else {
        return false;
    };
    if now.timestamp() >= expires_at_unix {
        return false;
    }
    let expected = access_token_signature(secret, token_artifact_id, expires_at_unix);
    constant_time_equal(signature.as_bytes(), expected.as_bytes())
}

/// Derives the per-artifact CSP from the manifest's `external_origins` and
/// `unsafe_eval` flag (spec 05). An artifact declaring nothing gets
/// `default-src 'self'`; `unsafe-eval` is only ever granted when the
/// manifest opts in.
fn isolated_content_security_policy(manifest: &PermanentManifest) -> String {
    let origins = manifest.external_origins.join(" ");
    let default_src = if origins.is_empty() {
        "'self'".to_string()
    } else {
        format!("'self' {origins}")
    };
    let mut script_src = default_src.clone();
    if manifest.unsafe_eval {
        script_src.push_str(" 'unsafe-eval'");
    }
    format!(
        "default-src {default_src}; script-src {script_src}; style-src 'self' 'unsafe-inline'; frame-ancestors 'none'; base-uri 'none'; form-action 'none';"
    )
}

/// Response headers for a permanent bundle file: the real header-
/// construction logic used at the `resolve_permanent_artifact` serving
/// site. Deliberately has no `Set-Cookie` code path — artifact origins are
/// cookieless by construction (spec 05), not by omission.
///
/// `is_isolated` must be true only for a request that actually verified on
/// an isolated `<slug>--<id>.artfct.dev` host — everything else (including
/// every free-tier and legacy shared-origin `/p/{id}` request) gets the
/// pre-spec-05 `PREVIEW_CONTENT_SECURITY_POLICY` byte-identical, so the CSP
/// derived from `external_origins`/`unsafe_eval` never silently tightens an
/// existing artifact that never opted into isolation.
fn permanent_file_response_headers(
    content_type: &str,
    manifest: &PermanentManifest,
    is_isolated: bool,
) -> Vec<(&'static str, String)> {
    let csp = if is_isolated {
        isolated_content_security_policy(manifest)
    } else {
        PREVIEW_CONTENT_SECURITY_POLICY.to_string()
    };
    vec![
        ("Content-Type", content_type.to_string()),
        ("X-Content-Type-Options", "nosniff".to_string()),
        ("Content-Security-Policy", csp),
    ]
}

#[derive(Debug, PartialEq, Eq)]
enum IsolatedAccess {
    /// The request did not arrive on an isolated `<slug>--<id>.artfct.dev`
    /// host — free-tier and legacy shared-origin `/p/{id}` behavior applies
    /// unchanged.
    NotIsolated,
    /// Arrived on an isolated host with a token that verifies for this
    /// artifact.
    Authorized,
    /// Arrived on an isolated host without a token that verifies for this
    /// artifact — callers must reject with 403.
    Forbidden,
}

/// Decides isolated-origin access from the request `Host` header and an
/// optional bearer/query token, given an explicit "now" and secret so it is
/// testable without a live Worker. Cookieless by design: nothing here reads
/// or sets a session cookie, so a stolen artifact origin cannot ride one.
fn isolated_access_check(
    host: Option<&str>,
    token: Option<&str>,
    artifact_id: &str,
    secret: Option<&str>,
    now: chrono::DateTime<Utc>,
) -> IsolatedAccess {
    let Some(host) = host else {
        return IsolatedAccess::NotIsolated;
    };
    if parse_isolated_hostname(host).is_none() {
        return IsolatedAccess::NotIsolated;
    }
    match token {
        Some(token) if verify_access_token(secret, token, artifact_id, now) => {
            IsolatedAccess::Authorized
        }
        _ => IsolatedAccess::Forbidden,
    }
}

fn artifact_token_secret(env: &Env) -> Option<String> {
    env.var(ARTIFACT_TOKEN_SECRET_ENV)
        .ok()
        .map(|value| value.to_string())
}

/// 403 response for a rejected isolated-origin request. Uses the plain HTML
/// error path rather than `json_error`/`json_response`, which sets
/// `Access-Control-Allow-Origin: *` on every response — the console and
/// artifact origins must share no CORS allowance (spec 05).
fn isolated_forbidden_response() -> Result<Response> {
    html_error(
        "This access token is invalid, expired, or not valid for this artifact.",
        403,
    )
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
    manifest: String,
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
        .prepare("SELECT a.id, a.content_hash, a.entrypoint, a.created_at, p.payload AS provenance, a.manifest FROM artifacts a LEFT JOIN provenance p ON p.artifact_row_id = a.row_id JOIN orgs o ON o.id = a.org_id WHERE o.slug = ? AND NOT EXISTS (SELECT 1 FROM json_each(a.manifest, '$.files') mf LEFT JOIN files f ON f.artifact_row_id = a.row_id AND f.path = json_extract(mf.value, '$.path') LEFT JOIN blobs b ON b.content_hash = json_extract(mf.value, '$.sha256') WHERE f.artifact_row_id IS NULL OR b.content_hash IS NULL) ORDER BY a.created_at")
        .bind(&[JsValue::from_str(org)])?
        .all()
        .await?
        .results::<ExportRow>()?;
    let mut blobs = std::collections::HashMap::new();
    let mut artifacts = Vec::with_capacity(rows.len());
    for row in rows {
        if let Ok(manifest) = serde_json::from_str::<PermanentManifest>(&row.manifest) {
            for file in manifest.files {
                blobs.entry(file.sha256.clone()).or_insert_with(|| {
                    format!(
                        "{}/v1/blobs/{}",
                        env_string(env, "ARTFCT_PUBLIC_BASE_URL", DEFAULT_BASE_URL)
                            .trim_end_matches('/'),
                        file.sha256
                    )
                });
            }
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
    let lock_row = database
        .prepare("SELECT a.row_id, a.manifest FROM artifacts a JOIN orgs o ON o.id = a.org_id WHERE a.id = ? AND o.slug = ? ORDER BY a.row_id LIMIT 1")
        .bind(&[
            JsValue::from_str(artifact_id),
            JsValue::from_str(&env_string(env, "ARTFCT_ORG_SLUG", "default")),
        ])?
        .first::<PermanentHashRow>(None)
        .await?;
    let Some(lock_row) = lock_row else {
        return json_error(ErrorCode::ArtifactNotFound, "Artifact not found.", 404);
    };
    let lock_hashes = serde_json::from_str::<PermanentManifest>(&lock_row.manifest)
        .map(|manifest| {
            manifest
                .files
                .into_iter()
                .map(|file| file.sha256)
                .collect::<Vec<_>>()
        })
        .unwrap_or_default();
    let locks = storage
        .acquire_content_locks(&lock_hashes)
        .await
        .map_err(|error| worker::Error::RustError(error.to_string()))?;
    let operation: Result<Response> = async {
        let row = database
            .prepare("SELECT a.row_id, a.manifest FROM artifacts a JOIN orgs o ON o.id = a.org_id WHERE a.id = ? AND o.slug = ? ORDER BY a.row_id LIMIT 1")
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
        let manifest = serde_json::from_str::<PermanentManifest>(&row.manifest)?;
        for file in manifest.files {
            database
                .prepare("UPDATE blobs SET ref_count = MAX(ref_count - 1, 0) WHERE content_hash = ?")
                .bind(&[
                    JsValue::from_str(&file.sha256),
                ])?
                .run()
                .await?;
            let count = database
                .prepare("SELECT ref_count AS count FROM blobs WHERE content_hash = ?")
                .bind(&[JsValue::from_str(&file.sha256)])?
                .first::<BlobReferenceRow>(None)
                .await?
                .map(|value| value.count)
                .unwrap_or(0);
            if count == 0 {
                database
                    .prepare("DELETE FROM blobs WHERE content_hash = ?")
                    .bind(&[JsValue::from_str(&file.sha256)])?
                    .run()
                    .await?;
                storage.bucket.delete(format!("blobs/{}", file.sha256)).await?;
            }
        }
        build_delete_response().into_worker_response()
    }
    .await;
    let release_result = storage.release_content_locks(&locks).await;
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
        .prepare("SELECT 1 AS present FROM artifacts a JOIN orgs o ON o.id = a.org_id JOIN files requested ON requested.artifact_row_id = a.row_id AND requested.content_hash = ? WHERE o.slug = ? AND a.expires_at IS NULL AND NOT EXISTS (SELECT 1 FROM json_each(a.manifest, '$.files') mf LEFT JOIN files complete ON complete.artifact_row_id = a.row_id AND complete.path = json_extract(mf.value, '$.path') LEFT JOIN blobs b ON b.content_hash = json_extract(mf.value, '$.sha256') WHERE complete.artifact_row_id IS NULL OR b.content_hash IS NULL) LIMIT 1")
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

    fn manifest_fixture(files: serde_json::Value, entrypoint: &str) -> Value {
        serde_json::json!({
            "manifest": {
                "entrypoint": entrypoint,
                "files": files,
                "external_origins": []
            }
        })
    }

    #[test]
    fn manifest_missing_entrypoint_rejected() {
        let file = serde_json::json!([
            {"path":"app.js","size_bytes":1,"sha256":"a".repeat(64),"content_type":"application/javascript"}
        ]);
        let payload = manifest_fixture(file, "index.html");
        assert_eq!(
            validate_permanent_manifest(&payload),
            Err(ErrorCode::EntrypointMissing)
        );
    }

    #[test]
    fn manifest_path_traversal_rejected() {
        let file = serde_json::json!([{"path":"../etc/passwd","size_bytes":1,"sha256":"a".repeat(64),"content_type":"text/plain"}]);
        assert_eq!(
            validate_permanent_manifest(&manifest_fixture(file, "../etc/passwd")),
            Err(ErrorCode::InvalidPath)
        );
    }

    #[test]
    fn manifest_absolute_path_rejected() {
        let file = serde_json::json!([{"path":"/index.html","size_bytes":1,"sha256":"a".repeat(64),"content_type":"text/html"}]);
        assert_eq!(
            validate_permanent_manifest(&manifest_fixture(file, "/index.html")),
            Err(ErrorCode::InvalidPath)
        );
    }

    #[test]
    fn manifest_windows_path_rejected() {
        let file = serde_json::json!([{"path":"..\\etc\\passwd","size_bytes":1,"sha256":"a".repeat(64),"content_type":"text/plain"}]);
        assert_eq!(
            validate_permanent_manifest(&manifest_fixture(file, "..\\etc\\passwd")),
            Err(ErrorCode::InvalidPath)
        );
    }

    #[test]
    fn manifest_duplicate_paths_rejected() {
        let file = serde_json::json!([
            {"path":"index.html","size_bytes":1,"sha256":"a".repeat(64),"content_type":"text/html"},
            {"path":"index.html","size_bytes":1,"sha256":"b".repeat(64),"content_type":"text/html"}
        ]);
        assert_eq!(
            validate_permanent_manifest(&manifest_fixture(file, "index.html")),
            Err(ErrorCode::DuplicatePath)
        );
    }

    #[test]
    fn file_hash_mismatch_rejected() {
        assert_eq!(
            uploaded_file_error(&"a".repeat(64), 15, b"different bytes"),
            Some(ErrorCode::HashMismatch)
        );
    }

    #[test]
    fn bundle_over_limit_rejected() {
        let file_size = 20 * 1024 * 1024;
        let payload = manifest_fixture(
            serde_json::json!([
                {"path":"index.html","size_bytes":file_size,"sha256":"a".repeat(64),"content_type":"text/html"},
                {"path":"assets/app.js","size_bytes":file_size,"sha256":"b".repeat(64),"content_type":"application/javascript"},
                {"path":"assets/app.css","size_bytes":file_size,"sha256":"c".repeat(64),"content_type":"text/css"}
            ]),
            "index.html",
        );
        assert_eq!(
            validate_permanent_manifest(&payload),
            Err(ErrorCode::BundleTooLarge)
        );
    }

    #[test]
    fn file_count_over_limit_rejected() {
        let files = (0..=MAX_BUNDLE_FILES).map(|index| serde_json::json!({"path": format!("{index}.js"), "size_bytes": 1, "sha256": "a".repeat(64), "content_type": "application/javascript"})).collect::<Vec<_>>();
        let payload = manifest_fixture(Value::Array(files), "0.js");
        assert_eq!(
            validate_permanent_manifest(&payload),
            Err(ErrorCode::FileCountExceeded)
        );
    }

    #[test]
    fn incomplete_bundle_returns_404() {
        let payload = manifest_fixture(
            serde_json::json!([{"path":"index.html","size_bytes":1,"sha256":"a".repeat(64),"content_type":"text/html"}]),
            "index.html",
        );
        let (manifest, _) = validate_permanent_manifest(&payload).expect("manifest validates");
        assert!(!manifest_is_complete(&manifest, &[]));
        assert!(manifest_is_complete(&manifest, &["index.html"]));
    }

    #[test]
    fn incomplete_upload_expires_after_one_hour() {
        let now = Utc::now();
        let expires = (now + chrono::Duration::hours(1)).to_rfc3339();
        assert!(!upload_expired(Some(&expires), now));
        assert!(upload_expired(
            Some(&expires),
            now + chrono::Duration::hours(1)
        ));
    }

    #[test]
    fn only_missing_files_are_requested() {
        let payload = manifest_fixture(
            serde_json::json!([
                {"path":"a.js","size_bytes":1,"sha256":"a".repeat(64),"content_type":"application/javascript"},
                {"path":"b.js","size_bytes":1,"sha256":"b".repeat(64),"content_type":"application/javascript"}
            ]),
            "a.js",
        );
        let (manifest, _) = validate_permanent_manifest(&payload).expect("manifest validates");
        assert_eq!(
            missing_manifest_files(&manifest, &[false, true]),
            vec!["a".repeat(64)]
        );
    }

    #[test]
    fn unknown_content_type_served_with_nosniff() {
        assert_eq!(
            normalized_content_type("application/x-private"),
            "application/octet-stream"
        );
        // Real header-construction logic for the permanent-bundle serving
        // site (`resolve_permanent_artifact`), not the unrelated ephemeral
        // preview shell — deleting the nosniff header there must fail this.
        let manifest = PermanentManifest {
            entrypoint: "index.html".to_string(),
            files: Vec::new(),
            external_origins: Vec::new(),
            unsafe_eval: false,
        };
        for is_isolated in [false, true] {
            assert!(permanent_file_response_headers(
                "application/octet-stream",
                &manifest,
                is_isolated
            )
            .iter()
            .any(|(name, value)| *name == "X-Content-Type-Options" && value == "nosniff"));
        }
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

    fn permanent_manifest(external_origins: Vec<&str>, unsafe_eval: bool) -> PermanentManifest {
        PermanentManifest {
            entrypoint: "index.html".to_string(),
            files: Vec::new(),
            external_origins: external_origins.into_iter().map(str::to_string).collect(),
            unsafe_eval,
        }
    }

    fn csp_directive<'a>(csp: &'a str, directive: &str) -> Vec<&'a str> {
        csp.split(';')
            .map(str::trim)
            .find_map(|segment| segment.strip_prefix(directive))
            .expect("directive present")
            .split_whitespace()
            .collect()
    }

    #[test]
    fn hostname_derives_from_slug_and_id() {
        let artifact_id = "0123456789abcdef0123456789abcdef";
        let host_a = isolated_artifact_hostname("acme", artifact_id).unwrap();
        assert_eq!(host_a, format!("acme--{artifact_id}.artfct.dev"));

        let host_b =
            isolated_artifact_hostname("acme", "ffffffffffffffffffffffffffffffff").unwrap();
        assert_ne!(
            host_a, host_b,
            "artifact A and artifact B must serve from different hostnames"
        );
        assert_eq!(
            parse_isolated_hostname(&host_a),
            Some(("acme".to_string(), artifact_id.to_string()))
        );
    }

    #[test]
    fn artifact_origin_sets_no_cookie() {
        let manifest = permanent_manifest(vec![], false);
        let headers = permanent_file_response_headers("text/html", &manifest, true);
        assert!(!headers
            .iter()
            .any(|(name, _)| name.eq_ignore_ascii_case("Set-Cookie")));
        // This is the sole header source at the serving site — pin the exact
        // set so an added cookie header cannot slip in unnoticed.
        let names: Vec<&str> = headers.iter().map(|(name, _)| *name).collect();
        assert_eq!(
            names,
            vec![
                "Content-Type",
                "X-Content-Type-Options",
                "Content-Security-Policy"
            ]
        );
    }

    #[test]
    fn expired_access_token_rejected() {
        let secret = "s3cr3t";
        let artifact_id = "artifact-a";
        let now = Utc::now();
        let token = mint_access_token(secret, artifact_id, now + chrono::Duration::minutes(5));

        // Positive control: valid and not yet expired.
        assert!(verify_access_token(Some(secret), &token, artifact_id, now));
        // At the boundary and past it, the token must be rejected.
        assert!(!verify_access_token(
            Some(secret),
            &token,
            artifact_id,
            now + chrono::Duration::minutes(5)
        ));
        assert!(!verify_access_token(
            Some(secret),
            &token,
            artifact_id,
            now + chrono::Duration::minutes(6)
        ));
    }

    #[test]
    fn token_for_other_artifact_rejected() {
        let secret = "s3cr3t";
        let now = Utc::now();
        let token = mint_access_token(secret, "artifact-a", now + chrono::Duration::minutes(5));

        // Positive control: the token is valid for the artifact it was
        // minted for.
        assert!(verify_access_token(Some(secret), &token, "artifact-a", now));
        // The same token must not authorize a different artifact.
        assert!(!verify_access_token(
            Some(secret),
            &token,
            "artifact-b",
            now
        ));
    }

    #[test]
    fn free_tier_permanent_artifact_keeps_preview_csp() {
        // A non-isolated /p/{id} request — free-tier and legacy shared-origin
        // behavior — must keep the exact pre-spec-05 CSP, even for a
        // manifest that would derive a tighter isolated CSP (e.g. it
        // declares no external_origins, which alone would yield a
        // restrictive `default-src 'self'`). Isolation opts artifacts in;
        // it must never silently opt an existing one in by omission.
        let manifest = permanent_manifest(vec![], false);
        let headers = permanent_file_response_headers("text/html", &manifest, false);
        let csp = headers
            .iter()
            .find(|(name, _)| *name == "Content-Security-Policy")
            .map(|(_, value)| value.as_str())
            .expect("Content-Security-Policy header present");
        assert_eq!(csp, PREVIEW_CONTENT_SECURITY_POLICY);
        assert_ne!(csp, isolated_content_security_policy(&manifest));
    }

    #[test]
    fn csp_defaults_to_self_when_nothing_declared() {
        let manifest = permanent_manifest(vec![], false);
        let csp = isolated_content_security_policy(&manifest);
        assert_eq!(csp_directive(&csp, "default-src"), vec!["'self'"]);
    }

    #[test]
    fn csp_includes_only_declared_origins() {
        let manifest = permanent_manifest(vec!["https://declared.example.com"], false);
        let csp = isolated_content_security_policy(&manifest);
        assert_eq!(
            csp_directive(&csp, "default-src"),
            vec!["'self'", "https://declared.example.com"]
        );
    }

    #[test]
    fn undeclared_origin_absent_from_csp() {
        let manifest = permanent_manifest(vec!["https://declared.example.com"], false);
        let csp = isolated_content_security_policy(&manifest);
        // Exact token match, not substring: a permissive `https:` wildcard
        // (today's ephemeral-preview CSP) would pass a substring check
        // against "https://declared.example.com" but must fail this.
        let tokens = csp_directive(&csp, "default-src");
        assert_eq!(tokens, vec!["'self'", "https://declared.example.com"]);
        assert!(!tokens.contains(&"https:"));
        assert!(!tokens.contains(&"https://undeclared.example.com"));
    }

    #[test]
    fn unsafe_eval_absent_unless_declared() {
        let declared = isolated_content_security_policy(&permanent_manifest(vec![], true));
        assert!(csp_directive(&declared, "script-src").contains(&"'unsafe-eval'"));

        let not_declared = isolated_content_security_policy(&permanent_manifest(vec![], false));
        assert!(!csp_directive(&not_declared, "script-src").contains(&"'unsafe-eval'"));
        assert!(!not_declared.contains("unsafe-eval"));
    }

    #[test]
    fn isolated_host_requires_verified_token() {
        let secret = "s3cr3t";
        let artifact_id = "artifact-a";
        let host = isolated_artifact_hostname("acme", artifact_id).unwrap();
        let now = Utc::now();
        let token = mint_access_token(secret, artifact_id, now + chrono::Duration::minutes(5));

        assert_eq!(
            isolated_access_check(Some(&host), Some(&token), artifact_id, Some(secret), now),
            IsolatedAccess::Authorized
        );
        assert_eq!(
            isolated_access_check(Some(&host), None, artifact_id, Some(secret), now),
            IsolatedAccess::Forbidden
        );
        assert_eq!(
            isolated_access_check(Some("artfct.dev"), None, artifact_id, Some(secret), now),
            IsolatedAccess::NotIsolated
        );
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
