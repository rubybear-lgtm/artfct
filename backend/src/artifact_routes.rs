use super::*;

#[derive(Debug, Deserialize)]
struct ArtifactMetadataRow {
    id: String,
    org_id: String,
    tier: String,
    entrypoint: String,
    created_at: String,
    expires_at: Option<String>,
    title: Option<String>,
    description: Option<String>,
}

/// `GET /v1/artifacts/{id}` — the credential-scoped metadata read spec 07's
/// DoD item 4 is about. Deliberately fetches the row *without* an org
/// filter in the query, then gates visibility in application code via
/// `decide_artifact_visibility` — that keeps the org-scoping predicate a
/// single, independently mutation-testable check rather than folded into
/// SQL where a mutation test could pass vacuously.
pub(crate) async fn get_artifact_metadata(
    path: &str,
    req: &Request,
    env: &Env,
) -> Result<Response> {
    let authorization = req.headers().get("Authorization")?;
    let credential = match require_org_credential(authorization.as_deref(), env).await? {
        Ok(credential) => credential,
        Err(refusal) => return Ok(refusal),
    };
    let artifact_id = path
        .trim_start_matches("/v1/artifacts/")
        .trim_end_matches('/');
    if artifact_id.is_empty() {
        return json_error(ErrorCode::ArtifactNotFound, "Artifact not found.", 404);
    }
    let database = env.d1("ARTIFACTS_DB")?;
    let row = database
        .prepare(
            "SELECT a.id AS id, o.slug AS org_id, a.tier AS tier, a.entrypoint AS entrypoint, a.created_at AS created_at, a.expires_at AS expires_at, a.title AS title, a.description AS description FROM artifacts a JOIN orgs o ON o.id = a.org_id WHERE a.id = ? ORDER BY a.row_id LIMIT 1",
        )
        .bind(&[JsValue::from_str(artifact_id)])?
        .first::<ArtifactMetadataRow>(None)
        .await?;
    let org_row = row.as_ref().map(|row| ArtifactOrgRow {
        id: row.id.clone(),
        org_id: row.org_id.clone(),
    });
    match decide_artifact_visibility(org_row.as_ref(), &credential.org_id) {
        ArtifactLookupDecision::NotFound => {
            json_error(ErrorCode::ArtifactNotFound, "Artifact not found.", 404)
        }
        ArtifactLookupDecision::Visible => {
            let row = row.expect("Visible is only returned when a row was fetched");
            JsonResponseDefinition::json(
                serde_json::json!({
                    "id": row.id,
                    "tier": row.tier,
                    "entrypoint": row.entrypoint,
                    "created_at": row.created_at,
                    "expires_at": row.expires_at,
                    "title": row.title,
                    "description": row.description,
                }),
                200,
            )
            .into_worker_response()
        }
    }
}
