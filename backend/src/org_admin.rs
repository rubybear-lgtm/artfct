use super::*;

const LIMITS_WRITE_SECRET_ENV: &str = "ARTFCT_LIMITS_WRITE_SECRET";
const DEFAULT_STORAGE_BYTES: u64 = 5 * 1024 * 1024 * 1024;
const DEFAULT_ARTIFACTS_PER_MONTH: u64 = 1000;
const DEFAULT_BUNDLE_CEILING_BYTES: u64 = 10 * 1024 * 1024;

#[derive(Debug, Deserialize)]
pub(crate) struct OrgLimitsRow {
    storage_bytes: i64,
    artifacts_per_month: i64,
    bundle_ceiling_bytes: i64,
    read_only: i64,
}

#[derive(Debug, Deserialize)]
pub(crate) struct CountRow {
    value: i64,
}

/// Limits for `org`: the pushed `org_limits` row, else defaults mirroring
/// Laravel's `QuotaLimits::default()` (overridable through Worker vars).
pub(crate) async fn load_org_limits(
    database: &worker::D1Database,
    env: &Env,
    org: &str,
) -> Result<quota::OrgLimits> {
    let row = database
        .prepare("SELECT storage_bytes, artifacts_per_month, bundle_ceiling_bytes, read_only FROM org_limits WHERE org_id = ?")
        .bind(&[JsValue::from_str(org)])?
        .first::<OrgLimitsRow>(None)
        .await?;
    Ok(match row {
        Some(row) => quota::OrgLimits {
            storage_bytes: row.storage_bytes.max(0) as u64,
            artifacts_per_month: row.artifacts_per_month.max(0) as u64,
            bundle_ceiling_bytes: row.bundle_ceiling_bytes.max(0) as u64,
            read_only: row.read_only != 0,
        },
        None => quota::OrgLimits {
            storage_bytes: env_u64(env, "ARTFCT_DEFAULT_STORAGE_BYTES", DEFAULT_STORAGE_BYTES),
            artifacts_per_month: env_u64(
                env,
                "ARTFCT_DEFAULT_ARTIFACTS_PER_MONTH",
                DEFAULT_ARTIFACTS_PER_MONTH,
            ),
            bundle_ceiling_bytes: env_u64(
                env,
                "ARTFCT_DEFAULT_BUNDLE_CEILING_BYTES",
                DEFAULT_BUNDLE_CEILING_BYTES,
            ),
            read_only: false,
        },
    })
}

/// D1 is the source of truth: storage counts each distinct `content_hash`
/// once per org; the period count is artifacts created this UTC month
/// (a soft-deleted artifact still counts; a hard delete would drop it).
pub(crate) async fn load_org_usage(
    database: &worker::D1Database,
    org: &str,
) -> Result<quota::OrgUsage> {
    let storage = database
        .prepare("SELECT COALESCE(SUM(size), 0) AS value FROM (SELECT f.content_hash, MAX(f.size_bytes) AS size FROM files f JOIN artifacts a ON a.row_id = f.artifact_row_id WHERE a.org_id = ? GROUP BY f.content_hash)")
        .bind(&[JsValue::from_str(org)])?
        .first::<CountRow>(None)
        .await?;
    let artifacts = database
        .prepare("SELECT COUNT(*) AS value FROM artifacts WHERE org_id = ? AND created_at >= ?")
        .bind(&[
            JsValue::from_str(org),
            JsValue::from_str(&quota::month_start(Utc::now())),
        ])?
        .first::<CountRow>(None)
        .await?;
    Ok(quota::OrgUsage {
        storage_bytes: storage.map_or(0, |row| row.value.max(0) as u64),
        artifacts_this_period: artifacts.map_or(0, |row| row.value.max(0) as u64),
    })
}

/// `Ok(None)` when the add is allowed, else the refusal response.
pub(crate) async fn quota_refusal(
    database: &worker::D1Database,
    env: &Env,
    org: &str,
    incoming_bytes: u64,
    kind: quota::AddKind,
) -> Result<Option<Response>> {
    let limits = load_org_limits(database, env, org).await?;
    let usage = load_org_usage(database, org).await?;
    match quota::assert_can_add(&limits, &usage, incoming_bytes, kind) {
        quota::QuotaDecision::Allowed => Ok(None),
        quota::QuotaDecision::QuotaExceeded(reason) => quota_error_response(
            ErrorCode::QuotaExceeded,
            403,
            serde_json::json!({"reason": reason.as_str()}),
        )
        .map(Some),
        quota::QuotaDecision::BundleTooLarge { limit_bytes } => quota_error_response(
            ErrorCode::BundleTooLarge,
            413,
            serde_json::json!({"limit_bytes": limit_bytes}),
        )
        .map(Some),
    }
}

pub(crate) fn quota_error_response(
    code: ErrorCode,
    status: u16,
    details: Value,
) -> Result<Response> {
    let message = match code {
        ErrorCode::BundleTooLarge => "The bundle exceeds this organization's per-artifact limit.",
        _ => "This organization has reached a usage limit for new artifacts.",
    };
    let mut definition = build_error_response(code, message, status);
    definition.body["error"]["details"] = details;
    definition.into_worker_response()
}

#[derive(Debug, Deserialize)]
pub(crate) struct OrgLimitsWriteRequest {
    org: String,
    storage_bytes: u64,
    artifacts_per_month: u64,
    bundle_ceiling_bytes: u64,
    read_only: bool,
}

/// An unset secret fails closed.
pub(crate) fn limits_write_authorized(secret: Option<&str>, authorization: Option<&str>) -> bool {
    authorization_matches(secret, authorization)
}

/// `POST /v1/internal/org-limits` — Laravel pushes each org's limits and
/// payment state here (spec 14). Own shared secret, same shape as the
/// revocation write; a wrong secret changes nothing.
pub(crate) async fn write_org_limits(req: &mut Request, env: &Env) -> Result<Response> {
    let authorization = req.headers().get("Authorization")?;
    let secret = env
        .var(LIMITS_WRITE_SECRET_ENV)
        .ok()
        .map(|value| value.to_string());
    if !limits_write_authorized(secret.as_deref(), authorization.as_deref()) {
        return json_error(ErrorCode::Unauthorized, "Invalid limits credential.", 401);
    }
    let payload = match req.json::<OrgLimitsWriteRequest>().await {
        Ok(payload) => payload,
        Err(_) => return json_error(ErrorCode::InvalidJson, "Invalid JSON request body.", 400),
    };
    if let Err(message) = store::validate_slug(&payload.org) {
        return json_error(ErrorCode::ValidationFailed, &message, 422);
    }
    let database = env.d1("ARTIFACTS_DB")?;
    database
        .prepare("INSERT INTO org_limits (org_id, storage_bytes, artifacts_per_month, bundle_ceiling_bytes, read_only, updated_at) VALUES (?, ?, ?, ?, ?, ?) ON CONFLICT(org_id) DO UPDATE SET storage_bytes = excluded.storage_bytes, artifacts_per_month = excluded.artifacts_per_month, bundle_ceiling_bytes = excluded.bundle_ceiling_bytes, read_only = excluded.read_only, updated_at = excluded.updated_at")
        .bind(&[
            JsValue::from_str(&payload.org),
            JsValue::from_f64(payload.storage_bytes as f64),
            JsValue::from_f64(payload.artifacts_per_month as f64),
            JsValue::from_f64(payload.bundle_ceiling_bytes as f64),
            JsValue::from_f64(f64::from(u8::from(payload.read_only))),
            JsValue::from_str(&Utc::now().to_rfc3339_opts(SecondsFormat::Secs, true)),
        ])?
        .run()
        .await?;
    JsonResponseDefinition::json(serde_json::json!({"org": payload.org}), 200)
        .into_worker_response()
}

/// Splits `/v1/orgs/{org}/usage` into `org`.
pub(crate) fn parse_usage_path(path: &str) -> Option<&str> {
    let org = path.strip_prefix("/v1/orgs/")?.strip_suffix("/usage")?;
    (!org.is_empty() && !org.contains('/')).then_some(org)
}

/// `GET /v1/orgs/{org}/usage` — the numbers the create gate enforces.
pub(crate) async fn get_org_usage(path: &str, req: &Request, env: &Env) -> Result<Response> {
    let authorization = req.headers().get("Authorization")?;
    let org = parse_usage_path(path).unwrap_or_default();
    let credential_org = match require_org_credential(authorization.as_deref(), env).await? {
        Ok(credential) => Some(credential.org_id),
        Err(_) => None,
    };
    match decide_org_read(credential_org.as_deref(), org) {
        OrgReadDecision::Unauthorized => {
            return json_error(
                ErrorCode::Unauthorized,
                "An organization token is required.",
                401,
            );
        }
        OrgReadDecision::NotFound => {
            return json_error(ErrorCode::ArtifactNotFound, "Organization not found.", 404);
        }
        OrgReadDecision::Allowed => {}
    }
    let database = env.d1("ARTIFACTS_DB")?;
    let usage = load_org_usage(&database, org).await?;
    // The limits the create gate actually enforces, so a meter never
    // disagrees with the Worker about how full an org is.
    let limits = load_org_limits(&database, env, org).await?;
    let now = Utc::now();
    JsonResponseDefinition::json(
        serde_json::json!({
            "storage_bytes": usage.storage_bytes,
            "artifacts_this_period": usage.artifacts_this_period,
            "period_start": quota::month_start(now),
            "period_end": quota::next_month_start(now),
            "limits": {
                "storage_bytes": limits.storage_bytes,
                "artifacts_per_month": limits.artifacts_per_month,
                "bundle_ceiling_bytes": limits.bundle_ceiling_bytes,
                "read_only": limits.read_only,
            },
        }),
        200,
    )
    .into_worker_response()
}

const JWKS_WRITE_SECRET_ENV: &str = "ARTFCT_JWKS_WRITE_SECRET";

/// An unset secret fails closed.
pub(crate) fn jwks_write_authorized(secret: Option<&str>, authorization: Option<&str>) -> bool {
    authorization_matches(secret, authorization)
}

/// A publishable JWKS has at least one key and every key has a kid, n and e.
pub(crate) fn validate_jwks(jwks: &Jwks) -> bool {
    !jwks.keys.is_empty()
        && jwks
            .keys
            .iter()
            .all(|key| !key.kid.is_empty() && !key.n.is_empty() && !key.e.is_empty())
}

/// `POST /v1/internal/jwks` — Laravel publishes the org-token public key(s)
/// here (RUB-343); the Worker verifies credentials against `auth:jwks` in KV.
/// Own shared secret, same shape as the limits and revocation writes.
pub(crate) async fn write_jwks(req: &mut Request, env: &Env) -> Result<Response> {
    let authorization = req.headers().get("Authorization")?;
    let secret = env
        .var(JWKS_WRITE_SECRET_ENV)
        .ok()
        .map(|value| value.to_string());
    if !jwks_write_authorized(secret.as_deref(), authorization.as_deref()) {
        return json_error(ErrorCode::Unauthorized, "Invalid JWKS credential.", 401);
    }
    let jwks = match req.json::<Jwks>().await {
        Ok(jwks) if validate_jwks(&jwks) => jwks,
        _ => {
            return json_error(
                ErrorCode::ValidationFailed,
                "A JWKS with at least one complete key is required.",
                422,
            );
        }
    };
    env.kv(KV_BINDING)?
        .put(JWKS_KV_KEY, serde_json::to_string(&jwks)?)?
        .execute()
        .await?;
    JsonResponseDefinition::json(serde_json::json!({"keys": jwks.keys.len()}), 200)
        .into_worker_response()
}

/// Admin console revocation (spec 8): `PATCH /v1/orgs/{org}/artifacts/{id}`.
/// A soft delete — sets `revoked_at`, retains the row and blob (spec 8's
/// "Revocation" section; reference counting from spec 3 still governs
/// whether a hard-deleted artifact's blob goes, unaffected by this path).
pub(crate) async fn revoke_org_artifact(path: &str, req: &Request, env: &Env) -> Result<Response> {
    let authorization = req.headers().get("Authorization")?;
    let credential = match require_org_credential(authorization.as_deref(), env).await? {
        Ok(credential) => credential,
        Err(refusal) => return Ok(refusal),
    };
    let rest = path.trim_start_matches("/v1/orgs/");
    let Some((org, tail)) = rest.split_once("/artifacts/") else {
        return json_error(ErrorCode::ArtifactNotFound, "Artifact not found.", 404);
    };
    let artifact_id = tail.trim_end_matches('/');
    if !is_valid_artifact_id(artifact_id) {
        return json_error(ErrorCode::InvalidArtifactId, "Invalid artifact id.", 400);
    }
    if let Err(message) = store::validate_slug(org) {
        return json_error(ErrorCode::ValidationFailed, &message, 422);
    }
    if org != credential.org_id {
        return json_error(ErrorCode::ArtifactNotFound, "Organization not found.", 404);
    }
    let storage =
        store::D1R2ArtifactStore::new(env.d1("ARTIFACTS_DB")?, env.bucket("ARTIFACTS_BUCKET")?);
    let updated = storage
        .revoke_artifact(org, &store::ArtifactId(artifact_id.to_string()))
        .await
        .map_err(|error| worker::Error::RustError(error.to_string()))?;
    let Some(item) = updated else {
        return json_error(ErrorCode::ArtifactNotFound, "Artifact not found.", 404);
    };
    JsonResponseDefinition::json(
        serde_json::json!({
            "id": item.id.0,
            "org_id": item.org,
            "revoked_at": item.revoked_at,
        }),
        200,
    )
    .into_worker_response()
}

#[derive(Debug, Serialize)]
pub(crate) struct ExportPayload {
    artifacts: Vec<Value>,
    blobs: std::collections::HashMap<String, String>,
}

#[derive(Debug, Deserialize)]
pub(crate) struct ExportRow {
    pub(crate) id: String,
    pub(crate) content_hash: String,
    pub(crate) entrypoint: String,
    pub(crate) created_at: String,
    pub(crate) provenance: Option<String>,
    pub(crate) manifest: String,
}

pub(crate) async fn export_organization(path: &str, req: &Request, env: &Env) -> Result<Response> {
    let authorization = req.headers().get("Authorization")?;
    let credential = match require_org_credential(authorization.as_deref(), env).await? {
        Ok(credential) => credential,
        Err(refusal) => return Ok(refusal),
    };
    let org = path
        .trim_start_matches("/v1/orgs/")
        .trim_end_matches("/export")
        .trim_end_matches('/');
    if let Err(message) = store::validate_slug(org) {
        return json_error(ErrorCode::ValidationFailed, &message, 422);
    }
    if org != credential.org_id {
        return json_error(ErrorCode::ArtifactNotFound, "Organization not found.", 404);
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
        artifacts.push(export_artifact_entry(&row));
    }
    JsonResponseDefinition::json(ExportPayload { artifacts, blobs }, 200).into_worker_response()
}

/// Builds one artifact's metadata JSON entry for the export payload,
/// factored out of `export_organization` so provenance round-tripping
/// (spec 8 DoD: exported metadata "includes `sources` for every provenance
/// field") is unit-testable without D1 — `row.provenance` is the exact
/// JSON string stored by spec 2's provenance capture, parsed and
/// re-serialized verbatim, never reconstructed field-by-field, so nothing
/// here can drop a `sources` entry spec 2 populated.
pub(crate) fn export_artifact_entry(row: &ExportRow) -> Value {
    serde_json::json!({
        "id": row.id,
        "content_hash": row.content_hash,
        "entrypoint": row.entrypoint,
        "created_at": row.created_at,
        "provenance": row.provenance.as_deref().and_then(|value| serde_json::from_str::<Value>(value).ok()),
    })
}

pub(crate) async fn delete_permanent_artifact(
    artifact_id: &str,
    req: &Request,
    env: &Env,
) -> Result<Response> {
    let authorization = req.headers().get("Authorization")?;
    let credential = match require_org_credential(authorization.as_deref(), env).await? {
        Ok(credential) => credential,
        Err(refusal) => return Ok(refusal),
    };
    let storage =
        store::D1R2ArtifactStore::new(env.d1("ARTIFACTS_DB")?, env.bucket("ARTIFACTS_BUCKET")?);
    let org = credential.org_id.clone();
    match hard_delete_permanent(&storage, &org, artifact_id).await? {
        HardDeleteOutcome::Deleted => build_delete_response().into_worker_response(),
        HardDeleteOutcome::NotFound => {
            json_error(ErrorCode::ArtifactNotFound, "Artifact not found.", 404)
        }
        HardDeleteOutcome::LegalHold => legal_hold_response(),
        HardDeleteOutcome::Contention => retryable_contention_response(),
    }
}

pub(crate) fn legal_hold_response() -> Result<Response> {
    let mut definition = build_error_response(
        ErrorCode::Forbidden,
        "The artifact is under legal hold and cannot be deleted.",
        409,
    );
    definition.body["error"]["details"] = serde_json::json!({"reason": "legal_hold"});
    definition.into_worker_response()
}

pub(crate) enum HardDeleteOutcome {
    Deleted,
    NotFound,
    LegalHold,
    Contention,
}

/// The one hard-delete path (public `DELETE` and the governance route):
/// refuses a held artifact before any write; in one D1 batch deletes the
/// row (cascading files, provenance, versions, shares), decrements each
/// referenced blob and writes the audit row; then, under the content locks,
/// removes blobs whose refcount reached zero. A failed R2 delete leaves an
/// orphan, never a dangling reference.
pub(crate) async fn hard_delete_permanent(
    storage: &store::D1R2ArtifactStore,
    org: &str,
    artifact_id: &str,
) -> Result<HardDeleteOutcome> {
    let database = &storage.database;
    let select = "SELECT a.manifest, (SELECT MAX(legal_hold) FROM artifacts held WHERE held.id = a.id AND held.org_id = a.org_id) AS legal_hold FROM artifacts a JOIN orgs o ON o.id = a.org_id WHERE a.id = ? AND o.slug = ? ORDER BY a.row_id LIMIT 1";
    let lock_row = database
        .prepare(select)
        .bind(&[JsValue::from_str(artifact_id), JsValue::from_str(org)])?
        .first::<PermanentHashRow>(None)
        .await?;
    let Some(lock_row) = lock_row else {
        return Ok(HardDeleteOutcome::NotFound);
    };
    if governance::decide_hard_delete(lock_row.legal_hold != 0)
        == governance::HardDeleteDecision::RefuseLegalHold
    {
        return Ok(HardDeleteOutcome::LegalHold);
    }
    let lock_hashes = serde_json::from_str::<PermanentManifest>(&lock_row.manifest)
        .map(|manifest| {
            manifest
                .files
                .into_iter()
                .map(|file| file.sha256)
                .collect::<Vec<_>>()
        })
        .unwrap_or_default();
    let locks = match storage.acquire_content_locks(&lock_hashes).await {
        Ok(locks) => locks,
        Err(store::StoreError::Contention) => return Ok(HardDeleteOutcome::Contention),
        Err(error) => return Err(worker::Error::RustError(error.to_string())),
    };
    let operation: Result<HardDeleteOutcome> = async {
        let row = database
            .prepare(select)
            .bind(&[JsValue::from_str(artifact_id), JsValue::from_str(org)])?
            .first::<PermanentHashRow>(None)
            .await?;
        let Some(row) = row else {
            return Ok(HardDeleteOutcome::NotFound);
        };
        // Re-checked under the lock: a hold placed while waiting still wins.
        if governance::decide_hard_delete(row.legal_hold != 0)
            == governance::HardDeleteDecision::RefuseLegalHold
        {
            return Ok(HardDeleteOutcome::LegalHold);
        }
        let manifest = serde_json::from_str::<PermanentManifest>(&row.manifest)?;
        // Delete every row for this (org, id), not only the one the pre-flight
        // resolution happened to return. Before RUB-371 a single-row delete
        // reported 204 while a duplicate kept serving the artifact from
        // `/p/{id}` — a revocation that silently did not revoke, which is what
        // matters for the abuse and legal-hold paths.
        let mut statements = vec![database
            .prepare(
                "DELETE FROM artifacts WHERE id = ? AND org_id = (SELECT id FROM orgs WHERE slug = ?)",
            )
            .bind(&[
                JsValue::from_str(artifact_id),
                JsValue::from_str(org),
            ])?];
        // Recompute rather than decrement. `ref_count` counts file rows by
        // construction, and the duplicates this delete is cleaning up had
        // inflated it N-fold, so decrementing once would leave the count above
        // zero and the blob unreclaimable forever. After the delete the
        // surviving file rows are the source of truth.
        for file in &manifest.files {
            statements.push(
                database
                    .prepare(
                        "UPDATE blobs SET ref_count = (SELECT COUNT(*) FROM files WHERE files.content_hash = blobs.content_hash) WHERE content_hash = ?",
                    )
                    .bind(&[JsValue::from_str(&file.sha256)])?,
            );
        }
        statements.push(governance_audit_statement(
            database,
            org,
            governance::AuditEventType::ArtifactHardDeleted,
            artifact_id,
        )?);
        storage
            .execute_batch(statements)
            .await
            .map_err(|error| worker::Error::RustError(error.to_string()))?;
        let mut released = std::collections::HashSet::new();
        for file in manifest.files {
            if released.insert(file.sha256.clone()) {
                release_blob_if_unreferenced(storage, &file.sha256).await?;
            }
        }
        Ok(HardDeleteOutcome::Deleted)
    }
    .await;
    let release_result = storage.release_content_locks(&locks).await;
    let outcome = operation?;
    release_result.map_err(|error| worker::Error::RustError(error.to_string()))?;
    Ok(outcome)
}

/// Deletes the blob row and R2 object when nothing references the hash.
/// Callers hold the content lock for `content_hash`.
pub(crate) async fn release_blob_if_unreferenced(
    storage: &store::D1R2ArtifactStore,
    content_hash: &str,
) -> Result<()> {
    let count = storage
        .database
        .prepare("SELECT ref_count AS count FROM blobs WHERE content_hash = ?")
        .bind(&[JsValue::from_str(content_hash)])?
        .first::<BlobReferenceRow>(None)
        .await?
        .map(|value| value.count)
        .unwrap_or(0);
    if count == 0 {
        storage
            .database
            .prepare("DELETE FROM blobs WHERE content_hash = ?")
            .bind(&[JsValue::from_str(content_hash)])?
            .run()
            .await?;
        storage
            .bucket
            .delete(format!("blobs/{content_hash}"))
            .await?;
    }
    Ok(())
}

pub(crate) fn governance_audit_statement(
    database: &worker::D1Database,
    org: &str,
    event_type: governance::AuditEventType,
    artifact_id: &str,
) -> Result<worker::d1::D1PreparedStatement> {
    database
        .prepare("INSERT INTO audit_events (id, org_id, event_type, payload, created_at) VALUES (?, ?, ?, ?, ?)")
        .bind(&[
            JsValue::from_str(&Uuid::new_v4().simple().to_string()),
            JsValue::from_str(org),
            JsValue::from_str(event_type.wire_name()),
            JsValue::from_str(&serde_json::json!({"org": org, "artifact_id": artifact_id}).to_string()),
            JsValue::from_str(&Utc::now().to_rfc3339_opts(SecondsFormat::Secs, true)),
        ])
}

pub(crate) async fn download_export_blob(path: &str, req: &Request, env: &Env) -> Result<Response> {
    let authorization = req.headers().get("Authorization")?;
    let credential = match require_org_credential(authorization.as_deref(), env).await? {
        Ok(credential) => credential,
        Err(refusal) => return Ok(refusal),
    };
    let hash = path.trim_start_matches("/v1/blobs/");
    if hash.len() != 64
        || !hash
            .bytes()
            .all(|byte| byte.is_ascii_hexdigit() && !byte.is_ascii_uppercase())
    {
        return json_error(ErrorCode::InvalidArtifactId, "Invalid blob hash.", 400);
    }
    let database = env.d1("ARTIFACTS_DB")?;
    let org = credential.org_id.clone();
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
pub(crate) struct BlobReferenceRow {
    pub(crate) count: i64,
}
