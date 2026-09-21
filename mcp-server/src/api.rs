use anyhow::{anyhow, Context, Result};
use reqwest::header::{ACCEPT, AUTHORIZATION, CONTENT_TYPE};
use serde::{Deserialize, Serialize, Serializer};

use crate::provenance::Provenance;

#[derive(Debug, Clone)]
pub struct CreateArtifactRequest {
    pub body_ciphertext_b64: String,
    pub body_iv_b64: String,
    pub tier: String,
    pub ttl_minutes: Option<u64>,
    pub title: String,
    pub description: String,
    pub thumbnail: String,
    pub preview_blurred: bool,
    pub provenance: Provenance,
}

#[derive(Serialize)]
struct EphemeralArtifactRequest<'a> {
    mode: &'static str,
    body_ciphertext_b64: &'a str,
    body_iv_b64: &'a str,
    tier: &'a str,
    #[serde(skip_serializing_if = "Option::is_none")]
    ttl_minutes: Option<u64>,
    title: &'a str,
    description: &'a str,
    thumbnail: &'a str,
    preview_blurred: bool,
    provenance: &'a Provenance,
}

impl CreateArtifactRequest {
    fn ephemeral_payload(&self) -> EphemeralArtifactRequest<'_> {
        EphemeralArtifactRequest {
            mode: "ephemeral",
            body_ciphertext_b64: &self.body_ciphertext_b64,
            body_iv_b64: &self.body_iv_b64,
            tier: &self.tier,
            ttl_minutes: self.ttl_minutes,
            title: &self.title,
            description: &self.description,
            thumbnail: &self.thumbnail,
            preview_blurred: self.preview_blurred,
            provenance: &self.provenance,
        }
    }
}

impl Serialize for CreateArtifactRequest {
    fn serialize<S>(&self, serializer: S) -> std::result::Result<S::Ok, S::Error>
    where
        S: Serializer,
    {
        self.ephemeral_payload().serialize(serializer)
    }
}

#[derive(Debug, Deserialize)]
pub struct CreateArtifactResponse {
    pub id: String,
    pub url: String,
    pub tier: String,
    pub expires_at: String,
    pub title: String,
    pub description: String,
    pub thumbnail: String,
    pub preview_blurred: bool,
}

#[derive(Debug, Clone, Serialize)]
pub struct PermanentArtifactRequest {
    pub mode: &'static str,
    pub tier: String,
    pub title: String,
    pub description: String,
    pub thumbnail: String,
    pub preview_blurred: bool,
    pub manifest: PermanentManifest,
    pub provenance: Provenance,
}

#[derive(Debug, Clone, Serialize)]
pub struct PermanentManifest {
    pub entrypoint: String,
    pub files: Vec<PermanentManifestFile>,
    pub external_origins: Vec<String>,
}

#[derive(Debug, Clone, Serialize)]
pub struct PermanentManifestFile {
    pub path: String,
    pub content_type: String,
    pub size_bytes: usize,
    pub sha256: String,
}

#[derive(Debug, Clone, Deserialize)]
#[allow(dead_code)]
pub struct PermanentArtifactResponse {
    pub id: String,
    pub url: String,
    pub tier: String,
    #[serde(default)]
    pub missing_files: Vec<String>,
}

#[derive(Debug, Clone, Deserialize, Serialize)]
pub struct ExportResponse {
    pub artifacts: Vec<serde_json::Value>,
    pub blobs: std::collections::HashMap<String, String>,
}

/// One `search_artifacts` result — spec 13: "title, description, URL,
/// provenance summary and a snippet — never the full bundle." Deliberately
/// has no field that could carry full artifact content.
#[derive(Debug, Clone, Deserialize, Serialize)]
pub struct SearchResultDto {
    pub id: String,
    pub title: String,
    pub description: Option<String>,
    pub url: String,
    pub snippet: String,
    pub provenance: serde_json::Value,
}

#[derive(Debug, Clone, Deserialize, Serialize)]
pub struct SearchResponse {
    pub results: Vec<SearchResultDto>,
}

#[derive(Debug, Clone, Deserialize, Serialize)]
pub struct CollectionDto {
    pub id: u64,
    pub name: String,
    pub description: Option<String>,
    pub canonical: bool,
    pub artifact_count: u64,
    pub created_at: Option<String>,
    pub updated_at: Option<String>,
}

#[derive(Debug, Clone, Deserialize, Serialize)]
pub struct CollectionListResponse {
    pub collections: Vec<CollectionDto>,
    pub next_cursor: Option<String>,
}

#[derive(Debug, Clone, Serialize)]
pub struct CreateCollectionRequest<'a> {
    pub name: &'a str,
    #[serde(skip_serializing_if = "Option::is_none")]
    pub description: Option<&'a str>,
}

#[derive(Debug, Clone, Deserialize, Serialize)]
pub struct CreateCollectionResponse {
    pub id: u64,
    pub name: String,
    pub description: Option<String>,
    pub canonical: bool,
    pub artifact_count: u64,
    pub created_at: Option<String>,
    pub updated_at: Option<String>,
}

#[derive(Debug, Clone, Deserialize, Serialize)]
pub struct AddCollectionArtifactResponse {
    pub collection_id: u64,
    pub artifact_id: String,
}

#[derive(Debug, Clone, Deserialize)]
pub struct ArtifactMetadataResponse {
    pub id: String,
    pub tier: String,
    pub entrypoint: String,
    pub created_at: String,
    pub expires_at: Option<String>,
    pub title: Option<String>,
    pub description: Option<String>,
}

#[derive(Debug, Clone, Deserialize)]
pub struct UsageResponse {
    pub storage_bytes: u64,
    pub artifacts_this_period: u64,
    #[serde(default)]
    pub render_minutes_this_period: u64,
    #[serde(default)]
    pub period_start: Option<String>,
    #[serde(default)]
    pub period_end: Option<String>,
    #[serde(default)]
    pub limits: Option<UsageLimits>,
}

#[derive(Debug, Clone, Deserialize)]
pub struct UsageLimits {
    pub storage_bytes: u64,
    pub artifacts_per_month: u64,
}

#[derive(Debug, Clone, Serialize)]
pub struct RegisterMcpConnectionRequest<'a> {
    pub client_name: &'a str,
    pub client_version: Option<&'a str>,
    pub host: Option<&'a str>,
    pub transport: &'a str,
}

#[derive(Debug, Clone, Deserialize)]
pub struct McpConnectionResponse {
    pub connection_id: String,
    pub organization: String,
    pub user_id: String,
    pub scopes: Vec<String>,
}

#[derive(Debug, Clone, Deserialize)]
pub struct OAuthTokenResponse {
    pub access_token: String,
    pub refresh_token: String,
    pub expires_in: u64,
    #[serde(default)]
    pub organization: Option<String>,
}

#[derive(Debug, Clone, Deserialize)]
pub struct OrganizationSummary {
    pub name: String,
    pub slug: String,
    pub role: Option<String>,
    pub selected: bool,
}

#[derive(Debug, Clone, Deserialize)]
pub struct OrganizationsResponse {
    pub organizations: Vec<OrganizationSummary>,
}

#[derive(Debug, Clone, PartialEq, Eq)]
pub struct McpHealth {
    pub protocol_version: String,
    pub server_name: String,
    pub tool_count: usize,
}

/// Probe the hosted MCP transport using the same initialize and tool discovery
/// sequence an MCP client uses. The response body is parsed locally and is
/// never copied into an error, keeping `artfct doctor` safe to run in logs.
pub async fn probe_mcp(
    client: &reqwest::Client,
    api_base_url: &str,
    token: &str,
) -> Result<McpHealth> {
    let initialize = client
        .post(format!("{}/mcp", api_base_url.trim_end_matches('/')))
        .header(AUTHORIZATION, format!("Bearer {token}"))
        .header(ACCEPT, "application/json, text/event-stream")
        .json(&serde_json::json!({
            "jsonrpc": "2.0",
            "id": 1,
            "method": "initialize",
            "params": {
                "protocolVersion": "2025-11-25",
                "clientInfo": {"name": "artfct-doctor", "version": env!("CARGO_PKG_VERSION")},
                "capabilities": {}
            }
        }))
        .send()
        .await
        .context("Failed to reach the hosted MCP endpoint")?;

    let status = initialize.status();
    let session_id = initialize
        .headers()
        .get("MCP-Session-Id")
        .and_then(|value| value.to_str().ok())
        .map(str::to_owned);
    let body = initialize
        .json::<serde_json::Value>()
        .await
        .context("Hosted MCP returned an invalid initialize response")?;
    if !status.is_success() {
        anyhow::bail!("Hosted MCP initialize returned HTTP {status}");
    }
    if let Some(error) = body.get("error") {
        anyhow::bail!("Hosted MCP initialize failed: {}", safe_json_error(error));
    }

    let result = body
        .get("result")
        .context("Hosted MCP initialize response omitted result")?;
    let protocol_version = result
        .get("protocolVersion")
        .and_then(serde_json::Value::as_str)
        .context("Hosted MCP initialize response omitted protocolVersion")?
        .to_string();
    let server_name = result
        .get("serverInfo")
        .and_then(|info| info.get("name"))
        .and_then(serde_json::Value::as_str)
        .context("Hosted MCP initialize response omitted serverInfo.name")?
        .to_string();

    let mut tools_request = client
        .post(format!("{}/mcp", api_base_url.trim_end_matches('/')))
        .header(AUTHORIZATION, format!("Bearer {token}"))
        .header(ACCEPT, "application/json, text/event-stream")
        .json(&serde_json::json!({
            "jsonrpc": "2.0",
            "id": 2,
            "method": "tools/list",
            "params": {}
        }));
    if let Some(session_id) = session_id {
        tools_request = tools_request.header("MCP-Session-Id", session_id);
    }
    let tools = tools_request
        .send()
        .await
        .context("Failed to reach hosted MCP tool discovery")?;
    let tools_status = tools.status();
    let tools_body = tools
        .json::<serde_json::Value>()
        .await
        .context("Hosted MCP returned an invalid tool discovery response")?;
    if !tools_status.is_success() {
        anyhow::bail!("Hosted MCP tool discovery returned HTTP {tools_status}");
    }
    if let Some(error) = tools_body.get("error") {
        anyhow::bail!(
            "Hosted MCP tool discovery failed: {}",
            safe_json_error(error)
        );
    }
    let tool_count = tools_body
        .get("result")
        .and_then(|result| result.get("tools"))
        .and_then(serde_json::Value::as_array)
        .context("Hosted MCP tool discovery response omitted tools")?
        .len();

    Ok(McpHealth {
        protocol_version,
        server_name,
        tool_count,
    })
}

fn safe_json_error(error: &serde_json::Value) -> String {
    error
        .get("message")
        .and_then(serde_json::Value::as_str)
        .unwrap_or("unknown MCP error")
        .chars()
        .take(200)
        .collect()
}

fn safe_response_body(body: &str, secrets: &[&str]) -> String {
    let mut summary: String = body.chars().take(200).collect();
    for secret in secrets.iter().copied().filter(|secret| !secret.is_empty()) {
        summary = summary.replace(secret, "[REDACTED]");
    }
    summary
}

pub async fn exchange_authorization_code(
    client: &reqwest::Client,
    api_base_url: &str,
    code: &str,
    client_id: &str,
    redirect_uri: &str,
    code_verifier: &str,
) -> Result<OAuthTokenResponse> {
    exchange_oauth_token(
        client,
        api_base_url,
        &[
            ("grant_type", "authorization_code"),
            ("code", code),
            ("client_id", client_id),
            ("redirect_uri", redirect_uri),
            ("code_verifier", code_verifier),
        ],
    )
    .await
}

pub async fn refresh_access_token(
    client: &reqwest::Client,
    api_base_url: &str,
    refresh_token: &str,
    client_id: &str,
) -> Result<OAuthTokenResponse> {
    exchange_oauth_token(
        client,
        api_base_url,
        &[
            ("grant_type", "refresh_token"),
            ("refresh_token", refresh_token),
            ("client_id", client_id),
        ],
    )
    .await
}

pub async fn revoke_oauth_token(
    client: &reqwest::Client,
    api_base_url: &str,
    token: &str,
    token_type_hint: &str,
) -> Result<()> {
    let response = client
        .post(format!(
            "{}/oauth/revoke",
            api_base_url.trim_end_matches('/')
        ))
        .form(&[
            ("token", token),
            ("token_type_hint", token_type_hint),
            ("client_id", "artfct-cli"),
        ])
        .send()
        .await
        .context("Failed to reach the Artfct OAuth revocation endpoint")?;
    let status = response.status();

    if !status.is_success() {
        let body = response.text().await.unwrap_or_default();
        return Err(anyhow!(
            "Artfct OAuth revocation endpoint returned {status}: {}",
            safe_response_body(&body, &[token])
        ));
    }

    Ok(())
}

pub async fn organizations(
    client: &reqwest::Client,
    api_base_url: &str,
    token: &str,
) -> Result<OrganizationsResponse> {
    let response = client
        .get(format!(
            "{}/oauth/organizations",
            api_base_url.trim_end_matches('/')
        ))
        .bearer_auth(token)
        .send()
        .await
        .context("Failed to reach the Artfct organizations endpoint")?;
    let status = response.status();
    let body = response
        .text()
        .await
        .context("Failed to read the Artfct organizations response")?;

    if !status.is_success() {
        return Err(anyhow!(
            "Artfct organizations endpoint returned {status}: {}",
            safe_response_body(&body, &[token])
        ));
    }

    serde_json::from_str(&body).context("Artfct returned an invalid organizations response")
}

async fn exchange_oauth_token(
    client: &reqwest::Client,
    api_base_url: &str,
    form: &[(&str, &str)],
) -> Result<OAuthTokenResponse> {
    let response = client
        .post(format!(
            "{}/oauth/token",
            api_base_url.trim_end_matches('/')
        ))
        .form(form)
        .send()
        .await
        .context("Failed to reach the Artfct OAuth token endpoint")?;
    let status = response.status();
    let body = response
        .text()
        .await
        .context("Failed to read the Artfct OAuth token response")?;

    if !status.is_success() {
        let form_values = form.iter().map(|(_, value)| *value).collect::<Vec<_>>();
        return Err(anyhow!(
            "Artfct OAuth token endpoint returned {status}: {}",
            safe_response_body(&body, &form_values)
        ));
    }

    serde_json::from_str(&body).context("Artfct returned an invalid OAuth token response")
}

pub async fn register_mcp_connection(
    client: &reqwest::Client,
    api_base_url: &str,
    token: &str,
    request: &RegisterMcpConnectionRequest<'_>,
) -> Result<McpConnectionResponse> {
    post_mcp_connection_request(
        client
            .post(format!(
                "{}/api/mcp/connections",
                api_base_url.trim_end_matches('/')
            ))
            .json(request),
        token,
        "register MCP connection",
    )
    .await
}

pub async fn heartbeat_mcp_connection(
    client: &reqwest::Client,
    api_base_url: &str,
    token: &str,
) -> Result<McpConnectionResponse> {
    post_mcp_connection_request(
        client.post(format!(
            "{}/api/mcp/connections/heartbeat",
            api_base_url.trim_end_matches('/')
        )),
        token,
        "refresh MCP connection",
    )
    .await
}

async fn post_mcp_connection_request(
    request: reqwest::RequestBuilder,
    token: &str,
    operation: &str,
) -> Result<McpConnectionResponse> {
    let response = request
        .bearer_auth(token)
        .send()
        .await
        .with_context(|| format!("Failed to {operation}"))?;
    let status = response.status();
    let body = response
        .text()
        .await
        .with_context(|| format!("Failed to read response while attempting to {operation}"))?;

    if !status.is_success() {
        return Err(anyhow!(
            "Artfct rejected request to {operation}: {status}: {}",
            safe_response_body(&body, &[token])
        ));
    }

    serde_json::from_str(&body).with_context(|| {
        format!("Artfct returned an invalid response while attempting to {operation}")
    })
}

pub fn artifact_endpoint(api_base_url: &str) -> String {
    format!("{}/v1/artifacts", api_base_url.trim_end_matches('/'))
}

pub async fn deploy_artifact(
    client: &reqwest::Client,
    api_base_url: &str,
    request: &CreateArtifactRequest,
) -> Result<CreateArtifactResponse> {
    deploy_artifact_payload(client, api_base_url, request).await
}

pub async fn deploy_permanent_artifact(
    client: &reqwest::Client,
    api_base_url: &str,
    request: &PermanentArtifactRequest,
    body: &[u8],
    token: &str,
) -> Result<PermanentArtifactResponse> {
    deploy_permanent_artifact_files(
        client,
        api_base_url,
        request,
        &[(
            request
                .manifest
                .files
                .first()
                .map(|file| file.path.clone())
                .unwrap_or_default(),
            body.to_vec(),
        )],
        token,
    )
    .await
}

pub async fn deploy_permanent_artifact_files(
    client: &reqwest::Client,
    api_base_url: &str,
    request: &PermanentArtifactRequest,
    files: &[(String, Vec<u8>)],
    token: &str,
) -> Result<PermanentArtifactResponse> {
    let response = client
        .post(artifact_endpoint(api_base_url))
        .header(AUTHORIZATION, format!("Bearer {token}"))
        .json(request)
        .send()
        .await
        .context("Failed to reach Artifact Engine")?;
    let status = response.status();
    let body_text = response
        .text()
        .await
        .context("Failed to read Artifact Engine response")?;
    if !status.is_success() {
        return Err(anyhow!(
            "Artifact Engine returned {status}: {}",
            safe_response_body(&body_text, &[token])
        ));
    }
    let created: PermanentArtifactResponse =
        serde_json::from_str(&body_text).context("Artifact Engine returned an invalid response")?;
    for manifest_file in missing_manifest_files(request, &created.missing_files)? {
        let (_, bytes) = files
            .iter()
            .find(|(path, _)| path == &manifest_file.path)
            .ok_or_else(|| anyhow!("Missing local file {}", manifest_file.path))?;
        let upload = client
            .put(format!(
                "{}/v1/artifacts/{}/files/{}",
                api_base_url.trim_end_matches('/'),
                created.id,
                &manifest_file.sha256
            ))
            .header(AUTHORIZATION, format!("Bearer {token}"))
            .header(CONTENT_TYPE, &manifest_file.content_type)
            .body(bytes.clone())
            .send()
            .await
            .context("Failed to upload permanent artifact file")?;
        if !upload.status().is_success() {
            return Err(anyhow!(
                "Artifact Engine rejected permanent file upload: {}",
                upload.status()
            ));
        }
    }
    Ok(created)
}

pub(crate) fn missing_manifest_files<'a>(
    request: &'a PermanentArtifactRequest,
    missing: &[String],
) -> Result<Vec<&'a PermanentManifestFile>> {
    missing
        .iter()
        .map(|hash| {
            request
                .manifest
                .files
                .iter()
                .find(|file| &file.sha256 == hash)
                .ok_or_else(|| anyhow!("Server requested an unknown file hash"))
        })
        .collect()
}

pub async fn export_artifacts(
    client: &reqwest::Client,
    api_base_url: &str,
    org: &str,
    token: &str,
) -> Result<ExportResponse> {
    let response = client
        .get(format!(
            "{}/v1/orgs/{org}/export",
            api_base_url.trim_end_matches('/')
        ))
        .header(AUTHORIZATION, format!("Bearer {token}"))
        .send()
        .await
        .context("Failed to reach Artifact Engine")?;
    let status = response.status();
    let body = response
        .text()
        .await
        .context("Failed to read export response")?;
    if !status.is_success() {
        return Err(anyhow!(
            "Artifact Engine returned {status}: {}",
            safe_response_body(&body, &[token])
        ));
    }
    serde_json::from_str(&body).context("Artifact Engine returned an invalid export")
}

/// Calls spec 13's `/api/search` — org-scoped by the bearer `token`'s
/// claims, never by a parameter this function could be tricked into
/// sending.
pub async fn search_artifacts(
    client: &reqwest::Client,
    api_base_url: &str,
    token: &str,
    request: &serde_json::Value,
) -> Result<SearchResponse> {
    let response = client
        .post(format!("{}/api/search", api_base_url.trim_end_matches('/')))
        .header(AUTHORIZATION, format!("Bearer {token}"))
        .json(request)
        .send()
        .await
        .context("Failed to reach Artifact Engine")?;
    let status = response.status();
    let body = response
        .text()
        .await
        .context("Failed to read search response")?;
    if !status.is_success() {
        return Err(anyhow!(
            "Artifact Engine returned {status}: {}",
            safe_response_body(&body, &[token])
        ));
    }
    serde_json::from_str(&body).context("Artifact Engine returned an invalid search response")
}

/// Calls the org-scoped collection directory. The organization is resolved
/// exclusively from the bearer token by the Laravel API.
pub async fn list_collections(
    client: &reqwest::Client,
    api_base_url: &str,
    token: &str,
    cursor: Option<&str>,
    limit: u32,
) -> Result<CollectionListResponse> {
    let mut query = vec![("limit", limit.to_string())];
    if let Some(cursor) = cursor {
        query.push(("cursor", cursor.to_string()));
    }

    let response = client
        .get(format!(
            "{}/api/collections",
            api_base_url.trim_end_matches('/')
        ))
        .header(AUTHORIZATION, format!("Bearer {token}"))
        .query(&query)
        .send()
        .await
        .context("Failed to reach the Artfct collection directory")?;
    let status = response.status();
    let body = response
        .text()
        .await
        .context("Failed to read collection directory response")?;
    if !status.is_success() {
        return Err(anyhow!(
            "Artfct collection directory returned {status}: {}",
            safe_response_body(&body, &[token])
        ));
    }

    serde_json::from_str(&body).context("Artfct returned an invalid collection directory")
}

pub async fn create_collection(
    client: &reqwest::Client,
    api_base_url: &str,
    token: &str,
    request: &CreateCollectionRequest<'_>,
) -> Result<CreateCollectionResponse> {
    let response = client
        .post(format!(
            "{}/api/collections",
            api_base_url.trim_end_matches('/')
        ))
        .header(AUTHORIZATION, format!("Bearer {token}"))
        .json(request)
        .send()
        .await
        .context("Failed to reach the Artfct collection directory")?;
    let status = response.status();
    let body = response
        .text()
        .await
        .context("Failed to read collection creation response")?;
    if !status.is_success() {
        return Err(anyhow!(
            "Artfct collection creation returned {status}: {}",
            safe_response_body(&body, &[token])
        ));
    }

    serde_json::from_str(&body).context("Artfct returned an invalid collection")
}

pub async fn add_collection_artifact(
    client: &reqwest::Client,
    api_base_url: &str,
    token: &str,
    collection_id: u64,
    artifact_id: &str,
) -> Result<AddCollectionArtifactResponse> {
    let response = client
        .post(format!(
            "{}/api/collections/{collection_id}/artifacts",
            api_base_url.trim_end_matches('/')
        ))
        .header(AUTHORIZATION, format!("Bearer {token}"))
        .json(&serde_json::json!({"artifact_id": artifact_id}))
        .send()
        .await
        .context("Failed to reach the Artfct collection directory")?;
    let status = response.status();
    let body = response
        .text()
        .await
        .context("Failed to read collection artifact response")?;
    if !status.is_success() {
        return Err(anyhow!(
            "Artfct collection mutation returned {status}: {}",
            safe_response_body(&body, &[token])
        ));
    }

    serde_json::from_str(&body).context("Artfct returned an invalid collection mutation")
}

pub async fn artifact_metadata(
    client: &reqwest::Client,
    api_base_url: &str,
    artifact_id: &str,
    token: &str,
) -> Result<ArtifactMetadataResponse> {
    let response = client
        .get(format!(
            "{}/v1/artifacts/{artifact_id}",
            api_base_url.trim_end_matches('/')
        ))
        .header(AUTHORIZATION, format!("Bearer {token}"))
        .send()
        .await
        .context("Failed to reach Artifact Engine")?;
    let status = response.status();
    let body = response
        .text()
        .await
        .context("Failed to read artifact metadata response")?;
    if !status.is_success() {
        return Err(anyhow!(
            "Artifact Engine returned {status}: {}",
            safe_response_body(&body, &[token])
        ));
    }
    serde_json::from_str(&body).context("Artifact Engine returned invalid artifact metadata")
}

pub async fn usage(
    client: &reqwest::Client,
    api_base_url: &str,
    org: &str,
    token: &str,
) -> Result<UsageResponse> {
    let response = client
        .get(format!(
            "{}/v1/orgs/{org}/usage",
            api_base_url.trim_end_matches('/')
        ))
        .header(AUTHORIZATION, format!("Bearer {token}"))
        .send()
        .await
        .context("Failed to reach Artifact Engine")?;
    let status = response.status();
    let body = response
        .text()
        .await
        .context("Failed to read usage response")?;
    if !status.is_success() {
        return Err(anyhow!(
            "Artifact Engine returned {status}: {}",
            safe_response_body(&body, &[token])
        ));
    }
    serde_json::from_str(&body).context("Artifact Engine returned an invalid usage response")
}

pub async fn deploy_artifact_payload<T: Serialize + ?Sized>(
    client: &reqwest::Client,
    api_base_url: &str,
    request: &T,
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
        return Err(anyhow!(
            "Artifact Engine returned {status}: {}",
            safe_response_body(&body, &[])
        ));
    }

    serde_json::from_str(&body).context("Artifact Engine returned an invalid response")
}

pub async fn delete_artifact(
    client: &reqwest::Client,
    api_base_url: &str,
    id: &str,
    token: Option<&str>,
) -> Result<()> {
    let url = format!("{}/{}", artifact_endpoint(api_base_url), id);
    let mut request = client.delete(&url);
    if let Some(token) = token {
        request = request.header(AUTHORIZATION, format!("Bearer {token}"));
    }
    let response = request
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
    let secrets = token.into_iter().collect::<Vec<_>>();
    Err(anyhow!(
        "Artifact Engine returned {status}: {}",
        safe_response_body(&body, &secrets)
    ))
}

#[cfg(test)]
pub(crate) mod tests {
    use std::fs;
    use std::path::Path;

    use anyhow::{anyhow, Context, Result};
    use serde_json::{json, Map, Value};

    use super::{
        artifact_endpoint, safe_json_error, safe_response_body, CreateArtifactRequest,
        ExportResponse,
    };
    use crate::artifact_crypto;
    use crate::provenance::build_cli_provenance;

    #[test]
    fn builds_artifact_endpoint_without_double_slash() {
        assert_eq!(
            artifact_endpoint("https://artfct.dev/"),
            "https://artfct.dev/v1/artifacts"
        );
    }

    #[test]
    fn doctor_error_summary_is_bounded_and_does_not_dump_json() {
        let message = "x".repeat(400);
        let error = json!({"code": -32600, "message": message, "data": {"secret": "do-not-print"}});

        let summary = safe_json_error(&error);

        assert_eq!(summary.len(), 200);
        assert!(!summary.contains("do-not-print"));
    }

    #[test]
    fn doctor_error_summary_has_safe_fallback() {
        assert_eq!(
            safe_json_error(&json!({"code": -32600})),
            "unknown MCP error"
        );
    }

    #[test]
    fn response_error_summary_is_bounded_and_redacts_credentials() {
        let token = "bearer-secret";
        let body = format!("{{\"error\":\"{token}\"}}{}", "x".repeat(400));

        let summary = safe_response_body(&body, &[token]);

        assert!(summary.chars().count() <= 200);
        assert!(!summary.contains(token));
        assert!(summary.contains("[REDACTED]"));
    }

    #[test]
    fn serializes_create_artifact_payload() {
        let provenance = build_cli_provenance(Path::new("."), None);
        let request = CreateArtifactRequest {
            body_ciphertext_b64: "ciphertext".to_string(),
            body_iv_b64: "nonce".to_string(),
            tier: "ephemeral".to_string(),
            ttl_minutes: Some(5),
            title: "Hello".to_string(),
            description: "World".to_string(),
            thumbnail: "https://example.com/thumb.png".to_string(),
            preview_blurred: true,
            provenance,
        };

        let serialized = serde_json::to_value(request).expect("serializes request");
        let mut expected = json!({
            "mode": "ephemeral",
            "body_ciphertext_b64": "ciphertext",
            "body_iv_b64": "nonce",
            "tier": "ephemeral",
            "ttl_minutes": 5,
            "title": "Hello",
            "description": "World",
            "thumbnail": "https://example.com/thumb.png",
            "preview_blurred": true,
        });
        expected["provenance"] = serde_json::to_value(build_cli_provenance(Path::new("."), None))
            .expect("serializes provenance");

        assert_eq!(serialized, expected);
    }

    #[test]
    fn cli_create_request_validates_against_contract() {
        let prepared = artifact_crypto::prepare_artifact_request(
            "<html><head><title>Hello</title></head><body><p>World</p></body></html>",
            artifact_crypto::ArtifactPreparationOptions {
                tier: "ephemeral".to_string(),
                ttl_minutes: None,
                preview_blurred: true,
                provenance: build_cli_provenance(Path::new("."), None),
            },
        )
        .expect("prepares CLI artifact request");
        let payload = serde_json::to_value(prepared.request).expect("serializes CLI request");

        assert!(!payload
            .as_object()
            .expect("CLI payload should be a JSON object")
            .contains_key("ttl_minutes"));

        validate_contract_schema(&payload, "EphemeralArtifactRequest")
            .expect("CLI create request matches EphemeralArtifactRequest");
    }

    #[test]
    fn export_writes_byte_identical_blobs() {
        let export = ExportResponse {
            artifacts: vec![],
            blobs: std::collections::HashMap::from([(
                String::from("hash"),
                String::from("https://worker.test/v1/blobs/hash"),
            )]),
        };
        assert!(export.blobs["hash"].starts_with("https://"));
        assert!(!export.blobs["hash"].starts_with("data:"));
    }

    #[test]
    fn export_metadata_round_trips() {
        let export = ExportResponse {
            artifacts: vec![serde_json::json!({"id": "artifact"})],
            blobs: std::collections::HashMap::from([(
                String::from("hash"),
                String::from("https://worker.test/v1/blobs/hash"),
            )]),
        };
        let encoded = serde_json::to_string(&export).expect("export should serialize");
        let decoded: ExportResponse = serde_json::from_str(&encoded).expect("export should parse");
        assert_eq!(decoded.artifacts, export.artifacts);
        assert_eq!(decoded.blobs, export.blobs);
    }

    pub(crate) fn validate_contract_schema(instance: &Value, schema_name: &str) -> Result<()> {
        let contract_path = format!("{}/../openapi/artfct.yaml", env!("CARGO_MANIFEST_DIR"));
        let contract_source = fs::read_to_string(&contract_path)
            .with_context(|| format!("failed to read {contract_path}"))?;
        let contract: Value = serde_json::from_str(&contract_source)
            .context("OpenAPI contract must be JSON-compatible YAML")?;
        let schema = contract
            .pointer(&format!("/components/schemas/{schema_name}"))
            .ok_or_else(|| anyhow!("schema {schema_name} is missing from the OpenAPI contract"))?;

        validate_schema(instance, schema, &contract, schema_name)
    }

    fn validate_schema(
        instance: &Value,
        schema: &Value,
        contract: &Value,
        location: &str,
    ) -> Result<()> {
        if let Some(reference) = schema.get("$ref").and_then(Value::as_str) {
            let pointer = reference
                .strip_prefix('#')
                .ok_or_else(|| anyhow!("unsupported non-local reference {reference}"))?;
            let resolved = contract
                .pointer(pointer)
                .ok_or_else(|| anyhow!("unresolved schema reference {reference}"))?;
            return validate_schema(instance, resolved, contract, location);
        }

        if let Some(all_of) = schema.get("allOf").and_then(Value::as_array) {
            for nested in all_of {
                validate_schema(instance, nested, contract, location)?;
            }
        }

        if let Some(one_of) = schema.get("oneOf").and_then(Value::as_array) {
            let matches = one_of
                .iter()
                .filter(|nested| validate_schema(instance, nested, contract, location).is_ok())
                .count();
            if matches != 1 {
                return Err(anyhow!(
                    "{location} matched {matches} oneOf branches instead of exactly one"
                ));
            }
        }

        if let Some(expected) = schema.get("const") {
            if instance != expected {
                return Err(anyhow!("{location} does not match const {expected}"));
            }
        }

        if let Some(allowed) = schema.get("enum").and_then(Value::as_array) {
            if !allowed.contains(instance) {
                return Err(anyhow!("{location} is not one of {allowed:?}"));
            }
        }

        if let Some(schema_type) = schema.get("type") {
            let type_matches = match schema_type {
                Value::String(expected) => instance_matches_type(instance, expected),
                Value::Array(expected) => expected.iter().any(|candidate| {
                    candidate
                        .as_str()
                        .is_some_and(|expected| instance_matches_type(instance, expected))
                }),
                _ => false,
            };
            if !type_matches {
                return Err(anyhow!(
                    "{location} has type {}, expected {schema_type}",
                    instance_type(instance)
                ));
            }
        }

        if instance.is_null() {
            return Ok(());
        }

        if let Some(value) = instance.as_str() {
            validate_string(value, schema, location)?;
        }

        if let Some(value) = instance.as_f64() {
            validate_number(value, schema, location)?;
        }

        if let Some(object) = instance.as_object() {
            validate_object(object, schema, contract, location)?;
        }

        if let (Some(items), Some(values)) = (schema.get("items"), instance.as_array()) {
            for (index, value) in values.iter().enumerate() {
                validate_schema(value, items, contract, &format!("{location}[{index}]"))?;
            }
        }

        Ok(())
    }

    fn validate_string(value: &str, schema: &Value, location: &str) -> Result<()> {
        let length = value.chars().count() as u64;
        if let Some(minimum) = schema.get("minLength").and_then(Value::as_u64) {
            if length < minimum {
                return Err(anyhow!("{location} is shorter than {minimum} characters"));
            }
        }
        if let Some(maximum) = schema.get("maxLength").and_then(Value::as_u64) {
            if length > maximum {
                return Err(anyhow!("{location} is longer than {maximum} characters"));
            }
        }
        if schema.get("format").and_then(Value::as_str) == Some("uri") {
            let valid_scheme = value
                .split_once(':')
                .map(|(scheme, _)| {
                    !scheme.is_empty()
                        && scheme.chars().all(|character| {
                            character.is_ascii_alphanumeric() || "+-.".contains(character)
                        })
                })
                .unwrap_or(false);
            if !valid_scheme {
                return Err(anyhow!("{location} is not a URI"));
            }
        }

        Ok(())
    }

    fn validate_number(value: f64, schema: &Value, location: &str) -> Result<()> {
        if let Some(minimum) = schema.get("minimum").and_then(Value::as_f64) {
            if value < minimum {
                return Err(anyhow!("{location} is less than {minimum}"));
            }
        }
        if let Some(maximum) = schema.get("maximum").and_then(Value::as_f64) {
            if value > maximum {
                return Err(anyhow!("{location} is greater than {maximum}"));
            }
        }

        Ok(())
    }

    fn validate_object(
        object: &Map<String, Value>,
        schema: &Value,
        contract: &Value,
        location: &str,
    ) -> Result<()> {
        if let Some(required) = schema.get("required").and_then(Value::as_array) {
            for field in required.iter().filter_map(Value::as_str) {
                if !object.contains_key(field) {
                    return Err(anyhow!("{location}.{field} is required"));
                }
            }
        }

        let properties = schema.get("properties").and_then(Value::as_object);
        if let Some(properties) = properties {
            for (field, value) in object {
                if let Some(field_schema) = properties.get(field) {
                    validate_schema(
                        value,
                        field_schema,
                        contract,
                        &format!("{location}.{field}"),
                    )?;
                } else if schema.get("additionalProperties") == Some(&Value::Bool(false)) {
                    return Err(anyhow!("{location}.{field} is not allowed"));
                }
            }
        }

        Ok(())
    }

    fn instance_matches_type(instance: &Value, expected: &str) -> bool {
        match expected {
            "null" => instance.is_null(),
            "object" => instance.is_object(),
            "array" => instance.is_array(),
            "string" => instance.is_string(),
            "boolean" => instance.is_boolean(),
            "integer" => instance.as_i64().is_some() || instance.as_u64().is_some(),
            "number" => instance.is_number(),
            _ => false,
        }
    }

    fn instance_type(instance: &Value) -> &'static str {
        match instance {
            Value::Null => "null",
            Value::Bool(_) => "boolean",
            Value::Number(number) if number.is_i64() || number.is_u64() => "integer",
            Value::Number(_) => "number",
            Value::String(_) => "string",
            Value::Array(_) => "array",
            Value::Object(_) => "object",
        }
    }
}
