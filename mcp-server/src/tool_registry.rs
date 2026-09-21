use serde::Serialize;
use serde_json::{json, Value};

#[derive(Debug, Serialize)]
#[serde(rename_all = "camelCase")]
pub struct ToolDefinition {
    pub name: &'static str,
    pub description: &'static str,
    pub input_schema: Value,
    pub annotations: Value,
    pub required_scopes: &'static [&'static str],
    #[serde(rename = "_meta")]
    pub meta: Value,
}

fn tool_meta(tool_name: &str, required_scopes: &[&str], tool_version: &str) -> Value {
    json!({
        "artfct": {
            "contractVersion": "1.0.0",
            "toolVersion": tool_version,
            "owner": "artfct-mcp",
            "requiredScopes": required_scopes,
            "compatibility": if tool_name == "deploy_to_canvas" { "deprecated" } else { "stable" },
            "replacedBy": if tool_name == "deploy_to_canvas" { Value::from("deploy_artifact") } else { Value::Null },
            "examples": tool_examples(tool_name)
        }
    })
}

fn tool_examples(tool_name: &str) -> Value {
    match tool_name {
        "deploy_artifact" => json!([{
            "description": "Publish a generated report to the team so it can be found and reused.",
            "arguments": {"html": "<!doctype html><title>Q3 report</title><p>Summary</p>", "tier": "secure"}
        }]),
        "deploy_to_canvas" => json!([{
            "description": "Publish a generated dashboard for review.",
            "arguments": {"html": "<!doctype html><title>Dashboard</title>", "tier": "public"}
        }]),
        "search_artifacts" => json!([{
            "description": "Find an existing dashboard before generating a new one.",
            "arguments": {"query": "billing dashboard", "limit": 5}
        }]),
        "get_connection" => json!([{
            "description": "Inspect the current authenticated workspace and client.",
            "arguments": {}
        }]),
        "get_usage" => json!([{
            "description": "Inspect current workspace usage and quota status.",
            "arguments": {}
        }]),
        "get_artifact" => json!([{
            "description": "Read safe metadata for one artifact.",
            "arguments": {"id": "abc123"}
        }]),
        "list_collections" => json!([{
            "description": "List approved workspace collections.",
            "arguments": {"limit": 20}
        }]),
        "create_collection" => json!([{
            "description": "Create a named collection for approved artifacts.",
            "arguments": {"name": "Reporting formats"}
        }]),
        "add_collection_artifact" => json!([{
            "description": "Add an artifact to an approved collection.",
            "arguments": {"collection_id": 1, "artifact_id": "abc123"}
        }]),
        _ => json!([]),
    }
}

pub fn definitions() -> Vec<ToolDefinition> {
    vec![
        ToolDefinition {
            name: "deploy_artifact",
            description: "Publish a self-contained HTML document as a permanent artifact in the authenticated workspace. The workspace can then search, retrieve, collect and count it. Re-publishing identical content returns the same artifact.",
            input_schema: json!({
                "type": "object",
                "properties": {
                    "html": {
                        "type": "string",
                        "description": "A self-contained HTML document."
                    },
                    "tier": {
                        "type": "string",
                        "enum": ["public", "secure"],
                        "description": "Who can open the link: secure (default, signed-in workspace members) or public."
                    },
                    "title": {
                        "type": "string",
                        "description": "Optional title; defaults to the document title."
                    },
                    "description": {
                        "type": "string",
                        "description": "Optional summary used in search results."
                    },
                    "model": {
                        "type": "string",
                        "description": "Optional model name for provenance."
                    }
                },
                "required": ["html"]
            }),
            annotations: json!({
                "readOnlyHint": false,
                "idempotentHint": true,
                "destructiveHint": false,
                "openWorldHint": true
            }),
            required_scopes: &["artifacts:deploy"],
            meta: tool_meta("deploy_artifact", &["artifacts:deploy"], "1.0.0"),
        },
        ToolDefinition {
            name: "deploy_to_canvas",
            description: "Deprecated: use deploy_artifact. Publishes an anonymous, expiring HTML artifact that the workspace cannot search, retrieve, collect or count.",
            input_schema: json!({
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
            }),
            annotations: json!({
                "readOnlyHint": false,
                "idempotentHint": false,
                "destructiveHint": false,
                "openWorldHint": true
            }),
            required_scopes: &["artifacts:deploy"],
            meta: tool_meta("deploy_to_canvas", &["artifacts:deploy"], "1.0.0"),
        },
        ToolDefinition {
            name: "search_artifacts",
            description: "Search this org's previously deployed artifacts before building something new. Call this BEFORE generating a dashboard, page, or report the user references (\"the billing dashboard\", \"that report from last week\") — an existing artifact answering the request should be returned as a link, not regenerated from scratch. Returns a short list of title, description, URL, provenance summary and a text snippet for each match — never the full HTML. Scoped strictly to the caller's org.",
            input_schema: json!({
                "type": "object",
                "properties": {
                    "query": {
                        "type": "string",
                        "description": "What to search for, in natural language."
                    },
                    "repo": {
                        "type": "string",
                        "description": "Optional: restrict to artifacts provenanced from this repo URL."
                    },
                    "agent": {
                        "type": "string",
                        "description": "Optional: restrict to artifacts created by this agent."
                    },
                    "since": {
                        "type": "string",
                        "description": "Optional: ISO 8601 date; excludes artifacts created before it."
                    },
                    "collection": {
                        "type": "string",
                        "description": "Optional: restrict to one named collection, e.g. the org's canonical/approved artifacts for this kind of request."
                    },
                    "limit": {
                        "type": "integer",
                        "minimum": 1,
                        "maximum": 50,
                        "description": "Maximum results to return (default 5)."
                    }
                },
                "required": ["query"]
            }),
            annotations: json!({
                "readOnlyHint": true,
                "idempotentHint": true,
                "destructiveHint": false,
                "openWorldHint": false
            }),
            required_scopes: &["artifacts:read"],
            meta: tool_meta("search_artifacts", &["artifacts:read"], "1.0.0"),
        },
        ToolDefinition {
            name: "get_connection",
            description: "Inspect the current Artfct MCP connection without exposing credentials. Use this to diagnose organization, client, scope, and authentication state.",
            input_schema: json!({
                "type": "object",
                "properties": {},
                "additionalProperties": false
            }),
            annotations: json!({
                "readOnlyHint": true,
                "idempotentHint": true,
                "destructiveHint": false,
                "openWorldHint": false
            }),
            required_scopes: &[],
            meta: tool_meta("get_connection", &[], "1.0.0"),
        },
        ToolDefinition {
            name: "get_usage",
            description: "Read the authenticated organization's current artifact, storage, and rendering usage without exposing credentials or billing details.",
            input_schema: json!({
                "type": "object",
                "properties": {},
                "additionalProperties": false
            }),
            annotations: json!({
                "readOnlyHint": true,
                "idempotentHint": true,
                "destructiveHint": false,
                "openWorldHint": false
            }),
            required_scopes: &["usage:read"],
            meta: tool_meta("get_usage", &["usage:read"], "1.0.0"),
        },
        ToolDefinition {
            name: "get_artifact",
            description: "Retrieve safe metadata for one artifact in the authenticated organization. Returns title, description, tier, entrypoint, and lifecycle timestamps, never the HTML bundle.",
            input_schema: json!({
                "type": "object",
                "properties": {
                    "id": {
                        "type": "string",
                        "minLength": 1,
                        "maxLength": 128,
                        "pattern": "^[A-Za-z0-9]+$",
                        "description": "The artifact ID returned by deploy_to_canvas or search_artifacts."
                    }
                },
                "required": ["id"],
                "additionalProperties": false
            }),
            annotations: json!({
                "readOnlyHint": true,
                "idempotentHint": true,
                "destructiveHint": false,
                "openWorldHint": false
            }),
            required_scopes: &["artifacts:read"],
            meta: tool_meta("get_artifact", &["artifacts:read"], "1.0.0"),
        },
        ToolDefinition {
            name: "list_collections",
            description: "List approved artifact collections in the authenticated organization. Returns bounded metadata and an opaque pagination cursor, never collection contents.",
            input_schema: json!({
                "type": "object",
                "properties": {
                    "cursor": {
                        "type": "string",
                        "maxLength": 512,
                        "description": "Opaque cursor returned by a previous page."
                    },
                    "limit": {
                        "type": "integer",
                        "minimum": 1,
                        "maximum": 50,
                        "description": "Maximum collections to return (default 20)."
                    }
                },
                "additionalProperties": false
            }),
            annotations: json!({
                "readOnlyHint": true,
                "idempotentHint": true,
                "destructiveHint": false,
                "openWorldHint": false
            }),
            required_scopes: &["collections:read"],
            meta: tool_meta("list_collections", &["collections:read"], "1.0.0"),
        },
        ToolDefinition {
            name: "create_collection",
            description: "Create an organization-scoped artifact collection. Requires the explicit collections:write scope.",
            input_schema: json!({
                "type": "object",
                "properties": {
                    "name": {"type": "string", "minLength": 1, "maxLength": 100},
                    "description": {"type": "string", "maxLength": 500}
                },
                "required": ["name"],
                "additionalProperties": false
            }),
            annotations: json!({
                "readOnlyHint": false,
                "idempotentHint": false,
                "destructiveHint": false,
                "openWorldHint": false
            }),
            required_scopes: &["collections:write"],
            meta: tool_meta("create_collection", &["collections:write"], "1.0.0"),
        },
        ToolDefinition {
            name: "add_collection_artifact",
            description: "Add an artifact to an organization-scoped collection. Requires collections:write and is idempotent.",
            input_schema: json!({
                "type": "object",
                "properties": {
                    "collection_id": {"type": "integer", "minimum": 1},
                    "artifact_id": {"type": "string", "minLength": 1, "maxLength": 128, "pattern": "^[A-Za-z0-9]+$"}
                },
                "required": ["collection_id", "artifact_id"],
                "additionalProperties": false
            }),
            annotations: json!({
                "readOnlyHint": false,
                "idempotentHint": true,
                "destructiveHint": false,
                "openWorldHint": false
            }),
            required_scopes: &["collections:write"],
            meta: tool_meta("add_collection_artifact", &["collections:write"], "1.0.0"),
        },
    ]
}

pub fn definitions_json() -> Value {
    json!(definitions())
}

pub fn find(name: &str) -> Option<ToolDefinition> {
    definitions().into_iter().find(|tool| tool.name == name)
}
