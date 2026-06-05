use std::io::{Cursor, Read, Write};

use base64::Engine;
use chrono::{SecondsFormat, Utc};
use serde::{Deserialize, Serialize};
use uuid::Uuid;
use worker::{event, Env, Headers, Method, Request, Response, Result};

const KV_BINDING: &str = "ARTIFACTS_KV";
const DEFAULT_BASE_URL: &str = "https://artfct.dev";
const DEFAULT_MAX_HTML_BYTES: usize = 1024 * 1024;
const DEFAULT_TTL_MINUTES: u64 = 60;
const MAX_TTL_MINUTES: u64 = 24 * 60;
const MIN_EXPIRATION_TTL_SECONDS: u64 = 60;
const BASE62_ALPHABET: &[u8; 62] =
    b"0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz";

#[derive(Debug, Deserialize)]
#[serde(rename_all = "snake_case")]
struct CreateArtifactRequest {
    html: String,
    tier: ArtifactTier,
    ttl_minutes: Option<u64>,
}

#[derive(Debug, Serialize)]
#[serde(rename_all = "snake_case")]
struct CreateArtifactResponse {
    id: String,
    url: String,
    tier: ArtifactTier,
    expires_at: String,
}

#[derive(Debug, Serialize, Deserialize, Clone, Copy)]
#[serde(rename_all = "snake_case")]
enum ArtifactTier {
    Public,
    Secure,
    Ephemeral,
}

#[derive(Debug, Serialize, Deserialize)]
#[serde(rename_all = "snake_case")]
struct StoredArtifact {
    html_brotli_base64: String,
    tier: ArtifactTier,
    created_at: String,
    expires_at: String,
}

#[event(fetch)]
pub async fn main(mut req: Request, env: Env, _ctx: worker::Context) -> Result<Response> {
    console_error_panic_hook::set_once();

    let method = req.method();
    let url = req.url()?;
    let path = url.path();

    match (method, path) {
        (Method::Post, "/v1/artifacts") => create_artifact(&mut req, &env).await,
        (Method::Delete, path) if path.starts_with("/v1/artifacts/") => {
            delete_artifact(path, &env).await
        }
        (Method::Patch, path) if path.starts_with("/v1/artifacts/") => {
            update_artifact(path, &mut req, &env).await
        }
        (Method::Get, path) if path.starts_with("/p/") => resolve_artifact(path, &env).await,
        (Method::Options, _) => options_response(),
        _ => not_found_response(),
    }
}

async fn create_artifact(req: &mut Request, env: &Env) -> Result<Response> {
    let payload = match req.json::<CreateArtifactRequest>().await {
        Ok(payload) => payload,
        Err(_) => return json_error("Invalid JSON request body.", 400),
    };

    if payload.html.trim().is_empty() {
        return json_error("The html field is required.", 422);
    }

    let max_html_bytes = env_usize(env, "ARTFCT_MAX_HTML_BYTES", DEFAULT_MAX_HTML_BYTES);
    if payload.html.len() > max_html_bytes {
        return json_error("The html payload exceeds the configured size limit.", 413);
    }

    let ttl_minutes = payload
        .ttl_minutes
        .unwrap_or_else(|| env_u64(env, "ARTFCT_DEFAULT_TTL_MINUTES", DEFAULT_TTL_MINUTES));
    let max_ttl_minutes = env_u64(env, "ARTFCT_MAX_TTL_MINUTES", MAX_TTL_MINUTES);

    if ttl_minutes == 0 || ttl_minutes > max_ttl_minutes {
        return json_error(
            "ttl_minutes must be between 1 and the configured maximum.",
            422,
        );
    }

    let ttl_seconds = (ttl_minutes * 60).max(MIN_EXPIRATION_TTL_SECONDS);
    let now = Utc::now();
    let expires_at = now + chrono::Duration::seconds(ttl_seconds as i64);
    let uuid = Uuid::new_v4();
    let hex_id = uuid.simple().to_string();
    let short_id = base62_encode(uuid.as_bytes());
    let compressed_html = compress_html(&payload.html)?;

    let stored = StoredArtifact {
        html_brotli_base64: base64::engine::general_purpose::STANDARD.encode(compressed_html),
        tier: payload.tier,
        created_at: now.to_rfc3339_opts(SecondsFormat::Secs, true),
        expires_at: expires_at.to_rfc3339_opts(SecondsFormat::Secs, true),
    };

    let body = serde_json::to_string(&stored)?;
    env.kv(KV_BINDING)?
        .put(&hex_id, body)?
        .expiration_ttl(ttl_seconds)
        .execute()
        .await?;

    let base_url = env_string(env, "ARTFCT_PUBLIC_BASE_URL", DEFAULT_BASE_URL);
    let response = CreateArtifactResponse {
        id: short_id.clone(),
        url: format!("{}/p/{}", base_url.trim_end_matches('/'), short_id),
        tier: payload.tier,
        expires_at: stored.expires_at,
    };

    json_response(&response, 201)
}

async fn resolve_artifact(path: &str, env: &Env) -> Result<Response> {
    let short_id = path.trim_start_matches("/p/");
    if !is_valid_artifact_id(short_id) {
        return expired_response();
    }

    let hex_id = match base62_decode(short_id) {
        Some(bytes) => Uuid::from_bytes(bytes).simple().to_string(),
        None => return expired_response(),
    };

    let Some(stored) = env
        .kv(KV_BINDING)?
        .get(&hex_id)
        .json::<StoredArtifact>()
        .await?
    else {
        return expired_response();
    };

    let compressed =
        match base64::engine::general_purpose::STANDARD.decode(stored.html_brotli_base64) {
            Ok(bytes) => bytes,
            Err(_) => return html_error("Stored artifact is corrupted.", 500),
        };
    let html = decompress_html(&compressed)?;
    let wrapped = render_canvas(&html, &stored.expires_at);

    html_response(&wrapped, 200)
}

async fn delete_artifact(path: &str, env: &Env) -> Result<Response> {
    let short_id = path.trim_start_matches("/v1/artifacts/");
    if !is_valid_artifact_id(short_id) {
        return json_error("Invalid artifact id.", 400);
    }

    let hex_id = match base62_decode(short_id) {
        Some(bytes) => Uuid::from_bytes(bytes).simple().to_string(),
        None => return json_error("Invalid artifact id.", 400),
    };

    env.kv(KV_BINDING)?.delete(&hex_id).await?;
    Response::empty().map(|response| response.with_status(204))
}

async fn update_artifact(path: &str, req: &mut Request, env: &Env) -> Result<Response> {
    let short_id = path.trim_start_matches("/v1/artifacts/");
    if !is_valid_artifact_id(short_id) {
        return json_error("Invalid artifact id.", 400);
    }

    let hex_id = match base62_decode(short_id) {
        Some(bytes) => Uuid::from_bytes(bytes).simple().to_string(),
        None => return json_error("Invalid artifact id.", 400),
    };

    #[derive(Deserialize)]
    struct UpdateArtifactRequest {
        ttl_minutes: u64,
    }

    let payload = match req.json::<UpdateArtifactRequest>().await {
        Ok(payload) => payload,
        Err(_) => return json_error("Invalid JSON request body.", 400),
    };

    let max_ttl_minutes = env_u64(env, "ARTFCT_MAX_TTL_MINUTES", MAX_TTL_MINUTES);
    if payload.ttl_minutes == 0 || payload.ttl_minutes > max_ttl_minutes {
        return json_error(
            "ttl_minutes must be between 1 and the configured maximum.",
            422,
        );
    }

    let Some(mut stored) = env
        .kv(KV_BINDING)?
        .get(&hex_id)
        .json::<StoredArtifact>()
        .await?
    else {
        return json_error("Artifact not found or expired.", 404);
    };

    let ttl_seconds = (payload.ttl_minutes * 60).max(MIN_EXPIRATION_TTL_SECONDS);
    let now = Utc::now();
    let expires_at = now + chrono::Duration::seconds(ttl_seconds as i64);

    stored.expires_at = expires_at.to_rfc3339_opts(SecondsFormat::Secs, true);

    let body = serde_json::to_string(&stored)?;
    env.kv(KV_BINDING)?
        .put(&hex_id, body)?
        .expiration_ttl(ttl_seconds)
        .execute()
        .await?;

    #[derive(Serialize)]
    struct UpdateArtifactResponse {
        id: String,
        expires_at: String,
    }

    let response = UpdateArtifactResponse {
        id: short_id.to_string(),
        expires_at: stored.expires_at,
    };

    json_response(&response, 200)
}

fn render_canvas(html: &str, expires_at: &str) -> String {
    let escaped_html = escape_attr(html);
    let escaped_expires_at = escape_text(expires_at);

    format!(
        r#"<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Artifact Preview</title>
<style>
html,body{{margin:0;min-height:100%;background:#0b0d10;color:#f8fafc;font-family:ui-monospace,SFMono-Regular,Menlo,Monaco,Consolas,monospace;}}
.artfct-frame{{position:fixed;inset:0;width:100%;height:100%;border:0;background:white;}}
.artfct-badge{{position:fixed;right:12px;bottom:12px;z-index:1;padding:8px 10px;border:1px solid rgb(255 255 255 / .18);border-radius:8px;background:rgb(15 23 42 / .72);backdrop-filter:blur(12px);font-size:12px;line-height:1.3;color:#e2e8f0;box-shadow:0 10px 30px rgb(0 0 0 / .22);}}
</style>
</head>
<body>
<iframe class="artfct-frame" sandbox="allow-scripts" referrerpolicy="no-referrer" srcdoc="{escaped_html}"></iframe>
<div class="artfct-badge">expires <time datetime="{escaped_expires_at}">{escaped_expires_at}</time></div>
</body>
</html>"#
    )
}

fn compress_html(html: &str) -> Result<Vec<u8>> {
    let mut writer = brotli::CompressorWriter::new(Vec::new(), 4096, 5, 22);
    writer.write_all(html.as_bytes())?;
    Ok(writer.into_inner())
}

fn decompress_html(bytes: &[u8]) -> Result<String> {
    let mut reader = brotli::Decompressor::new(Cursor::new(bytes), 4096);
    let mut html = String::new();
    reader.read_to_string(&mut html)?;
    Ok(html)
}

fn base62_encode(bytes: &[u8; 16]) -> String {
    let mut digits = Vec::new();
    let mut data = *bytes;

    loop {
        let mut remainder = 0u32;
        let mut all_zero = true;

        for byte in data.iter_mut() {
            let combined = (remainder as u64 * 256) + *byte as u64;
            *byte = (combined / 62) as u8;
            remainder = (combined % 62) as u32;
            if *byte != 0 {
                all_zero = false;
            }
        }

        digits.push(BASE62_ALPHABET[remainder as usize]);

        if all_zero {
            break;
        }
    }

    digits.reverse();

    while digits.len() < 22 {
        digits.insert(0, BASE62_ALPHABET[0]);
    }

    String::from_utf8(digits).unwrap()
}

fn base62_decode(encoded: &str) -> Option<[u8; 16]> {
    let mut result = [0u8; 16];

    for c in encoded.chars() {
        let digit = BASE62_ALPHABET.iter().position(|&b| b == c as u8)? as u16;
        let mut carry = digit;

        for byte in result.iter_mut().rev() {
            carry += *byte as u16 * 62;
            *byte = (carry % 256) as u8;
            carry /= 256;
        }

        if carry > 0 {
            return None;
        }
    }

    Some(result)
}

fn is_valid_artifact_id(id: &str) -> bool {
    id.len() == 22 && id.bytes().all(|byte| byte.is_ascii_alphanumeric())
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

fn json_error(message: &str, status: u16) -> Result<Response> {
    json_response(&serde_json::json!({ "error": message }), status)
}

fn html_response(html: &str, status: u16) -> Result<Response> {
    let headers = Headers::new();
    headers.set("Content-Type", "text/html; charset=utf-8")?;
    headers.set("X-Frame-Options", "DENY")?;
    headers.set("X-Content-Type-Options", "nosniff")?;
    headers.set(
        "Content-Security-Policy",
        "default-src 'self' https://cdn.jsdelivr.net https://cdnjs.cloudflare.com https://fonts.googleapis.com; script-src 'unsafe-inline' 'unsafe-eval' https://cdn.jsdelivr.net https://cdnjs.cloudflare.com; style-src 'unsafe-inline' https://cdn.jsdelivr.net https://cdnjs.cloudflare.com https://fonts.googleapis.com; font-src https://fonts.gstatic.com; img-src 'self' data: blob: https:; frame-ancestors 'none'; form-action 'none'; base-uri 'none';",
    )?;
    Response::from_html(html).map(|response| response.with_headers(headers).with_status(status))
}

fn html_error(message: &str, status: u16) -> Result<Response> {
    let html = format!(
        "<!doctype html><meta charset=\"utf-8\"><title>Artifact unavailable</title><body>{}</body>",
        escape_text(message)
    );
    html_response(&html, status)
}

fn expired_response() -> Result<Response> {
    html_error("This artifact has expired or does not exist.", 404)
}

fn not_found_response() -> Result<Response> {
    html_error("Not found.", 404)
}

fn options_response() -> Result<Response> {
    let mut response = Response::empty()?.with_status(204);
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

    #[test]
    fn base62_roundtrip() {
        let uuid = Uuid::new_v4();
        let encoded = base62_encode(uuid.as_bytes());
        assert_eq!(encoded.len(), 22);
        let decoded = base62_decode(&encoded).unwrap();
        assert_eq!(*uuid.as_bytes(), decoded);
    }

    #[test]
    fn base62_zero_bytes() {
        let bytes = [0u8; 16];
        let encoded = base62_encode(&bytes);
        assert_eq!(encoded, "0000000000000000000000");
        let decoded = base62_decode(&encoded).unwrap();
        assert_eq!(bytes, decoded);
    }

    #[test]
    fn base62_one_hundred_random_roundtrips() {
        for _ in 0..100 {
            let uuid = Uuid::new_v4();
            let bytes = uuid.as_bytes();
            let encoded = base62_encode(bytes);
            assert_eq!(encoded.len(), 22);
            let decoded = base62_decode(&encoded).unwrap();
            assert_eq!(*bytes, decoded);
        }
    }

    #[test]
    fn base62_overflow_rejected() {
        assert!(base62_decode("zzzzzzzzzzzzzzzzzzzzzz").is_none());
    }

    #[test]
    fn base62_decode_empty_string_is_zero() {
        let decoded = base62_decode("").unwrap();
        assert_eq!(decoded, [0u8; 16]);
    }

    #[test]
    fn base62_decode_short_string_works_for_small_numbers() {
        let decoded = base62_decode("ABC").unwrap();
        let mut expected = [0u8; 16];
        expected[14] = 0x98;
        expected[15] = 0xDE;
        assert_eq!(decoded, expected);
    }

    #[test]
    fn base62_non_alphanumeric_is_invalid_id() {
        assert!(!is_valid_artifact_id("00000000000000000000!0"));
        assert!(!is_valid_artifact_id("00000000000000000000-0"));
        assert!(!is_valid_artifact_id("abc defghijklmnopqrstuv"));
    }

    #[test]
    fn base62_wrong_length_is_invalid_id() {
        assert!(!is_valid_artifact_id(""));
        assert!(!is_valid_artifact_id("12345"));
        assert!(!is_valid_artifact_id(&"a".repeat(21)));
        assert!(!is_valid_artifact_id(&"a".repeat(23)));
    }

    #[test]
    fn base62_valid_id_accepted() {
        let uuid = Uuid::new_v4();
        let short = base62_encode(uuid.as_bytes());
        assert!(is_valid_artifact_id(&short));
    }

    #[test]
    fn base62_id_deterministic() {
        let uuid = Uuid::parse_str("4fa8e33748f2434f8223e5c36a7848b1").unwrap();
        let encoded = base62_encode(uuid.as_bytes());
        assert_eq!(encoded, "2QJZgqlWux7NBsETBVa1Oj");
    }
}
