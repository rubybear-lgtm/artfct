use std::io::{Read, Write};
use std::process::{Command, Stdio};

use serde_json::Value;

#[test]
fn stdio_server_round_trips_initialize_and_tools_list_as_json_lines() {
    let mut child = Command::new(env!("CARGO_BIN_EXE_artfct"))
        .args(["mcp", "serve"])
        .stdin(Stdio::piped())
        .stdout(Stdio::piped())
        .stderr(Stdio::piped())
        .spawn()
        .expect("start the artfct MCP server");

    let requests = [
        serde_json::json!({
            "jsonrpc": "2.0",
            "id": 1,
            "method": "initialize",
            "params": {
                "protocolVersion": "2025-11-25",
                "clientInfo": {"name": "stdio-test", "version": "1.0.0"},
                "capabilities": {}
            }
        }),
        serde_json::json!({
            "jsonrpc": "2.0",
            "id": 2,
            "method": "tools/list",
            "params": {}
        }),
    ];

    {
        let stdin = child.stdin.as_mut().expect("server stdin is piped");
        for request in requests {
            writeln!(stdin, "{}", serde_json::to_string(&request).unwrap())
                .expect("write JSON-RPC request");
        }
    }
    drop(child.stdin.take());

    let mut stdout = String::new();
    child
        .stdout
        .take()
        .expect("server stdout is piped")
        .read_to_string(&mut stdout)
        .expect("read JSON-RPC responses");
    let output = child.wait_with_output().expect("wait for the MCP server");

    assert!(
        output.status.success(),
        "stderr: {}",
        String::from_utf8_lossy(&output.stderr)
    );

    let responses: Vec<Value> = stdout
        .lines()
        .map(|line| serde_json::from_str(line).expect("stdout contains JSON-RPC only"))
        .collect();

    assert_eq!(responses.len(), 2);
    assert_eq!(responses[0]["jsonrpc"], "2.0");
    assert_eq!(responses[0]["id"], 1);
    assert_eq!(responses[0]["result"]["serverInfo"]["name"], "artfct");
    assert_eq!(responses[1]["jsonrpc"], "2.0");
    assert_eq!(responses[1]["id"], 2);
    assert!(responses[1]["result"]["tools"]
        .as_array()
        .expect("tools/list returns a tool array")
        .iter()
        .any(|tool| tool["name"] == "deploy_artifact"));
}
