use anyhow::{anyhow, Context, Result};
use serde::{Deserialize, Serialize};

#[derive(Debug, Deserialize, Serialize)]
pub struct CreateArtifactRequest {
    pub html: String,
    pub tier: String,
    #[serde(skip_serializing_if = "Option::is_none")]
    pub ttl_minutes: Option<u64>,
}

#[derive(Debug, Deserialize)]
pub struct CreateArtifactResponse {
    pub id: String,
    pub url: String,
    pub tier: String,
    pub expires_at: String,
}

pub fn artifact_endpoint(api_base_url: &str) -> String {
    format!("{}/v1/artifacts", api_base_url.trim_end_matches('/'))
}

pub async fn deploy_artifact(
    client: &reqwest::Client,
    api_base_url: &str,
    request: &CreateArtifactRequest,
) -> Result<CreateArtifactResponse> {
    let response = client
        .post(artifact_endpoint(api_base_url))
        .json(request)
        .send()
        .await
        .context("Failed to reach Artifact Engine")?;
    let status = response.status();
    let body = response
        .text()
        .await
        .context("Failed to read Artifact Engine response")?;

    if !status.is_success() {
        return Err(anyhow!("Artifact Engine returned {status}: {body}"));
    }

    serde_json::from_str(&body).context("Artifact Engine returned an invalid response")
}

pub async fn delete_artifact(client: &reqwest::Client, api_base_url: &str, id: &str) -> Result<()> {
    let url = format!("{}/{}", artifact_endpoint(api_base_url), id);
    let response = client
        .delete(&url)
        .send()
        .await
        .context("Failed to reach Artifact Engine")?;
    let status = response.status();

    if status == reqwest::StatusCode::NO_CONTENT {
        return Ok(());
    }

    let body = response
        .text()
        .await
        .context("Failed to read Artifact Engine response")?;
    Err(anyhow!("Artifact Engine returned {status}: {body}"))
}

#[cfg(test)]
mod tests {
    use serde_json::json;

    use super::{artifact_endpoint, CreateArtifactRequest};

    #[test]
    fn builds_artifact_endpoint_without_double_slash() {
        assert_eq!(
            artifact_endpoint("https://artfct.dev/"),
            "https://artfct.dev/v1/artifacts"
        );
    }

    #[test]
    fn serializes_create_artifact_payload() {
        let request = CreateArtifactRequest {
            html: "<h1>Hello</h1>".to_string(),
            tier: "ephemeral".to_string(),
            ttl_minutes: Some(5),
        };

        assert_eq!(
            serde_json::to_value(request).expect("serializes request"),
            json!({
                "html": "<h1>Hello</h1>",
                "tier": "ephemeral",
                "ttl_minutes": 5
            })
        );
    }
}
