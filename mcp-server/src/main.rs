use std::env;

use anyhow::{anyhow, Context, Result};
use serde::{Deserialize, Serialize};
use serde_json::{json, Value};
use tokio::io::{self, AsyncBufReadExt, AsyncWriteExt, BufReader};

const PROTOCOL_VERSION: &str = "2025-11-25";
const DEFAULT_API_BASE_URL: &str = "https://artfct.dev";

#[derive(Debug, Deserialize)]
struct JsonRpcRequest {
    jsonrpc: String,
    id: Option<Value>,
    method: String,
    #[serde(default)]
    params: Value,
}

#[derive(Debug, Serialize)]
struct JsonRpcResponse {
    jsonrpc: &'static str,
    #[serde(skip_serializing_if = "Option::is_none")]
    id: Option<Value>,
    #[serde(skip_serializing_if = "Option::is_none")]
    result: Option<Value>,
    #[serde(skip_serializing_if = "Option::is_none")]
    error: Option<JsonRpcError>,
}

#[derive(Debug, Serialize)]
struct JsonRpcError {
    code: i32,
    message: String,
}

#[derive(Debug, Deserialize)]
struct ToolCallParams {
    name: String,
    arguments: DeployToCanvasArgs,
}

#[derive(Debug, Deserialize, Serialize)]
#[serde(rename_all = "snake_case")]
struct DeployToCanvasArgs {
    html: String,
    tier: ArtifactTier,
    #[serde(skip_serializing_if = "Option::is_none")]
    ttl_minutes: Option<u64>,
}

#[derive(Debug, Deserialize, Serialize)]
#[serde(rename_all = "snake_case")]
enum ArtifactTier {
    Public,
    Secure,
    Ephemeral,
}

#[derive(Debug, Deserialize)]
struct CreateArtifactResponse {
    id: String,
    url: String,
    tier: ArtifactTier,
    expires_at: String,
}

#[tokio::main]
async fn main() -> Result<()> {
    let stdin = BufReader::new(io::stdin());
    let mut lines = stdin.lines();
    let mut stdout = io::stdout();

    while let Some(line) = lines.next_line().await? {
        if line.trim().is_empty() {
            continue;
        }

        let response = match serde_json::from_str::<JsonRpcRequest>(&line) {
            Ok(request) => handle_request(request).await,
            Err(error) => Some(JsonRpcResponse::error(
                None,
                -32700,
                format!("Parse error: {error}"),
            )),
        };

        if let Some(response) = response {
            let encoded = serde_json::to_string(&response)?;
            stdout.write_all(encoded.as_bytes()).await?;
            stdout.write_all(b"\n").await?;
            stdout.flush().await?;
        }
    }

    Ok(())
}

async fn handle_request(request: JsonRpcRequest) -> Option<JsonRpcResponse> {
    if request.id.is_none() {
        return None;
    }

    if request.jsonrpc != "2.0" {
        return Some(JsonRpcResponse::error(
            request.id,
            -32600,
            "Invalid JSON-RPC version.",
        ));
    }

    let result = match request.method.as_str() {
        "initialize" => Ok(initialize_result()),
        "tools/list" => Ok(tools_list_result()),
        "tools/call" => call_tool(request.params).await,
        _ => Err(anyhow!("Unknown method: {}", request.method)),
    };

    match result {
        Ok(result) => Some(JsonRpcResponse::success(request.id, result)),
        Err(error) => Some(JsonRpcResponse::error(
            request.id,
            -32603,
            error.to_string(),
        )),
    }
}

fn initialize_result() -> Value {
    json!({
        "protocolVersion": PROTOCOL_VERSION,
        "capabilities": {
            "tools": {}
        },
        "serverInfo": {
            "name": "artfct-mcp-server",
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

async fn call_tool(params: Value) -> Result<Value> {
    let params: ToolCallParams =
        serde_json::from_value(params).context("Invalid tools/call params")?;

    if params.name != "deploy_to_canvas" {
        return Err(anyhow!("Unknown tool: {}", params.name));
    }

    if params.arguments.html.trim().is_empty() {
        return Err(anyhow!("html is required"));
    }

    let artifact = deploy_to_canvas(params.arguments).await?;
    Ok(json!({
        "content": [
            {
                "type": "text",
                "text": format!("Artifact deployed: {}", artifact.url)
            }
        ],
        "structuredContent": {
            "id": artifact.id,
            "url": artifact.url,
            "tier": artifact.tier,
            "expires_at": artifact.expires_at
        }
    }))
}

async fn deploy_to_canvas(args: DeployToCanvasArgs) -> Result<CreateArtifactResponse> {
    let api_base_url =
        env::var("ARTFCT_API_BASE_URL").unwrap_or_else(|_| DEFAULT_API_BASE_URL.to_string());
    let endpoint = format!("{}/v1/artifacts", api_base_url.trim_end_matches('/'));
    let mut request = reqwest::Client::new().post(endpoint).json(&args);

    if let Ok(token) = env::var("ARTFCT_API_TOKEN") {
        if !token.trim().is_empty() {
            request = request.bearer_auth(token);
        }
    }

    let response = request
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

impl JsonRpcResponse {
    fn success(id: Option<Value>, result: Value) -> Self {
        Self {
            jsonrpc: "2.0",
            id,
            result: Some(result),
            error: None,
        }
    }

    fn error(id: Option<Value>, code: i32, message: impl Into<String>) -> Self {
        Self {
            jsonrpc: "2.0",
            id,
            result: None,
            error: Some(JsonRpcError {
                code,
                message: message.into(),
            }),
        }
    }
}
