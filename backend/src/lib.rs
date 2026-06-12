use base64::Engine;
use chrono::{SecondsFormat, Utc};
use serde::{Deserialize, Serialize};
use uuid::Uuid;
use worker::{event, Env, Headers, Method, Request, Response, Result};

const KV_BINDING: &str = "ARTIFACTS_KV";
const DEFAULT_BASE_URL: &str = "https://artfct.dev";
const DEFAULT_MAX_HTML_BYTES: usize = 1024 * 1024;
const DEFAULT_TTL_MINUTES: u64 = 5 * 24 * 60;
const MAX_TTL_MINUTES: u64 = 365 * 24 * 60;
const MIN_EXPIRATION_TTL_SECONDS: u64 = 60;
const ARTIFACT_ID_LENGTH: usize = 10;
const DEFAULT_ARTIFACT_TITLE: &str = "Encrypted artifact";
const DEFAULT_ARTIFACT_DESCRIPTION: &str = "Encrypted HTML preview on artfct.";
const DEFAULT_ARTIFACT_THUMBNAIL: &str = "https://artfct.dev/og-image.svg";

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

    if payload.body_ciphertext_b64.trim().is_empty() {
        return json_error("The body_ciphertext_b64 field is required.", 422);
    }

    if payload.body_iv_b64.trim().is_empty() {
        return json_error("The body_iv_b64 field is required.", 422);
    }

    let max_html_bytes = env_usize(env, "ARTFCT_MAX_HTML_BYTES", DEFAULT_MAX_HTML_BYTES);
    let ciphertext_bytes = match base64::engine::general_purpose::URL_SAFE_NO_PAD
        .decode(payload.body_ciphertext_b64.trim())
    {
        Ok(bytes) => bytes,
        Err(_) => {
            return json_error(
                "The body_ciphertext_b64 field must be valid base64url.",
                422,
            );
        }
    };

    if ciphertext_bytes.len() > max_html_bytes + 64 {
        return json_error("The encrypted body exceeds the configured size limit.", 413);
    }

    let iv_bytes =
        match base64::engine::general_purpose::URL_SAFE_NO_PAD.decode(payload.body_iv_b64.trim()) {
            Ok(bytes) => bytes,
            Err(_) => return json_error("The body_iv_b64 field must be valid base64url.", 422),
        };

    if iv_bytes.len() != 12 {
        return json_error("The body_iv_b64 field must decode to a 12-byte nonce.", 422);
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
    let artifact_id = loop {
        let candidate = random_artifact_id(ARTIFACT_ID_LENGTH);

        if env.kv(KV_BINDING)?.get(&candidate).text().await?.is_none() {
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
    env.kv(KV_BINDING)?
        .put(&artifact_id, body)?
        .expiration_ttl(ttl_seconds)
        .execute()
        .await?;

    let base_url = env_string(env, "ARTFCT_PUBLIC_BASE_URL", DEFAULT_BASE_URL);
    let response = CreateArtifactResponse {
        id: artifact_id.clone(),
        url: format!("{}/p/{}", base_url.trim_end_matches('/'), artifact_id),
        tier: payload.tier,
        expires_at: stored.expires_at,
        title,
        description,
        thumbnail,
        preview_blurred: stored.preview_blurred,
    };

    json_response(&response, 201)
}

async fn resolve_artifact(path: &str, env: &Env) -> Result<Response> {
    let artifact_id = path.trim_start_matches("/p/");
    if !is_valid_artifact_id(artifact_id) {
        return expired_response();
    }

    let Some(mut stored) = env
        .kv(KV_BINDING)?
        .get(artifact_id)
        .json::<StoredArtifact>()
        .await?
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
    env.kv(KV_BINDING)?
        .put(artifact_id, body)?
        .expiration_ttl(ttl_seconds)
        .execute()
        .await?;

    let base_url = env_string(env, "ARTFCT_PUBLIC_BASE_URL", DEFAULT_BASE_URL);
    let url = format!("{}/p/{}", base_url.trim_end_matches('/'), artifact_id);
    let rendered = render_preview_shell(&stored, &url);

    html_response(&rendered, 200)
}

async fn delete_artifact(path: &str, env: &Env) -> Result<Response> {
    let artifact_id = path.trim_start_matches("/v1/artifacts/");
    if !is_valid_artifact_id(artifact_id) {
        return json_error("Invalid artifact id.", 400);
    }

    env.kv(KV_BINDING)?.delete(artifact_id).await?;
    Response::empty().map(|response| response.with_status(204))
}

async fn update_artifact(path: &str, req: &mut Request, env: &Env) -> Result<Response> {
    let artifact_id = path.trim_start_matches("/v1/artifacts/");
    if !is_valid_artifact_id(artifact_id) {
        return json_error("Invalid artifact id.", 400);
    }

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
        .get(artifact_id)
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
        .put(artifact_id, body)?
        .expiration_ttl(ttl_seconds)
        .execute()
        .await?;

    #[derive(Serialize)]
    struct UpdateArtifactResponse {
        id: String,
        expires_at: String,
    }

    let response = UpdateArtifactResponse {
        id: artifact_id.to_string(),
        expires_at: stored.expires_at,
    };

    json_response(&response, 200)
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
    id.len() == ARTIFACT_ID_LENGTH && id.bytes().all(|byte| byte.is_ascii_alphanumeric())
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
