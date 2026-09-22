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

/// PUTs `body` to `url` and returns the response, retrying a bounded number
/// of times on a bare transport-level failure (the connection dropped
/// before a complete response arrived) — never on a response the server
/// actually sent, even an unexpected status. Some CI runners are slow
/// enough under load that a request which triggers a heavier synchronous
/// write cascade (e.g. cross-table cascade delete on expiry) occasionally
/// gets its response cut off; this call is the one place that's been
/// observed happening (`expired_incomplete_bundle_is_cleaned_up`, CI-only,
/// never locally), not a server bug to paper over silently: repeating the
/// same idempotent PUT is safe, and a real bug still fails after retries.
async fn put_with_retry(
    client: &reqwest::Client,
    url: &str,
    token: &str,
    body: Vec<u8>,
) -> Result<reqwest::Response, reqwest::Error> {
    let attempts = 3;
    for attempt in 1..=attempts {
        match client
            .put(url)
            .bearer_auth(token)
            .body(body.clone())
            .send()
            .await
        {
            Ok(response) => return Ok(response),
            Err(error) if attempt < attempts && error.is_request() && error.status().is_none() => {
                tokio::time::sleep(std::time::Duration::from_millis(200 * attempt)).await;
            }
            Err(error) => return Err(error),
        }
    }
    unreachable!("loop always returns on the final attempt")
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
    client: &reqwest::Client,
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
            .header(reqwest::header::CONTENT_TYPE, *content_type)
            .body(bytes.to_vec())
            .send()
            .await?;
        assert_eq!(upload.status(), reqwest::StatusCode::NO_CONTENT);
    }
    Ok(id)
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

fn d1_execute(context: &Context, sql: &str) -> Result<(), Box<dyn Error>> {
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
        ])
        .output()?;
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
async fn reposting_identical_content_keeps_one_artifact_and_one_blob() -> Result<(), Box<dyn Error>>
{
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
    let client = reqwest::Client::new();
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
    let client = reqwest::Client::new();
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
    let client = reqwest::Client::new();
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
    let output = Command::new(env!("CARGO_BIN_EXE_artfct"))
        .args([
            "deploy",
            output_dir.path().to_str().ok_or("path is not UTF-8")?,
            "--tier",
            "permanent",
        ])
        .env("ARTFCT_API_BASE_URL", &context.base)
        .env("ARTFCT_ORG_TOKEN", &context.token)
        .output()?;
    assert!(
        output.status.success(),
        "bundle deploy failed: {}",
        String::from_utf8_lossy(&output.stderr)
    );
    let base_prefix = format!("{}/p/", context.base);
    let url = String::from_utf8(output.stdout)?
        .lines()
        .find(|line| line.trim_start().starts_with(&base_prefix))
        .ok_or("bundle deploy omitted URL")?
        .split_whitespace()
        .next()
        .ok_or("bundle deploy URL was empty")?
        .to_string();
    let preview = reqwest::get(&url).await?;
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
        let response = reqwest::get(file_url).await?;
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
    let client = reqwest::Client::new();
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
    let client = reqwest::Client::new();
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
    let client = reqwest::Client::new();
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
    let upload = put_with_retry(
        &client,
        &format!("{}/v1/artifacts/{id}/files/{script_hash}", context.base),
        &context.token,
        script.to_vec(),
    )
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
    let client = reqwest::Client::new();
    let bytes = b"same bytes";
    let hash = sha256(bytes);
    let response = client
        .post(format!("{}/v1/artifacts", context.base))
        .bearer_auth(&context.token)
        .json(&bundle_payload(
            &[
                ("index.html", bytes, "text/html"),
                ("assets/app.js", bytes, "application/javascript"),
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
    let run = |dir: &std::path::Path| -> Result<std::process::Output, Box<dyn Error>> {
        Ok(Command::new(env!("CARGO_BIN_EXE_artfct"))
            .args([
                "deploy",
                dir.to_str().ok_or("path is not UTF-8")?,
                "--tier",
                "permanent",
            ])
            .env("ARTFCT_API_BASE_URL", &context.base)
            .env("ARTFCT_ORG_TOKEN", &context.token)
            .output()?)
    };
    let first = run(output_dir.path())?;
    assert!(first.status.success(), "first deploy failed");
    let changed = output_dir.path().join("index.html");
    let mut html = fs::read_to_string(&changed)?;
    html.push_str("<!-- changed -->");
    fs::write(changed, html)?;
    let second = run(output_dir.path())?;
    assert!(
        second.status.success(),
        "second deploy failed: {}",
        String::from_utf8_lossy(&second.stderr)
    );
    let stdout = String::from_utf8(second.stdout)?;
    assert!(
        stdout.contains("uploaded 1 file(s)"),
        "unexpected upload report: {stdout}"
    );
    assert!(
        stdout.contains(&format!("skipped {} file(s)", initial_files.len() - 1)),
        "unexpected skip report: {stdout}"
    );
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
