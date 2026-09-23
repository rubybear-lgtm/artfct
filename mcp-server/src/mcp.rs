use anyhow::{Context, Result};
use chrono::{DateTime, Utc};
use ring::rand::{SecureRandom, SystemRandom};
use serde::Deserialize;
use serde_json::{json, Value};
use std::fmt::{Display, Formatter};
use tokio::io::{self, AsyncBufReadExt, AsyncWriteExt, BufReader};

use crate::api;
use crate::artifact_crypto;
use crate::provenance::{self, McpProvenanceInput, ProvenanceSource};
use crate::tool_registry;

const PROTOCOL_VERSION: &str = "2025-11-25";
const SUPPORTED_PROTOCOL_VERSIONS: &[&str] =
    &["2025-11-25", "2025-06-18", "2025-03-26", "2024-11-05"];
const MCP_SERVER_VERSION: &str = "1.0.0";
const SERVER_INSTRUCTIONS: &str =
    "Publish HTML artifacts to the authenticated workspace, then search, retrieve and organize them. deploy_to_canvas is deprecated: it creates anonymous expiring artifacts the workspace cannot search.";
const DEFAULT_API_BASE_URL: &str = "https://artfct.dev";

/// Where the control plane (the Laravel app) lives. Its session-authenticated
/// open route is the link a person can actually follow for a secure artifact;
/// the local stdio server has no session of its own, so it addresses that route
/// rather than minting a token the way the hosted server can.
const DEFAULT_APP_BASE_URL: &str = "https://artfct.dev";

/// The app's open route, mirroring `route('console.open')` on the control
/// plane. `tests/Fixtures/artifact-view-link-contract.json` holds the one copy
/// of this shape that both servers are tested against, so the PHP and Rust
/// servers cannot drift apart about the link they hand back.
const APP_OPEN_PATH_TEMPLATE: &str = "/settings/teams/{team}/console/artifacts/{artifactId}/open";

/// Where the control plane lives: `ARTFCT_APP_BASE_URL` when set (local dev and
/// staging point at their own Laravel host), `https://artfct.dev` otherwise —
/// the same host that serves the hosted MCP endpoint.
fn app_base_url() -> String {
    std::env::var("ARTFCT_APP_BASE_URL")
        .ok()
        .map(|value| value.trim().trim_end_matches('/').to_string())
        .filter(|value| !value.is_empty())
        .unwrap_or_else(|| DEFAULT_APP_BASE_URL.to_string())
}

/// Whether `tier` names an artifact the Worker serves to anyone, with no
/// credential: a permanent `public` artifact, or the anonymous `ephemeral`
/// preview `deploy_to_canvas` publishes. Everything else — including a tier
/// this response did not carry — is treated as secure.
fn is_anonymous_tier(tier: Option<&str>) -> bool {
    matches!(tier, Some(value) if value.eq_ignore_ascii_case("public") || value.eq_ignore_ascii_case("ephemeral"))
}

/// The URL the Worker itself serves an anonymous artifact at: the artifact's
/// real address when the create response carried one (`ARTFCT_PUBLIC_BASE_URL`),
/// a reconstruction from the API base otherwise. It is also the only link that
/// resolves for a KV artifact — `deploy_to_canvas` calls this directly.
fn anonymous_artifact_url(
    worker_base_url: &str,
    artifact_id: &str,
    worker_url: Option<&str>,
) -> String {
    worker_url
        .filter(|value| !value.is_empty())
        .map(ToOwned::to_owned)
        .unwrap_or_else(|| format!("{}/p/{artifact_id}", worker_base_url.trim_end_matches('/')))
}

/// The one place the local server decides which URL a person is handed for an
/// artifact, mirroring `App\Services\Artifacts\ArtifactViewLink` on the hosted
/// side: an artifact the Worker serves without a credential — public, or an
/// anonymous ephemeral preview — is handed out as the Worker's own `/p/{id}`
/// URL, and no token is involved; every other artifact (secure, or a tier this
/// response did not carry) gets the app's session-authenticated open route,
/// which authorizes the viewer and mints a short-lived signed link at click
/// time.
///
/// That rule is for artifacts the workspace holds in D1. `deploy_to_canvas`
/// publishes anonymous KV records instead, at every tier it accepts, and the
/// app's open route resolves content from D1 — so for those the only link that
/// resolves is `anonymous_artifact_url()`.
///
/// `worker_url` is the URL the Worker published for the artifact, when the
/// response carried one (`ARTFCT_PUBLIC_BASE_URL`). It is used for an anonymous
/// artifact in preference to reconstructing from `worker_base_url`, because it
/// is the artifact's real address: on a deployment whose Worker public base is
/// not the API base, reconstructing would hand out a link to the wrong host.
///
/// Without a workspace slug there is no app route to address, so the Worker's
/// own `/p/{id}` URL is the only link left; that is the pre-existing
/// environment-token edge case, not the ordinary path.
fn artifact_view_url(
    worker_base_url: &str,
    app_base_url: &str,
    organization: Option<&str>,
    artifact_id: &str,
    tier: Option<&str>,
    worker_url: Option<&str>,
) -> String {
    if is_anonymous_tier(tier) {
        return anonymous_artifact_url(worker_base_url, artifact_id, worker_url);
    }

    let Some(organization) = organization else {
        return anonymous_artifact_url(worker_base_url, artifact_id, worker_url);
    };

    format!(
        "{}{}",
        app_base_url.trim_end_matches('/'),
        APP_OPEN_PATH_TEMPLATE
            .replace("{team}", organization)
            .replace("{artifactId}", artifact_id)
    )
}

#[derive(Clone, Copy, Debug, PartialEq, Eq)]
pub enum HostSource {
    Config,
    ClientInfo,
    Process,
    Env,
    Absent,
}

impl HostSource {
    fn as_str(self) -> &'static str {
        match self {
            Self::Config => "config",
            Self::ClientInfo => "client_info",
            Self::Process => "process",
            Self::Env => "env",
            Self::Absent => "absent",
        }
    }
}

#[derive(Clone, Debug, PartialEq, Eq)]
pub struct HostIdentity {
    pub normalized: Option<String>,
    pub raw: Option<String>,
    pub source: HostSource,
}

impl HostIdentity {
    fn absent() -> Self {
        Self {
            normalized: None,
            raw: None,
            source: HostSource::Absent,
        }
    }
}

#[derive(Clone, Debug)]
pub struct Session {
    pub client_name: Option<String>,
    pub client_version: Option<String>,
    pub host: HostIdentity,
    pub session_id: String,
    pub started_at: DateTime<Utc>,
    pub connection: ConnectionContext,
    pub connection_id: Option<String>,
}

#[derive(Clone, Debug, Default, PartialEq, Eq)]
pub struct ConnectionContext {
    pub organization: Option<String>,
    pub user_id: Option<String>,
    pub scopes: Vec<String>,
    pub credential_source: CredentialSource,
}

/// Credential sources used by local and hosted transports. The non-anonymous
/// variants are introduced here so transport authentication can populate one
/// stable connection context without changing tool implementations later.
#[allow(dead_code)]
#[derive(Clone, Copy, Debug, Default, PartialEq, Eq)]
pub enum CredentialSource {
    #[default]
    Anonymous,
    Environment,
    LocalCredential,
    OAuth,
}

impl CredentialSource {
    fn as_str(self) -> &'static str {
        match self {
            Self::Anonymous => "anonymous",
            Self::Environment => "environment",
            Self::LocalCredential => "local_credential",
            Self::OAuth => "oauth",
        }
    }
}

impl ConnectionContext {
    fn is_authenticated(&self) -> bool {
        self.credential_source != CredentialSource::Anonymous
    }

    fn missing_scopes(&self, required: &[&str]) -> Vec<String> {
        required
            .iter()
            .map(|scope| (*scope).to_string())
            .filter(|scope| !self.scopes.iter().any(|granted| granted == scope))
            .collect()
    }
}

impl Session {
    pub fn new(configured_host: Option<String>) -> Self {
        let process_name = parent_process_name();
        Self::new_with_resolution(configured_host, process_name, resolve_environment_host())
    }

    fn new_with_resolution(
        configured_host: Option<String>,
        process_name: Option<String>,
        environment_host: Option<HostIdentity>,
    ) -> Self {
        let host = resolve_host(
            configured_host.as_deref(),
            None,
            process_name.as_deref(),
            environment_host,
        );

        Self {
            client_name: None,
            client_version: None,
            host,
            session_id: mint_session_id(),
            started_at: Utc::now(),
            connection: ConnectionContext::default(),
            connection_id: None,
        }
    }

    fn capture_client_info(&mut self, params: &Value) {
        self.client_name = None;
        self.client_version = None;

        let Some(client_info) = params.get("clientInfo").and_then(Value::as_object) else {
            return;
        };

        self.client_name = client_info
            .get("name")
            .and_then(Value::as_str)
            .map(ToOwned::to_owned);
        self.client_version = client_info
            .get("version")
            .and_then(Value::as_str)
            .map(ToOwned::to_owned);

        if self.host.source == HostSource::Config {
            return;
        }

        if let Some(identity) = self
            .client_name
            .as_deref()
            .and_then(|name| normalized_host_identity(name, HostSource::ClientInfo))
        {
            self.host = identity;
        }
    }
}

impl Default for Session {
    fn default() -> Self {
        Self::new(None)
    }
}

#[derive(Debug, Deserialize)]
struct JsonRpcRequest {
    jsonrpc: String,
    id: Option<Value>,
    method: String,
    #[serde(default)]
    params: Value,
}

#[derive(Debug, Deserialize)]
struct ToolCallParams {
    name: String,
    #[serde(default)]
    arguments: Value,
}

#[derive(Debug)]
struct McpError {
    rpc_code: i32,
    code: &'static str,
    message: String,
    retryable: bool,
}

impl McpError {
    fn new(rpc_code: i32, code: &'static str, message: impl Into<String>) -> Self {
        Self {
            rpc_code,
            code,
            message: message.into(),
            retryable: false,
        }
    }

    fn retryable(rpc_code: i32, code: &'static str, message: impl Into<String>) -> Self {
        Self {
            rpc_code,
            code,
            message: message.into(),
            retryable: true,
        }
    }

    fn response(self, id: Option<Value>) -> Value {
        json!({
            "jsonrpc": "2.0",
            "id": id,
            "error": {
                "code": self.rpc_code,
                "message": self.message,
                "data": {
                    "code": self.code,
                    "retryable": self.retryable
                }
            }
        })
    }
}

impl Display for McpError {
    fn fmt(&self, formatter: &mut Formatter<'_>) -> std::fmt::Result {
        formatter.write_str(&self.message)
    }
}

impl std::error::Error for McpError {}

impl From<anyhow::Error> for McpError {
    fn from(error: anyhow::Error) -> Self {
        let details = error.to_string().to_ascii_lowercase();

        if details.contains("401")
            || details.contains("authentication")
            || details.contains("unauthorized")
            || details.contains("invalid_grant")
        {
            return Self::new(
                -32001,
                "authentication_error",
                "Authentication failed. Run artfct login --oauth and retry.",
            );
        }

        if details.contains("quota_exceeded")
            || details.contains("over quota")
            || details.contains("quota")
        {
            return Self::new(
                -32004,
                "quota_exceeded",
                "This workspace has reached a usage limit. Call get_usage for current usage and remediation.",
            );
        }

        if details.contains("403") || details.contains("insufficient_scope") {
            return Self::new(
                -32003,
                "insufficient_scope",
                "This MCP connection lacks the permission required for that operation. Reauthorize with the required scope.",
            );
        }

        if details.contains("429") || details.contains("rate limit") {
            return Self::retryable(
                -32005,
                "rate_limited",
                "This workspace is being rate limited. Retry after the server-provided delay.",
            );
        }

        if details.contains("failed to reach")
            || details.contains("service unavailable")
            || details.contains("returned 5")
            || details.contains("returned 502")
            || details.contains("returned 503")
            || details.contains("returned 504")
        {
            return Self::retryable(
                -32006,
                "upstream_unavailable",
                "Artfct is temporarily unavailable. Retry shortly.",
            );
        }

        if details.contains("invalid") || details.contains("missing") {
            return Self::new(
                -32602,
                "invalid_request",
                "The request could not be accepted. Check the tool arguments and retry.",
            );
        }

        Self::new(
            -32603,
            "internal_error",
            "Artfct could not complete the request. Retry shortly or contact support if it persists.",
        )
    }
}

#[derive(Debug, Deserialize)]
struct DeployToolArguments {
    html: String,
    tier: String,
    ttl_minutes: Option<u64>,
    model: Option<String>,
}

/// Arguments for `deploy_artifact`: a permanent, org-scoped publish.
#[derive(Debug, Deserialize)]
struct DeployArtifactArguments {
    html: String,
    tier: Option<String>,
    title: Option<String>,
    description: Option<String>,
    model: Option<String>,
}

/// Arguments for `search_artifacts` (spec 13, `collection` added by spec 16).
#[derive(Debug, Deserialize)]
struct SearchToolArguments {
    query: String,
    repo: Option<String>,
    agent: Option<String>,
    since: Option<String>,
    /// Scopes results to one named, org-scoped collection — "use our
    /// approved billing report format," not "find anything about billing."
    collection: Option<String>,
    #[serde(default = "default_search_limit")]
    limit: u32,
}

#[derive(Debug, Deserialize)]
struct GetArtifactArguments {
    id: String,
}

#[derive(Debug, Deserialize)]
struct ListCollectionsArguments {
    cursor: Option<String>,
    #[serde(default = "default_collection_limit")]
    limit: u32,
}

#[derive(Debug, Deserialize)]
struct CreateCollectionArguments {
    name: String,
    description: Option<String>,
}

#[derive(Debug, Deserialize)]
struct AddCollectionArtifactArguments {
    collection_id: u64,
    artifact_id: String,
}

fn default_search_limit() -> u32 {
    5
}

fn default_collection_limit() -> u32 {
    20
}

pub async fn run_stdio_server(configured_host: Option<String>) -> Result<()> {
    let mut session = Session::new(configured_host);
    log_identity(&session);
    let stdin = BufReader::new(io::stdin());
    let mut lines = stdin.lines();
    let mut stdout = io::stdout();

    while let Some(line) = lines.next_line().await? {
        if line.trim().is_empty() {
            continue;
        }

        if let Some(response) = handle_json_rpc_line(&mut session, &line).await {
            let encoded = serde_json::to_string(&response)?;
            stdout.write_all(encoded.as_bytes()).await?;
            stdout.write_all(b"\n").await?;
            stdout.flush().await?;
        }
    }

    Ok(())
}

async fn handle_json_rpc_line(session: &mut Session, line: &str) -> Option<Value> {
    match serde_json::from_str::<Value>(line) {
        Ok(value) => handle_json_rpc(session, value).await,
        Err(error) => Some(
            McpError::new(-32700, "parse_error", format!("Parse error: {error}")).response(None),
        ),
    }
}

fn mint_session_id() -> String {
    let mut bytes = [0_u8; 16];
    SystemRandom::new()
        .fill(&mut bytes)
        .expect("system randomness is required to create an MCP session id");
    bytes[6] = (bytes[6] & 0x0f) | 0x40;
    bytes[8] = (bytes[8] & 0x3f) | 0x80;

    format!(
        "{:02x}{:02x}{:02x}{:02x}-{:02x}{:02x}-{:02x}{:02x}-{:02x}{:02x}-{:02x}{:02x}{:02x}{:02x}{:02x}{:02x}",
        bytes[0],
        bytes[1],
        bytes[2],
        bytes[3],
        bytes[4],
        bytes[5],
        bytes[6],
        bytes[7],
        bytes[8],
        bytes[9],
        bytes[10],
        bytes[11],
        bytes[12],
        bytes[13],
        bytes[14],
        bytes[15]
    )
}

fn log_identity(session: &Session) {
    let host = session.host.normalized.as_deref().unwrap_or("absent");
    eprintln!(
        "mcp identity: host={host} source={} session_id={} started_at={}",
        session.host.source.as_str(),
        session.session_id,
        session.started_at.to_rfc3339()
    );
}

fn host_identity(raw: String, source: HostSource) -> HostIdentity {
    HostIdentity {
        normalized: normalize_host(&raw),
        raw: Some(raw),
        source,
    }
}

fn normalized_host_identity(raw: &str, source: HostSource) -> Option<HostIdentity> {
    normalize_host(raw).map(|normalized| HostIdentity {
        normalized: Some(normalized),
        raw: Some(raw.to_string()),
        source,
    })
}

fn normalize_host(raw: &str) -> Option<String> {
    let normalized = raw.trim().to_ascii_lowercase();
    let normalized = normalized.replace('_', "-");
    let normalized = normalized.replace(' ', "-");

    match normalized.as_str() {
        "claude" | "claude-code" | "claudecode" => Some("claude-code".to_string()),
        "cursor" => Some("cursor".to_string()),
        "codex" | "openai-codex" => Some("codex".to_string()),
        "gemini" | "gemini-cli" => Some("gemini".to_string()),
        "opencode" | "open-code" => Some("opencode".to_string()),
        _ => None,
    }
}

fn resolve_host(
    configured_host: Option<&str>,
    client_info_name: Option<&str>,
    process_name: Option<&str>,
    environment_host: Option<HostIdentity>,
) -> HostIdentity {
    if let Some(configured_host) = configured_host.filter(|value| !value.trim().is_empty()) {
        return host_identity(configured_host.to_string(), HostSource::Config);
    }

    if let Some(client_info_name) = client_info_name {
        if let Some(identity) = normalized_host_identity(client_info_name, HostSource::ClientInfo) {
            return identity;
        }
    }

    if let Some(process_name) = process_name {
        if let Some(identity) = normalized_host_identity(process_name, HostSource::Process) {
            return identity;
        }
    }

    environment_host.unwrap_or_else(HostIdentity::absent)
}

fn resolve_environment_host() -> Option<HostIdentity> {
    if let Some(value) = env_value("CLAUDECODE") {
        return Some(observed_host_identity(
            "claude-code",
            value,
            HostSource::Env,
        ));
    }

    if let Some(value) = env_value("CLAUDE_CODE_ENTRYPOINT") {
        return Some(observed_host_identity(
            "claude-code",
            value,
            HostSource::Env,
        ));
    }

    if let Some(value) = env_value("CURSOR_TRACE_ID") {
        return Some(observed_host_identity("cursor", value, HostSource::Env));
    }

    None
}

fn env_value(name: &str) -> Option<String> {
    std::env::var(name)
        .ok()
        .filter(|value| !value.trim().is_empty())
}

fn observed_host_identity(normalized: &str, raw: String, source: HostSource) -> HostIdentity {
    HostIdentity {
        normalized: Some(normalized.to_string()),
        raw: Some(raw),
        source,
    }
}

#[cfg(target_os = "linux")]
fn parent_process_name() -> Option<String> {
    #[cfg(unix)]
    unsafe extern "C" {
        fn getppid() -> i32;
    }

    let parent_pid = unsafe { getppid() };
    std::fs::read_to_string(format!("/proc/{parent_pid}/comm"))
        .ok()
        .map(|name| name.trim().to_string())
        .filter(|name| !name.is_empty())
}

#[cfg(target_os = "macos")]
fn parent_process_name() -> Option<String> {
    use std::os::raw::{c_int, c_void};

    unsafe extern "C" {
        fn getppid() -> c_int;
        fn proc_name(pid: c_int, buffer: *mut c_void, buffersize: u32) -> c_int;
    }

    let mut buffer = [0_u8; 256];
    let length = unsafe {
        proc_name(
            getppid(),
            buffer.as_mut_ptr().cast::<c_void>(),
            buffer.len() as u32,
        )
    };

    (length > 0).then(|| {
        String::from_utf8_lossy(&buffer[..length as usize])
            .trim()
            .to_string()
    })
}

#[cfg(not(any(target_os = "linux", target_os = "macos")))]
fn parent_process_name() -> Option<String> {
    None
}

pub async fn handle_json_rpc(session: &mut Session, value: Value) -> Option<Value> {
    let request = match serde_json::from_value::<JsonRpcRequest>(value) {
        Ok(request) => request,
        Err(error) => {
            return Some(
                McpError::new(
                    -32600,
                    "invalid_request",
                    format!("Invalid request: {error}"),
                )
                .response(None),
            );
        }
    };

    request.id.as_ref()?;

    if request.jsonrpc != "2.0" {
        return Some(
            McpError::new(-32600, "invalid_request", "Invalid JSON-RPC version.")
                .response(request.id),
        );
    }

    let result: std::result::Result<Value, McpError> = match request.method.as_str() {
        "initialize" => {
            session.capture_client_info(&request.params);
            log_identity(session);
            if let Err(error) = ensure_connection(session).await {
                eprintln!("{error}");
            }
            negotiate_protocol_version(&request.params).map(initialize_result)
        }
        "tools/list" => Ok(tools_list_result()),
        "tools/call" => match ensure_connection(session).await {
            Ok(()) => call_tool(session, request.params, |message| eprintln!("{message}")).await,
            Err(error) => Err(error),
        },
        _ => {
            return Some(
                McpError::new(
                    -32601,
                    "method_not_found",
                    format!("Unknown method: {}", request.method),
                )
                .response(request.id),
            );
        }
    };

    Some(match result {
        Ok(result) => json_rpc_success(request.id, result),
        Err(error) => error.response(request.id),
    })
}

fn negotiate_protocol_version(params: &Value) -> std::result::Result<&'static str, McpError> {
    let Some(requested) = params.get("protocolVersion") else {
        return Ok(PROTOCOL_VERSION);
    };

    let Some(requested) = requested.as_str() else {
        return Err(McpError::new(
            -32602,
            "unsupported_protocol_version",
            format!(
                "protocolVersion must be one of: {}",
                SUPPORTED_PROTOCOL_VERSIONS.join(", ")
            ),
        ));
    };

    if let Some(version) = SUPPORTED_PROTOCOL_VERSIONS
        .iter()
        .copied()
        .find(|version| *version == requested)
    {
        Ok(version)
    } else {
        Err(McpError::new(
            -32602,
            "unsupported_protocol_version",
            format!(
                "Unsupported protocol version {requested}; supported versions: {}",
                SUPPORTED_PROTOCOL_VERSIONS.join(", ")
            ),
        ))
    }
}

fn initialize_result(protocol_version: &str) -> Value {
    let mut result = json!({
        "protocolVersion": protocol_version,
        "capabilities": {
            "tools": {}
        },
        "serverInfo": {
            "name": "artfct",
            "version": MCP_SERVER_VERSION
        }
    });

    if !matches!(protocol_version, "2024-11-05" | "2025-03-26") {
        result["instructions"] = json!(SERVER_INSTRUCTIONS);
    }

    result
}

async fn ensure_connection(session: &mut Session) -> std::result::Result<(), McpError> {
    let api_base_url = crate::auth::api_base_url(DEFAULT_API_BASE_URL);
    let client = reqwest::Client::new();
    let Some(token) = crate::auth::access_token(&client, &api_base_url)
        .await
        .map_err(|error| {
            McpError::new(
                -32001,
                "authentication_error",
                format!("MCP authentication failed: {error}"),
            )
        })?
    else {
        return Ok(());
    };
    let response = if session.connection_id.is_some() {
        api::heartbeat_mcp_connection(&client, &api_base_url, &token).await
    } else {
        let client_name = session
            .client_name
            .as_deref()
            .or(session.host.normalized.as_deref())
            .unwrap_or("unknown-mcp-client");
        let request = api::RegisterMcpConnectionRequest {
            client_name,
            client_version: session.client_version.as_deref(),
            host: session.host.normalized.as_deref(),
            transport: "stdio",
        };

        api::register_mcp_connection(&client, &api_base_url, &token, &request).await
    }
    .map_err(|error| {
        McpError::new(
            -32001,
            "authentication_error",
            format!("MCP authentication failed: {error}"),
        )
    })?;

    session.connection_id = Some(response.connection_id);
    session.connection.organization = Some(response.organization);
    session.connection.user_id = Some(response.user_id);
    session.connection.scopes = response.scopes;
    session.connection.credential_source = if std::env::var("ARTFCT_ORG_TOKEN")
        .ok()
        .is_some_and(|value| !value.trim().is_empty())
    {
        CredentialSource::Environment
    } else {
        CredentialSource::LocalCredential
    };

    Ok(())
}

fn tools_list_result() -> Value {
    json!({"tools": tool_registry::definitions_json()})
}

async fn call_tool<F>(
    session: &Session,
    params: Value,
    mut diagnostic_sink: F,
) -> std::result::Result<Value, McpError>
where
    F: FnMut(&str),
{
    let params: ToolCallParams =
        serde_json::from_value(params).context("Invalid tools/call params")?;

    let (host, source) = session_identity(session);
    diagnostic_sink(&format!(
        "mcp tool call: tool={} host={host} source={} session_id={}",
        params.name,
        source.as_str(),
        session.session_id
    ));

    if let Some(definition) = tool_registry::find(&params.name) {
        let missing = session
            .connection
            .missing_scopes(definition.required_scopes);
        if session.connection.is_authenticated() && !missing.is_empty() {
            return Err(McpError::new(
                -32003,
                "insufficient_scope",
                format!(
                    "Insufficient scope for {}: missing {}",
                    params.name,
                    missing.join(", ")
                ),
            ));
        }
    }

    match params.name.as_str() {
        "deploy_artifact" => call_deploy_artifact(session, params.arguments)
            .await
            .map_err(McpError::from),
        "deploy_to_canvas" => call_deploy_to_canvas(session, params.arguments)
            .await
            .map_err(McpError::from),
        "search_artifacts" => call_search_artifacts(session, params.arguments)
            .await
            .map_err(McpError::from),
        "get_connection" => Ok(connection_result(session)),
        "get_usage" => call_get_usage(session).await.map_err(McpError::from),
        "get_artifact" => call_get_artifact(session, params.arguments)
            .await
            .map_err(McpError::from),
        "list_collections" => call_list_collections(params.arguments)
            .await
            .map_err(McpError::from),
        "create_collection" => call_create_collection(params.arguments)
            .await
            .map_err(McpError::from),
        "add_collection_artifact" => call_add_collection_artifact(params.arguments)
            .await
            .map_err(McpError::from),
        other => Err(McpError::new(
            -32602,
            "tool_not_found",
            format!("Unknown tool: {other}"),
        )),
    }
}

fn connection_result(session: &Session) -> Value {
    json!({
        "content": [{
            "type": "text",
            "text": "Artfct MCP connection details are available in structured content."
        }],
        "structuredContent": {
            "authenticated": session.connection.credential_source != CredentialSource::Anonymous,
            "organization": session.connection.organization,
            "user_id": session.connection.user_id,
            "scopes": session.connection.scopes,
            "credential_source": session.connection.credential_source.as_str(),
            "connection_id": session.connection_id,
            "client": session.client_name,
            "client_version": session.client_version,
            "host": session.host.normalized,
            "session_id": session.session_id,
            "server_version": MCP_SERVER_VERSION,
            "started_at": session.started_at.to_rfc3339(),
        }
    })
}

async fn call_deploy_artifact(session: &Session, arguments: Value) -> Result<Value> {
    let arguments: DeployArtifactArguments =
        serde_json::from_value(arguments).context("Invalid deploy_artifact arguments")?;
    let tier = arguments.tier.unwrap_or_else(|| "secure".to_string());
    if tier != "public" && tier != "secure" {
        anyhow::bail!("deploy_artifact tier must be public or secure");
    }
    let html = arguments.html.trim();
    if html.is_empty() || html.len() > 1024 * 1024 {
        anyhow::bail!("deploy_artifact html must be between 1 byte and 1 MB");
    }

    let (host, source) = session_identity(session);
    eprintln!(
        "mcp tool preparation: host={host} source={}",
        source.as_str()
    );
    let cwd = std::env::current_dir().context("Failed to determine current directory")?;
    let provenance = provenance::build_mcp_provenance(
        &cwd,
        McpProvenanceInput {
            agent: session.host.normalized.clone(),
            agent_raw: session.host.raw.clone(),
            agent_version: session.client_version.clone(),
            agent_source: provenance_source(source),
            session_id: session.session_id.clone(),
            model: arguments.model.clone(),
        },
    );
    let mut request = artifact_crypto::prepare_permanent_artifact_request(html, tier, provenance)?;
    if let Some(title) = arguments.title.filter(|value| !value.trim().is_empty()) {
        request.title = title;
    }
    if let Some(description) = arguments
        .description
        .filter(|value| !value.trim().is_empty())
    {
        request.description = description;
    }

    let api_base_url = crate::auth::api_base_url(DEFAULT_API_BASE_URL);
    let client = reqwest::Client::new();
    let token = crate::auth::access_token(&client, &api_base_url)
        .await?
        .context("deploy_artifact requires `artfct login` or ARTFCT_ORG_TOKEN")?;
    let created =
        api::deploy_permanent_artifact(&client, &api_base_url, &request, html.as_bytes(), &token)
            .await?;
    let view_url = artifact_view_url(
        &api_base_url,
        &app_base_url(),
        session.connection.organization.as_deref(),
        &created.id,
        Some(created.tier.as_str()),
        Some(created.url.as_str()),
    );

    Ok(json!({
        "content": [{
            "type": "text",
            "text": format!("Artifact published: {view_url}")
        }],
        "structuredContent": {
            "id": created.id,
            "view_url": view_url,
            "tier": created.tier,
            "title": request.title,
            "organization": session.connection.organization
        }
    }))
}

async fn call_deploy_to_canvas(session: &Session, arguments: Value) -> Result<Value> {
    let api_base_url = crate::auth::api_base_url(DEFAULT_API_BASE_URL);

    call_deploy_to_canvas_at(session, arguments, &api_base_url).await
}

/// `call_deploy_to_canvas` against an explicit base URL, so a test can drive the
/// whole tool — create request, Worker response, link — over a stub socket
/// without reaching for a process-global environment variable.
async fn call_deploy_to_canvas_at(
    session: &Session,
    arguments: Value,
    api_base_url: &str,
) -> Result<Value> {
    let arguments: DeployToolArguments =
        serde_json::from_value(arguments).context("Invalid deploy_to_canvas arguments")?;

    let prepared = prepare_mcp_tool_request(session, &arguments)?;
    let request = mcp_create_request_payload(&prepared.request)?;
    let artifact =
        api::deploy_artifact_payload(&reqwest::Client::new(), api_base_url, &request).await?;
    // This tool publishes an anonymous KV record at every tier it accepts, and
    // the app's open route resolves content from D1 — so that route 404s for
    // these artifacts no matter the tier. The Worker's own `/p/{id}` URL is the
    // only link that resolves, and the share fragment is its access mechanism.
    // A server-side redirect cannot carry a fragment at all, which is why the
    // fragment rides on this URL rather than on the app route: the Worker never
    // sees it, and the preview shell reads it client-side.
    let view_url = format!(
        "{}{}",
        anonymous_artifact_url(api_base_url, &artifact.id, Some(artifact.url.as_str())),
        prepared.fragment
    );

    Ok(json!({
        "content": [
            {
                "type": "text",
                "text": format!("Artifact deployed: {view_url}")
            }
        ],
        "structuredContent": {
            "id": artifact.id,
            "view_url": view_url,
            "canonical_url": artifact.url,
            "tier": artifact.tier,
            "expires_at": artifact.expires_at,
            "title": artifact.title,
            "description": artifact.description,
            "thumbnail": artifact.thumbnail,
            "preview_blurred": artifact.preview_blurred
        }
    }))
}

async fn call_search_artifacts(session: &Session, arguments: Value) -> Result<Value> {
    let arguments: SearchToolArguments =
        serde_json::from_value(arguments).context("Invalid search_artifacts arguments")?;

    let api_base_url = std::env::var("ARTFCT_SEARCH_BASE_URL")
        .unwrap_or_else(|_| crate::auth::api_base_url(DEFAULT_API_BASE_URL));
    let client = reqwest::Client::new();
    let token = crate::auth::access_token(&client, &api_base_url)
        .await?
        .context("search_artifacts requires `artfct login` or ARTFCT_ORG_TOKEN")?;

    let request = search_request_payload(&arguments);
    let response = api::search_artifacts(&client, &api_base_url, &token, &request).await?;

    Ok(format_search_response(session, &api_base_url, &response))
}

async fn call_get_usage(session: &Session) -> Result<Value> {
    let organization = session
        .connection
        .organization
        .as_deref()
        .context("get_usage requires an authenticated organization")?;
    let api_base_url = crate::auth::api_base_url(DEFAULT_API_BASE_URL);
    let client = reqwest::Client::new();
    let token = crate::auth::access_token(&client, &api_base_url)
        .await?
        .context("get_usage requires `artfct login` or ARTFCT_ORG_TOKEN")?;
    let response = api::usage(&client, &api_base_url, organization, &token).await?;

    Ok(format_usage_response(&response))
}

async fn call_get_artifact(session: &Session, arguments: Value) -> Result<Value> {
    let arguments: GetArtifactArguments =
        serde_json::from_value(arguments).context("Invalid get_artifact arguments")?;
    if arguments.id.is_empty()
        || arguments.id.len() > 128
        || !arguments
            .id
            .bytes()
            .all(|byte| byte.is_ascii_alphanumeric())
    {
        anyhow::bail!("get_artifact requires an alphanumeric artifact ID");
    }

    let api_base_url = crate::auth::api_base_url(DEFAULT_API_BASE_URL);
    let client = reqwest::Client::new();
    let token = crate::auth::access_token(&client, &api_base_url)
        .await?
        .context("get_artifact requires `artfct login` or ARTFCT_ORG_TOKEN")?;
    let response = api::artifact_metadata(&client, &api_base_url, &arguments.id, &token).await?;
    // The metadata endpoint returns no URL, so an anonymous artifact's link is
    // built from the Worker base.
    let view_url = artifact_view_url(
        &api_base_url,
        &app_base_url(),
        session.connection.organization.as_deref(),
        &response.id,
        Some(response.tier.as_str()),
        None,
    );

    Ok(json!({
        "content": [{
            "type": "text",
            "text": format!("Artifact metadata for {}.", response.id)
        }],
        "structuredContent": {
            "id": response.id,
            "view_url": view_url,
            "tier": response.tier,
            "entrypoint": response.entrypoint,
            "created_at": response.created_at,
            "expires_at": response.expires_at,
            "title": response.title,
            "description": response.description
        }
    }))
}

async fn call_list_collections(arguments: Value) -> Result<Value> {
    let arguments: ListCollectionsArguments =
        serde_json::from_value(arguments).context("Invalid list_collections arguments")?;
    if !(1..=50).contains(&arguments.limit) {
        anyhow::bail!("list_collections limit must be between 1 and 50");
    }

    let api_base_url = crate::auth::api_base_url(DEFAULT_API_BASE_URL);
    let client = reqwest::Client::new();
    let token = crate::auth::access_token(&client, &api_base_url)
        .await?
        .context("list_collections requires `artfct login` or ARTFCT_ORG_TOKEN")?;
    let response = api::list_collections(
        &client,
        &api_base_url,
        &token,
        arguments.cursor.as_deref(),
        arguments.limit,
    )
    .await?;

    Ok(json!({
        "content": [{
            "type": "text",
            "text": format!("Returned {} collection(s).", response.collections.len())
        }],
        "structuredContent": response
    }))
}

async fn call_create_collection(arguments: Value) -> Result<Value> {
    let arguments: CreateCollectionArguments =
        serde_json::from_value(arguments).context("Invalid create_collection arguments")?;
    if arguments.name.trim().is_empty() || arguments.name.chars().count() > 100 {
        anyhow::bail!("create_collection name must be between 1 and 100 characters");
    }
    if arguments
        .description
        .as_ref()
        .is_some_and(|value| value.chars().count() > 500)
    {
        anyhow::bail!("create_collection description must be at most 500 characters");
    }

    let api_base_url = crate::auth::api_base_url(DEFAULT_API_BASE_URL);
    let client = reqwest::Client::new();
    let token = crate::auth::access_token(&client, &api_base_url)
        .await?
        .context("create_collection requires `artfct login` or ARTFCT_ORG_TOKEN")?;
    let request = api::CreateCollectionRequest {
        name: &arguments.name,
        description: arguments.description.as_deref(),
    };
    let response = api::create_collection(&client, &api_base_url, &token, &request).await?;

    Ok(json!({
        "content": [{"type": "text", "text": format!("Created collection {}.", response.name)}],
        "structuredContent": response
    }))
}

async fn call_add_collection_artifact(arguments: Value) -> Result<Value> {
    let arguments: AddCollectionArtifactArguments =
        serde_json::from_value(arguments).context("Invalid add_collection_artifact arguments")?;
    if arguments.collection_id == 0
        || arguments.artifact_id.is_empty()
        || arguments.artifact_id.len() > 128
        || !arguments
            .artifact_id
            .bytes()
            .all(|byte| byte.is_ascii_alphanumeric())
    {
        anyhow::bail!(
            "add_collection_artifact requires a valid collection ID and alphanumeric artifact ID"
        );
    }

    let api_base_url = crate::auth::api_base_url(DEFAULT_API_BASE_URL);
    let client = reqwest::Client::new();
    let token = crate::auth::access_token(&client, &api_base_url)
        .await?
        .context("add_collection_artifact requires `artfct login` or ARTFCT_ORG_TOKEN")?;
    let response = api::add_collection_artifact(
        &client,
        &api_base_url,
        &token,
        arguments.collection_id,
        &arguments.artifact_id,
    )
    .await?;

    Ok(json!({
        "content": [{"type": "text", "text": format!("Added {} to collection {}.", response.artifact_id, response.collection_id)}],
        "structuredContent": response
    }))
}

fn format_usage_response(response: &api::UsageResponse) -> Value {
    let storage_limit = response.limits.as_ref().map(|limits| limits.storage_bytes);
    let artifacts_limit = response
        .limits
        .as_ref()
        .map(|limits| limits.artifacts_per_month);
    let storage_percent = storage_limit
        .filter(|limit| *limit > 0)
        .map_or(0.0, |limit| {
            response.storage_bytes as f64 / limit as f64 * 100.0
        });
    let artifacts_percent = artifacts_limit
        .filter(|limit| *limit > 0)
        .map_or(0.0, |limit| {
            response.artifacts_this_period as f64 / limit as f64 * 100.0
        });

    json!({
        "content": [{
            "type": "text",
            "text": "Current organization usage and quota status."
        }],
        "structuredContent": {
            "period": {
                "starts_at": response.period_start,
                "resets_at": response.period_end
            },
            "storage": {
                "used_bytes": response.storage_bytes,
                "limit_bytes": storage_limit,
                "percent": storage_percent,
                "exceeded": storage_limit.is_some_and(|limit| response.storage_bytes >= limit)
            },
            "artifacts": {
                "used": response.artifacts_this_period,
                "limit": artifacts_limit,
                "percent": artifacts_percent,
                "exceeded": artifacts_limit.is_some_and(|limit| response.artifacts_this_period >= limit)
            },
            "render_minutes": {"used": response.render_minutes_this_period},
            "can_create": storage_limit.is_none_or(|limit| response.storage_bytes < limit)
                && artifacts_limit.is_none_or(|limit| response.artifacts_this_period < limit)
        }
    })
}

/// Builds the outgoing search request body from tool arguments. Pure — no
/// network — so `search_respects_limit` and friends can assert the shape
/// without a live call.
fn search_request_payload(arguments: &SearchToolArguments) -> Value {
    json!({
        "query": arguments.query,
        "repo": arguments.repo,
        "agent": arguments.agent,
        "since": arguments.since,
        "collection": arguments.collection,
        "limit": arguments.limit,
    })
}

/// Shapes the API's search response into the MCP tool result. Pure — the
/// only place that decides what an agent sees, so it's the one place that
/// can be checked to never include a bundle's full HTML (DoD: "never the
/// full bundle").
fn format_search_response(
    session: &Session,
    worker_base_url: &str,
    response: &api::SearchResponse,
) -> Value {
    let summary = if response.results.is_empty() {
        "No matching artifacts found.".to_string()
    } else {
        format!("Found {} matching artifact(s).", response.results.len())
    };
    let app_base_url = app_base_url();

    json!({
        "content": [
            {
                "type": "text",
                "text": summary
            }
        ],
        "structuredContent": {
            "results": response.results.iter().map(|result| json!({
                "id": result.id,
                "title": result.title,
                "description": result.description,
                // The index carries no tier, so every row links through the
                // app's open route: it authorizes the viewer and is the one
                // link that is right either way.
                "view_url": artifact_view_url(
                    worker_base_url,
                    &app_base_url,
                    session.connection.organization.as_deref(),
                    &result.id,
                    None,
                    // Search results carry no artifact URL.
                    None,
                ),
                "snippet": result.snippet,
                "provenance": result.provenance,
            })).collect::<Vec<_>>()
        }
    })
}

fn session_identity(session: &Session) -> (&str, HostSource) {
    (
        session.host.normalized.as_deref().unwrap_or("absent"),
        session.host.source,
    )
}

fn provenance_source(source: HostSource) -> ProvenanceSource {
    match source {
        HostSource::Config => ProvenanceSource::Config,
        HostSource::ClientInfo => ProvenanceSource::ClientInfo,
        HostSource::Process => ProvenanceSource::Process,
        HostSource::Env => ProvenanceSource::Env,
        HostSource::Absent => ProvenanceSource::Absent,
    }
}

fn prepare_mcp_tool_request(
    session: &Session,
    arguments: &DeployToolArguments,
) -> Result<artifact_crypto::PreparedArtifactRequest> {
    let (host, source) = session_identity(session);
    eprintln!(
        "mcp tool preparation: host={host} source={}",
        source.as_str()
    );
    let cwd = std::env::current_dir().context("Failed to determine current directory")?;
    let provenance = provenance::build_mcp_provenance(
        &cwd,
        McpProvenanceInput {
            agent: session.host.normalized.clone(),
            agent_raw: session.host.raw.clone(),
            agent_version: session.client_version.clone(),
            agent_source: provenance_source(source),
            session_id: session.session_id.clone(),
            model: arguments.model.clone(),
        },
    );

    artifact_crypto::prepare_artifact_request(
        &arguments.html,
        artifact_crypto::ArtifactPreparationOptions {
            tier: arguments.tier.clone(),
            ttl_minutes: arguments.ttl_minutes,
            preview_blurred: true,
            provenance,
        },
    )
}

fn mcp_create_request_payload(request: &api::CreateArtifactRequest) -> Result<Value> {
    serde_json::to_value(request).context("Failed to serialize artifact request")
}

fn json_rpc_success(id: Option<Value>, result: Value) -> Value {
    json!({
        "jsonrpc": "2.0",
        "id": id,
        "result": result
    })
}

#[cfg(test)]
mod tests {
    use std::io::{ErrorKind, Read, Write};
    use std::net::{TcpListener, TcpStream};
    use std::process::Command;
    use std::sync::atomic::{AtomicBool, Ordering};
    use std::sync::{Arc, Mutex};
    use std::thread::{self, JoinHandle};
    use std::time::Duration;

    use anyhow::anyhow;
    use serde_json::{json, Value};

    use super::{
        anonymous_artifact_url, artifact_view_url, call_add_collection_artifact, call_get_artifact,
        call_tool, format_search_response, format_usage_response, handle_json_rpc,
        mcp_create_request_payload, negotiate_protocol_version, prepare_mcp_tool_request,
        resolve_host, search_request_payload, session_identity, ConnectionContext,
        CredentialSource, DeployToolArguments, HostIdentity, HostSource, McpError,
        SearchToolArguments, Session, APP_OPEN_PATH_TEMPLATE, MCP_SERVER_VERSION, PROTOCOL_VERSION,
        SERVER_INSTRUCTIONS,
    };
    use crate::api::tests::validate_contract_schema;
    use crate::api::{SearchResponse, SearchResultDto, UsageLimits, UsageResponse};
    use crate::tool_registry;

    #[tokio::test]
    async fn initialize_captures_client_info() {
        let mut session = Session::new(None);

        handle_json_rpc(
            &mut session,
            json!({
                "jsonrpc": "2.0",
                "id": 1,
                "method": "initialize",
                "params": {"clientInfo": {"name": "claude-code", "version": "2.1"}}
            }),
        )
        .await
        .expect("response");

        assert_eq!(session.client_name.as_deref(), Some("claude-code"));
        assert_eq!(session.client_version.as_deref(), Some("2.1"));
        assert_eq!(session.host.normalized.as_deref(), Some("claude-code"));
        assert_eq!(session.host.source, HostSource::ClientInfo);
        assert_eq!(session.host.raw.as_deref(), Some("claude-code"));
    }

    #[tokio::test]
    async fn initialize_with_empty_params_does_not_error() {
        let mut session = Session::new_with_resolution(None, None, None);
        let response = handle_json_rpc(
            &mut session,
            json!({
                "jsonrpc": "2.0",
                "id": 1,
                "method": "initialize",
                "params": {}
            }),
        )
        .await;

        assert!(response.is_some());
        assert_eq!(session.host.normalized, None);
        assert_eq!(session.host.source, HostSource::Absent);
    }

    #[tokio::test]
    async fn host_flag_beats_client_info() {
        let mut session = Session::new(Some("cursor".to_string()));

        handle_json_rpc(
            &mut session,
            json!({
                "jsonrpc": "2.0",
                "id": 1,
                "method": "initialize",
                "params": {"clientInfo": {"name": "claude-code"}}
            }),
        )
        .await
        .expect("response");

        assert_eq!(session.host.normalized.as_deref(), Some("cursor"));
        assert_eq!(session.host.source, HostSource::Config);
    }

    #[tokio::test]
    async fn client_info_beats_process_name() {
        let mut session = Session::new_with_resolution(
            None,
            Some("codex".to_string()),
            Some(super::HostIdentity {
                normalized: Some("gemini".to_string()),
                raw: Some("gemini".to_string()),
                source: HostSource::Env,
            }),
        );

        handle_json_rpc(
            &mut session,
            json!({
                "jsonrpc": "2.0",
                "id": 1,
                "method": "initialize",
                "params": {"clientInfo": {"name": "cursor"}}
            }),
        )
        .await
        .expect("response");

        assert_eq!(session.host.normalized.as_deref(), Some("cursor"));
        assert_eq!(session.host.source, HostSource::ClientInfo);
    }

    #[test]
    fn unresolvable_host_records_absent_not_error() {
        let identity = resolve_host(None, Some("node"), Some("node"), None);

        assert_eq!(identity.normalized, None);
        assert_eq!(identity.source, HostSource::Absent);
    }

    #[tokio::test]
    async fn session_id_stable_within_process() {
        let session = Session::new(None);
        let session_id = session.session_id.clone();
        let original_host = session.host.clone();
        let original_client_name = session.client_name.clone();
        let original_client_version = session.client_version.clone();
        let original_started_at = session.started_at;
        let mut diagnostics = Vec::new();
        let params = json!({
            "name": "unknown",
            "arguments": {"html": "<html />", "tier": "ephemeral"}
        });
        let first = call_tool(&session, params.clone(), |message| {
            diagnostics.push(message.to_string())
        })
        .await;
        let second = call_tool(&session, params, |message| {
            diagnostics.push(message.to_string())
        })
        .await;

        assert!(first.is_err());
        assert!(second.is_err());
        assert_eq!(diagnostics.len(), 2);
        assert!(diagnostics
            .iter()
            .all(|message| message.contains(&session_id)));
        assert_eq!(diagnostics[0], diagnostics[1]);
        assert_eq!(session.session_id, session_id);
        assert_eq!(session.host, original_host);
        assert_eq!(session.client_name, original_client_name);
        assert_eq!(session.client_version, original_client_version);
        assert_eq!(session.started_at, original_started_at);
    }

    #[test]
    fn session_id_differs_between_processes() {
        let output = Command::new(std::env::current_exe().expect("test executable"))
            .args([
                "--exact",
                "mcp::tests::session_id_child_process",
                "--nocapture",
            ])
            .output()
            .expect("starts child test process");
        assert!(output.status.success());

        let child_session_id = String::from_utf8_lossy(&output.stdout)
            .lines()
            .find_map(|line| line.strip_prefix("session-id: "))
            .map(ToOwned::to_owned)
            .expect("child emits session id");
        assert_ne!(Session::new(None).session_id, child_session_id);
    }

    #[test]
    fn session_id_child_process() {
        println!("session-id: {}", Session::new(None).session_id);
    }

    #[tokio::test]
    async fn call_tool_reads_session_host() {
        let session = Session::new(Some("cursor".to_string()));
        let mut diagnostic = String::new();

        assert_eq!(session_identity(&session), ("cursor", HostSource::Config));
        let result = call_tool(
            &session,
            json!({
                "name": "unknown",
                "arguments": {"html": "<html />", "tier": "ephemeral"}
            }),
            |message| diagnostic = message.to_string(),
        )
        .await;

        assert!(result.is_err());
        assert!(diagnostic.contains("host=cursor"));
        assert!(diagnostic.contains("source=config"));
        assert!(diagnostic.contains(&session.session_id));
    }

    #[tokio::test]
    async fn handles_initialize() {
        let mut session = Session::new(None);
        let response = handle_json_rpc(
            &mut session,
            json!({
                "jsonrpc": "2.0",
                "id": 1,
                "method": "initialize",
                "params": {}
            }),
        )
        .await
        .expect("response");

        assert_eq!(response["jsonrpc"], "2.0");
        assert_eq!(response["id"], 1);
        assert_eq!(response["result"]["serverInfo"]["name"], "artfct");
        assert_eq!(response["result"]["protocolVersion"], PROTOCOL_VERSION);
        assert_eq!(
            response["result"]["serverInfo"]["version"],
            MCP_SERVER_VERSION
        );
        assert_eq!(response["result"]["instructions"], SERVER_INSTRUCTIONS);
    }

    #[tokio::test]
    async fn accepts_cancellation_notifications_without_a_response() {
        let mut session = Session::new(None);

        let response = handle_json_rpc(
            &mut session,
            json!({
                "jsonrpc": "2.0",
                "method": "notifications/cancelled",
                "params": {"requestId": 42, "reason": "user_cancelled"}
            }),
        )
        .await;

        assert!(response.is_none());
    }

    #[tokio::test]
    async fn suppresses_unknown_notifications_without_a_response() {
        let mut session = Session::new(None);

        let response = handle_json_rpc(
            &mut session,
            json!({
                "jsonrpc": "2.0",
                "method": "notifications/example"
            }),
        )
        .await;

        assert!(response.is_none());
    }

    #[test]
    fn negotiates_supported_and_rejects_unknown_protocol_versions() {
        assert_eq!(
            negotiate_protocol_version(&json!({"protocolVersion": "2024-11-05"}))
                .expect("legacy protocol is supported"),
            "2024-11-05"
        );

        let error = negotiate_protocol_version(&json!({"protocolVersion": "2099-01-01"}))
            .expect_err("unknown protocol must be rejected");
        assert_eq!(error.rpc_code, -32602);
        assert_eq!(error.code, "unsupported_protocol_version");
    }

    #[tokio::test]
    async fn lists_deploy_tools() {
        let mut session = Session::new(None);
        let response = handle_json_rpc(
            &mut session,
            json!({
                "jsonrpc": "2.0",
                "id": 2,
                "method": "tools/list",
                "params": {}
            }),
        )
        .await
        .expect("response");

        let tools = response["result"]["tools"].as_array().expect("tools");
        assert_eq!(tools[0]["name"], "deploy_artifact");
        let legacy = tools
            .iter()
            .find(|tool| tool["name"] == "deploy_to_canvas")
            .expect("legacy tool stays listed");
        assert_eq!(legacy["_meta"]["artfct"]["compatibility"], "deprecated");
        assert_eq!(legacy["_meta"]["artfct"]["replacedBy"], "deploy_artifact");
        assert_eq!(tools[0]["_meta"]["artfct"]["compatibility"], "stable");
    }

    #[tokio::test]
    async fn deploy_artifact_rejects_unknown_tiers_before_any_network_call() {
        let session = Session::new_with_resolution(None, None, None);
        let error =
            super::call_deploy_artifact(&session, json!({"html": "<p>x</p>", "tier": "ephemeral"}))
                .await
                .expect_err("ephemeral is not a permanent tier");

        assert!(error.to_string().contains("public or secure"));
    }

    fn deploy_arguments(model: Option<&str>) -> DeployToolArguments {
        DeployToolArguments {
            html: "<html><body>Hello</body></html>".to_string(),
            tier: "ephemeral".to_string(),
            ttl_minutes: Some(5),
            model: model.map(ToOwned::to_owned),
        }
    }

    #[test]
    fn mcp_builder_populates_agent_and_session() {
        let mut session = Session::new_with_resolution(None, None, None);
        session.host = HostIdentity {
            normalized: Some("cursor".to_string()),
            raw: Some("Cursor".to_string()),
            source: HostSource::ClientInfo,
        };
        session.client_version = Some("2.1".to_string());

        let prepared = prepare_mcp_tool_request(&session, &deploy_arguments(None))
            .expect("prepares MCP artifact request");
        let payload = mcp_create_request_payload(&prepared.request).expect("serializes request");

        assert_eq!(payload["provenance"]["agent"], "cursor");
        assert_eq!(payload["provenance"]["agent_version"], "2.1");
        assert_eq!(
            payload["provenance"]["session_id"],
            session.session_id.as_str()
        );
        assert_eq!(payload["provenance"]["tool"], "deploy_to_canvas");
    }

    #[test]
    fn model_argument_is_marked_self_reported() {
        let session = Session::new_with_resolution(None, None, None);
        let prepared = prepare_mcp_tool_request(&session, &deploy_arguments(Some("claude-opus-5")))
            .expect("prepares MCP artifact request");
        let payload = mcp_create_request_payload(&prepared.request).expect("serializes request");

        assert_eq!(payload["provenance"]["model"], "claude-opus-5");
        assert_eq!(payload["provenance"]["sources"]["model"], "self_reported");
    }

    #[test]
    fn model_absent_records_absent_source() {
        let session = Session::new_with_resolution(None, None, None);
        let prepared = prepare_mcp_tool_request(&session, &deploy_arguments(None))
            .expect("prepares MCP artifact request");
        let payload = mcp_create_request_payload(&prepared.request).expect("serializes request");

        assert!(payload["provenance"]["model"].is_null());
        assert_eq!(payload["provenance"]["sources"]["model"], "absent");
    }

    #[test]
    fn mcp_create_request_validates_against_contract() {
        let session = Session::new_with_resolution(None, None, None);
        let prepared = prepare_mcp_tool_request(&session, &deploy_arguments(None))
            .expect("prepares MCP artifact request");
        let payload =
            mcp_create_request_payload(&prepared.request).expect("builds MCP request payload");

        assert_eq!(payload["provenance"]["tool"], "deploy_to_canvas");
        validate_contract_schema(&payload, "EphemeralArtifactRequest")
            .expect("MCP create request matches EphemeralArtifactRequest");
    }

    #[tokio::test]
    async fn search_tool_listed_alongside_deploy() {
        let mut session = Session::new(None);
        let response = handle_json_rpc(
            &mut session,
            json!({
                "jsonrpc": "2.0",
                "id": 3,
                "method": "tools/list",
                "params": {}
            }),
        )
        .await
        .expect("response");

        let names: Vec<&str> = response["result"]["tools"]
            .as_array()
            .expect("tools array")
            .iter()
            .map(|tool| tool["name"].as_str().expect("tool name"))
            .collect();

        assert!(names.contains(&"deploy_to_canvas"));
        assert!(names.contains(&"search_artifacts"));
        assert!(names.contains(&"get_connection"));
        assert!(names.contains(&"get_usage"));
        assert!(names.contains(&"get_artifact"));
        assert!(names.contains(&"list_collections"));
        assert!(names.contains(&"create_collection"));
        assert!(names.contains(&"add_collection_artifact"));

        let collection_tool = response["result"]["tools"]
            .as_array()
            .unwrap()
            .iter()
            .find(|tool| tool["name"] == "list_collections")
            .expect("list_collections tool present");
        assert_eq!(
            collection_tool["_meta"]["artfct"]["contractVersion"],
            "1.0.0"
        );
        assert_eq!(
            collection_tool["_meta"]["artfct"]["requiredScopes"],
            json!(["collections:read"])
        );

        let search_tool = response["result"]["tools"]
            .as_array()
            .unwrap()
            .iter()
            .find(|tool| tool["name"] == "search_artifacts")
            .expect("search_artifacts tool present");
        assert_eq!(
            search_tool["inputSchema"]["required"],
            json!(["query"]),
            "query must be the only required argument"
        );
    }

    #[tokio::test]
    async fn get_connection_reports_safe_anonymous_context() {
        let mut session = Session::new_with_resolution(None, None, None);
        let response = handle_json_rpc(
            &mut session,
            json!({
                "jsonrpc": "2.0",
                "id": 4,
                "method": "tools/call",
                "params": {"name": "get_connection", "arguments": {}}
            }),
        )
        .await
        .expect("response");

        assert_eq!(
            response["result"]["structuredContent"]["authenticated"],
            false
        );
        assert_eq!(
            response["result"]["structuredContent"]["credential_source"],
            "anonymous"
        );
        assert!(response["result"]["structuredContent"]
            .get("token")
            .is_none());
    }

    #[test]
    fn usage_response_is_customer_safe_and_computes_quota_percentages() {
        let response = format_usage_response(&UsageResponse {
            storage_bytes: 800,
            artifacts_this_period: 4,
            render_minutes_this_period: 12,
            period_start: Some("2026-09-01T00:00:00Z".to_string()),
            period_end: Some("2026-10-01T00:00:00Z".to_string()),
            limits: Some(UsageLimits {
                storage_bytes: 1000,
                artifacts_per_month: 5,
            }),
        });

        assert_eq!(response["structuredContent"]["storage"]["percent"], 80.0);
        assert_eq!(response["structuredContent"]["artifacts"]["percent"], 80.0);
        assert_eq!(response["structuredContent"]["render_minutes"]["used"], 12);
        assert_eq!(
            response["structuredContent"]["period"]["resets_at"],
            "2026-10-01T00:00:00Z"
        );
        assert_eq!(response["structuredContent"]["can_create"], true);
        assert!(response["structuredContent"].get("token").is_none());
    }

    #[tokio::test]
    async fn get_artifact_rejects_path_injection_in_ids() {
        let session = Session::new(None);
        let error = call_get_artifact(&session, json!({"id": "../secrets"}))
            .await
            .expect_err("path-like artifact ID should be rejected");

        assert!(error.to_string().contains("alphanumeric artifact ID"));
    }

    #[tokio::test]
    async fn collection_mutation_rejects_path_injection_in_artifact_ids() {
        let error = call_add_collection_artifact(json!({
            "collection_id": 1,
            "artifact_id": "../secrets"
        }))
        .await
        .expect_err("path-like artifact ID should be rejected");

        assert!(error.to_string().contains("alphanumeric artifact ID"));
    }

    #[tokio::test]
    async fn unknown_method_has_a_stable_error_code() {
        let mut session = Session::new_with_resolution(None, None, None);
        let response = handle_json_rpc(
            &mut session,
            json!({
                "jsonrpc": "2.0",
                "id": 5,
                "method": "does/not/exist",
                "params": {}
            }),
        )
        .await
        .expect("response");

        assert_eq!(response["error"]["code"], -32601);
        assert_eq!(response["error"]["data"]["code"], "method_not_found");
    }

    #[tokio::test]
    async fn malformed_json_rpc_inputs_return_bounded_protocol_errors() {
        let inputs = [
            json!(null),
            json!([]),
            json!({"jsonrpc": "1.0", "id": 1, "method": "tools/list"}),
            json!({"jsonrpc": "2.0", "id": 1}),
            json!({"jsonrpc": "2.0", "id": 1, "method": "tools/call", "params": {"name": 42}}),
        ];

        for input in inputs {
            let mut session = Session::new_with_resolution(None, None, None);
            let response = handle_json_rpc(&mut session, input)
                .await
                .expect("malformed requests should receive a response");

            assert_eq!(response["jsonrpc"], "2.0");
            assert!(response.get("error").is_some());
            assert!(
                response["error"]["message"]
                    .as_str()
                    .expect("protocol error message")
                    .len()
                    <= 300
            );
        }
    }

    #[test]
    fn upstream_errors_are_classified_without_echoing_response_bodies() {
        let error = McpError::from(anyhow!(
            "Artifact Engine returned 429: {{\"code\":\"rate_limit\",\"token\":\"secret\"}}"
        ));
        let response = error.response(Some(json!(1)));

        assert_eq!(response["error"]["data"]["code"], "rate_limited");
        assert_eq!(response["error"]["data"]["retryable"], true);
        assert_eq!(
            response["error"]["message"],
            "This workspace is being rate limited. Retry after the server-provided delay."
        );
        assert!(!response.to_string().contains("secret"));
    }

    #[test]
    fn quota_and_authentication_failures_have_stable_remediation_codes() {
        let quota = McpError::from(anyhow!("Artifact Engine returned 403: quota_exceeded"));
        assert_eq!(
            quota.response(None)["error"]["data"]["code"],
            "quota_exceeded"
        );

        let auth = McpError::from(anyhow!("Artifact Engine returned 401: invalid_token"));
        assert_eq!(
            auth.response(None)["error"]["data"]["code"],
            "authentication_error"
        );
    }

    #[tokio::test]
    async fn authenticated_tool_call_requires_registry_scopes() {
        let mut session = Session::new_with_resolution(None, None, None);
        session.connection = ConnectionContext {
            organization: Some("acme".to_string()),
            user_id: Some("user-1".to_string()),
            scopes: vec!["artifacts:read".to_string()],
            credential_source: CredentialSource::OAuth,
        };

        let response = handle_json_rpc(
            &mut session,
            json!({
                "jsonrpc": "2.0",
                "id": 6,
                "method": "tools/call",
                "params": {
                    "name": "deploy_to_canvas",
                    "arguments": {
                        "html": "<html></html>",
                        "tier": "ephemeral"
                    }
                }
            }),
        )
        .await
        .expect("response");

        assert_eq!(response["error"]["code"], -32003);
        assert_eq!(response["error"]["data"]["code"], "insufficient_scope");
        assert!(response["error"]["message"]
            .as_str()
            .expect("error message")
            .contains("artifacts:deploy"));
    }

    #[test]
    fn tool_definitions_expose_scope_metadata_without_secrets() {
        let definitions = tool_registry::definitions_json();
        let deploy = definitions
            .as_array()
            .expect("tool definitions array")
            .iter()
            .find(|tool| tool["name"] == "deploy_to_canvas")
            .expect("deploy tool definition");

        assert_eq!(deploy["requiredScopes"], json!(["artifacts:deploy"]));
        assert!(serde_json::to_string(&definitions)
            .expect("serialize definitions")
            .find("token")
            .is_none());
    }

    #[test]
    fn every_tool_definition_is_self_describing_and_schema_safe() {
        for tool in tool_registry::definitions_json()
            .as_array()
            .expect("tool definitions should be an array")
        {
            assert!(!tool["name"].as_str().unwrap_or_default().is_empty());
            assert!(!tool["description"].as_str().unwrap_or_default().is_empty());
            assert_eq!(tool["inputSchema"]["type"], "object");
            assert!(tool["annotations"].get("readOnlyHint").is_some());
            assert!(tool["annotations"].get("idempotentHint").is_some());
            assert!(tool["annotations"].get("destructiveHint").is_some());
            assert!(tool["_meta"]["artfct"]["contractVersion"].is_string());
            assert_eq!(tool["_meta"]["artfct"]["owner"], "artfct-mcp");
            assert!(tool["_meta"]["artfct"]["requiredScopes"].is_array());
            assert!(!tool["_meta"]["artfct"]["examples"]
                .as_array()
                .expect("tool examples should be an array")
                .is_empty());
        }
    }

    #[test]
    fn search_respects_limit() {
        let arguments = SearchToolArguments {
            query: "billing dashboard".to_string(),
            repo: None,
            agent: None,
            since: None,
            collection: None,
            limit: 3,
        };

        let payload = search_request_payload(&arguments);

        assert_eq!(payload["limit"], 3);
        assert_eq!(payload["query"], "billing dashboard");
    }

    #[test]
    fn search_returns_snippet_not_bundle() {
        let response = SearchResponse {
            results: vec![SearchResultDto {
                id: "artifact-1".to_string(),
                title: "Billing dashboard".to_string(),
                description: Some("Q3 revenue".to_string()),
                url: "https://artfct.dev/p/artifact-1".to_string(),
                snippet: "Q3 revenue grew 40%…".to_string(),
                provenance: json!({"agent": "cursor", "repo_url": "https://github.com/acme/billing"}),
            }],
        };
        let mut session = Session::new(None);
        session.connection.organization = Some("acme".to_string());

        let shaped = format_search_response(&session, "https://artfct.dev", &response);
        let result = &shaped["structuredContent"]["results"][0];
        assert_eq!(result["snippet"], "Q3 revenue grew 40%…");
        // The Worker's raw /p/{id} URL is not what a member is handed: the app's
        // open route is, because it is the one that authorizes the viewer.
        assert_eq!(
            result["view_url"],
            format!("https://artfct.dev{APP_OPEN_PATH_TEMPLATE}")
                .replace("{team}", "acme")
                .replace("{artifactId}", "artifact-1")
        );
        assert!(result.get("url").is_none());
        // Never the full bundle: no "html" or "content"/"bundle" field on a
        // result, only what the tool description promises.
        assert!(result.get("html").is_none());
        assert!(result.get("bundle").is_none());
        let allowed_keys = [
            "id",
            "title",
            "description",
            "view_url",
            "snippet",
            "provenance",
        ];
        for key in result.as_object().expect("result object").keys() {
            assert!(
                allowed_keys.contains(&key.as_str()),
                "unexpected field `{key}` in a search result — only snippet/provenance summary allowed, never full content"
            );
        }
    }

    /// Stands in for the Artifact Engine as `deploy_to_canvas` meets it: a
    /// hand-rolled HTTP/1.1 responder on a real loopback socket that serves the
    /// create call, then serves `/p/{id}` the way the Worker's KV path does —
    /// and serves nothing else. Anything else 404s, which is what the app's own
    /// open route would do for a KV artifact anyway: that route resolves D1
    /// content this artifact does not have.
    struct StubArtifactEngine {
        base_url: String,
        requests: Arc<Mutex<Vec<String>>>,
        stop: Arc<AtomicBool>,
        accept_loop: Option<JoinHandle<()>>,
    }

    impl StubArtifactEngine {
        fn start(artifact_id: &str, tier: &str) -> Self {
            let listener = TcpListener::bind(("127.0.0.1", 0)).expect("bind the stub engine");
            let port = listener.local_addr().expect("stub engine address").port();
            listener
                .set_nonblocking(true)
                .expect("the stub engine polls for connections");

            let base_url = format!("http://127.0.0.1:{port}");
            let created = json!({
                "id": artifact_id,
                "url": format!("{base_url}/p/{artifact_id}"),
                "tier": tier,
                "expires_at": "2026-09-30T00:00:00Z",
                "title": "Canvas preview",
                "description": "Encrypted HTML preview on artfct.",
                "thumbnail": "https://artfct.dev/og-image.svg",
                "preview_blurred": true
            })
            .to_string();
            let kv_path = format!("/p/{artifact_id}");

            let requests = Arc::new(Mutex::new(Vec::new()));
            let stop = Arc::new(AtomicBool::new(false));
            let accept_loop = {
                let requests = Arc::clone(&requests);
                let stop = Arc::clone(&stop);
                thread::spawn(move || {
                    while !stop.load(Ordering::SeqCst) {
                        match listener.accept() {
                            Ok((stream, _)) => {
                                serve_stub_request(stream, &created, &kv_path, &requests)
                            }
                            Err(error) if error.kind() == ErrorKind::WouldBlock => {
                                thread::sleep(Duration::from_millis(5));
                            }
                            Err(_) => break,
                        }
                    }
                })
            };

            Self {
                base_url,
                requests,
                stop,
                accept_loop: Some(accept_loop),
            }
        }

        fn base_url(&self) -> &str {
            &self.base_url
        }

        fn request_lines(&self) -> Vec<String> {
            self.requests.lock().expect("stub requests").clone()
        }
    }

    impl Drop for StubArtifactEngine {
        fn drop(&mut self) {
            self.stop.store(true, Ordering::SeqCst);
            if let Some(accept_loop) = self.accept_loop.take() {
                let _ = accept_loop.join();
            }
        }
    }

    fn serve_stub_request(
        mut stream: TcpStream,
        created: &str,
        kv_path: &str,
        requests: &Mutex<Vec<String>>,
    ) {
        stream
            .set_read_timeout(Some(Duration::from_secs(10)))
            .expect("the stub engine bounds its reads");
        let request = read_stub_request(&mut stream);
        requests
            .lock()
            .expect("stub requests")
            .push(request.lines().next().unwrap_or_default().to_string());

        let (status, content_type, body) = if request.starts_with("POST /v1/artifacts ") {
            ("201 Created", "application/json", created.to_string())
        } else if request.starts_with(&format!("GET {kv_path} ")) {
            (
                "200 OK",
                "text/html",
                "<!doctype html><title>Canvas preview</title><p>Waiting for the decryption key in the URL fragment.</p>"
                    .to_string(),
            )
        } else {
            (
                "404 Not Found",
                "application/json",
                "{\"error\":{\"code\":\"artifact_not_found\"}}".to_string(),
            )
        };

        let _ = stream.write_all(
            format!(
                "HTTP/1.1 {status}\r\nContent-Type: {content_type}\r\nContent-Length: {}\r\nConnection: close\r\n\r\n{body}",
                body.len()
            )
            .as_bytes(),
        );
        let _ = stream.flush();
    }

    /// Reads one HTTP request off `stream`: the headers, then the bytes the
    /// `Content-Length` header announces.
    fn read_stub_request(stream: &mut TcpStream) -> String {
        let mut request = Vec::new();
        let mut buffer = [0_u8; 1024];
        let mut header_end = None;

        while header_end.is_none() {
            match stream.read(&mut buffer) {
                Ok(0) | Err(_) => break,
                Ok(length) => request.extend_from_slice(&buffer[..length]),
            }
            header_end = request
                .windows(4)
                .position(|window| window == b"\r\n\r\n")
                .map(|index| index + 4);
        }

        let header_end = header_end.unwrap_or(request.len());
        let content_length = String::from_utf8_lossy(&request[..header_end])
            .lines()
            .find_map(|line| {
                let (name, value) = line.split_once(':')?;
                if !name.eq_ignore_ascii_case("content-length") {
                    return None;
                }
                value.trim().parse::<usize>().ok()
            })
            .unwrap_or(0);

        while request.len() - header_end < content_length {
            match stream.read(&mut buffer) {
                Ok(0) | Err(_) => break,
                Ok(length) => request.extend_from_slice(&buffer[..length]),
            }
        }

        String::from_utf8_lossy(&request).into_owned()
    }

    /// Follow a `view_url` the way a browser would: connect to the origin it
    /// names, send its path — a browser never sends the fragment — and report
    /// the status and body that came back.
    fn follow_loopback_url(url: &str) -> (u16, String) {
        let (authority, rest) = url
            .split_once("://")
            .expect("a view_url carries a scheme")
            .1
            .split_once('/')
            .expect("a view_url carries a path");
        let path = format!("/{}", rest.split('#').next().unwrap_or_default());

        let mut stream = TcpStream::connect(authority).expect("connect to the view_url origin");
        stream
            .set_read_timeout(Some(Duration::from_secs(10)))
            .expect("the follow is bounded");
        stream
            .write_all(
                format!("GET {path} HTTP/1.1\r\nHost: {authority}\r\nConnection: close\r\n\r\n")
                    .as_bytes(),
            )
            .expect("write the follow request");

        let mut response = String::new();
        stream
            .read_to_string(&mut response)
            .expect("read the follow response");

        let status = response
            .split_whitespace()
            .nth(1)
            .and_then(|code| code.parse::<u16>().ok())
            .unwrap_or_else(|| panic!("no status line in {response}"));
        let body = response
            .split_once("\r\n\r\n")
            .map(|(_, body)| body.to_string())
            .unwrap_or_default();

        (status, body)
    }

    /// The property the hosted suite proves for `deploy_to_canvas` — the link
    /// the tool hands back resolves for the artifact the tool actually created —
    /// proved here for the local server's tool, at every tier its schema
    /// accepts, by following the link over a real socket. The stub serves the
    /// create call and the KV path and nothing else, so an app open route (or
    /// any other URL) answers 404 rather than being blessed by a prefix match.
    #[tokio::test(flavor = "multi_thread")]
    async fn deploy_to_canvas_returns_a_link_that_resolves_at_every_tier_it_accepts() {
        let artifact_id = "abcdefghijklmnop";

        for tier in ["public", "secure", "ephemeral"] {
            let stub = StubArtifactEngine::start(artifact_id, tier);
            let session = Session::new_with_resolution(None, None, None);

            let result = super::call_deploy_to_canvas_at(
                &session,
                json!({"html": "<title>Canvas</title><h1>preview</h1>", "tier": tier}),
                stub.base_url(),
            )
            .await
            .unwrap_or_else(|error| {
                panic!("tier {tier}: the stub engine accepts the deploy: {error}")
            });

            let view_url = result["structuredContent"]["view_url"]
                .as_str()
                .unwrap_or_else(|| panic!("tier {tier}: the tool returns a view_url"))
                .to_string();
            let worker_url = format!("{}/p/{artifact_id}", stub.base_url());

            assert_eq!(
                result["structuredContent"]["canonical_url"], worker_url,
                "tier {tier}: the canonical URL is the Worker's own"
            );
            assert!(
                view_url.starts_with(&format!("{worker_url}#")),
                "tier {tier}: the link is the Worker's KV path with its fragment, not another origin: {view_url}"
            );
            assert!(
                !view_url.contains(APP_OPEN_PATH_TEMPLATE),
                "tier {tier}: the app's open route cannot resolve a KV artifact, so it must never be handed out: {view_url}"
            );

            let (status, body) = follow_loopback_url(&view_url);

            assert_eq!(
                status, 200,
                "tier {tier}: following {view_url} must reach the artifact, not a 404"
            );
            assert!(
                body.contains("Waiting for the decryption key in the URL fragment."),
                "tier {tier}: the KV preview shell answers, so the link resolves for this artifact: {body}"
            );

            let lines = stub.request_lines();
            assert!(
                lines
                    .iter()
                    .any(|line| line == &format!("GET /p/{artifact_id} HTTP/1.1")),
                "tier {tier}: the follow must hit the KV path itself: {lines:?}"
            );
        }
    }

    /// The shared cross-server contract: which link a tool hands back for a
    /// tier, and what that link is called. The fixture is the same file the
    /// hosted server's PHP suite reads, so a change to the shape fails both
    /// servers rather than letting one drift.
    #[test]
    fn view_url_follows_the_shared_cross_server_contract() {
        let contract: Value = serde_json::from_str(include_str!(
            "../../tests/Fixtures/artifact-view-link-contract.json"
        ))
        .expect("shared view-url contract fixture parses");

        assert_eq!(contract["field"], "view_url");
        assert_eq!(contract["public_path_template"], "/p/{artifact}");
        assert_eq!(contract["app_open_path_template"], APP_OPEN_PATH_TEMPLATE);
        assert_eq!(contract["tiers"]["public"], "worker_public_url");
        assert_eq!(contract["tiers"]["ephemeral"], "worker_public_url");
        assert_eq!(contract["tiers"]["secure"], "app_open_route");
        assert_eq!(contract["tiers"]["unreadable"], "app_open_route");

        // `deploy_to_canvas` is the exception the hosted suite proves in
        // behaviour: its artifacts are anonymous KV records the app's open
        // route cannot resolve at any tier, so every tier it accepts gets the
        // Worker's own URL. The fixture states the rule once for both servers.
        assert_eq!(
            contract["deploy_to_canvas"]["path_template"],
            "/p/{artifact}"
        );
        assert_eq!(contract["deploy_to_canvas"]["fragment"], "required");

        let deploy_to_canvas = tool_registry::definitions_json();
        let accepted_tiers: Vec<String> = deploy_to_canvas
            .as_array()
            .expect("tool array")
            .iter()
            .find(|tool| tool["name"] == "deploy_to_canvas")
            .expect("the catalog defines deploy_to_canvas")["inputSchema"]["properties"]["tier"]
            ["enum"]
            .as_array()
            .expect("deploy_to_canvas takes a tier enum")
            .iter()
            .map(|tier| tier.as_str().expect("tier names are strings").to_string())
            .collect();

        for tier in &accepted_tiers {
            assert_eq!(
                contract["deploy_to_canvas"]["tiers"][tier.as_str()], "worker_public_url",
                "`{tier}` is a tier deploy_to_canvas accepts, so the fixture must say it gets the Worker's URL"
            );
        }

        // The fixture names exactly the tiers the tool accepts: a tier added to
        // the schema without a contract entry fails here, on both servers.
        let fixture_tiers: Vec<&str> = contract["deploy_to_canvas"]["tiers"]
            .as_object()
            .expect("the fixture lists deploy_to_canvas tiers")
            .keys()
            .map(String::as_str)
            .collect();
        for tier in &fixture_tiers {
            assert!(
                accepted_tiers.iter().any(|accepted| accepted == tier),
                "the fixture names `{tier}`, which deploy_to_canvas does not accept"
            );
        }

        // And the local server's own link builder for those artifacts yields
        // the Worker's path with no app route in it, at every tier.
        let anonymous = anonymous_artifact_url("https://artfct.dev", "artifact-1", None);
        assert_eq!(anonymous, "https://artfct.dev/p/artifact-1");
        assert!(!anonymous.contains(APP_OPEN_PATH_TEMPLATE));
        assert_eq!(
            anonymous_artifact_url(
                "https://api.artfct.dev",
                "artifact-1",
                Some("https://staging.artfct.dev/p/artifact-1"),
            ),
            "https://staging.artfct.dev/p/artifact-1"
        );

        let app_open_path = APP_OPEN_PATH_TEMPLATE
            .replace("{team}", "acme")
            .replace("{artifactId}", "artifact-1");

        // An artifact the Worker serves without a credential — a public one, or
        // the anonymous ephemeral preview deploy_to_canvas publishes — gets the
        // raw URL: no token, no session.
        for tier in ["public", "ephemeral"] {
            assert_eq!(
                artifact_view_url(
                    "https://artfct.dev",
                    "https://app.test",
                    Some("acme"),
                    "artifact-1",
                    Some(tier),
                    None,
                ),
                "https://artfct.dev/p/artifact-1"
            );
        }

        // Secure — and any tier the response did not carry — opens through the
        // app's session-authenticated open route, never the Worker's raw URL.
        for tier in [Some("secure"), None, Some("permanent")] {
            let url = artifact_view_url(
                "https://artfct.dev",
                "https://app.test",
                Some("acme"),
                "artifact-1",
                tier,
                None,
            );

            assert_eq!(url, format!("https://app.test{app_open_path}"));
            assert!(!url.contains("/p/"));
        }

        // The Worker's own published URL wins for an anonymous artifact: it is
        // the artifact's real address, and a staging Worker whose public base
        // differs from the API base must not be reconstructed from config.
        assert_eq!(
            artifact_view_url(
                "https://api.artfct.dev",
                "https://app.test",
                Some("acme"),
                "artifact-1",
                Some("public"),
                Some("https://custom.example/p/artifact-1"),
            ),
            "https://custom.example/p/artifact-1"
        );

        // No workspace slug: there is no app route to address, so the Worker's
        // own URL is the only link left.
        assert_eq!(
            artifact_view_url(
                "https://artfct.dev",
                "https://app.test",
                None,
                "artifact-1",
                Some("secure"),
                None,
            ),
            "https://artfct.dev/p/artifact-1"
        );
    }

    /// Both servers must name the field `view_url`. The local catalog is what a
    /// local agent reads, so a description that still promises a `url`
    /// disagrees with what the tool returns.
    #[test]
    fn the_local_tool_catalog_names_view_url() {
        let definitions = tool_registry::definitions_json();
        let by_name: std::collections::HashMap<&str, &Value> = definitions
            .as_array()
            .expect("tool array")
            .iter()
            .map(|tool| (tool["name"].as_str().expect("tool name"), tool))
            .collect();

        for name in [
            "deploy_artifact",
            "deploy_to_canvas",
            "search_artifacts",
            "get_artifact",
        ] {
            let description = by_name
                .get(name)
                .unwrap_or_else(|| panic!("the catalog must define `{name}`"))["description"]
                .as_str()
                .expect("description is a string");

            assert!(
                description.contains("view_url"),
                "`{name}` must tell a local agent the field is `view_url`"
            );
        }
    }
}
