use super::*;
use crate::org_admin::{governance_audit_statement, legal_hold_response};

const GOVERNANCE_SECRET_ENV: &str = "ARTFCT_GOVERNANCE_SECRET";
const GOVERNANCE_MAX_PAGE: usize = 500;

#[derive(Debug, PartialEq, Eq)]
pub(crate) enum GovernanceRoute<'a> {
    ListArtifacts { org: &'a str },
    DeleteArtifact { org: &'a str, artifact_id: &'a str },
    LegalHold { org: &'a str, artifact_id: &'a str },
    SweepOrphans { org: &'a str },
}

/// Parses `/v1/internal/orgs/{org}/governance/...`.
pub(crate) fn parse_governance_path(path: &str) -> Option<GovernanceRoute<'_>> {
    let rest = path.strip_prefix("/v1/internal/orgs/")?;
    let (org, rest) = rest.split_once("/governance/")?;
    if org.is_empty() || org.contains('/') {
        return None;
    }
    if rest == "artifacts" {
        return Some(GovernanceRoute::ListArtifacts { org });
    }
    if rest == "sweep-orphans" {
        return Some(GovernanceRoute::SweepOrphans { org });
    }
    let rest = rest.strip_prefix("artifacts/")?;
    if let Some(artifact_id) = rest.strip_suffix("/legal-hold") {
        return (!artifact_id.is_empty() && !artifact_id.contains('/'))
            .then_some(GovernanceRoute::LegalHold { org, artifact_id });
    }
    (!rest.is_empty() && !rest.contains('/')).then_some(GovernanceRoute::DeleteArtifact {
        org,
        artifact_id: rest,
    })
}

/// An unset secret fails closed; the org token is a different credential.
pub(crate) fn governance_authorized(secret: Option<&str>, authorization: Option<&str>) -> bool {
    authorization_matches(secret, authorization)
}

#[derive(Debug, Deserialize)]
struct GovernanceListRow {
    id: String,
    created_at: String,
    legal_hold: i64,
}

#[derive(Debug, Deserialize)]
struct OrgPresenceRow {
    #[allow(dead_code)]
    id: String,
}

#[derive(Debug, Deserialize)]
struct OrphanRow {
    content_hash: String,
}

pub(crate) fn encode_governance_cursor(created_at: &str, id: &str) -> String {
    use base64::Engine;
    base64::engine::general_purpose::URL_SAFE_NO_PAD.encode(format!("{created_at}|{id}"))
}

pub(crate) fn decode_governance_cursor(raw: &str) -> Option<(String, String)> {
    use base64::Engine;
    let bytes = base64::engine::general_purpose::URL_SAFE_NO_PAD
        .decode(raw)
        .ok()?;
    let text = String::from_utf8(bytes).ok()?;
    let (created_at, id) = text.split_once('|')?;
    Some((created_at.to_string(), id.to_string()))
}

/// Internal governance routes for Laravel jobs (retention, erasure, legal
/// hold), authenticated with their own secret. The org comes from the path
/// and must already exist; unknown org is 404.
pub(crate) async fn governance_route(
    method: Method,
    path: &str,
    req: &mut Request,
    env: &Env,
) -> Result<Response> {
    let authorization = req.headers().get("Authorization")?;
    let secret = env
        .var(GOVERNANCE_SECRET_ENV)
        .ok()
        .map(|value| value.to_string());
    if !governance_authorized(secret.as_deref(), authorization.as_deref()) {
        return json_error(
            ErrorCode::Unauthorized,
            "Invalid governance credential.",
            401,
        );
    }
    let Some(route) = parse_governance_path(path) else {
        return not_found_response();
    };
    let org = match route {
        GovernanceRoute::ListArtifacts { org }
        | GovernanceRoute::DeleteArtifact { org, .. }
        | GovernanceRoute::LegalHold { org, .. }
        | GovernanceRoute::SweepOrphans { org } => org,
    };
    let storage =
        store::D1R2ArtifactStore::new(env.d1("ARTIFACTS_DB")?, env.bucket("ARTIFACTS_BUCKET")?);
    let database = &storage.database;
    let org_exists = database
        .prepare("SELECT id FROM orgs WHERE slug = ?")
        .bind(&[JsValue::from_str(org)])?
        .first::<OrgPresenceRow>(None)
        .await?
        .is_some();
    if !org_exists {
        return json_error(ErrorCode::ArtifactNotFound, "Organization not found.", 404);
    }
    match (route, method) {
        (GovernanceRoute::ListArtifacts { .. }, Method::Get) => {
            let params: std::collections::HashMap<String, String> =
                req.url()?.query_pairs().into_owned().collect();
            let limit = params
                .get("limit")
                .and_then(|raw| raw.parse::<usize>().ok())
                .filter(|value| *value > 0)
                .unwrap_or(GOVERNANCE_MAX_PAGE)
                .min(GOVERNANCE_MAX_PAGE);
            let cutoff = params.get("older_than").cloned();
            let cursor = params
                .get("cursor")
                .and_then(|raw| decode_governance_cursor(raw));
            let rows = database
                .prepare("SELECT a.id AS id, a.created_at AS created_at, a.legal_hold AS legal_hold FROM artifacts a JOIN orgs o ON o.id = a.org_id WHERE o.slug = ?1 AND (?2 IS NULL OR a.created_at < ?2) AND (?3 IS NULL OR a.created_at > ?3 OR (a.created_at = ?3 AND a.id > ?4)) ORDER BY a.created_at, a.id LIMIT ?5")
                .bind(&[
                    JsValue::from_str(org),
                    cutoff.as_deref().map(JsValue::from_str).unwrap_or_else(JsValue::null),
                    cursor.as_ref().map(|(created_at, _)| JsValue::from_str(created_at)).unwrap_or_else(JsValue::null),
                    cursor.as_ref().map(|(_, id)| JsValue::from_str(id)).unwrap_or_else(|| JsValue::from_str("")),
                    JsValue::from_f64((limit + 1) as f64),
                ])?
                .all()
                .await?
                .results::<GovernanceListRow>()?;
            let has_more = rows.len() > limit;
            let page: Vec<&GovernanceListRow> = rows.iter().take(limit).collect();
            let next_cursor = has_more
                .then(|| {
                    page.last()
                        .map(|row| encode_governance_cursor(&row.created_at, &row.id))
                })
                .flatten();
            let artifacts: Vec<Value> = page
                .iter()
                .map(|row| serde_json::json!({"id": row.id, "created_at": row.created_at, "legal_hold": row.legal_hold != 0}))
                .collect();
            JsonResponseDefinition::json(
                serde_json::json!({"artifacts": artifacts, "next_cursor": next_cursor}),
                200,
            )
            .into_worker_response()
        }
        (GovernanceRoute::DeleteArtifact { artifact_id, .. }, Method::Delete) => {
            match hard_delete_permanent(&storage, org, artifact_id).await? {
                HardDeleteOutcome::Deleted => build_delete_response().into_worker_response(),
                HardDeleteOutcome::NotFound => {
                    json_error(ErrorCode::ArtifactNotFound, "Artifact not found.", 404)
                }
                HardDeleteOutcome::LegalHold => legal_hold_response(),
                HardDeleteOutcome::Contention => retryable_contention_response(),
            }
        }
        (
            GovernanceRoute::LegalHold { artifact_id, .. },
            method @ (Method::Put | Method::Delete),
        ) => {
            let placing = method == Method::Put;
            let update = database
                .prepare("UPDATE artifacts SET legal_hold = ? WHERE id = ? AND org_id = (SELECT id FROM orgs WHERE slug = ?)")
                .bind(&[
                    JsValue::from_f64(if placing { 1.0 } else { 0.0 }),
                    JsValue::from_str(artifact_id),
                    JsValue::from_str(org),
                ])?
                .run()
                .await?;
            let changed = update.meta()?.and_then(|meta| meta.changes).unwrap_or(0);
            if changed == 0 {
                return json_error(ErrorCode::ArtifactNotFound, "Artifact not found.", 404);
            }
            storage
                .execute_batch(vec![governance_audit_statement(
                    database,
                    org,
                    if placing {
                        governance::AuditEventType::LegalHoldPlaced
                    } else {
                        governance::AuditEventType::LegalHoldReleased
                    },
                    artifact_id,
                )?])
                .await
                .map_err(|error| worker::Error::RustError(error.to_string()))?;
            build_delete_response().into_worker_response()
        }
        (GovernanceRoute::SweepOrphans { .. }, Method::Post) => {
            let orphans = database
                .prepare("SELECT content_hash FROM blobs WHERE ref_count <= 0")
                .all()
                .await?
                .results::<OrphanRow>()?;
            let mut removed = 0usize;
            for orphan in orphans {
                let locks = match storage
                    .acquire_content_locks(std::slice::from_ref(&orphan.content_hash))
                    .await
                {
                    Ok(locks) => locks,
                    Err(store::StoreError::Contention) => return retryable_contention_response(),
                    Err(error) => return Err(worker::Error::RustError(error.to_string())),
                };
                let result = release_blob_if_unreferenced(&storage, &orphan.content_hash).await;
                let release = storage.release_content_locks(&locks).await;
                result?;
                release.map_err(|error| worker::Error::RustError(error.to_string()))?;
                removed += 1;
            }
            JsonResponseDefinition::json(serde_json::json!({"removed": removed}), 200)
                .into_worker_response()
        }
        _ => not_found_response(),
    }
}
