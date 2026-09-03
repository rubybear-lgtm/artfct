use anyhow::{anyhow, Context, Result};
use chrono::{DateTime, Utc};
use ring::rand::{SecureRandom, SystemRandom};
use serde::Deserialize;
use serde_json::{json, Value};
use tokio::io::{self, AsyncBufReadExt, AsyncWriteExt, BufReader};

use crate::api;
use crate::artifact_crypto;
use crate::provenance::{self, McpProvenanceInput, ProvenanceSource};

const PROTOCOL_VERSION: &str = "2025-11-25";
const DEFAULT_API_BASE_URL: &str = "https://artfct.dev";

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
    arguments: DeployToolArguments,
}

#[derive(Debug, Deserialize)]
struct DeployToolArguments {
    html: String,
    tier: String,
    ttl_minutes: Option<u64>,
    model: Option<String>,
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
        Err(error) => Some(json_rpc_error(
            None,
            -32700,
            format!("Parse error: {error}"),
        )),
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
            return Some(json_rpc_error(
                None,
                -32600,
                format!("Invalid request: {error}"),
            ));
        }
    };

    request.id.as_ref()?;

    if request.jsonrpc != "2.0" {
        return Some(json_rpc_error(
            request.id,
            -32600,
            "Invalid JSON-RPC version.",
        ));
    }

    let result = match request.method.as_str() {
        "initialize" => {
            session.capture_client_info(&request.params);
            log_identity(session);
            Ok(initialize_result())
        }
        "tools/list" => Ok(tools_list_result()),
        "tools/call" => call_tool(session, request.params, |message| eprintln!("{message}")).await,
        _ => Err(anyhow!("Unknown method: {}", request.method)),
    };

    Some(match result {
        Ok(result) => json_rpc_success(request.id, result),
        Err(error) => json_rpc_error(request.id, -32603, error.to_string()),
    })
}

fn initialize_result() -> Value {
    json!({
        "protocolVersion": PROTOCOL_VERSION,
        "capabilities": {
            "tools": {}
        },
        "serverInfo": {
            "name": "artfct",
            "version": env!("CARGO_PKG_VERSION")
        }
    })
}

fn tools_list_result() -> Value {
    json!({
        "tools": [
            {
                "name": "deploy_to_canvas",
                "description": "Call this tool whenever you generate a self-contained HTML/CSS/JS page, template, or visual dashboard that the user needs to view or share via Slack. Do not emit raw code markdown blocks if this tool is available.",
                "inputSchema": {
                    "type": "object",
                    "properties": {
                        "html": {
                            "type": "string",
                            "description": "The complete, valid, self-contained HTML payload to host."
                        },
                        "tier": {
                            "type": "string",
                            "enum": ["public", "secure", "ephemeral"]
                        },
                        "ttl_minutes": {
                            "type": "integer",
                            "minimum": 1,
                            "description": "Optional artifact lifetime in minutes."
                        },
                        "model": {
                            "type": "string",
                            "description": "Optional agent-attested model identifier."
                        }
                    },
                    "required": ["html", "tier"]
                },
                "annotations": {
                    "readOnlyHint": false,
                    "idempotentHint": false,
                    "destructiveHint": false,
                    "openWorldHint": true
                }
            }
        ]
    })
}

async fn call_tool<F>(session: &Session, params: Value, mut diagnostic_sink: F) -> Result<Value>
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

    if params.name != "deploy_to_canvas" {
        return Err(anyhow!("Unknown tool: {}", params.name));
    }

    let api_base_url =
        std::env::var("ARTFCT_API_BASE_URL").unwrap_or_else(|_| DEFAULT_API_BASE_URL.to_string());
    let prepared = prepare_mcp_tool_request(session, &params.arguments)?;
    let request = mcp_create_request_payload(&prepared.request)?;
    let artifact =
        api::deploy_artifact_payload(&reqwest::Client::new(), &api_base_url, &request).await?;
    let full_url = format!("{}{}", artifact.url, prepared.fragment);

    Ok(json!({
        "content": [
            {
                "type": "text",
                "text": format!("Artifact deployed: {}", full_url)
            }
        ],
        "structuredContent": {
            "id": artifact.id,
            "url": full_url,
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

fn json_rpc_error(id: Option<Value>, code: i32, message: impl Into<String>) -> Value {
    json!({
        "jsonrpc": "2.0",
        "id": id,
        "error": {
            "code": code,
            "message": message.into()
        }
    })
}

#[cfg(test)]
mod tests {
    use std::process::Command;

    use serde_json::json;

    use super::{
        call_tool, handle_json_rpc, mcp_create_request_payload, prepare_mcp_tool_request,
        resolve_host, session_identity, DeployToolArguments, HostIdentity, HostSource, Session,
    };
    use crate::api::tests::validate_contract_schema;

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
    }

    #[tokio::test]
    async fn lists_deploy_to_canvas_tool() {
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

        assert_eq!(response["result"]["tools"][0]["name"], "deploy_to_canvas");
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
}
