//! Production-path storage checks for an isolated local Wrangler Worker.
//!
//! These tests are ignored by default. They require all of
//! `ARTFCT_INTEGRATION_BASE_URL`, `ARTFCT_INTEGRATION_TOKEN`,
//! `ARTFCT_INTEGRATION_PERSIST_TO`, `ARTFCT_WRANGLER_BIN`, and
//! `ARTFCT_ARTIFACT_TOKEN_SECRET`, and `ARTFCT_GOVERNANCE_SECRET`, then run with:
//! `cargo test -p artfct-backend --test storage_integration -- --ignored`.
//!
//! The token secret is required rather than optional on purpose: it is what
//! the Worker verifies isolated-origin links with, so a run without it can
//! only silently skip the one test that proves a signed link opens. The stack
//! that owns these variables (`scripts/mcp-e2e-stack.sh`) always sets it.

use std::{env, error::Error, fs, process::Command};

use ring::{digest, hmac};
use serde_json::{json, Value};

#[derive(Clone)]
struct Context {
    base: String,
    token: String,
    org: String,
    persist_to: String,
    wrangler: String,
    /// `ARTFCT_ARTIFACT_TOKEN_SECRET` on the live Worker: the key the Worker
    /// verifies `<artifact_id>.<expires_at>.<hmac>` link tokens with.
    token_secret: String,
    /// The Worker's `ARTFCT_ARTIFACT_ORIGIN_SUFFIX`, defaulting to the
    /// production `.artfct.dev` exactly as `artifact_origin_suffix` does.
    origin_suffix: String,
    /// `ARTFCT_GOVERNANCE_SECRET` configured on the isolated local Worker.
    governance_secret: String,
    /// `ARTFCT_INTEGRATION_MEMBER_TOKEN`: a plain Member of `org`. Optional,
    /// because a stack that predates the sharing tests does not mint one; a
    /// test that needs it skips with `Ok(())`.
    member_token: Option<String>,
    /// `ARTFCT_INTEGRATION_MEMBER2_TOKEN`: a second plain Member of `org`, the
    /// non-owner viewer in the private-visibility test.
    member2_token: Option<String>,
    /// `ARTFCT_INTEGRATION_OTHER_ORG_TOKEN`: an admin of the second seeded
    /// org, for cross-org refusals and the public + edit write.
    other_org_token: Option<String>,
    /// `ARTFCT_LIMITS_WRITE_SECRET`: the shared secret the internal
    /// org-settings and owner-backfill endpoints require.
    limits_secret: Option<String>,
    /// `ARTFCT_INTEGRATION_APP_ORIGIN`: the one origin an isolated artifact
    /// response may be framed by.
    app_origin: Option<String>,
}

fn optional_env(name: &str) -> Option<String> {
    env::var(name).ok().filter(|value| !value.is_empty())
}

fn context() -> Option<Context> {
    let names = [
        "ARTFCT_INTEGRATION_BASE_URL",
        "ARTFCT_INTEGRATION_TOKEN",
        "ARTFCT_INTEGRATION_PERSIST_TO",
        "ARTFCT_WRANGLER_BIN",
        "ARTFCT_ARTIFACT_TOKEN_SECRET",
        "ARTFCT_GOVERNANCE_SECRET",
    ];
    let present = names
        .iter()
        .filter(|name| env::var_os(name).is_some())
        .count();
    if present == 0 {
        return None;
    }
    assert_eq!(
        present,
        names.len(),
        "storage integration requires all six environment variables"
    );
    let base = env::var(names[0]).expect("base URL is configured");
    assert!(
        !base.to_ascii_lowercase().contains("artfct.dev"),
        "refusing storage integration against production"
    );
    Some(Context {
        base: base.trim_end_matches('/').to_string(),
        token: env::var(names[1]).expect("token is configured"),
        org: env::var("ARTFCT_INTEGRATION_ORG").unwrap_or_else(|_| "default".to_string()),
        persist_to: env::var(names[2]).expect("persistence path is configured"),
        wrangler: env::var(names[3]).expect("Wrangler binary is configured"),
        token_secret: env::var(names[4]).expect("artifact token secret is configured"),
        governance_secret: env::var(names[5]).expect("governance secret is configured"),
        origin_suffix: env::var("ARTFCT_ARTIFACT_ORIGIN_SUFFIX")
            .ok()
            .filter(|value| !value.is_empty())
            .unwrap_or_else(|| ".artfct.dev".to_string()),
        member_token: optional_env("ARTFCT_INTEGRATION_MEMBER_TOKEN"),
        member2_token: optional_env("ARTFCT_INTEGRATION_MEMBER2_TOKEN"),
        other_org_token: optional_env("ARTFCT_INTEGRATION_OTHER_ORG_TOKEN"),
        limits_secret: optional_env("ARTFCT_LIMITS_WRITE_SECRET"),
        app_origin: optional_env("ARTFCT_INTEGRATION_APP_ORIGIN"),
    })
}

fn sha256(bytes: &[u8]) -> String {
    digest::digest(&digest::SHA256, bytes)
        .as_ref()
        .iter()
        .map(|byte| format!("{byte:02x}"))
        .collect()
}

fn unique_html(label: &str) -> Vec<u8> {
    format!(
        "<!doctype html><html><body><h1>{label}-{}</h1></body></html>",
        std::time::SystemTime::now()
            .duration_since(std::time::UNIX_EPOCH)
            .expect("clock is after epoch")
            .as_nanos()
    )
    .into_bytes()
}

fn permanent_payload(bytes: &[u8], provenance: Value) -> Value {
    permanent_payload_with_tier(bytes, provenance, "public")
}

fn permanent_payload_with_tier(bytes: &[u8], provenance: Value, tier: &str) -> Value {
    let hash = sha256(bytes);
    json!({
        "mode": "permanent", "tier": tier, "title": "Storage integration",
        "description": "Storage integration", "thumbnail": "https://example.com/thumbnail.png",
        "preview_blurred": false,
        "manifest": {"entrypoint": "index.html", "external_origins": [], "files": [{
            "path": "index.html", "content_type": "text/html; charset=utf-8", "size_bytes": bytes.len(), "sha256": hash
        }]},
        "provenance": provenance
    })
}

/// The isolated origin `isolated_artifact_hostname` in backend/src/lib.rs and
/// `ArtifactAccessLink::isolatedHostname` in Laravel both build for one
/// artifact: `<tenant-slug>--<artifact-id><suffix>`.
fn isolated_origin(tenant_slug: &str, artifact_id: &str, suffix: &str) -> String {
    format!("{tenant_slug}--{artifact_id}{suffix}")
}

/// `<artifact_id>.<expires_at_unix>.<hmac_sha256_hex>` over
/// `<artifact_id>.<expires_at_unix>` — the token `mint_access_token` in
/// backend/src/lib.rs mints and `ArtifactAccessLink::mintToken` mirrors, built
/// here with the same key so the live Worker's `verify_access_token` is what
/// decides whether it is valid.
fn mint_access_token(secret: &str, artifact_id: &str, expires_at_unix: i64) -> String {
    let message = format!("{artifact_id}.{expires_at_unix}");
    let key = hmac::Key::new(hmac::HMAC_SHA256, secret.as_bytes());
    let signature: String = hmac::sign(&key, message.as_bytes())
        .as_ref()
        .iter()
        .map(|byte| format!("{byte:02x}"))
        .collect();
    format!("{message}.{signature}")
}

/// How many times a request is re-sent after a connection-level failure.
/// Five attempts, backing off 200ms, 400ms, 800ms and 1.6s, is past the
/// observed drop, which clears on the next request; a real outage still
/// fails the test after about three seconds.
const TRANSPORT_ATTEMPTS: u32 = 5;

/// Whether `error` is a connection-level failure — nothing the server
/// actually said — rather than a response it sent. `wrangler dev --local`
/// serves behind a proxy that intermittently drops a connection (the dev
/// session's log shows `Uncaught Error: Network connection lost.`; this side
/// sees `hyper::Error(IncompleteMessage)`), and the request that never got a
/// response is safe to repeat because every endpoint this suite uses is
/// idempotent by design: content-addressed creates, uploads, and deletes.
fn is_transport_failure(error: &reqwest::Error) -> bool {
    error.status().is_none() && !error.is_builder() && !error.is_decode()
}

async fn backoff(attempt: u32) {
    tokio::time::sleep(std::time::Duration::from_millis(200 * u64::from(attempt))).await;
}

/// Sends `request` until it answers or `TRANSPORT_ATTEMPTS` is exhausted.
async fn execute_with_retry(
    client: &reqwest::Client,
    request: &reqwest::Request,
) -> Result<reqwest::Response, reqwest::Error> {
    let mut attempt = 1;
    loop {
        let clone = request
            .try_clone()
            .expect("every body in this suite is buffered and therefore cloneable");
        match client.execute(clone).await {
            Ok(response) => return Ok(response),
            Err(error) if attempt < TRANSPORT_ATTEMPTS && is_transport_failure(&error) => {
                backoff(attempt).await;
                attempt += 1;
            }
            Err(error) => return Err(error),
        }
    }
}

/// The client this suite makes every request with. A transport-level failure
/// re-sends the request instead of failing the test.
///
/// The retry used to cover one PUT in one test (`expired_incomplete_bundle_is_
/// cleaned_up`), which is why the rest of the suite kept failing on an
/// `IncompleteMessage` that landed on whichever request followed a
/// `wrangler d1 execute` CLI access to the same persisted database. Hosting it
/// in the client covers every call site, including body reads that are cut off
/// after the response headers arrived.
#[derive(Clone)]
struct RetryingClient {
    inner: reqwest::Client,
}

impl RetryingClient {
    fn new() -> Self {
        Self {
            inner: reqwest::Client::new(),
        }
    }

    fn get(&self, url: impl reqwest::IntoUrl) -> RetryingRequest {
        RetryingRequest {
            client: self.inner.clone(),
            builder: self.inner.get(url),
        }
    }

    fn post(&self, url: impl reqwest::IntoUrl) -> RetryingRequest {
        RetryingRequest {
            client: self.inner.clone(),
            builder: self.inner.post(url),
        }
    }

    fn patch(&self, url: impl reqwest::IntoUrl) -> RetryingRequest {
        RetryingRequest {
            client: self.inner.clone(),
            builder: self.inner.patch(url),
        }
    }

    fn put(&self, url: impl reqwest::IntoUrl) -> RetryingRequest {
        RetryingRequest {
            client: self.inner.clone(),
            builder: self.inner.put(url),
        }
    }

    fn delete(&self, url: impl reqwest::IntoUrl) -> RetryingRequest {
        RetryingRequest {
            client: self.inner.clone(),
            builder: self.inner.delete(url),
        }
    }
}

/// One request under construction. Mirrors the `reqwest::RequestBuilder`
/// methods this suite uses, and builds the request once in `send` so a retry
/// re-executes an identical clone rather than rebuilding the chain.
struct RetryingRequest {
    client: reqwest::Client,
    builder: reqwest::RequestBuilder,
}

impl RetryingRequest {
    fn bearer_auth(mut self, token: &str) -> Self {
        self.builder = self.builder.bearer_auth(token);
        self
    }

    fn header(mut self, name: reqwest::header::HeaderName, value: &str) -> Self {
        self.builder = self.builder.header(name, value);
        self
    }

    fn json<T: serde::Serialize + ?Sized>(mut self, value: &T) -> Self {
        self.builder = self.builder.json(value);
        self
    }

    fn body(mut self, body: Vec<u8>) -> Self {
        self.builder = self.builder.body(body);
        self
    }

    async fn send(self) -> Result<RetryingResponse, reqwest::Error> {
        let request = self.builder.build()?;
        let response = execute_with_retry(&self.client, &request).await?;

        Ok(RetryingResponse {
            client: self.client,
            request,
            response,
        })
    }
}

/// A response whose body reads also retry: a transfer cut off after the
/// headers arrived is re-read by re-executing the same request.
struct RetryingResponse {
    client: reqwest::Client,
    request: reqwest::Request,
    response: reqwest::Response,
}

impl RetryingResponse {
    fn status(&self) -> reqwest::StatusCode {
        self.response.status()
    }

    fn headers(&self) -> &reqwest::header::HeaderMap {
        self.response.headers()
    }

    fn header(&self, name: &str) -> Option<&reqwest::header::HeaderValue> {
        self.response.headers().get(name)
    }

    fn error_for_status(self) -> Result<Self, reqwest::Error> {
        let Self {
            client,
            request,
            response,
        } = self;

        Ok(Self {
            client,
            request,
            response: response.error_for_status()?,
        })
    }

    async fn bytes(
        self,
    ) -> Result<impl AsRef<[u8]> + std::ops::Deref<Target = [u8]>, reqwest::Error> {
        let Self {
            client,
            request,
            mut response,
        } = self;
        let mut attempt = 1;
        loop {
            match response.bytes().await {
                Ok(bytes) => return Ok(bytes),
                Err(error) if attempt < TRANSPORT_ATTEMPTS && is_transport_failure(&error) => {
                    backoff(attempt).await;
                    attempt += 1;
                    response = execute_with_retry(&client, &request).await?;
                }
                Err(error) => return Err(error),
            }
        }
    }

    async fn text(self) -> Result<String, reqwest::Error> {
        let Self {
            client,
            request,
            mut response,
        } = self;
        let mut attempt = 1;
        loop {
            match response.text().await {
                Ok(text) => return Ok(text),
                Err(error) if attempt < TRANSPORT_ATTEMPTS && is_transport_failure(&error) => {
                    backoff(attempt).await;
                    attempt += 1;
                    response = execute_with_retry(&client, &request).await?;
                }
                Err(error) => return Err(error),
            }
        }
    }

    async fn json<T: serde::de::DeserializeOwned>(self) -> Result<T, reqwest::Error> {
        let Self {
            client,
            request,
            mut response,
        } = self;
        let mut attempt = 1;
        loop {
            match response.json::<T>().await {
                Ok(value) => return Ok(value),
                Err(error) if attempt < TRANSPORT_ATTEMPTS && is_transport_failure(&error) => {
                    backoff(attempt).await;
                    attempt += 1;
                    response = execute_with_retry(&client, &request).await?;
                }
                Err(error) => return Err(error),
            }
        }
    }
}

fn response_header(response: &RetryingResponse, name: &str) -> String {
    response
        .header(name)
        .and_then(|value| value.to_str().ok())
        .unwrap_or_default()
        .to_string()
}

fn bundle_payload(files: &[(&str, &[u8], &str)], entrypoint: &str) -> Value {
    json!({
        "mode": "permanent", "tier": "public", "title": "Bundle",
        "description": "Bundle", "thumbnail": "https://example.com/thumbnail.png",
        "preview_blurred": false,
        "manifest": {"entrypoint": entrypoint, "external_origins": [], "files": files.iter().map(|(path, bytes, content_type)| json!({"path": path, "content_type": content_type, "size_bytes": bytes.len(), "sha256": sha256(bytes)})).collect::<Vec<_>>()},
        "provenance": {"agent": "integration", "agent_raw": "integration", "agent_version": "1", "model": null, "session_id": "integration", "tool": "cli", "repo_url": null, "branch": null, "commit_sha": null, "dirty": null, "source_path": null, "client": "integration", "client_version": "1", "sources": {"agent": "self_reported", "agent_raw": "self_reported", "agent_version": "client_info", "model": "absent", "session_id": "process", "tool": "config", "repo_url": "absent", "branch": "absent", "commit_sha": "absent", "dirty": "absent", "source_path": "absent"}}
    })
}

async fn create_and_upload_bundle(
    context: &Context,
    client: &RetryingClient,
    files: &[(&str, &[u8], &str)],
    entrypoint: &str,
) -> Result<String, Box<dyn Error>> {
    let response = client
        .post(format!("{}/v1/artifacts", context.base))
        .bearer_auth(&context.token)
        .json(&bundle_payload(files, entrypoint))
        .send()
        .await?;
    assert_eq!(response.status(), reqwest::StatusCode::CREATED);
    let body: Value = response.json().await?;
    let id = body["id"]
        .as_str()
        .ok_or("create response omitted id")?
        .to_string();
    let published_url =
        reqwest::Url::parse(body["url"].as_str().ok_or("create response omitted url")?)?;
    assert_eq!(published_url.path(), format!("/p/{id}/"));
    assert_eq!(
        published_url.join("app.js")?.path(),
        format!("/p/{id}/app.js")
    );
    for hash in body["missing_files"]
        .as_array()
        .ok_or("missing_files omitted")?
    {
        let hash = hash.as_str().ok_or("invalid missing hash")?;
        let (_, bytes, content_type) = files
            .iter()
            .find(|(_, bytes, _)| sha256(bytes) == hash)
            .ok_or("missing local file")?;
        let upload = client
            .put(format!("{}/v1/artifacts/{id}/files/{hash}", context.base))
            .bearer_auth(&context.token)
            .header(reqwest::header::CONTENT_TYPE, content_type)
            .body(bytes.to_vec())
            .send()
            .await?;
        assert_eq!(upload.status(), reqwest::StatusCode::NO_CONTENT);
    }
    Ok(id)
}

fn content_type_for(path: &str) -> &'static str {
    match std::path::Path::new(path)
        .extension()
        .and_then(|value| value.to_str())
    {
        Some("html") => "text/html; charset=utf-8",
        Some("css") => "text/css",
        Some("js") => "application/javascript",
        Some("woff2") => "font/woff2",
        _ => "application/octet-stream",
    }
}

/// Publishes every file under `directory` as one permanent bundle the way the
/// hosted `deploy_artifact` tool does: create with the manifest, then upload
/// only the files the Worker reports missing. Returns the published URL and
/// how many files were uploaded.
async fn deploy_directory(
    context: &Context,
    client: &RetryingClient,
    directory: &std::path::Path,
) -> Result<(String, usize), Box<dyn Error>> {
    let mut collected = Vec::new();
    collect_files(directory, directory, &mut collected)?;
    let files: Vec<(&str, &[u8], &str)> = collected
        .iter()
        .map(|(path, bytes)| (path.as_str(), bytes.as_slice(), content_type_for(path)))
        .collect();
    let response = client
        .post(format!("{}/v1/artifacts", context.base))
        .bearer_auth(&context.token)
        .json(&bundle_payload(&files, "index.html"))
        .send()
        .await?;
    assert_eq!(response.status(), reqwest::StatusCode::CREATED);
    let body: Value = response.json().await?;
    let id = body["id"].as_str().ok_or("create response omitted id")?;
    let url = body["url"]
        .as_str()
        .ok_or("create response omitted url")?
        .to_string();
    let missing = body["missing_files"]
        .as_array()
        .ok_or("missing_files omitted")?;
    for hash in missing {
        let hash = hash.as_str().ok_or("invalid missing hash")?;
        let (_, bytes, content_type) = files
            .iter()
            .find(|(_, bytes, _)| sha256(bytes) == hash)
            .ok_or("missing local file")?;
        let upload = client
            .put(format!("{}/v1/artifacts/{id}/files/{hash}", context.base))
            .bearer_auth(&context.token)
            .header(reqwest::header::CONTENT_TYPE, content_type)
            .body(bytes.to_vec())
            .send()
            .await?;
        assert_eq!(upload.status(), reqwest::StatusCode::NO_CONTENT);
    }
    Ok((url, missing.len()))
}

async fn create_and_upload(
    context: &Context,
    client: &RetryingClient,
    bytes: &[u8],
    provenance: Value,
) -> Result<(String, String), Box<dyn Error>> {
    create_and_upload_payload(context, client, bytes, permanent_payload(bytes, provenance)).await
}

async fn create_and_upload_payload(
    context: &Context,
    client: &RetryingClient,
    bytes: &[u8],
    payload: Value,
) -> Result<(String, String), Box<dyn Error>> {
    let hash = sha256(bytes);
    let created = client
        .post(format!("{}/v1/artifacts", context.base))
        .bearer_auth(&context.token)
        .json(&payload)
        .send()
        .await?;
    assert_eq!(created.status(), reqwest::StatusCode::CREATED);
    let body: Value = created.json().await?;
    let id = body["id"].as_str().ok_or("create response omitted id")?;
    let missing_files = body["missing_files"]
        .as_array()
        .ok_or("create response omitted missing_files")?;
    if missing_files.iter().any(|missing| missing == &hash) {
        let upload = client
            .put(format!("{}/v1/artifacts/{id}/files/{hash}", context.base))
            .bearer_auth(&context.token)
            .header(reqwest::header::CONTENT_TYPE, "text/html; charset=utf-8")
            .body(bytes.to_vec())
            .send()
            .await?;
        assert_eq!(upload.status(), reqwest::StatusCode::NO_CONTENT);
    }
    Ok((id.to_string(), hash))
}

fn d1_row(context: &Context, sql: &str) -> Result<Value, Box<dyn Error>> {
    let working_directory = env!("CARGO_MANIFEST_DIR").to_string();
    let output = Command::new(&context.wrangler)
        .current_dir(&working_directory)
        .args([
            "d1",
            "execute",
            "artfct-artifacts",
            "--local",
            "--persist-to",
            &context.persist_to,
            "--command",
            sql,
            "--json",
        ])
        .output()
        .map_err(|error| {
            format!(
                "failed to launch Wrangler executable {} from {working_directory}: {error}",
                context.wrangler
            )
        })?;
    if !output.status.success() {
        return Err(format!(
            "Wrangler D1 query failed: {}",
            String::from_utf8_lossy(&output.stderr)
        )
        .into());
    }
    let value: Value = serde_json::from_slice(&output.stdout)?;
    value
        .as_array()
        .and_then(|items| items.first())
        .and_then(|item| item.get("results"))
        .and_then(Value::as_array)
        .and_then(|rows| rows.first())
        .cloned()
        .ok_or_else(|| format!("Wrangler D1 query returned no row: {value}").into())
}

fn anonymous_viewer_keys(artifact_id: &str) -> Result<Vec<Option<String>>, Box<dyn Error>> {
    let repository_root = std::path::Path::new(env!("CARGO_MANIFEST_DIR"))
        .parent()
        .ok_or("backend directory has no repository parent")?;
    let expression = format!(
        "echo json_encode(App\\Models\\ArtifactUsageEvent::query()->where('artifact_id', '{artifact_id}')->orderBy('id')->pluck('viewer_key')->all());"
    );
    let output = Command::new("php")
        .current_dir(repository_root)
        .args(["artisan", "tinker", "--execute", &expression])
        .output()?;

    if !output.status.success() {
        return Err(format!(
            "could not read local anonymous view events: {}",
            String::from_utf8_lossy(&output.stderr)
        )
        .into());
    }

    let stdout = String::from_utf8(output.stdout)?;
    let json_line = stdout
        .lines()
        .find(|line| line.trim_start().starts_with('['))
        .ok_or("Tinker did not return the anonymous view keys as JSON")?;

    Ok(serde_json::from_str(json_line)?)
}

fn d1_execute(context: &Context, sql: &str) -> Result<(), Box<dyn Error>> {
    let working_directory = env!("CARGO_MANIFEST_DIR").to_string();
    let output = Command::new(&context.wrangler)
        .current_dir(&working_directory)
        .args([
            "d1",
            "execute",
            "artfct-artifacts",
            "--local",
            "--persist-to",
            &context.persist_to,
            "--command",
            sql,
        ])
        .output()
        .map_err(|error| {
            format!(
                "failed to launch Wrangler executable {} from {working_directory}: {error}",
                context.wrangler
            )
        })?;
    if output.status.success() {
        Ok(())
    } else {
        Err(format!(
            "Wrangler D1 execute failed: {}",
            String::from_utf8_lossy(&output.stderr)
        )
        .into())
    }
}

/// Reads an R2 object through Wrangler's local backend, bypassing the Worker's
/// D1 metadata and HTTP routes. This is the direct storage proof required by
/// RUB-317.
fn r2_object_get(
    context: &Context,
    content_hash: &str,
) -> Result<std::process::Output, Box<dyn Error>> {
    let working_directory = env!("CARGO_MANIFEST_DIR").to_string();
    let object_path = format!("artfct-blobs/blobs/{content_hash}");
    let output = Command::new(&context.wrangler)
        .current_dir(&working_directory)
        .args([
            "r2",
            "object",
            "get",
            &object_path,
            "--local",
            "--persist-to",
            &context.persist_to,
            "--pipe",
        ])
        .output()
        .map_err(|error| format!("failed to launch Wrangler R2 read: {error}"))?;
    Ok(output)
}

fn count_value(context: &Context, sql: &str, key: &str) -> Result<i64, Box<dyn Error>> {
    Ok(d1_row(context, sql)?[key]
        .as_i64()
        .ok_or_else(|| format!("D1 result field {key} was not an integer"))?)
}

#[tokio::test]
#[ignore = "requires an isolated local Wrangler Worker"]
async fn an_expired_lock_from_a_crashed_holder_is_reclaimed() -> Result<(), Box<dyn Error>> {
    let Some(context) = context() else {
        return Ok(());
    };
    let client = RetryingClient::new();
    let bytes = unique_html("expired-lock");
    let hash = sha256(&bytes);

    d1_execute(
        &context,
        &format!(
            "INSERT INTO blob_locks (content_hash, owner, expires_at) VALUES ('{hash}', 'crashed-holder', '2000-01-01T00:00:00Z')"
        ),
    )?;

    let (id, _) = create_and_upload(&context, &client, &bytes, json!({})).await?;
    assert_eq!(
        count_value(
            &context,
            &format!("SELECT COUNT(*) AS count FROM artifacts WHERE id = '{id}'"),
            "count"
        )?,
        1,
        "an expired lease must not block a new artifact"
    );
    assert_eq!(
        count_value(
            &context,
            &format!("SELECT COUNT(*) AS count FROM blob_locks WHERE content_hash = '{hash}'"),
            "count"
        )?,
        0,
        "a completed operation must release its reclaimed lease"
    );
    Ok(())
}

#[tokio::test]
#[ignore = "requires an isolated local Wrangler Worker"]
async fn an_active_lock_reports_contention_without_writing_an_artifact(
) -> Result<(), Box<dyn Error>> {
    let Some(context) = context() else {
        return Ok(());
    };
    let client = RetryingClient::new();
    let bytes = unique_html("active-lock");
    let hash = sha256(&bytes);

    d1_execute(
        &context,
        &format!(
            "INSERT INTO blob_locks (content_hash, owner, expires_at) VALUES ('{hash}', 'live-holder', '2099-01-01T00:00:00Z')"
        ),
    )?;

    let response = client
        .post(format!("{}/v1/artifacts", context.base))
        .bearer_auth(&context.token)
        .json(&permanent_payload(&bytes, json!({})))
        .send()
        .await?;
    assert_eq!(response.status(), reqwest::StatusCode::SERVICE_UNAVAILABLE);
    let error: Value = response.json().await?;
    assert_eq!(error["error"]["code"], "internal_error");
    assert_eq!(error["error"]["details"]["retryable"], true);
    assert_eq!(
        count_value(
            &context,
            &format!("SELECT COUNT(*) AS count FROM artifacts WHERE content_hash = '{hash}'"),
            "count"
        )?,
        0,
        "contention must fail before the artifact row is written"
    );
    assert_eq!(
        count_value(
            &context,
            &format!("SELECT COUNT(*) AS count FROM blob_locks WHERE content_hash = '{hash}' AND owner = 'live-holder'"),
            "count"
        )?,
        1,
        "a live holder's lease must not be released by another caller"
    );
    d1_execute(
        &context,
        &format!("DELETE FROM blob_locks WHERE content_hash = '{hash}'"),
    )?;
    Ok(())
}

#[tokio::test]
#[ignore = "requires an isolated local Wrangler Worker"]
async fn permanent_roundtrip_stores_d1_and_r2() -> Result<(), Box<dyn Error>> {
    let Some(context) = context() else {
        return Ok(());
    };
    let client = RetryingClient::new();
    let bytes = unique_html("roundtrip");
    let (id, hash) = create_and_upload(&context, &client, &bytes, json!({"agent": "integration", "repo_url": "https://github.com/example/repo", "commit_sha": "abcdef123456", "sources": {"agent": "self_reported"}})).await?;
    let preview = client
        .get(format!("{}/p/{id}", context.base))
        .send()
        .await?;
    assert_eq!(preview.status(), reqwest::StatusCode::OK);
    assert_eq!(preview.bytes().await?.as_ref(), bytes.as_slice());
    assert_eq!(
        count_value(
            &context,
            &format!("SELECT COUNT(*) AS count FROM blobs WHERE content_hash = '{hash}'"),
            "count"
        )?,
        1
    );
    Ok(())
}

#[tokio::test]
#[ignore = "requires an isolated local Wrangler Worker and Laravel event receiver"]
async fn caller_written_request_headers_change_real_anonymous_view_keys(
) -> Result<(), Box<dyn Error>> {
    let Some(context) = context() else {
        return Ok(());
    };
    for name in [
        "ARTFCT_VISITOR_KEY_SECRET",
        "ARTFCT_WORKER_EVENT_SECRET",
        "ARTFCT_WORKER_EVENT_URL",
    ] {
        assert!(env::var_os(name).is_some(), "{name} must be configured");
    }
    let client = RetryingClient::new();
    let bytes = unique_html("anonymous-view-ranking");
    let (artifact_id, _) = create_and_upload(&context, &client, &bytes, json!({})).await?;

    let views: Result<Vec<Option<String>>, Box<dyn Error>> = async {
        let requests = [
            ("198.51.100.10", "RUB-423-test/1"),
            ("198.51.100.11", "RUB-423-test/1"),
            ("198.51.100.10", "RUB-423-test/2"),
        ];

        for (ip, user_agent) in requests {
            let response = client
                .get(format!("{}/p/{artifact_id}", context.base))
                .header(
                    reqwest::header::HeaderName::from_static("cf-connecting-ip"),
                    ip,
                )
                .header(reqwest::header::USER_AGENT, user_agent)
                .send()
                .await?;
            if response.status() != reqwest::StatusCode::OK {
                return Err(
                    format!("anonymous artifact view returned {}", response.status()).into(),
                );
            }
            if response.bytes().await?.as_ref() != bytes.as_slice() {
                return Err("anonymous artifact view returned unexpected bytes".into());
            }
        }

        let deadline = std::time::Instant::now() + std::time::Duration::from_secs(20);
        loop {
            let keys = anonymous_viewer_keys(&artifact_id)?;
            if keys.len() == requests.len() {
                break Ok(keys);
            }
            if std::time::Instant::now() >= deadline {
                break Err(format!(
                    "timed out waiting for {} anonymous view events; received {}",
                    requests.len(),
                    keys.len()
                )
                .into());
            }
            tokio::time::sleep(std::time::Duration::from_millis(500)).await;
        }
    }
    .await;

    let cleanup = client
        .delete(format!("{}/v1/artifacts/{artifact_id}", context.base))
        .bearer_auth(&context.token)
        .send()
        .await?;
    assert_eq!(cleanup.status(), reqwest::StatusCode::NO_CONTENT);

    let keys = views?;
    assert!(keys.iter().all(Option::is_some));
    let distinct_keys: std::collections::HashSet<&str> =
        keys.iter().filter_map(Option::as_deref).collect();
    assert_eq!(
        distinct_keys.len(),
        3,
        "changing CF-Connecting-IP or User-Agent on real /p requests must vary anonymous ranking keys"
    );

    Ok(())
}

#[tokio::test]
#[ignore = "requires an isolated local Wrangler Worker"]
async fn signed_link_opens_the_artifact_on_its_isolated_origin() -> Result<(), Box<dyn Error>> {
    let Some(context) = context() else {
        return Ok(());
    };
    let client = RetryingClient::new();
    let bytes = unique_html("isolated-origin");
    let (id, _) = create_and_upload(&context, &client, &bytes, json!({})).await?;
    let other_bytes = unique_html("isolated-origin-other");
    let (other_id, _) = create_and_upload(&context, &client, &other_bytes, json!({})).await?;
    // Public artifacts open on their own isolated origin without a token
    // (RUB-438), so the token checks below run against team-shared ones.
    for team_id in [&id, &other_id] {
        d1_execute(
            &context,
            &format!("UPDATE artifacts SET tier = 'secure' WHERE id = '{team_id}'"),
        )?;
    }
    let expires_at_unix = std::time::SystemTime::now()
        .duration_since(std::time::UNIX_EPOCH)?
        .as_secs() as i64
        + 3600;
    let token = mint_access_token(&context.token_secret, &id, expires_at_unix);
    let origin = isolated_origin(&context.org, &id, &context.origin_suffix);
    let path = format!("{}/p/{id}", context.base);

    // 1. The link opens. This is the assertion RUB-365 is about: not the tab a
    //    click asks for, but the artifact body served on the origin the link
    //    names. The isolated hostname resolves nowhere from this machine and
    //    terminates no TLS locally, so it travels in the `Host` header instead
    //    of the URL authority — the Worker's decision is a function of that
    //    header alone.
    let opened = client
        .get(format!("{path}?token={token}"))
        .header(reqwest::header::HOST, origin.as_str())
        .send()
        .await?;
    assert_eq!(
        opened.status(),
        reqwest::StatusCode::OK,
        "the signed link did not open {origin}{path}"
    );
    let opened_policy = response_header(&opened, "content-security-policy");
    assert_eq!(
        opened.bytes().await?.as_ref(),
        bytes.as_slice(),
        "the isolated origin served something other than the artifact body"
    );
    assert!(
        opened_policy.starts_with("default-src 'self';"),
        "a 200 on the isolated host has to be the authorized branch's per-artifact policy, not \
         the shared-origin one that is also served there: {opened_policy}"
    );

    // 2. A token minted for a different artifact is not a credential for this
    //    one, even with host and tenant both matching.
    let other_token = mint_access_token(&context.token_secret, &other_id, expires_at_unix);
    let wrong_token = client
        .get(format!("{path}?token={other_token}"))
        .header(reqwest::header::HOST, origin.as_str())
        .send()
        .await?;
    assert_eq!(
        wrong_token.status(),
        reqwest::StatusCode::FORBIDDEN,
        "a token for {other_id} must not open {id}"
    );

    // 3. The host's artifact id must name the artifact being served: this
    //    artifact's own valid token presented on another artifact's origin.
    let wrong_artifact_origin = isolated_origin(&context.org, &other_id, &context.origin_suffix);
    let wrong_artifact = client
        .get(format!("{path}?token={token}"))
        .header(reqwest::header::HOST, wrong_artifact_origin.as_str())
        .send()
        .await?;
    assert_eq!(
        wrong_artifact.status(),
        reqwest::StatusCode::FORBIDDEN,
        "{wrong_artifact_origin} is not {id}'s origin"
    );

    // 4. ...and the host's tenant must be the org that owns the artifact: a
    //    token valid for this artifact, on a host naming another tenant.
    let wrong_tenant_origin = isolated_origin(
        &format!("{}-other", context.org),
        &id,
        &context.origin_suffix,
    );
    let wrong_tenant = client
        .get(format!("{path}?token={token}"))
        .header(reqwest::header::HOST, wrong_tenant_origin.as_str())
        .send()
        .await?;
    assert_eq!(
        wrong_tenant.status(),
        reqwest::StatusCode::FORBIDDEN,
        "{wrong_tenant_origin} does not name the org that owns {id}"
    );

    // 5. A non-isolated host keeps the shared-origin behaviour the isolated
    //    check must not have tightened. `artfct.dev` is no artifact's origin,
    //    so the isolated branch never runs: a public artifact is still served
    //    with no credential at all, under the policy it had before isolated
    //    origins existed. (Back to public: the token checks above needed it
    //    team-shared.)
    d1_execute(
        &context,
        &format!("UPDATE artifacts SET tier = 'public' WHERE id = '{id}'"),
    )?;
    let shared = client
        .get(&path)
        .header(reqwest::header::HOST, "artfct.dev")
        .send()
        .await?;
    assert_eq!(shared.status(), reqwest::StatusCode::OK);
    let shared_policy = response_header(&shared, "content-security-policy");
    assert_eq!(shared.bytes().await?.as_ref(), bytes.as_slice());
    assert!(
        shared_policy.starts_with("default-src 'self' https:;"),
        "the shared origin must keep the policy it had before isolated origins: {shared_policy}"
    );

    // 6. ...and the credential that behaviour demands is still demanded: a
    //    secure artifact on the shared origin is refused without an org token
    //    (404, the same as a missing artifact, so the URL never confirms that
    //    an id exists), and serves with one.
    let secure_bytes = unique_html("isolated-origin-secure");
    let (secure_id, _) = create_and_upload_payload(
        &context,
        &client,
        &secure_bytes,
        permanent_payload_with_tier(&secure_bytes, json!({}), "secure"),
    )
    .await?;
    let secure_path = format!("{}/p/{secure_id}", context.base);
    let uncredentialed = client
        .get(&secure_path)
        .header(reqwest::header::HOST, "artfct.dev")
        .send()
        .await?;
    assert_eq!(
        uncredentialed.status(),
        reqwest::StatusCode::NOT_FOUND,
        "a secure artifact on the shared origin still requires an org credential"
    );
    let credentialed = client
        .get(&secure_path)
        .bearer_auth(&context.token)
        .header(reqwest::header::HOST, "artfct.dev")
        .send()
        .await?;
    assert_eq!(credentialed.status(), reqwest::StatusCode::OK);
    assert_eq!(
        credentialed.bytes().await?.as_ref(),
        secure_bytes.as_slice()
    );
    Ok(())
}

#[tokio::test]
#[ignore = "requires an isolated local Wrangler Worker"]
async fn signed_entrypoint_cookie_authorizes_bundle_subresources() -> Result<(), Box<dyn Error>> {
    let Some(context) = context() else {
        return Ok(());
    };
    let client = RetryingClient::new();
    let index = b"<!doctype html><script src=\"assets/app.js\"></script>";
    let script = b"console.log('bundle');";
    let id = create_and_upload_bundle(
        &context,
        &client,
        &[
            ("index.html", index, "text/html; charset=utf-8"),
            ("assets/app.js", script, "application/javascript"),
        ],
        "index.html",
    )
    .await?;
    // Team-shared, so the cookie is what authorizes the subresources (a
    // public artifact would open on its isolated origin without one).
    d1_execute(
        &context,
        &format!("UPDATE artifacts SET tier = 'secure' WHERE id = '{id}'"),
    )?;
    let expires_at_unix = std::time::SystemTime::now()
        .duration_since(std::time::UNIX_EPOCH)?
        .as_secs() as i64
        + 3600;
    let token = mint_access_token(&context.token_secret, &id, expires_at_unix);
    let origin = isolated_origin(&context.org, &id, &context.origin_suffix);
    let entrypoint = client
        .get(format!("{}/p/{id}?token={token}", context.base))
        .header(reqwest::header::HOST, origin.as_str())
        .send()
        .await?;
    assert_eq!(entrypoint.status(), reqwest::StatusCode::OK);
    let cookie = response_header(&entrypoint, "set-cookie");
    assert!(cookie.starts_with("artfct_access="));
    let cookie = cookie
        .split(';')
        .next()
        .ok_or("set-cookie omitted the access cookie")?;

    let asset = client
        .get(format!("{}/p/{id}/assets/app.js", context.base))
        .header(reqwest::header::HOST, origin.as_str())
        .header(reqwest::header::COOKIE, cookie)
        .send()
        .await?;
    assert_eq!(asset.status(), reqwest::StatusCode::OK);
    assert_eq!(asset.bytes().await?.as_ref(), script);

    let without_cookie = client
        .get(format!("{}/p/{id}/assets/app.js", context.base))
        .header(reqwest::header::HOST, origin.as_str())
        .send()
        .await?;
    assert_eq!(
        without_cookie.status(),
        reqwest::StatusCode::FORBIDDEN,
        "subresources must remain forbidden without the host-only access cookie"
    );
    Ok(())
}

#[tokio::test]
#[ignore = "requires an isolated local Wrangler Worker"]
async fn six_megabyte_permanent_file_succeeds() -> Result<(), Box<dyn Error>> {
    let Some(context) = context() else {
        return Ok(());
    };
    let client = RetryingClient::new();
    let bytes = vec![b'x'; 6 * 1024 * 1024];
    let (id, _) = create_and_upload(&context, &client, &bytes, json!({})).await?;
    assert_eq!(
        client
            .get(format!("{}/p/{id}", context.base))
            .send()
            .await?
            .status(),
        reqwest::StatusCode::OK
    );
    Ok(())
}

#[tokio::test]
#[ignore = "requires an isolated local Wrangler Worker"]
async fn reposting_identical_content_keeps_one_artifact_and_one_blob() -> Result<(), Box<dyn Error>>
{
    let Some(context) = context() else {
        return Ok(());
    };
    let client = RetryingClient::new();
    let bytes = unique_html("duplicate");
    let (first, hash) = create_and_upload(&context, &client, &bytes, json!({})).await?;
    let (second, second_hash) = create_and_upload(&context, &client, &bytes, json!({})).await?;
    assert_eq!(first, second);
    assert_eq!(hash, second_hash);
    // New artifacts get a random 13-character stable id (RUB-437).
    assert_eq!(first.len(), 13);
    assert_eq!(
        count_value(
            &context,
            &format!("SELECT COUNT(*) AS count FROM artifacts WHERE id = '{first}'"),
            "count"
        )?,
        1,
        "a re-post of identical bytes names the same artifact, so it must not add a row"
    );
    assert_eq!(
        count_value(
            &context,
            &format!("SELECT COUNT(*) AS count FROM artifacts WHERE id = '{first}' AND content_hash != '{hash}'"),
            "count"
        )?,
        1,
        "artifact content_hash stores the canonical bundle id, not a file SHA"
    );
    assert_eq!(
        count_value(
            &context,
            &format!("SELECT COUNT(*) AS count FROM blobs WHERE content_hash = '{hash}'"),
            "count"
        )?,
        1
    );
    assert_eq!(
        count_value(
            &context,
            &format!("SELECT ref_count AS count FROM blobs WHERE content_hash = '{hash}'"),
            "count"
        )?,
        1,
        "one artifact referencing the bundle once must leave the refcount at one"
    );
    Ok(())
}

#[tokio::test]
#[ignore = "requires an isolated local Wrangler Worker"]
async fn concurrent_identical_creates_keep_one_artifact_and_one_reference(
) -> Result<(), Box<dyn Error>> {
    let Some(context) = context() else {
        return Ok(());
    };
    let client = RetryingClient::new();
    let bytes = unique_html("concurrent");

    // Six at once rather than two. A sequential re-post is closed by the
    // pre-flight existence check; what this exercises is the window where
    // several creates pass that check before any of them inserts. Then the
    // unique index refuses all but one, and the losers have to return the
    // winner's artifact rather than a 500 -- a re-post that fails depending on
    // timing is exactly the flakiness this is meant to rule out.
    let results = {
        let (a, b, c, d, e, f) = tokio::join!(
            create_and_upload(&context, &client, &bytes, json!({})),
            create_and_upload(&context, &client, &bytes, json!({})),
            create_and_upload(&context, &client, &bytes, json!({})),
            create_and_upload(&context, &client, &bytes, json!({})),
            create_and_upload(&context, &client, &bytes, json!({})),
            create_and_upload(&context, &client, &bytes, json!({})),
        );

        [a, b, c, d, e, f]
    };

    let mut ids = std::collections::HashSet::new();
    let mut hash = String::new();
    for (index, result) in results.into_iter().enumerate() {
        let (id, bundle_hash) =
            result.map_err(|error| format!("concurrent create {} failed: {error}", index + 1))?;
        ids.insert(id);
        hash = bundle_hash;
    }
    assert_eq!(
        ids.len(),
        1,
        "every concurrent create must name the same artifact"
    );
    let id = ids
        .into_iter()
        .next()
        .expect("at least one create returned");

    assert_eq!(
        count_value(
            &context,
            &format!("SELECT COUNT(*) AS count FROM artifacts WHERE id = '{id}'"),
            "count"
        )?,
        1,
        "six concurrent posts of identical bytes must leave exactly one row"
    );
    assert_eq!(
        count_value(
            &context,
            &format!("SELECT ref_count AS count FROM blobs WHERE content_hash = '{hash}'"),
            "count"
        )?,
        1,
        "and exactly one reference, however many creates lost the race"
    );
    Ok(())
}

#[tokio::test]
#[ignore = "requires an isolated local Wrangler Worker"]
async fn concurrent_deletes_leave_no_artifact_and_no_orphan_blob() -> Result<(), Box<dyn Error>> {
    let Some(context) = context() else {
        return Ok(());
    };
    let client = RetryingClient::new();
    let bytes = unique_html("concurrent-delete");
    let (id, hash) = create_and_upload(&context, &client, &bytes, json!({})).await?;
    create_and_upload(&context, &client, &bytes, json!({})).await?;
    let left = client
        .delete(format!("{}/v1/artifacts/{id}", context.base))
        .bearer_auth(&context.token)
        .send();
    let right = client
        .delete(format!("{}/v1/artifacts/{id}", context.base))
        .bearer_auth(&context.token)
        .send();
    let (left, right) = tokio::join!(left, right);
    // Exactly one delete removes the artifact; the other finds nothing left.
    // Both answering 204 was only possible while a duplicate row survived the
    // first -- which is the false success RUB-371 describes, and why the old
    // assertion in this test had to change rather than the code.
    let mut no_content = 0;
    let mut not_found = 0;
    for status in [left?.status(), right?.status()] {
        if status == reqwest::StatusCode::NO_CONTENT {
            no_content += 1;
        }
        if status == reqwest::StatusCode::NOT_FOUND {
            not_found += 1;
        }
    }
    assert_eq!(no_content, 1, "one delete should have removed the artifact");
    assert_eq!(
        not_found, 1,
        "the other should report nothing left to delete, not a second success"
    );
    assert_eq!(
        count_value(
            &context,
            &format!("SELECT COUNT(*) AS count FROM blobs WHERE content_hash = '{hash}'"),
            "count"
        )?,
        0
    );
    assert_eq!(
        client
            .get(format!("{}/v1/blobs/{hash}", context.base))
            .bearer_auth(&context.token)
            .send()
            .await?
            .status(),
        reqwest::StatusCode::NOT_FOUND
    );
    Ok(())
}

#[tokio::test]
#[ignore = "requires an isolated local Wrangler Worker"]
async fn deleting_a_permanent_artifact_stops_serving_it() -> Result<(), Box<dyn Error>> {
    let Some(context) = context() else {
        return Ok(());
    };
    let client = RetryingClient::new();
    let bytes = unique_html("delete-stops-serving");
    let (id, _hash) = create_and_upload(&context, &client, &bytes, json!({})).await?;

    // Serving first, so the 404 below means the delete did something rather
    // than the artifact never having been reachable at all.
    let serving = client
        .get(format!("{}/p/{id}", context.base))
        .send()
        .await?;
    assert_eq!(serving.status(), reqwest::StatusCode::OK);

    let delete = client
        .delete(format!("{}/v1/artifacts/{id}", context.base))
        .bearer_auth(&context.token)
        .send()
        .await?;
    assert_eq!(delete.status(), reqwest::StatusCode::NO_CONTENT);

    // The assertion RUB-371 asks for. A DELETE that reported success must stop
    // the artifact serving; before the fix a duplicate row kept answering here
    // while the response said the artifact was gone.
    let after = client
        .get(format!("{}/p/{id}", context.base))
        .send()
        .await?;
    assert_eq!(
        after.status(),
        reqwest::StatusCode::NOT_FOUND,
        "a DELETE that answered 204 left the artifact serving"
    );
    assert_eq!(
        count_value(
            &context,
            &format!("SELECT COUNT(*) AS count FROM artifacts WHERE id = '{id}'"),
            "count"
        )?,
        0,
        "no row for the deleted id may survive, not even a revoked or duplicate one"
    );
    Ok(())
}

#[tokio::test]
#[ignore = "requires an isolated local Wrangler Worker"]
async fn create_delete_create_delete_returns_refcount_to_zero() -> Result<(), Box<dyn Error>> {
    let Some(context) = context() else {
        return Ok(());
    };
    let client = RetryingClient::new();
    let bytes = unique_html("refcount-cycle");

    // Two full cycles over the same bytes. The second create must succeed -- the
    // first delete removed the row, so its id is free again, which the unique
    // index is partial on revocation to allow -- and each delete must bring the
    // refcount back to zero. An inflated count left the blob unreclaimable
    // forever, which is the half of RUB-371 that outlives the duplicate row.
    for cycle in 1..=2 {
        let (id, hash) = create_and_upload(&context, &client, &bytes, json!({})).await?;

        let delete = client
            .delete(format!("{}/v1/artifacts/{id}", context.base))
            .bearer_auth(&context.token)
            .send()
            .await?;
        assert_eq!(
            delete.status(),
            reqwest::StatusCode::NO_CONTENT,
            "cycle {cycle}"
        );
        assert_eq!(
            count_value(
                &context,
                &format!("SELECT COUNT(*) AS count FROM blobs WHERE content_hash = '{hash}'"),
                "count"
            )?,
            0,
            "cycle {cycle}: the blob row should be gone once its last reference went"
        );
    }
    Ok(())
}

#[tokio::test]
#[ignore = "requires an isolated local Wrangler Worker"]
async fn create_raced_with_final_delete_keeps_live_blob() -> Result<(), Box<dyn Error>> {
    let Some(context) = context() else {
        return Ok(());
    };
    let client = RetryingClient::new();
    let bytes = unique_html("create-delete-race");
    let (id, hash) = create_and_upload(&context, &client, &bytes, json!({})).await?;

    let delete = client
        .delete(format!("{}/v1/artifacts/{id}", context.base))
        .bearer_auth(&context.token)
        .send();
    let create = create_and_upload(&context, &client, &bytes, json!({}));
    let (deleted, created) = tokio::join!(delete, create);
    assert_eq!(deleted?.status(), reqwest::StatusCode::NO_CONTENT);
    let (created_id, created_hash) = created?;
    assert_eq!(created_hash, hash);

    // Ids are random since RUB-437, so the re-post either deduped onto the
    // artifact before the delete removed it, or landed after the delete and
    // minted a fresh id. Only the second leaves a live artifact; that is the
    // case where the blob must survive. In the first case the blob may go.
    if created_id == id {
        return Ok(());
    }
    let id = created_id;
    assert_eq!(
        count_value(
            &context,
            &format!("SELECT COUNT(*) AS count FROM artifacts WHERE id = '{id}'"),
            "count"
        )?,
        1
    );
    assert_eq!(
        count_value(
            &context,
            &format!("SELECT ref_count AS count FROM blobs WHERE content_hash = '{hash}'"),
            "count"
        )?,
        1
    );
    assert_eq!(
        count_value(
            &context,
            &format!("SELECT COUNT(*) AS count FROM files WHERE content_hash = '{hash}'"),
            "count"
        )?,
        1
    );
    let blob = client
        .get(format!("{}/v1/blobs/{hash}", context.base))
        .bearer_auth(&context.token)
        .send()
        .await?
        .error_for_status()?
        .bytes()
        .await?;
    assert_eq!(blob.as_ref(), bytes.as_slice());
    assert_eq!(
        client
            .get(format!("{}/p/{id}", context.base))
            .send()
            .await?
            .status(),
        reqwest::StatusCode::OK
    );
    Ok(())
}

#[tokio::test]
#[ignore = "requires an isolated local Wrangler Worker"]
async fn delete_once_keeps_shared_blob_and_delete_last_removes_it() -> Result<(), Box<dyn Error>> {
    let Some(context) = context() else {
        return Ok(());
    };
    let client = RetryingClient::new();
    let shared = unique_html("delete-shared");
    let extra = unique_html("delete-extra");
    let shared_hash = sha256(&shared);

    // Two genuinely different bundles that share one file's blob. Posting the
    // same bundle twice no longer yields two artifacts -- that is the
    // idempotency RUB-371 fixes -- so the shared reference this test is about
    // has to come from two artifacts whose manifests differ while one file's
    // bytes are identical.
    let (first, _) = create_and_upload(&context, &client, &shared, json!({})).await?;
    let second = create_and_upload_bundle(
        &context,
        &client,
        &[
            ("index.html", shared.as_slice(), "text/html; charset=utf-8"),
            ("extra.txt", extra.as_slice(), "text/plain; charset=utf-8"),
        ],
        "index.html",
    )
    .await?;
    assert_ne!(
        first, second,
        "distinct manifests must get distinct artifact ids"
    );

    // Deleting one sharer drops one reference. The blob must survive, the
    // deleted artifact must stop serving, and the other must be untouched.
    let response = client
        .delete(format!("{}/v1/artifacts/{first}", context.base))
        .bearer_auth(&context.token)
        .send()
        .await?;
    assert_eq!(response.status(), reqwest::StatusCode::NO_CONTENT);
    assert_eq!(
        count_value(
            &context,
            &format!("SELECT ref_count AS count FROM blobs WHERE content_hash = '{shared_hash}'"),
            "count"
        )?,
        1,
        "the surviving artifact still references this blob"
    );
    assert_eq!(
        client
            .get(format!("{}/p/{first}", context.base))
            .send()
            .await?
            .status(),
        reqwest::StatusCode::NOT_FOUND
    );
    assert_eq!(
        client
            .get(format!("{}/p/{second}", context.base))
            .send()
            .await?
            .status(),
        reqwest::StatusCode::OK,
        "deleting one sharer must not disturb the other"
    );

    // Deleting the last sharer removes the last reference, so the blob goes too.
    let response = client
        .delete(format!("{}/v1/artifacts/{second}", context.base))
        .bearer_auth(&context.token)
        .send()
        .await?;
    assert_eq!(response.status(), reqwest::StatusCode::NO_CONTENT);
    assert_eq!(
        count_value(
            &context,
            &format!("SELECT COUNT(*) AS count FROM blobs WHERE content_hash = '{shared_hash}'"),
            "count"
        )?,
        0
    );
    assert_eq!(
        client
            .get(format!("{}/v1/blobs/{shared_hash}", context.base))
            .bearer_auth(&context.token)
            .send()
            .await?
            .status(),
        reqwest::StatusCode::NOT_FOUND
    );
    Ok(())
}

#[tokio::test]
#[ignore = "requires an isolated local Wrangler Worker and local R2 storage"]
async fn gdpr_erasure_removes_bytes_from_r2() -> Result<(), Box<dyn Error>> {
    let Some(context) = context() else {
        return Ok(());
    };
    let client = RetryingClient::new();
    let shared = unique_html("erasure-shared");
    let first_only = unique_html("erasure-first-only");
    let second_only = unique_html("erasure-second-only");
    let shared_hash = sha256(&shared);
    let first_only_hash = sha256(&first_only);
    let second_only_hash = sha256(&second_only);

    // The artifacts have distinct manifests but share one content-addressed
    // blob. Each also owns a unique object so the first deletion proves that
    // R2 removes only unreferenced bytes.
    let first = create_and_upload_bundle(
        &context,
        &client,
        &[
            ("index.html", shared.as_slice(), "text/html; charset=utf-8"),
            (
                "first.txt",
                first_only.as_slice(),
                "text/plain; charset=utf-8",
            ),
        ],
        "index.html",
    )
    .await?;
    let second = create_and_upload_bundle(
        &context,
        &client,
        &[
            ("index.html", shared.as_slice(), "text/html; charset=utf-8"),
            (
                "second.txt",
                second_only.as_slice(),
                "text/plain; charset=utf-8",
            ),
        ],
        "index.html",
    )
    .await?;
    assert_ne!(first, second);

    for hash in [&shared_hash, &first_only_hash, &second_only_hash] {
        let object = r2_object_get(&context, hash)?;
        assert!(
            object.status.success(),
            "R2 object blobs/{hash} should exist before erasure: {}",
            String::from_utf8_lossy(&object.stderr)
        );
    }

    let first_delete = client
        .delete(format!(
            "{}/v1/internal/orgs/{}/governance/artifacts/{first}",
            context.base, context.org
        ))
        .header(
            reqwest::header::AUTHORIZATION,
            &format!("Bearer {}", context.governance_secret),
        )
        .send()
        .await?;
    assert_eq!(first_delete.status(), reqwest::StatusCode::NO_CONTENT);
    assert_eq!(
        client
            .get(format!("{}/v1/blobs/{shared_hash}", context.base))
            .bearer_auth(&context.token)
            .send()
            .await?
            .status(),
        reqwest::StatusCode::OK,
        "shared HTTP content must remain after erasing one artifact"
    );
    assert!(
        r2_object_get(&context, &shared_hash)?.status.success(),
        "direct R2 read of a shared object must still succeed"
    );
    assert!(
        !r2_object_get(&context, &first_only_hash)?.status.success(),
        "direct R2 read of the first artifact's unique object must fail after erasure"
    );

    let second_delete = client
        .delete(format!(
            "{}/v1/internal/orgs/{}/governance/artifacts/{second}",
            context.base, context.org
        ))
        .header(
            reqwest::header::AUTHORIZATION,
            &format!("Bearer {}", context.governance_secret),
        )
        .send()
        .await?;
    assert_eq!(second_delete.status(), reqwest::StatusCode::NO_CONTENT);
    assert_eq!(
        client
            .get(format!("{}/v1/blobs/{shared_hash}", context.base))
            .bearer_auth(&context.token)
            .send()
            .await?
            .status(),
        reqwest::StatusCode::NOT_FOUND,
        "the final erasure must remove the shared blob from the HTTP path"
    );
    for hash in [&shared_hash, &second_only_hash] {
        let object = r2_object_get(&context, hash)?;
        assert!(
            !object.status.success(),
            "direct R2 read of erased object blobs/{hash} must fail"
        );
    }

    Ok(())
}

#[tokio::test]
#[ignore = "requires an isolated local Wrangler Worker and local R2 storage"]
async fn orphan_sweep_retries_zero_ref_blob_after_r2_recovers() -> Result<(), Box<dyn Error>> {
    let Some(context) = context() else {
        return Ok(());
    };
    let client = RetryingClient::new();
    let bytes = unique_html("orphan-sweep-retry");
    let (id, hash) = create_and_upload(&context, &client, &bytes, json!({})).await?;

    // Reproduce the persisted state left by a failed R2 delete: the artifact
    // and file reference are gone, while the zero-ref metadata row and object
    // remain available for sweep-orphans.
    d1_execute(
        &context,
        &format!("DELETE FROM files WHERE content_hash = '{hash}'"),
    )?;
    d1_execute(
        &context,
        &format!("DELETE FROM artifacts WHERE id = '{id}'"),
    )?;
    d1_execute(
        &context,
        &format!("UPDATE blobs SET ref_count = 0 WHERE content_hash = '{hash}'"),
    )?;
    assert_eq!(
        count_value(
            &context,
            &format!("SELECT ref_count AS count FROM blobs WHERE content_hash = '{hash}'"),
            "count"
        )?,
        0,
        "the retry key must remain in D1 at ref_count zero"
    );
    assert!(
        r2_object_get(&context, &hash)?.status.success(),
        "the simulated failed delete leaves the R2 object for a later sweep"
    );

    let sweep = client
        .post(format!(
            "{}/v1/internal/orgs/{}/governance/sweep-orphans",
            context.base, context.org
        ))
        .header(
            reqwest::header::AUTHORIZATION,
            &format!("Bearer {}", context.governance_secret),
        )
        .send()
        .await?;
    assert_eq!(sweep.status(), reqwest::StatusCode::OK);
    let body: Value = sweep.json().await?;
    assert!(body["removed"].as_u64().unwrap_or_default() >= 1);
    assert_eq!(
        count_value(
            &context,
            &format!("SELECT COUNT(*) AS count FROM blobs WHERE content_hash = '{hash}'"),
            "count"
        )?,
        0,
        "a successful retry removes the D1 metadata after R2"
    );
    assert!(
        !r2_object_get(&context, &hash)?.status.success(),
        "a successful retry removes the orphaned R2 object"
    );

    Ok(())
}

#[tokio::test]
#[ignore = "requires an isolated local Wrangler Worker"]
async fn provenance_columns_and_complete_json_survive() -> Result<(), Box<dyn Error>> {
    let Some(context) = context() else {
        return Ok(());
    };
    let client = RetryingClient::new();
    let bytes = unique_html("provenance");
    let (id, _) = create_and_upload(&context, &client, &bytes, json!({"agent": "integration-agent", "repo_url": "https://github.com/example/provenance", "commit_sha": "0123456789abcdef", "sources": {"agent": "process", "repo_url": "process", "commit_sha": "process"}, "unknown_tail": {"retained": true}})).await?;
    let row = d1_row(&context, &format!("SELECT p.agent, p.repo_url, p.commit_sha, p.payload FROM provenance p JOIN artifacts a ON a.row_id = p.artifact_row_id WHERE a.id = '{id}' ORDER BY a.row_id DESC LIMIT 1"))?;
    assert_eq!(row["agent"], "integration-agent");
    assert_eq!(row["repo_url"], "https://github.com/example/provenance");
    assert_eq!(row["commit_sha"], "0123456789abcdef");
    let payload: Value =
        serde_json::from_str(row["payload"].as_str().ok_or("payload was not text")?)?;
    assert_eq!(payload["unknown_tail"]["retained"], true);
    assert_eq!(payload["sources"]["repo_url"], "process");
    Ok(())
}

#[tokio::test]
#[ignore = "requires an isolated local Wrangler Worker"]
async fn export_writes_byte_identical_blobs() -> Result<(), Box<dyn Error>> {
    let Some(context) = context() else {
        return Ok(());
    };
    let client = RetryingClient::new();
    let bytes = unique_html("export");
    let (_, hash) = create_and_upload(&context, &client, &bytes, json!({})).await?;
    let response: Value = client
        .get(format!("{}/v1/orgs/{}/export", context.base, context.org))
        .bearer_auth(&context.token)
        .send()
        .await?
        .error_for_status()?
        .json()
        .await?;
    let url = response["blobs"][&hash]
        .as_str()
        .ok_or("export omitted this test's blob URL")?;
    let exported = client
        .get(url)
        .bearer_auth(&context.token)
        .send()
        .await?
        .error_for_status()?
        .bytes()
        .await?;
    assert_eq!(exported.as_ref(), bytes.as_slice());
    Ok(())
}

#[tokio::test]
#[ignore = "requires an isolated local Wrangler Worker"]
async fn export_metadata_round_trips() -> Result<(), Box<dyn Error>> {
    let Some(context) = context() else {
        return Ok(());
    };
    let client = RetryingClient::new();
    let bytes = unique_html("worker-export");
    let (_, hash) = create_and_upload(&context, &client, &bytes, json!({})).await?;
    let metadata: Value = client
        .get(format!("{}/v1/orgs/{}/export", context.base, context.org))
        .bearer_auth(&context.token)
        .send()
        .await?
        .error_for_status()?
        .json()
        .await?;
    assert!(metadata["artifacts"].is_array());
    let blob_url = metadata["blobs"][&hash]
        .as_str()
        .ok_or("export omitted the uploaded blob")?;
    let blob = client
        .get(blob_url)
        .bearer_auth(&context.token)
        .send()
        .await?
        .error_for_status()?
        .bytes()
        .await?;
    assert_eq!(blob.as_ref(), bytes.as_slice());
    Ok(())
}

#[tokio::test]
#[ignore = "requires an isolated local Wrangler Worker"]
async fn permanent_mode_requires_auth() -> Result<(), Box<dyn Error>> {
    let Some(context) = context() else {
        return Ok(());
    };
    let response = RetryingClient::new()
        .post(format!("{}/v1/artifacts", context.base))
        .json(&json!({"mode": "permanent"}))
        .send()
        .await?;
    assert_eq!(response.status(), reqwest::StatusCode::UNAUTHORIZED);
    Ok(())
}

#[tokio::test]
#[ignore = "requires an isolated local Wrangler Worker and scoped JWT fixtures"]
async fn direct_worker_artifact_writes_enforce_role_and_oauth_scopes() -> Result<(), Box<dyn Error>>
{
    let Some(context) = context() else {
        return Ok(());
    };
    let read_token = env::var("ARTFCT_INTEGRATION_READ_TOKEN")?;
    let deploy_token = env::var("ARTFCT_INTEGRATION_DEPLOY_TOKEN")?;
    let client = RetryingClient::new();
    let bytes = unique_html("scope-enforcement");
    let payload = permanent_payload(&bytes, json!({"agent": "scope-integration"}));

    let denied_create = client
        .post(format!("{}/v1/artifacts", context.base))
        .bearer_auth(&read_token)
        .json(&payload)
        .send()
        .await?;
    assert_eq!(denied_create.status(), reqwest::StatusCode::FORBIDDEN);

    let created = client
        .post(format!("{}/v1/artifacts", context.base))
        .bearer_auth(&deploy_token)
        .json(&payload)
        .send()
        .await?;
    assert_eq!(created.status(), reqwest::StatusCode::CREATED);
    let created: Value = created.json().await?;
    let artifact_id = created["id"].as_str().ok_or("create response omitted id")?;
    let missing_files = created["missing_files"]
        .as_array()
        .ok_or("create response omitted missing_files")?;
    let hash = sha256(&bytes);
    assert!(missing_files.iter().any(|missing| missing == &hash));

    let denied_upload = client
        .put(format!(
            "{}/v1/artifacts/{artifact_id}/files/{hash}",
            context.base
        ))
        .bearer_auth(&read_token)
        .header(reqwest::header::CONTENT_TYPE, "text/html; charset=utf-8")
        .body(bytes.clone())
        .send()
        .await?;
    assert_eq!(denied_upload.status(), reqwest::StatusCode::FORBIDDEN);

    let uploaded = client
        .put(format!(
            "{}/v1/artifacts/{artifact_id}/files/{hash}",
            context.base
        ))
        .bearer_auth(&deploy_token)
        .header(reqwest::header::CONTENT_TYPE, "text/html; charset=utf-8")
        .body(bytes)
        .send()
        .await?;
    assert_eq!(uploaded.status(), reqwest::StatusCode::NO_CONTENT);

    let denied_delete = client
        .delete(format!("{}/v1/artifacts/{artifact_id}", context.base))
        .bearer_auth(&read_token)
        .send()
        .await?;
    assert_eq!(denied_delete.status(), reqwest::StatusCode::FORBIDDEN);

    let cleanup = client
        .delete(format!("{}/v1/artifacts/{artifact_id}", context.base))
        .bearer_auth(&context.token)
        .send()
        .await?;
    assert_eq!(cleanup.status(), reqwest::StatusCode::NO_CONTENT);
    Ok(())
}

#[tokio::test]
#[ignore = "requires an isolated local Wrangler Worker"]
async fn ephemeral_mode_rejects_manifest_field() -> Result<(), Box<dyn Error>> {
    let Some(context) = context() else {
        return Ok(());
    };
    let response = RetryingClient::new()
        .post(format!("{}/v1/artifacts", context.base))
        .json(&json!({"mode": "ephemeral", "manifest": {}}))
        .send()
        .await?;
    assert_eq!(response.status(), reqwest::StatusCode::UNPROCESSABLE_ENTITY);
    Ok(())
}

#[tokio::test]
#[ignore = "requires an isolated local Wrangler Worker"]
async fn ephemeral_roundtrip_unchanged() -> Result<(), Box<dyn Error>> {
    let Some(context) = context() else {
        return Ok(());
    };
    let client = RetryingClient::new();
    let created = client
        .post(format!("{}/v1/artifacts", context.base))
        .json(&json!({
            "tier": "secure",
            "body_ciphertext_b64": "ZXBoZW1lcmFsLWludGVncmF0aW9u",
            "body_iv_b64": "AAAAAAAAAAAAAAAA",
            "title": "Ephemeral integration"
        }))
        .send()
        .await?;
    assert_eq!(created.status(), reqwest::StatusCode::CREATED);
    let created: Value = created.json().await?;
    let preview_url = created["url"]
        .as_str()
        .ok_or("create response omitted url")?
        .to_string();
    assert!(preview_url.starts_with(&format!("{}/p/", context.base)));
    let preview = client.get(preview_url).send().await?;
    assert_eq!(preview.status(), reqwest::StatusCode::OK);
    let body = preview.text().await?;
    assert!(body.contains("bodyCiphertextB64"));
    assert!(body.contains("Waiting for the decryption key"));
    Ok(())
}

#[tokio::test]
#[ignore = "requires an isolated local Wrangler Worker and Node/Vite"]
async fn real_vite_build_deploys_and_renders() -> Result<(), Box<dyn Error>> {
    let Some(context) = context() else {
        return Ok(());
    };
    let fixture =
        std::path::Path::new(env!("CARGO_MANIFEST_DIR")).join("tests/fixtures/vite-react");
    let output_dir = tempfile::tempdir()?;
    let build = Command::new("npm")
        .args(["exec", "--", "vite", "build", "--outDir"])
        .arg(output_dir.path())
        .current_dir(&fixture)
        .output()?;
    assert!(
        build.status.success(),
        "Vite build failed: {}",
        String::from_utf8_lossy(&build.stderr)
    );
    let (url, _) = deploy_directory(&context, &RetryingClient::new(), output_dir.path()).await?;
    let preview = RetryingClient::new().get(&url).send().await?;
    assert_eq!(preview.status(), reqwest::StatusCode::OK);
    let index = preview.text().await?;
    assert!(index.contains("assets/"));
    let mut built_files = Vec::new();
    collect_files(output_dir.path(), output_dir.path(), &mut built_files)?;
    assert!(
        built_files.iter().any(|(path, _)| path.ends_with(".html")),
        "Vite output omitted HTML"
    );
    assert!(
        built_files.iter().any(|(path, _)| path.ends_with(".js")),
        "Vite output omitted JavaScript"
    );
    assert!(
        built_files.iter().any(|(path, _)| path.ends_with(".css")),
        "Vite output omitted CSS"
    );
    assert!(
        built_files.iter().any(|(path, _)| path.ends_with(".woff2")),
        "Vite output omitted WOFF2 font"
    );
    for (relative, _) in built_files {
        let file_url = if relative == "index.html" {
            url.clone()
        } else {
            format!("{}/{}", url.trim_end_matches('/'), relative)
        };
        let response = RetryingClient::new().get(file_url).send().await?;
        assert_eq!(response.status(), reqwest::StatusCode::OK, "{relative}");
        assert_eq!(
            response
                .headers()
                .get("x-content-type-options")
                .and_then(|value| value.to_str().ok()),
            Some("nosniff")
        );
        let expected_type = match std::path::Path::new(&relative)
            .extension()
            .and_then(|value| value.to_str())
        {
            Some("html") => "text/html",
            Some("css") => "text/css",
            Some("js") => "application/javascript",
            Some("woff2") => "font/woff2",
            _ => "application/octet-stream",
        };
        assert!(
            response
                .headers()
                .get("content-type")
                .and_then(|value| value.to_str().ok())
                .is_some_and(|value| value.starts_with(expected_type)),
            "{relative} content type"
        );
        if relative.ends_with(".js") {
            assert!(
                response
                    .bytes()
                    .await?
                    .windows(b"vite-react-bundle".len())
                    .any(|window| window == b"vite-react-bundle"),
                "built JS marker missing"
            );
        }
    }
    Ok(())
}

#[tokio::test]
#[ignore = "requires an isolated local Wrangler Worker"]
async fn incomplete_bundle_returns_404() -> Result<(), Box<dyn Error>> {
    let Some(context) = context() else {
        return Ok(());
    };
    let client = RetryingClient::new();
    let index = b"<script src=\"app.js\"></script>";
    let app = b"console.log('bundle')";
    let response = client
        .post(format!("{}/v1/artifacts", context.base))
        .bearer_auth(&context.token)
        .json(&bundle_payload(
            &[
                ("index.html", index, "text/html"),
                ("app.js", app, "application/javascript"),
            ],
            "index.html",
        ))
        .send()
        .await?;
    assert_eq!(response.status(), reqwest::StatusCode::CREATED);
    let body: Value = response.json().await?;
    let id = body["id"].as_str().ok_or("missing id")?;
    assert_eq!(
        client
            .get(format!("{}/p/{id}", context.base))
            .send()
            .await?
            .status(),
        reqwest::StatusCode::NOT_FOUND
    );
    Ok(())
}

#[tokio::test]
#[ignore = "requires an isolated local Wrangler Worker"]
async fn nested_bundle_asset_uses_manifest_content_type() -> Result<(), Box<dyn Error>> {
    let Some(context) = context() else {
        return Ok(());
    };
    let client = RetryingClient::new();
    let index = b"<h1>bundle</h1>";
    let app = b"console.log('bundle')";
    let id = create_and_upload_bundle(
        &context,
        &client,
        &[
            ("index.html", index, "text/html"),
            ("assets/app.js", app, "application/javascript"),
        ],
        "index.html",
    )
    .await?;
    assert_eq!(
        count_value(
            &context,
            &format!(
                "SELECT COUNT(*) AS count FROM artifacts WHERE id = '{id}' AND expires_at IS NULL"
            ),
            "count"
        )?,
        1,
        "completed permanent bundles must not expire"
    );
    let response = client
        .get(format!("{}/p/{id}/assets/app.js", context.base))
        .send()
        .await?;
    assert_eq!(response.status(), reqwest::StatusCode::OK);
    assert!(response
        .headers()
        .get("content-type")
        .and_then(|v| v.to_str().ok())
        .is_some_and(|v| v.starts_with("application/javascript")));
    assert_eq!(
        response
            .headers()
            .get("x-content-type-options")
            .and_then(|v| v.to_str().ok()),
        Some("nosniff")
    );
    Ok(())
}

#[tokio::test]
#[ignore = "requires an isolated local Wrangler Worker"]
async fn expired_incomplete_bundle_is_cleaned_up() -> Result<(), Box<dyn Error>> {
    let Some(context) = context() else {
        return Ok(());
    };
    let client = RetryingClient::new();
    let index = b"<h1>expired</h1>";
    let script = b"console.log('expired')";
    let index_hash = sha256(index);
    let script_hash = sha256(script);
    let response = client
        .post(format!("{}/v1/artifacts", context.base))
        .bearer_auth(&context.token)
        .json(&bundle_payload(
            &[
                ("index.html", index, "text/html"),
                ("app.js", script, "application/javascript"),
            ],
            "index.html",
        ))
        .send()
        .await?;
    assert_eq!(response.status(), reqwest::StatusCode::CREATED);
    let body: Value = response.json().await?;
    let id = body["id"].as_str().ok_or("missing id")?;
    let first_upload = client
        .put(format!(
            "{}/v1/artifacts/{id}/files/{index_hash}",
            context.base
        ))
        .bearer_auth(&context.token)
        .body(index.to_vec())
        .send()
        .await?;
    assert_eq!(first_upload.status(), reqwest::StatusCode::NO_CONTENT);
    assert_eq!(count_value(&context, &format!("SELECT COUNT(*) AS count FROM files f JOIN artifacts a ON a.row_id = f.artifact_row_id WHERE a.id = '{id}'"), "count")?, 1);
    assert_eq!(
        count_value(
            &context,
            &format!("SELECT ref_count AS count FROM blobs WHERE content_hash = '{index_hash}'"),
            "count"
        )?,
        1
    );
    assert_eq!(
        count_value(
            &context,
            &format!("SELECT ref_count AS count FROM blobs WHERE content_hash = '{script_hash}'"),
            "count"
        )?,
        1
    );
    let row_id = d1_row(
        &context,
        &format!(
            "SELECT row_id FROM artifacts WHERE id = '{id}' AND expires_at IS NOT NULL LIMIT 1"
        ),
    )?["row_id"]
        .as_str()
        .ok_or("artifact row id was not text")?
        .to_string();
    let update = format!("UPDATE artifacts SET expires_at = '2000-01-01T00:00:00Z' WHERE row_id = '{row_id}' AND id = '{id}'");
    d1_execute(&context, &update)?;
    let upload = client
        .put(format!(
            "{}/v1/artifacts/{id}/files/{script_hash}",
            context.base
        ))
        .bearer_auth(&context.token)
        .body(script.to_vec())
        .send()
        .await?;
    assert_eq!(upload.status(), reqwest::StatusCode::NOT_FOUND);
    assert_eq!(
        count_value(
            &context,
            &format!("SELECT COUNT(*) AS count FROM artifacts WHERE id = '{id}'"),
            "count"
        )?,
        0
    );
    assert_eq!(
        count_value(
            &context,
            &format!("SELECT COUNT(*) AS count FROM provenance WHERE artifact_row_id = '{row_id}'"),
            "count"
        )?,
        0
    );
    assert_eq!(
        count_value(
            &context,
            &format!("SELECT COUNT(*) AS count FROM files WHERE artifact_row_id = '{row_id}'"),
            "count"
        )?,
        0
    );
    assert_eq!(count_value(&context, &format!("SELECT COUNT(*) AS count FROM blobs WHERE content_hash IN ('{index_hash}', '{script_hash}')"), "count")?, 0);
    for hash in [index_hash, script_hash] {
        assert_eq!(
            client
                .get(format!("{}/v1/blobs/{hash}", context.base))
                .bearer_auth(&context.token)
                .send()
                .await?
                .status(),
            reqwest::StatusCode::NOT_FOUND
        );
    }
    Ok(())
}

#[tokio::test]
#[ignore = "requires an isolated local Wrangler Worker"]
async fn same_hash_paths_share_one_blob_and_manifest_types() -> Result<(), Box<dyn Error>> {
    let Some(context) = context() else {
        return Ok(());
    };
    let client = RetryingClient::new();
    // Run-unique bytes: identical across the two paths so the sharing assertion
    // means something, but never bytes an earlier run already stored. This suite
    // shares one persisted D1/R2 store across every run in the same stack, and a
    // re-post of identical content is idempotent, so fixed bytes would make the
    // create report no missing files (and this test fail) on the second run.
    let bytes = format!(
        "same bytes {}",
        std::time::SystemTime::now()
            .duration_since(std::time::UNIX_EPOCH)?
            .as_nanos()
    )
    .into_bytes();
    let hash = sha256(&bytes);
    let response = client
        .post(format!("{}/v1/artifacts", context.base))
        .bearer_auth(&context.token)
        .json(&bundle_payload(
            &[
                ("index.html", bytes.as_slice(), "text/html"),
                ("assets/app.js", bytes.as_slice(), "application/javascript"),
            ],
            "index.html",
        ))
        .send()
        .await?;
    assert_eq!(response.status(), reqwest::StatusCode::CREATED);
    let body: Value = response.json().await?;
    assert_eq!(body["missing_files"], json!([hash]));
    let id = body["id"].as_str().ok_or("missing id")?.to_string();
    let upload = client
        .put(format!("{}/v1/artifacts/{id}/files/{hash}", context.base))
        .bearer_auth(&context.token)
        .header(reqwest::header::CONTENT_TYPE, "text/html")
        .body(bytes.to_vec())
        .send()
        .await?;
    assert_eq!(upload.status(), reqwest::StatusCode::NO_CONTENT);
    assert_eq!(count_value(&context, &format!("SELECT COUNT(*) AS count FROM files f JOIN artifacts a ON a.row_id = f.artifact_row_id WHERE a.id = '{id}'"), "count")?, 2);
    assert_eq!(
        count_value(
            &context,
            &format!("SELECT ref_count AS count FROM blobs WHERE content_hash = '{hash}'"),
            "count"
        )?,
        2
    );
    let index_response = client
        .get(format!("{}/p/{id}", context.base))
        .send()
        .await?;
    assert_eq!(index_response.status(), reqwest::StatusCode::OK);
    assert!(index_response
        .headers()
        .get("content-type")
        .and_then(|v| v.to_str().ok())
        .is_some_and(|v| v.starts_with("text/html")));
    let script_response = client
        .get(format!("{}/p/{id}/assets/app.js", context.base))
        .send()
        .await?;
    assert_eq!(script_response.status(), reqwest::StatusCode::OK);
    assert!(script_response
        .headers()
        .get("content-type")
        .and_then(|v| v.to_str().ok())
        .is_some_and(|v| v.starts_with("application/javascript")));
    client
        .delete(format!("{}/v1/artifacts/{id}", context.base))
        .bearer_auth(&context.token)
        .send()
        .await?
        .error_for_status()?;
    assert_eq!(count_value(&context, &format!("SELECT COUNT(*) AS count FROM files f JOIN artifacts a ON a.row_id = f.artifact_row_id WHERE a.id = '{id}'"), "count")?, 0);
    assert_eq!(
        count_value(
            &context,
            &format!("SELECT COUNT(*) AS count FROM blobs WHERE content_hash = '{hash}'"),
            "count"
        )?,
        0
    );
    Ok(())
}

#[tokio::test]
#[ignore = "requires an isolated local Wrangler Worker and Node/Vite"]
async fn redeploying_changed_bundle_uploads_one_file() -> Result<(), Box<dyn Error>> {
    let Some(context) = context() else {
        return Ok(());
    };
    let fixture =
        std::path::Path::new(env!("CARGO_MANIFEST_DIR")).join("tests/fixtures/vite-react");
    let output_dir = tempfile::tempdir()?;
    let build = Command::new("npm")
        .args(["exec", "--", "vite", "build", "--outDir"])
        .arg(output_dir.path())
        .current_dir(&fixture)
        .output()?;
    assert!(
        build.status.success(),
        "Vite build failed: {}",
        String::from_utf8_lossy(&build.stderr)
    );
    let mut initial_files = Vec::new();
    collect_files(output_dir.path(), output_dir.path(), &mut initial_files)?;
    let client = RetryingClient::new();
    // The Vite build is deterministic and this store is shared with every
    // earlier run in the same stack, so the build has to be revised into
    // something no run has published before it is first deployed. Otherwise the
    // second deploy's changed index.html is already stored and the CLI reports
    // zero uploads -- an already-populated store, not a failed upload.
    let revision = output_dir.path().join("index.html");
    let mut html = fs::read_to_string(&revision)?;
    html.push_str(&format!(
        "<!-- run {} -->",
        std::time::SystemTime::now()
            .duration_since(std::time::UNIX_EPOCH)?
            .as_nanos()
    ));
    fs::write(&revision, html)?;
    deploy_directory(&context, &client, output_dir.path()).await?;
    let changed = output_dir.path().join("index.html");
    let mut html = fs::read_to_string(&changed)?;
    html.push_str("<!-- changed -->");
    fs::write(changed, html)?;
    let (_, uploaded) = deploy_directory(&context, &client, output_dir.path()).await?;
    assert_eq!(
        uploaded,
        1,
        "only the changed index.html should be uploaded; {} files total",
        initial_files.len()
    );
    Ok(())
}

// ── RUB-437: artifact versioning ─────────────────────────────────────────
//
// End-to-end coverage of the versioning contract against a live local Worker
// (openapi/artfct.yaml; the data-model invariants live in the header of
// migrations/0006_artifact_versions.sql). Every test uses unique bytes so a
// shared local D1/R2 cannot make one test's artifact answer another's read.

/// Whether `id` is the 13-character lowercase base36 shape a new permanent
/// artifact gets (RUB-437).
fn is_stable_id(id: &str) -> bool {
    id.len() == 13
        && id
            .chars()
            .all(|value| value.is_ascii_lowercase() || value.is_ascii_digit())
}

/// A new-version request body: the create body's bundle fields, without the
/// `mode`/`tier` a version never changes.
fn version_payload(bytes: &[u8], provenance: Value) -> Value {
    let hash = sha256(bytes);
    json!({
        "title": "Storage integration version",
        "description": "Storage integration version",
        "thumbnail": "https://example.com/thumbnail.png",
        "preview_blurred": false,
        "manifest": {"entrypoint": "index.html", "external_origins": [], "files": [{
            "path": "index.html", "content_type": "text/html; charset=utf-8", "size_bytes": bytes.len(), "sha256": hash
        }]},
        "provenance": provenance
    })
}

async fn post_version(
    context: &Context,
    client: &RetryingClient,
    artifact_id: &str,
    bytes: &[u8],
    provenance: Value,
) -> Result<(reqwest::StatusCode, Value), Box<dyn Error>> {
    let response = client
        .post(format!(
            "{}/v1/artifacts/{artifact_id}/versions",
            context.base
        ))
        .bearer_auth(&context.token)
        .json(&version_payload(bytes, provenance))
        .send()
        .await?;
    let status = response.status();
    let body: Value = response.json().await?;
    Ok((status, body))
}

async fn post_restore(
    context: &Context,
    client: &RetryingClient,
    artifact_id: &str,
    version: u32,
) -> Result<(reqwest::StatusCode, Value), Box<dyn Error>> {
    let response = client
        .post(format!(
            "{}/v1/artifacts/{artifact_id}/versions/{version}/restore",
            context.base
        ))
        .bearer_auth(&context.token)
        .send()
        .await?;
    let status = response.status();
    let body: Value = response.json().await?;
    Ok((status, body))
}

async fn upload_version_file(
    context: &Context,
    client: &RetryingClient,
    artifact_id: &str,
    bytes: &[u8],
) -> Result<(), Box<dyn Error>> {
    let hash = sha256(bytes);
    let upload = client
        .put(format!(
            "{}/v1/artifacts/{artifact_id}/files/{hash}",
            context.base
        ))
        .bearer_auth(&context.token)
        .header(reqwest::header::CONTENT_TYPE, "text/html; charset=utf-8")
        .body(bytes.to_vec())
        .send()
        .await?;
    assert_eq!(upload.status(), reqwest::StatusCode::NO_CONTENT);
    Ok(())
}

/// Publishes `bytes` as the artifact's next version and uploads its one file,
/// returning the create-version response body.
async fn publish_version(
    context: &Context,
    client: &RetryingClient,
    artifact_id: &str,
    bytes: &[u8],
) -> Result<Value, Box<dyn Error>> {
    let (status, body) = post_version(context, client, artifact_id, bytes, json!({})).await?;
    assert_eq!(
        status,
        reqwest::StatusCode::CREATED,
        "unseen bytes should create a version: {body}"
    );
    assert_eq!(body["missing_files"], json!([sha256(bytes)]));
    upload_version_file(context, client, artifact_id, bytes).await?;
    Ok(body)
}

async fn artifact_metadata(
    context: &Context,
    client: &RetryingClient,
    artifact_id: &str,
) -> Result<Value, Box<dyn Error>> {
    let response = client
        .get(format!("{}/v1/artifacts/{artifact_id}", context.base))
        .bearer_auth(&context.token)
        .send()
        .await?;
    assert_eq!(response.status(), reqwest::StatusCode::OK);
    Ok(response.json().await?)
}

async fn org_content_read(
    context: &Context,
    client: &RetryingClient,
    artifact_id: &str,
) -> Result<Value, Box<dyn Error>> {
    let response = client
        .get(format!(
            "{}/v1/orgs/{}/artifacts/{artifact_id}/content",
            context.base, context.org
        ))
        .bearer_auth(&context.token)
        .send()
        .await?;
    assert_eq!(response.status(), reqwest::StatusCode::OK);
    Ok(response.json().await?)
}

async fn version_list(
    context: &Context,
    client: &RetryingClient,
    artifact_id: &str,
) -> Result<Value, Box<dyn Error>> {
    let response = client
        .get(format!(
            "{}/v1/artifacts/{artifact_id}/versions",
            context.base
        ))
        .bearer_auth(&context.token)
        .send()
        .await?;
    assert_eq!(response.status(), reqwest::StatusCode::OK);
    Ok(response.json().await?)
}

async fn get_and_read(
    client: &RetryingClient,
    url: String,
) -> Result<(reqwest::StatusCode, Vec<u8>), Box<dyn Error>> {
    let response = client.get(url).send().await?;
    let status = response.status();
    let bytes = response.bytes().await?;
    Ok((status, bytes.as_ref().to_vec()))
}

/// `(rows, max ref_count)` for one blob: a released blob is either gone or at
/// ref_count zero. `MAX` over no rows is NULL, so it coalesces to zero.
fn blob_state(context: &Context, content_hash: &str) -> Result<(i64, i64), Box<dyn Error>> {
    let row = d1_row(
        context,
        &format!(
            "SELECT COUNT(*) AS row_count, COALESCE(MAX(ref_count), 0) AS ref_count FROM blobs WHERE content_hash = '{content_hash}'"
        ),
    )?;
    Ok((
        row["row_count"]
            .as_i64()
            .ok_or("row_count was not an integer")?,
        row["ref_count"]
            .as_i64()
            .ok_or("ref_count was not an integer")?,
    ))
}

fn artifact_row_id(context: &Context, artifact_id: &str) -> Result<String, Box<dyn Error>> {
    Ok(d1_row(
        context,
        &format!("SELECT row_id FROM artifacts WHERE id = '{artifact_id}'"),
    )?["row_id"]
        .as_str()
        .ok_or("row_id was not text")?
        .to_string())
}

#[tokio::test]
#[ignore = "requires an isolated local Wrangler Worker"]
async fn new_artifacts_get_a_13_character_stable_id() -> Result<(), Box<dyn Error>> {
    let Some(context) = context() else {
        return Ok(());
    };
    let client = RetryingClient::new();
    let bytes = unique_html("stable-id");
    let created = client
        .post(format!("{}/v1/artifacts", context.base))
        .bearer_auth(&context.token)
        .json(&permanent_payload(&bytes, json!({})))
        .send()
        .await?;
    assert_eq!(created.status(), reqwest::StatusCode::CREATED);
    let body: Value = created.json().await?;
    let id = body["id"].as_str().ok_or("create response omitted id")?;
    assert!(
        is_stable_id(id),
        "a new permanent artifact id must be 13 lowercase base36 characters, got {id}"
    );
    assert_eq!(body["version"], 1, "a new artifact starts at version 1");
    // Complete the v1 upload so the artifact does not sit pending.
    upload_version_file(&context, &client, id, &bytes).await?;
    Ok(())
}

#[tokio::test]
#[ignore = "requires an isolated local Wrangler Worker"]
async fn publishing_v2_keeps_the_id_and_serves_new_content() -> Result<(), Box<dyn Error>> {
    let Some(context) = context() else {
        return Ok(());
    };
    let client = RetryingClient::new();
    let v1 = unique_html("versions-alpha");
    let v2 = unique_html("versions-beta");
    let (id, v1_hash) = create_and_upload(&context, &client, &v1, json!({})).await?;

    let published = publish_version(&context, &client, &id, &v2).await?;
    assert_eq!(published["id"].as_str(), Some(id.as_str()));
    assert_eq!(published["version"], 2, "a second publish is version 2");
    assert_eq!(published["created"], true);
    let expected_url = format!("{}/p/{id}/", context.base);
    assert_eq!(published["url"].as_str(), Some(expected_url.as_str()));

    // The unversioned path follows the current version...
    let (status, current) = get_and_read(&client, format!("{}/p/{id}", context.base)).await?;
    assert_eq!(status, reqwest::StatusCode::OK);
    assert_eq!(current, v2, "/p/{id} must serve the newest version");

    // ...and the version-addressed path keeps serving v1.
    let (status, old) = get_and_read(&client, format!("{}/p/{id}/v:1/", context.base)).await?;
    assert_eq!(status, reqwest::StatusCode::OK);
    assert_eq!(old, v1, "an addressed version keeps its own bytes");
    assert_ne!(v1_hash, sha256(&v2));

    let metadata = artifact_metadata(&context, &client, &id).await?;
    assert_eq!(metadata["version"], 2);
    assert_eq!(metadata["version_count"], 2);

    let content = org_content_read(&context, &client, &id).await?;
    assert_eq!(
        content["version"], 2,
        "the content read reports the served version"
    );
    assert_eq!(
        content["content"].as_str(),
        Some(String::from_utf8(v2.clone())?.as_str())
    );

    let history = version_list(&context, &client, &id).await?;
    assert_eq!(history["current_version"], 2);
    let versions = history["versions"].as_array().ok_or("versions omitted")?;
    assert_eq!(versions.len(), 2, "history lists the completed versions");
    assert_eq!(versions[0]["version"], 2);
    assert_eq!(versions[0]["current"], true);
    assert_eq!(versions[1]["version"], 1);
    assert_eq!(versions[1]["current"], false);
    Ok(())
}

#[tokio::test]
#[ignore = "requires an isolated local Wrangler Worker"]
async fn reposting_identical_bytes_creates_no_new_version() -> Result<(), Box<dyn Error>> {
    let Some(context) = context() else {
        return Ok(());
    };
    let client = RetryingClient::new();
    let bytes = unique_html("version-noop");
    let (id, hash) = create_and_upload(&context, &client, &bytes, json!({})).await?;
    let row_id = artifact_row_id(&context, &id)?;
    let before = count_value(
        &context,
        &format!(
            "SELECT COUNT(*) AS count FROM artifact_versions WHERE artifact_row_id = '{row_id}'"
        ),
        "count",
    )?;
    assert_eq!(before, 1, "a new artifact records exactly its v1");

    let (status, body) = post_version(&context, &client, &id, &bytes, json!({})).await?;
    assert_eq!(
        status,
        reqwest::StatusCode::OK,
        "identical bytes must not create a version: {body}"
    );
    assert_eq!(body["created"], false);
    assert_eq!(body["version"], 1);
    assert_eq!(body["missing_files"], json!([]));
    let after = count_value(
        &context,
        &format!(
            "SELECT COUNT(*) AS count FROM artifact_versions WHERE artifact_row_id = '{row_id}'"
        ),
        "count",
    )?;
    assert_eq!(
        after, before,
        "a no-op repost must not add an artifact_versions row"
    );

    // Re-posting the same bytes as a *create* also names the same artifact.
    let (same_id, same_hash) = create_and_upload(&context, &client, &bytes, json!({})).await?;
    assert_eq!(same_id, id);
    assert_eq!(same_hash, hash);
    assert_eq!(
        count_value(
            &context,
            &format!("SELECT COUNT(*) AS count FROM artifacts WHERE id = '{id}'"),
            "count"
        )?,
        1,
        "identical bytes must not add an artifacts row"
    );
    Ok(())
}

#[tokio::test]
#[ignore = "requires an isolated local Wrangler Worker"]
async fn restore_creates_a_new_version() -> Result<(), Box<dyn Error>> {
    let Some(context) = context() else {
        return Ok(());
    };
    let client = RetryingClient::new();
    let v1 = unique_html("restore-alpha");
    let v2 = unique_html("restore-beta");
    let (id, _) = create_and_upload(&context, &client, &v1, json!({})).await?;
    publish_version(&context, &client, &id, &v2).await?;

    let (status, current) = get_and_read(&client, format!("{}/p/{id}", context.base)).await?;
    assert_eq!(status, reqwest::StatusCode::OK);
    assert_eq!(current, v2);

    let (status, restored) = post_restore(&context, &client, &id, 1).await?;
    assert_eq!(status, reqwest::StatusCode::CREATED);
    assert_eq!(restored["version"], 3, "restoring v1 publishes a new v3");
    assert_eq!(restored["created"], true);

    let response = client
        .get(format!("{}/v1/artifacts/{id}/versions/3", context.base))
        .bearer_auth(&context.token)
        .send()
        .await?;
    assert_eq!(response.status(), reqwest::StatusCode::OK);
    let version: Value = response.json().await?;
    assert_eq!(version["restored_from"], 1);
    assert_eq!(version["current"], true);

    let (status, served) = get_and_read(&client, format!("{}/p/{id}", context.base)).await?;
    assert_eq!(status, reqwest::StatusCode::OK);
    assert_eq!(served, v1, "restoring v1 makes its bytes current again");

    let (status, body) = post_restore(&context, &client, &id, 3).await?;
    assert_eq!(
        status,
        reqwest::StatusCode::OK,
        "restoring the current version is a no-op: {body}"
    );
    assert_eq!(body["created"], false);
    assert_eq!(body["version"], 3);
    Ok(())
}

#[tokio::test]
#[ignore = "requires an isolated local Wrangler Worker and local R2 storage"]
async fn old_version_blob_survives_the_orphan_sweep() -> Result<(), Box<dyn Error>> {
    let Some(context) = context() else {
        return Ok(());
    };
    let client = RetryingClient::new();
    let v1 = unique_html("sweep-alpha");
    let v2 = unique_html("sweep-beta");
    let (id, v1_hash) = create_and_upload(&context, &client, &v1, json!({})).await?;
    publish_version(&context, &client, &id, &v2).await?;

    // v1's blob is no longer the current copy's, but invariant 3 counts every
    // version's manifest files, so it must stay positively referenced.
    let (rows, ref_count) = blob_state(&context, &v1_hash)?;
    assert_eq!(rows, 1, "v1's blob metadata must survive promotion");
    assert!(
        ref_count > 0,
        "the old version's blob must stay referenced, ref_count={ref_count}"
    );
    assert!(
        r2_object_get(&context, &v1_hash)?.status.success(),
        "v1's R2 object exists before the sweep"
    );

    let sweep = client
        .post(format!(
            "{}/v1/internal/orgs/{}/governance/sweep-orphans",
            context.base, context.org
        ))
        .header(
            reqwest::header::AUTHORIZATION,
            &format!("Bearer {}", context.governance_secret),
        )
        .send()
        .await?;
    assert_eq!(sweep.status(), reqwest::StatusCode::OK);

    let (rows, ref_count) = blob_state(&context, &v1_hash)?;
    assert_eq!(
        rows, 1,
        "the sweep must not delete a blob an old version still references"
    );
    assert!(ref_count > 0);
    assert!(
        r2_object_get(&context, &v1_hash)?.status.success(),
        "v1's R2 object must survive the sweep"
    );
    Ok(())
}

#[tokio::test]
#[ignore = "requires an isolated local Wrangler Worker"]
async fn revoking_revokes_every_version() -> Result<(), Box<dyn Error>> {
    let Some(context) = context() else {
        return Ok(());
    };
    let client = RetryingClient::new();
    let v1 = unique_html("revoke-alpha");
    let v2 = unique_html("revoke-beta");
    let (id, _) = create_and_upload(&context, &client, &v1, json!({})).await?;
    publish_version(&context, &client, &id, &v2).await?;

    let revoked = client
        .patch(format!(
            "{}/v1/orgs/{}/artifacts/{id}",
            context.base, context.org
        ))
        .bearer_auth(&context.token)
        .send()
        .await?;
    assert_eq!(revoked.status(), reqwest::StatusCode::OK);
    let body: Value = revoked.json().await?;
    assert_eq!(body["id"].as_str(), Some(id.as_str()));
    assert!(
        body["revoked_at"].is_string(),
        "revocation stamps revoked_at: {body}"
    );

    // Revocation is a column on the single artifact row, so it covers every
    // version by construction.
    for path in [
        format!("/p/{id}"),
        format!("/p/{id}/v:1/"),
        format!("/p/{id}/v:2/"),
    ] {
        let response = client.get(format!("{}{path}", context.base)).send().await?;
        assert_eq!(
            response.status(),
            reqwest::StatusCode::NOT_FOUND,
            "a revoked artifact must not serve {path}"
        );
    }

    let (status, _) = post_version(
        &context,
        &client,
        &id,
        &unique_html("revoke-gamma"),
        json!({}),
    )
    .await?;
    assert_eq!(
        status,
        reqwest::StatusCode::NOT_FOUND,
        "publishing a version of a revoked artifact is 404, not 403"
    );
    Ok(())
}

#[tokio::test]
#[ignore = "requires an isolated local Wrangler Worker"]
async fn hard_delete_releases_every_version_blob() -> Result<(), Box<dyn Error>> {
    let Some(context) = context() else {
        return Ok(());
    };
    let client = RetryingClient::new();
    let v1 = unique_html("delete-versions-alpha");
    let v2 = unique_html("delete-versions-beta");
    let (id, v1_hash) = create_and_upload(&context, &client, &v1, json!({})).await?;
    publish_version(&context, &client, &id, &v2).await?;
    let v2_hash = sha256(&v2);
    assert_ne!(v1_hash, v2_hash);
    let row_id = artifact_row_id(&context, &id)?;

    let delete = client
        .delete(format!("{}/v1/artifacts/{id}", context.base))
        .bearer_auth(&context.token)
        .send()
        .await?;
    assert_eq!(delete.status(), reqwest::StatusCode::NO_CONTENT);

    assert_eq!(
        count_value(
            &context,
            &format!(
                "SELECT COUNT(*) AS count FROM artifact_versions WHERE artifact_row_id = '{row_id}'"
            ),
            "count"
        )?,
        0,
        "no version row may survive a hard delete"
    );
    assert_eq!(
        count_value(
            &context,
            &format!(
                "SELECT COUNT(*) AS count FROM version_files WHERE version_id IN (SELECT id FROM artifact_versions WHERE artifact_row_id = '{row_id}')"
            ),
            "count"
        )?,
        0,
        "no version_files row may survive a hard delete"
    );

    for hash in [&v1_hash, &v2_hash] {
        let (rows, ref_count) = blob_state(&context, hash)?;
        assert!(
            rows == 0 || ref_count == 0,
            "a hard delete must release {hash}: rows={rows} ref_count={ref_count}"
        );
    }
    Ok(())
}

#[tokio::test]
#[ignore = "requires an isolated local Wrangler Worker"]
async fn legacy_32_character_artifact_accepts_a_new_version() -> Result<(), Box<dyn Error>> {
    let Some(context) = context() else {
        return Ok(());
    };
    let client = RetryingClient::new();
    let v1 = unique_html("legacy-v1");
    let v2 = unique_html("legacy-v2");
    let (stable_id, _) = create_and_upload(&context, &client, &v1, json!({})).await?;

    // A pre-versioning artifact carried a 32-character hex id. The migration's
    // backfill makes every existing row a v1, and every dependant hangs off
    // `row_id`, so renaming only the id column reproduces that shape without
    // hand-building an artifact across four tables.
    let legacy_id = sha256(&v1)[..32].to_string();
    assert_eq!(legacy_id.len(), 32);
    d1_execute(
        &context,
        &format!("UPDATE artifacts SET id = '{legacy_id}' WHERE id = '{stable_id}'"),
    )?;

    let (status, body) = post_version(&context, &client, &legacy_id, &v2, json!({})).await?;
    assert_eq!(
        status,
        reqwest::StatusCode::CREATED,
        "a 32-character legacy id accepts a new version: {body}"
    );
    assert_eq!(body["version"], 2);
    assert_eq!(body["id"].as_str(), Some(legacy_id.as_str()));
    upload_version_file(&context, &client, &legacy_id, &v2).await?;

    let metadata = artifact_metadata(&context, &client, &legacy_id).await?;
    assert_eq!(metadata["version"], 2);
    let (status, served) = get_and_read(&client, format!("{}/p/{legacy_id}", context.base)).await?;
    assert_eq!(status, reqwest::StatusCode::OK);
    assert_eq!(served, v2);
    Ok(())
}

#[tokio::test]
#[ignore = "requires an isolated local Wrangler Worker"]
async fn pending_v2_does_not_replace_v1_until_uploaded() -> Result<(), Box<dyn Error>> {
    let Some(context) = context() else {
        return Ok(());
    };
    let client = RetryingClient::new();
    let v1 = unique_html("pending-alpha");
    let v2 = unique_html("pending-beta");
    let (id, _) = create_and_upload(&context, &client, &v1, json!({})).await?;

    let (status, body) = post_version(&context, &client, &id, &v2, json!({})).await?;
    assert_eq!(status, reqwest::StatusCode::CREATED);
    assert_eq!(body["version"], 2);
    assert_eq!(body["missing_files"], json!([sha256(&v2)]));

    // A pending version lives only on its artifact_versions row, so the current
    // copy keeps serving and the pending upload is not yet history.
    let (status, served) = get_and_read(&client, format!("{}/p/{id}", context.base)).await?;
    assert_eq!(status, reqwest::StatusCode::OK);
    assert_eq!(
        served, v1,
        "a pending v2 must not replace the current version"
    );
    let metadata = artifact_metadata(&context, &client, &id).await?;
    assert_eq!(metadata["version"], 1);
    assert_eq!(
        metadata["version_count"], 1,
        "a pending version is not counted in history"
    );

    upload_version_file(&context, &client, &id, &v2).await?;

    let (status, served) = get_and_read(&client, format!("{}/p/{id}", context.base)).await?;
    assert_eq!(status, reqwest::StatusCode::OK);
    assert_eq!(served, v2, "completing the upload promotes v2");
    let metadata = artifact_metadata(&context, &client, &id).await?;
    assert_eq!(metadata["version"], 2);
    assert_eq!(metadata["version_count"], 2);
    Ok(())
}

fn collect_files(
    root: &std::path::Path,
    current: &std::path::Path,
    files: &mut Vec<(String, Vec<u8>)>,
) -> Result<(), Box<dyn Error>> {
    for entry in fs::read_dir(current)? {
        let path = entry?.path();
        if path.is_dir() {
            collect_files(root, &path, files)?;
        } else {
            files.push((
                path.strip_prefix(root)?
                    .to_string_lossy()
                    .replace('\\', "/"),
                fs::read(path)?,
            ));
        }
    }
    Ok(())
}

// ---------------------------------------------------------------------------
// RUB-438: sharing (private/team/public), edit access and artifact ownership,
// driven against the live Worker with the extra credentials the stack mints.
// Every test skips with `Ok(())` when its optional token is absent, so an old
// state env runs the suite without failing on a stack it predates.
// ---------------------------------------------------------------------------

/// One JSON request under an explicit bearer token. The suite's own helpers all
/// use `context.token`; these tests need several identities at once.
async fn send_json(
    request: RetryingRequest,
    token: &str,
) -> Result<(reqwest::StatusCode, Value), Box<dyn Error>> {
    let response = request.bearer_auth(token).send().await?;
    let status = response.status();
    let body: Value = response.json().await?;
    Ok((status, body))
}

/// `GET`s `url` with `token` and returns the status and the whole body.
async fn get_and_read_as(
    client: &RetryingClient,
    token: &str,
    url: String,
) -> Result<(reqwest::StatusCode, Vec<u8>), Box<dyn Error>> {
    let response = client.get(url).bearer_auth(token).send().await?;
    let status = response.status();
    let bytes = response.bytes().await?;
    Ok((status, bytes.as_ref().to_vec()))
}

/// A permanent create body that names sharing and edit access directly, as the
/// new contract allows, instead of the deprecated `tier` alias.
fn sharing_payload(bytes: &[u8], sharing: &str, edit_access: &str) -> Value {
    json!({
        "mode": "permanent",
        "sharing": sharing,
        "edit_access": edit_access,
        "title": "Sharing integration",
        "description": "Sharing integration",
        "thumbnail": "https://example.com/thumbnail.png",
        "preview_blurred": false,
        "manifest": {"entrypoint": "index.html", "external_origins": [], "files": [{
            "path": "index.html", "content_type": "text/html; charset=utf-8",
            "size_bytes": bytes.len(), "sha256": sha256(bytes)
        }]},
        "provenance": {}
    })
}

/// Creates a permanent artifact as `token`'s user with the given sharing, then
/// uploads its one file with the same token. Returns the artifact id.
async fn create_sharing_artifact(
    context: &Context,
    client: &RetryingClient,
    token: &str,
    bytes: &[u8],
    sharing: &str,
    edit_access: &str,
) -> Result<String, Box<dyn Error>> {
    let payload = sharing_payload(bytes, sharing, edit_access);
    let (status, body) = send_json(
        client
            .post(format!("{}/v1/artifacts", context.base))
            .json(&payload),
        token,
    )
    .await?;
    assert_eq!(
        status,
        reqwest::StatusCode::CREATED,
        "create failed: {body}"
    );
    let id = body["id"]
        .as_str()
        .ok_or("create response omitted id")?
        .to_string();
    let hash = sha256(bytes);
    if body["missing_files"]
        .as_array()
        .is_some_and(|files| files.iter().any(|file| file == &hash))
    {
        let upload = client
            .put(format!("{}/v1/artifacts/{id}/files/{hash}", context.base))
            .bearer_auth(token)
            .header(reqwest::header::CONTENT_TYPE, "text/html; charset=utf-8")
            .body(bytes.to_vec())
            .send()
            .await?;
        assert_eq!(upload.status(), reqwest::StatusCode::NO_CONTENT);
    }
    Ok(id)
}

async fn artifact_metadata_as(
    context: &Context,
    client: &RetryingClient,
    token: &str,
    artifact_id: &str,
) -> Result<(reqwest::StatusCode, Value), Box<dyn Error>> {
    send_json(
        client.get(format!("{}/v1/artifacts/{artifact_id}", context.base)),
        token,
    )
    .await
}

async fn org_list_as(
    context: &Context,
    client: &RetryingClient,
    token: &str,
) -> Result<(reqwest::StatusCode, Value), Box<dyn Error>> {
    send_json(
        client.get(format!(
            "{}/v1/orgs/{}/artifacts",
            context.base, context.org
        )),
        token,
    )
    .await
}

async fn version_list_as(
    context: &Context,
    client: &RetryingClient,
    token: &str,
    artifact_id: &str,
) -> Result<(reqwest::StatusCode, Value), Box<dyn Error>> {
    send_json(
        client.get(format!(
            "{}/v1/artifacts/{artifact_id}/versions",
            context.base
        )),
        token,
    )
    .await
}

async fn get_version_as(
    context: &Context,
    client: &RetryingClient,
    token: &str,
    artifact_id: &str,
    version: u64,
) -> Result<(reqwest::StatusCode, Value), Box<dyn Error>> {
    send_json(
        client.get(format!(
            "{}/v1/artifacts/{artifact_id}/versions/{version}",
            context.base
        )),
        token,
    )
    .await
}

async fn patch_sharing_as(
    context: &Context,
    client: &RetryingClient,
    token: &str,
    artifact_id: &str,
    body: Value,
) -> Result<(reqwest::StatusCode, Value), Box<dyn Error>> {
    send_json(
        client
            .patch(format!(
                "{}/v1/artifacts/{artifact_id}/sharing",
                context.base
            ))
            .json(&body),
        token,
    )
    .await
}

async fn post_version_as(
    context: &Context,
    client: &RetryingClient,
    token: &str,
    artifact_id: &str,
    bytes: &[u8],
) -> Result<(reqwest::StatusCode, Value), Box<dyn Error>> {
    send_json(
        client
            .post(format!(
                "{}/v1/artifacts/{artifact_id}/versions",
                context.base
            ))
            .json(&version_payload(bytes, json!({}))),
        token,
    )
    .await
}

/// A `POST` to an internal, limits-secret-authenticated endpoint.
async fn internal_post(
    context: &Context,
    client: &RetryingClient,
    secret: &str,
    path: &str,
    body: Value,
) -> Result<(reqwest::StatusCode, Value), Box<dyn Error>> {
    send_json(
        client.post(format!("{}{path}", context.base)).json(&body),
        secret,
    )
    .await
}

/// The `user_id` claim of an org JWT, decoded without verifying the signature:
/// these tests minted the token themselves and only compare identities.
fn token_user_id(token: &str) -> Result<String, Box<dyn Error>> {
    use base64::Engine as _;

    let payload = token.split('.').nth(1).ok_or("token is not a JWT")?;
    let decoded = base64::engine::general_purpose::URL_SAFE_NO_PAD.decode(payload)?;
    let claims: Value = serde_json::from_slice(&decoded)?;
    Ok(claims["user_id"]
        .as_str()
        .ok_or("token has no user_id")?
        .to_string())
}

#[tokio::test]
#[ignore = "requires an isolated local Wrangler Worker"]
async fn private_artifact_is_hidden_from_other_members() -> Result<(), Box<dyn Error>> {
    let Some(context) = context() else {
        return Ok(());
    };
    // The owner is a plain member, not the admin, so "the owner sees it" and
    // "an admin sees it" are two different rules; the viewer is a third user.
    let (Some(owner_token), Some(viewer_token)) = (
        context.member_token.as_deref(),
        context.member2_token.as_deref(),
    ) else {
        return Ok(());
    };
    let client = RetryingClient::new();
    let bytes = unique_html("private-hiding");
    let id =
        create_sharing_artifact(&context, &client, owner_token, &bytes, "private", "view").await?;

    // A different non-admin member is neither owner nor viewer: private is a
    // 404 on every read path, never a 403 that would confirm it exists.
    let (status, _) = artifact_metadata_as(&context, &client, viewer_token, &id).await?;
    assert_eq!(status, reqwest::StatusCode::NOT_FOUND);

    let (status, list) = org_list_as(&context, &client, viewer_token).await?;
    assert_eq!(status, reqwest::StatusCode::OK);
    assert!(
        !list["artifacts"]
            .as_array()
            .ok_or("list omitted artifacts")?
            .iter()
            .any(|artifact| artifact["id"] == id),
        "a private artifact must not appear in another member's org list"
    );

    let (status, _) = version_list_as(&context, &client, viewer_token, &id).await?;
    assert_eq!(status, reqwest::StatusCode::NOT_FOUND);

    let (status, _) =
        get_and_read_as(&client, viewer_token, format!("{}/p/{id}/", context.base)).await?;
    assert_eq!(
        status,
        reqwest::StatusCode::NOT_FOUND,
        "a private artifact must not be served to a non-owner member"
    );

    // The owner and an admin both see it.
    let (status, _) = artifact_metadata_as(&context, &client, owner_token, &id).await?;
    assert_eq!(
        status,
        reqwest::StatusCode::OK,
        "the owner sees their own artifact"
    );
    let (status, _) = artifact_metadata_as(&context, &client, &context.token, &id).await?;
    assert_eq!(
        status,
        reqwest::StatusCode::OK,
        "a team admin sees a private artifact"
    );
    let (status, list) = org_list_as(&context, &client, owner_token).await?;
    assert_eq!(status, reqwest::StatusCode::OK);
    assert!(
        list["artifacts"]
            .as_array()
            .ok_or("list omitted artifacts")?
            .iter()
            .any(|artifact| artifact["id"] == id),
        "the owner's org list contains their private artifact"
    );
    Ok(())
}

#[tokio::test]
#[ignore = "requires an isolated local Wrangler Worker"]
async fn private_artifact_is_never_served_anonymously() -> Result<(), Box<dyn Error>> {
    let Some(context) = context() else {
        return Ok(());
    };
    let client = RetryingClient::new();
    let bytes = unique_html("private-anonymous");
    let id = create_sharing_artifact(&context, &client, &context.token, &bytes, "private", "view")
        .await?;

    let (status, body) = get_and_read(&client, format!("{}/p/{id}/", context.base)).await?;
    assert_ne!(
        status,
        reqwest::StatusCode::OK,
        "a private artifact must never be served with no credential: {}",
        String::from_utf8_lossy(&body)
    );
    Ok(())
}

#[tokio::test]
#[ignore = "requires an isolated local Wrangler Worker"]
async fn sharing_change_takes_effect_on_next_request() -> Result<(), Box<dyn Error>> {
    let Some(context) = context() else {
        return Ok(());
    };
    let Some(owner_token) = context.member_token.as_deref() else {
        return Ok(());
    };
    let client = RetryingClient::new();
    let bytes = unique_html("sharing-downgrade");
    let id =
        create_sharing_artifact(&context, &client, owner_token, &bytes, "public", "view").await?;

    // A public artifact is served with no credential, and the response is
    // uncached, so a later downgrade cannot be outlived by a cache.
    let served = client
        .get(format!("{}/p/{id}/", context.base))
        .send()
        .await?;
    assert_eq!(served.status(), reqwest::StatusCode::OK);
    assert_eq!(
        response_header(&served, "cache-control"),
        "private, no-store"
    );
    assert_eq!(served.bytes().await?.as_ref(), bytes.as_slice());

    let (status, body) = patch_sharing_as(
        &context,
        &client,
        owner_token,
        &id,
        json!({"sharing": "team"}),
    )
    .await?;
    assert_eq!(
        status,
        reqwest::StatusCode::OK,
        "sharing change failed: {body}"
    );
    assert_eq!(body["sharing"], "team");
    assert_eq!(body["previous_sharing"], "public");

    // The downgrade is live on the very next request: no credential, no body.
    let (status, _) = get_and_read(&client, format!("{}/p/{id}/", context.base)).await?;
    assert_ne!(
        status,
        reqwest::StatusCode::OK,
        "a downgraded artifact is no longer anonymous"
    );

    // The team artifact still serves to its member, under the same no-store
    // policy.
    let served = client
        .get(format!("{}/p/{id}/", context.base))
        .bearer_auth(owner_token)
        .send()
        .await?;
    assert_eq!(served.status(), reqwest::StatusCode::OK);
    assert_eq!(
        response_header(&served, "cache-control"),
        "private, no-store"
    );
    assert_eq!(served.bytes().await?.as_ref(), bytes.as_slice());
    Ok(())
}

#[tokio::test]
#[ignore = "requires an isolated local Wrangler Worker"]
async fn only_owner_or_admin_can_change_sharing() -> Result<(), Box<dyn Error>> {
    let Some(context) = context() else {
        return Ok(());
    };
    let (Some(owner_token), Some(viewer_token), Some(other_org_token)) = (
        context.member_token.as_deref(),
        context.member2_token.as_deref(),
        context.other_org_token.as_deref(),
    ) else {
        return Ok(());
    };
    let client = RetryingClient::new();
    let bytes = unique_html("sharing-permission");
    let id =
        create_sharing_artifact(&context, &client, owner_token, &bytes, "team", "view").await?;

    // A plain member who is not the owner may not change it.
    let (status, _) = patch_sharing_as(
        &context,
        &client,
        viewer_token,
        &id,
        json!({"sharing": "private"}),
    )
    .await?;
    assert_eq!(status, reqwest::StatusCode::FORBIDDEN);

    // Another org's admin cannot even see it: 404, not 403.
    let (status, _) = patch_sharing_as(
        &context,
        &client,
        other_org_token,
        &id,
        json!({"sharing": "private"}),
    )
    .await?;
    assert_eq!(status, reqwest::StatusCode::NOT_FOUND);
    Ok(())
}

#[tokio::test]
#[ignore = "requires an isolated local Wrangler Worker"]
async fn public_sharing_off_downgrades_and_refuses_public() -> Result<(), Box<dyn Error>> {
    let Some(context) = context() else {
        return Ok(());
    };
    let Some(secret) = context.limits_secret.as_deref() else {
        return Ok(());
    };
    let client = RetryingClient::new();
    let bytes = unique_html("public-off");
    let id = create_sharing_artifact(&context, &client, &context.token, &bytes, "public", "view")
        .await?;

    // Turning public off downgrades every live public artifact in the same
    // call and names the ones it moved.
    let (status, body) = internal_post(
        &context,
        &client,
        secret,
        "/v1/internal/org-settings",
        json!({"org": context.org.clone(), "public_sharing_allowed": false}),
    )
    .await?;
    assert_eq!(
        status,
        reqwest::StatusCode::OK,
        "settings write failed: {body}"
    );
    assert!(
        body["downgraded"]
            .as_array()
            .ok_or("downgraded omitted")?
            .iter()
            .any(|downgraded| downgraded == &id),
        "the live public artifact must be listed as downgraded"
    );

    // Choosing public is refused with the stable code while it is off.
    let refused_bytes = unique_html("public-off-refused");
    let (status, body) = send_json(
        client
            .post(format!("{}/v1/artifacts", context.base))
            .json(&sharing_payload(&refused_bytes, "public", "view")),
        &context.token,
    )
    .await?;
    assert_eq!(
        status,
        reqwest::StatusCode::FORBIDDEN,
        "public must be refused while the team has it off: {body}"
    );
    assert_eq!(body["error"]["code"], "public_sharing_disabled");

    // Re-enable so the rest of the suite sees the default.
    let (status, _) = internal_post(
        &context,
        &client,
        secret,
        "/v1/internal/org-settings",
        json!({"org": context.org.clone(), "public_sharing_allowed": true}),
    )
    .await?;
    assert_eq!(status, reqwest::StatusCode::OK);
    Ok(())
}

#[tokio::test]
#[ignore = "requires an isolated local Wrangler Worker"]
async fn team_edit_lets_members_publish_versions() -> Result<(), Box<dyn Error>> {
    let Some(context) = context() else {
        return Ok(());
    };
    let (Some(owner_token), Some(publisher_token)) = (
        context.member_token.as_deref(),
        context.member2_token.as_deref(),
    ) else {
        return Ok(());
    };
    let client = RetryingClient::new();
    let bytes = unique_html("team-edit-v1");
    let id =
        create_sharing_artifact(&context, &client, owner_token, &bytes, "team", "view").await?;

    // team + view: another member may see it but not version it.
    let (status, _) = post_version_as(
        &context,
        &client,
        publisher_token,
        &id,
        &unique_html("team-edit-v2"),
    )
    .await?;
    assert_eq!(status, reqwest::StatusCode::FORBIDDEN);

    // The owner opens editing to the team.
    let (status, body) = patch_sharing_as(
        &context,
        &client,
        owner_token,
        &id,
        json!({"edit_access": "edit"}),
    )
    .await?;
    assert_eq!(
        status,
        reqwest::StatusCode::OK,
        "edit_access change failed: {body}"
    );
    assert_eq!(body["edit_access"], "edit");

    let (status, body) = post_version_as(
        &context,
        &client,
        publisher_token,
        &id,
        &unique_html("team-edit-v2"),
    )
    .await?;
    assert_eq!(
        status,
        reqwest::StatusCode::CREATED,
        "team + edit lets a member publish a version: {body}"
    );
    Ok(())
}

#[tokio::test]
#[ignore = "requires an isolated local Wrangler Worker"]
async fn public_edit_allows_another_org_to_publish_a_version() -> Result<(), Box<dyn Error>> {
    let Some(context) = context() else {
        return Ok(());
    };
    let (Some(owner_token), Some(other_org_token)) = (
        context.member_token.as_deref(),
        context.other_org_token.as_deref(),
    ) else {
        return Ok(());
    };
    let client = RetryingClient::new();
    let bytes = unique_html("public-edit-v1");
    let id =
        create_sharing_artifact(&context, &client, owner_token, &bytes, "public", "edit").await?;

    // public + edit is the one cross-org write: any signed-in account may
    // publish a version.
    let v2 = unique_html("public-edit-v2");
    let (status, body) = post_version_as(&context, &client, other_org_token, &id, &v2).await?;
    assert_eq!(
        status,
        reqwest::StatusCode::CREATED,
        "public + edit lets another org publish: {body}"
    );
    let version = body["version"].as_u64().ok_or("version omitted")?;

    // A novel file means the version starts pending; upload it as the same
    // other-org editor (the cross-org Public + edit upload path) so it
    // completes and history can be read back.
    let missing = body["missing_files"]
        .as_array()
        .cloned()
        .unwrap_or_default();
    assert!(
        !missing.is_empty(),
        "a novel file must be reported missing on a new version: {body}"
    );
    for hash in &missing {
        let hash = hash.as_str().ok_or("missing_files entry is not a string")?;
        let upload = client
            .put(format!("{}/v1/artifacts/{id}/files/{hash}", context.base))
            .bearer_auth(other_org_token)
            .header(reqwest::header::CONTENT_TYPE, "text/html; charset=utf-8")
            .body(v2.clone())
            .send()
            .await?;
        assert_eq!(
            upload.status(),
            reqwest::StatusCode::NO_CONTENT,
            "cross-org upload failed"
        );
    }

    // The version records the other-org user as its author. The owner reads it
    // back, because the version read path is same-org.
    let other_user = token_user_id(other_org_token)?;
    let (status, version_body) =
        get_version_as(&context, &client, &context.token, &id, version).await?;
    assert_eq!(
        status,
        reqwest::StatusCode::OK,
        "version read failed: {version_body}"
    );
    assert_eq!(
        version_body["created_by"].as_str(),
        Some(other_user.as_str()),
        "the version must record the cross-org publisher"
    );

    // public + view grants no such thing.
    let view_bytes = unique_html("public-view-v1");
    let view_id = create_sharing_artifact(
        &context,
        &client,
        owner_token,
        &view_bytes,
        "public",
        "view",
    )
    .await?;
    let (status, _) = post_version_as(
        &context,
        &client,
        other_org_token,
        &view_id,
        &unique_html("public-view-v2"),
    )
    .await?;
    assert!(
        status == reqwest::StatusCode::FORBIDDEN || status == reqwest::StatusCode::NOT_FOUND,
        "public + view must not let another org publish a version: {status}"
    );
    Ok(())
}

#[tokio::test]
#[ignore = "requires an isolated local Wrangler Worker"]
async fn owner_backfill_sets_owners() -> Result<(), Box<dyn Error>> {
    let Some(context) = context() else {
        return Ok(());
    };
    let Some(secret) = context.limits_secret.as_deref() else {
        return Ok(());
    };
    let client = RetryingClient::new();
    let bytes = unique_html("owner-backfill");
    let id =
        create_sharing_artifact(&context, &client, &context.token, &bytes, "team", "view").await?;

    // Simulate an artifact published before owners were recorded.
    d1_execute(
        &context,
        &format!("UPDATE artifacts SET user_id = NULL WHERE id = '{id}'"),
    )?;

    let owner = "e2e-backfill-owner";
    let (status, body) = internal_post(
        &context,
        &client,
        secret,
        &format!("/v1/internal/orgs/{}/owner-backfill", context.org),
        json!({"owner_user_id": owner}),
    )
    .await?;
    assert_eq!(
        status,
        reqwest::StatusCode::OK,
        "owner backfill failed: {body}"
    );
    assert!(
        body["updated"].as_u64().ok_or("updated omitted")? >= 1,
        "the ownerless artifact must be counted: {body}"
    );

    let row = d1_row(
        &context,
        &format!("SELECT user_id FROM artifacts WHERE id = '{id}'"),
    )?;
    assert_eq!(row["user_id"].as_str(), Some(owner));
    Ok(())
}

#[tokio::test]
#[ignore = "requires an isolated local Wrangler Worker"]
async fn isolated_origin_frames_only_for_the_app() -> Result<(), Box<dyn Error>> {
    let Some(context) = context() else {
        return Ok(());
    };
    let client = RetryingClient::new();
    let bytes = unique_html("isolated-framing");
    let id = create_sharing_artifact(&context, &client, &context.token, &bytes, "public", "view")
        .await?;
    let expires_at_unix = std::time::SystemTime::now()
        .duration_since(std::time::UNIX_EPOCH)?
        .as_secs() as i64
        + 3600;
    let token = mint_access_token(&context.token_secret, &id, expires_at_unix);
    let origin = isolated_origin(&context.org, &id, &context.origin_suffix);
    let path = format!("{}/p/{id}", context.base);

    let framed = client
        .get(format!("{path}?token={token}"))
        .header(reqwest::header::HOST, origin.as_str())
        .send()
        .await?;
    assert_eq!(framed.status(), reqwest::StatusCode::OK);
    let csp = response_header(&framed, "content-security-policy");
    assert_eq!(framed.bytes().await?.as_ref(), bytes.as_slice());
    match context.app_origin.as_deref() {
        Some(app_origin) => assert!(
            csp.contains(&format!("frame-ancestors {app_origin};")),
            "the app origin must be the one origin allowed to frame an isolated artifact: {csp}"
        ),
        None => assert!(
            !csp.contains("frame-ancestors 'none'"),
            "with a configured app origin an isolated response must not claim to be unframable: \
             {csp}"
        ),
    }

    // A public artifact is anonymous on its own origin, token or not.
    let anonymous = client
        .get(&path)
        .header(reqwest::header::HOST, origin.as_str())
        .send()
        .await?;
    assert_eq!(
        anonymous.status(),
        reqwest::StatusCode::OK,
        "a public artifact is served with no token on its isolated origin"
    );
    assert_eq!(anonymous.bytes().await?.as_ref(), bytes.as_slice());
    Ok(())
}
