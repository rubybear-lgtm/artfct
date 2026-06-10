use anyhow::{anyhow, Context, Result};
use serde::Deserialize;
use serde_json::{json, Value};
use tokio::io::{self, AsyncBufReadExt, AsyncWriteExt, BufReader};

use crate::api;
use crate::artifact_crypto;

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
}

pub async fn run_stdio_server() -> Result<()> {
    let stdin = BufReader::new(io::stdin());
    let mut lines = stdin.lines();
    let mut stdout = io::stdout();

    while let Some(line) = lines.next_line().await? {
        if line.trim().is_empty() {
            continue;
        }

        if let Some(response) = handle_json_rpc_line(&line).await {
            let encoded = serde_json::to_string(&response)?;
            stdout.write_all(encoded.as_bytes()).await?;
            stdout.write_all(b"\n").await?;
            stdout.flush().await?;
        }
    }

    Ok(())
}

async fn handle_json_rpc_line(line: &str) -> Option<Value> {
    match serde_json::from_str::<Value>(line) {
        Ok(value) => handle_json_rpc(value).await,
        Err(error) => Some(json_rpc_error(
            None,
            -32700,
            format!("Parse error: {error}"),
        )),
    }
}

pub async fn handle_json_rpc(value: Value) -> Option<Value> {
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
        "initialize" => Ok(initialize_result()),
        "tools/list" => Ok(tools_list_result()),
        "tools/call" => call_tool(request.params).await,
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

    let api_base_url =
        std::env::var("ARTFCT_API_BASE_URL").unwrap_or_else(|_| DEFAULT_API_BASE_URL.to_string());
    let prepared = artifact_crypto::prepare_artifact_request(
        &params.arguments.html,
        params.arguments.tier,
        params.arguments.ttl_minutes,
        true,
    )?;
    let artifact =
        api::deploy_artifact(&reqwest::Client::new(), &api_base_url, &prepared.request).await?;
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
    use serde_json::json;

    use super::handle_json_rpc;

    #[tokio::test]
    async fn handles_initialize() {
        let response = handle_json_rpc(json!({
            "jsonrpc": "2.0",
            "id": 1,
            "method": "initialize",
            "params": {}
        }))
        .await
        .expect("response");

        assert_eq!(response["jsonrpc"], "2.0");
        assert_eq!(response["id"], 1);
        assert_eq!(response["result"]["serverInfo"]["name"], "artfct");
    }

    #[tokio::test]
    async fn lists_deploy_to_canvas_tool() {
        let response = handle_json_rpc(json!({
            "jsonrpc": "2.0",
            "id": 2,
            "method": "tools/list",
            "params": {}
        }))
        .await
        .expect("response");

        assert_eq!(response["result"]["tools"][0]["name"], "deploy_to_canvas");
    }
}
