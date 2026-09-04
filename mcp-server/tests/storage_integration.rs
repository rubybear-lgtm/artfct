//! Production-path storage checks for an isolated local Wrangler Worker.
//!
//! These tests are ignored by default. They require all of
//! `ARTFCT_INTEGRATION_BASE_URL`, `ARTFCT_INTEGRATION_TOKEN`,
//! `ARTFCT_INTEGRATION_PERSIST_TO`, and `ARTFCT_WRANGLER_BIN`, then run with:
//! `cargo test -p artfct --test storage_integration -- --ignored`.

use std::{env, error::Error, fs, process::Command};

use ring::digest;
use serde_json::{json, Value};

#[derive(Clone)]
struct Context {
    base: String,
    token: String,
    org: String,
    persist_to: String,
    wrangler: String,
}

fn context() -> Option<Context> {
    let names = [
        "ARTFCT_INTEGRATION_BASE_URL",
        "ARTFCT_INTEGRATION_TOKEN",
        "ARTFCT_INTEGRATION_PERSIST_TO",
        "ARTFCT_WRANGLER_BIN",
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
        "storage integration requires all four environment variables"
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
    let hash = sha256(bytes);
    json!({
        "mode": "permanent", "tier": "public", "title": "Storage integration",
        "description": "Storage integration", "thumbnail": "https://example.com/thumbnail.png",
        "preview_blurred": false,
        "manifest": {"entrypoint": "index.html", "external_origins": [], "files": [{
            "path": "index.html", "content_type": "text/html; charset=utf-8", "size_bytes": bytes.len(), "sha256": hash
        }]},
        "provenance": provenance
    })
}

async fn create_and_upload(
    context: &Context,
    client: &reqwest::Client,
    bytes: &[u8],
    provenance: Value,
) -> Result<(String, String), Box<dyn Error>> {
    let hash = sha256(bytes);
    let created = client
        .post(format!("{}/v1/artifacts", context.base))
        .bearer_auth(&context.token)
        .json(&permanent_payload(bytes, provenance))
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
    let output = Command::new(&context.wrangler)
        .current_dir(format!("{}/../backend", env!("CARGO_MANIFEST_DIR")))
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
        .output()?;
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

fn count_value(context: &Context, sql: &str, key: &str) -> Result<i64, Box<dyn Error>> {
    Ok(d1_row(context, sql)?[key]
        .as_i64()
        .ok_or_else(|| format!("D1 result field {key} was not an integer"))?)
}

#[tokio::test]
#[ignore = "requires an isolated local Wrangler Worker"]
async fn permanent_roundtrip_stores_d1_and_r2() -> Result<(), Box<dyn Error>> {
    let Some(context) = context() else {
        return Ok(());
    };
    let client = reqwest::Client::new();
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
#[ignore = "requires an isolated local Wrangler Worker"]
async fn six_megabyte_permanent_file_succeeds() -> Result<(), Box<dyn Error>> {
    let Some(context) = context() else {
        return Ok(());
    };
    let client = reqwest::Client::new();
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
async fn duplicate_content_has_two_artifacts_and_one_blob() -> Result<(), Box<dyn Error>> {
    let Some(context) = context() else {
        return Ok(());
    };
    let client = reqwest::Client::new();
    let bytes = unique_html("duplicate");
    let (first, hash) = create_and_upload(&context, &client, &bytes, json!({})).await?;
    let (second, second_hash) = create_and_upload(&context, &client, &bytes, json!({})).await?;
    assert_eq!(first, second);
    assert_eq!(hash, second_hash);
    assert_eq!(first.len(), 32);
    assert_eq!(count_value(&context, &format!("SELECT COUNT(*) AS count FROM artifacts WHERE id = '{first}' AND content_hash = '{hash}'"), "count")?, 2);
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
        2
    );
    Ok(())
}

#[tokio::test]
#[ignore = "requires an isolated local Wrangler Worker"]
async fn concurrent_identical_creates_have_one_blob_and_two_references(
) -> Result<(), Box<dyn Error>> {
    let Some(context) = context() else {
        return Ok(());
    };
    let client = reqwest::Client::new();
    let bytes = unique_html("concurrent");
    let left = create_and_upload(&context, &client, &bytes, json!({}));
    let right = create_and_upload(&context, &client, &bytes, json!({}));
    let (left, right) = tokio::join!(left, right);
    let (left_id, hash) = left?;
    let (right_id, right_hash) = right?;
    assert_eq!(left_id, right_id);
    assert_eq!(hash, right_hash);
    assert_eq!(
        count_value(
            &context,
            &format!("SELECT COUNT(*) AS count FROM artifacts WHERE id = '{left_id}'"),
            "count"
        )?,
        2
    );
    assert_eq!(
        count_value(
            &context,
            &format!("SELECT ref_count AS count FROM blobs WHERE content_hash = '{hash}'"),
            "count"
        )?,
        2
    );
    Ok(())
}

#[tokio::test]
#[ignore = "requires an isolated local Wrangler Worker"]
async fn concurrent_deletes_do_not_remove_a_live_blob() -> Result<(), Box<dyn Error>> {
    let Some(context) = context() else {
        return Ok(());
    };
    let client = reqwest::Client::new();
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
    assert_eq!(left?.status(), reqwest::StatusCode::NO_CONTENT);
    assert_eq!(right?.status(), reqwest::StatusCode::NO_CONTENT);
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
async fn create_raced_with_final_delete_keeps_live_blob() -> Result<(), Box<dyn Error>> {
    let Some(context) = context() else {
        return Ok(());
    };
    let client = reqwest::Client::new();
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
    assert_eq!(created_id, id);
    assert_eq!(created_hash, hash);

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
    let client = reqwest::Client::new();
    let bytes = unique_html("delete");
    let (id, hash) = create_and_upload(&context, &client, &bytes, json!({})).await?;
    create_and_upload(&context, &client, &bytes, json!({})).await?;
    for last in [false, true] {
        let response = client
            .delete(format!("{}/v1/artifacts/{id}", context.base))
            .bearer_auth(&context.token)
            .send()
            .await?;
        assert_eq!(response.status(), reqwest::StatusCode::NO_CONTENT);
        if last {
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
                    .get(format!("{}/p/{id}", context.base))
                    .send()
                    .await?
                    .status(),
                reqwest::StatusCode::NOT_FOUND
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
        } else {
            assert_eq!(
                count_value(
                    &context,
                    &format!("SELECT ref_count AS count FROM blobs WHERE content_hash = '{hash}'"),
                    "count"
                )?,
                1
            );
            assert_eq!(
                client
                    .get(format!("{}/p/{id}", context.base))
                    .send()
                    .await?
                    .status(),
                reqwest::StatusCode::OK
            );
        }
    }
    Ok(())
}

#[tokio::test]
#[ignore = "requires an isolated local Wrangler Worker"]
async fn provenance_columns_and_complete_json_survive() -> Result<(), Box<dyn Error>> {
    let Some(context) = context() else {
        return Ok(());
    };
    let client = reqwest::Client::new();
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
    let client = reqwest::Client::new();
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
    let client = reqwest::Client::new();
    let bytes = unique_html("cli-export");
    let (_, hash) = create_and_upload(&context, &client, &bytes, json!({})).await?;
    let directory = tempfile::tempdir()?;
    let output = Command::new(env!("CARGO_BIN_EXE_artfct"))
        .args([
            "export",
            &context.org,
            directory.path().to_str().ok_or("directory is not UTF-8")?,
        ])
        .env("ARTFCT_API_BASE_URL", &context.base)
        .env("ARTFCT_ORG_TOKEN", &context.token)
        .output()?;
    assert!(
        output.status.success(),
        "CLI export failed: {}",
        String::from_utf8_lossy(&output.stderr)
    );
    let metadata: Value =
        serde_json::from_slice(&fs::read(directory.path().join("metadata.json"))?)?;
    assert!(metadata["artifacts"].is_array());
    assert!(metadata["blobs"][&hash].as_str().is_some());
    assert_eq!(fs::read(directory.path().join("blobs").join(&hash))?, bytes);
    Ok(())
}

#[tokio::test]
#[ignore = "requires an isolated local Wrangler Worker"]
async fn permanent_mode_requires_auth() -> Result<(), Box<dyn Error>> {
    let Some(context) = context() else {
        return Ok(());
    };
    let response = reqwest::Client::new()
        .post(format!("{}/v1/artifacts", context.base))
        .json(&json!({"mode": "permanent"}))
        .send()
        .await?;
    assert_eq!(response.status(), reqwest::StatusCode::UNAUTHORIZED);
    Ok(())
}

#[tokio::test]
#[ignore = "requires an isolated local Wrangler Worker"]
async fn ephemeral_mode_rejects_manifest_field() -> Result<(), Box<dyn Error>> {
    let Some(context) = context() else {
        return Ok(());
    };
    let response = reqwest::Client::new()
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
    let directory = tempfile::tempdir()?;
    let html_path = directory.path().join("ephemeral.html");
    fs::write(&html_path, b"<h1>ephemeral integration</h1>")?;
    let output = Command::new(env!("CARGO_BIN_EXE_artfct"))
        .args(["deploy", html_path.to_str().ok_or("path is not UTF-8")?])
        .env("ARTFCT_API_BASE_URL", &context.base)
        .output()?;
    assert!(
        output.status.success(),
        "CLI deploy failed: {}",
        String::from_utf8_lossy(&output.stderr)
    );
    let base_prefix = format!("{}/p/", context.base);
    let stdout = String::from_utf8(output.stdout)?;
    let preview_url = stdout
        .lines()
        .find(|line| line.trim_start().starts_with(&base_prefix))
        .and_then(|line| line.trim().split('#').next())
        .ok_or("CLI deploy omitted preview URL")?
        .to_string();
    let preview = reqwest::get(preview_url).await?;
    assert_eq!(preview.status(), reqwest::StatusCode::OK);
    let body = preview.text().await?;
    assert!(body.contains("bodyCiphertextB64"));
    assert!(body.contains("Waiting for the decryption key"));
    Ok(())
}
