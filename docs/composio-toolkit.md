# Composio toolkit — Artfct tool spec (RUB-621)

Status: **draft spec**. Schemas here are the source of truth for the Composio toolkit;
execution and transport are RUB-622. Mirrors `app/Mcp/Tools/*` (Laravel MCP server).

## v1 tool set (7)

| Composio tool                   | MCP tool                 | Scope              | Kind  | Idempotent                              |
| ------------------------------- | ------------------------ | ------------------ | ----- | --------------------------------------- |
| `artfct_deploy_artifact`        | `deploy_artifact`        | `artifacts:deploy` | write | yes (identical content → same artifact) |
| `artfct_search_artifacts`       | `search_artifacts`       | `artifacts:read`   | read  | yes                                     |
| `artfct_get_artifact`           | `get_artifact`           | `artifacts:read`   | read  | yes                                     |
| `artfct_read_artifact`          | `read_artifact`          | `artifacts:read`   | read  | yes                                     |
| `artfct_list_artifact_comments` | `list_artifact_comments` | `artifacts:read`   | read  | yes                                     |
| `artfct_add_artifact_comment`   | `add_artifact_comment`   | `comments:write`   | write | no                                      |
| `artfct_get_connection`         | `get_connection`         | —                  | read  | yes                                     |

## Scope decision (needs a yes)

The hosted MCP server already uses **`artifacts:deploy`**, **`artifacts:read`** and
**`comments:write`**, so none are new scopes. But the C1 listing copy and C2 OAuth
config currently name only `artifacts:deploy` + `artifacts:read`. To ship **commenting**
in v1, add **`comments:write`** to both (it is an existing Artfct scope, not a new
surface). Otherwise drop `artfct_add_artifact_comment` from v1 and keep the two scopes.

`list_collections` / `list_templates` / `get_guidance` / the `db_*` tools are **out of
v1** to keep the scope set small. They can be a v2 follow-up.

## Error mapping

MCP currently returns a structured error object `{ error, code, ... }`. Composio tools
should surface the same `code` and a matching HTTP-ish status so the harness can act.

| `code`                                                                  | Meaning                                           | Suggested status | Retryable          |
| ----------------------------------------------------------------------- | ------------------------------------------------- | ---------------- | ------------------ |
| `invalid_request`                                                       | bad argument shape                                | 400              | no                 |
| `unauthorized` / `insufficient_scope`                                   | missing/expired token or scope                    | 401 / 403        | re-auth            |
| `artifact_not_found` / `version_not_found`                              | not visible or missing (same shape, Private-safe) | 404              | no                 |
| `comment_not_found` / `collection_not_found`                            | referenced object missing                         | 404              | no                 |
| `stale_base`                                                            | `base_version` conflicts with live version        | 409              | re-read then retry |
| `quota_exceeded`                                                        | daily publish cap                                 | 429              | after `reset_at`   |
| `rate_limited`                                                          | per-minute comment cap                            | 429              | after a short wait |
| `plan_upgrade_required` / `feature_not_enabled`                         | plan/flag gate                                    | 402 / 422        | no                 |
| `payload_too_large`                                                     | html > 1 MB or bundle > 8 MB                      | 413              | no                 |
| `search_not_configured` / `content_not_indexed` / `version_not_indexed` | indexing off/lagging                              | 409 / 503        | maybe              |
| `upstream_unavailable`                                                  | artifact service down                             | 503              | yes                |
| `configuration_error`                                                   | server misconfigured                              | 500              | no                 |

## Transport note (for RUB-622, not solved here)

The MCP surface is **JSON-RPC at `/mcp`**; the Worker API at `/v1/*` expects an **org
JWT**, not the MCP OAuth bearer. So Composio cannot simply Proxy-Execute to `/v1`. C4 must
pick one of:

1. **Native Composio tools** that POST JSON-RPC `tools/call` to `https://artfct.dev/mcp`
   with the connected account's OAuth bearer.
2. A thin **REST facade** on the Artfct app that accepts the MCP OAuth token and maps to
   the tools — then auth-only + Proxy Execute works.
3. Custom executor that holds the token server-side and calls the Laravel MCP path.

Recommendation: (1) first for the spike; revisit (2) only if the catalog needs a REST
surface. Recorded as an open item, not a blocker for these schemas.

## Per-tool input schemas (JSON Schema 2020-12)

`artifact_type` enum everywhere: `report | table | document | mockup | slide_deck |
dashboard | post | design_system | other`.

### `artfct_deploy_artifact`

Exactly one of `html` **or** `files`. `html` is one self-contained document (no inline
`<script>`, `onclick=`, `data:` images or outside resources — put them in `files`).
Markdown is refused in `html`; publish it as `files` with a `.md` entrypoint.

```json
{
    "type": "object",
    "properties": {
        "html": { "type": "string", "maxLength": 1048576 },
        "files": {
            "type": "array",
            "maxItems": 500,
            "items": {
                "type": "object",
                "properties": {
                    "path": { "type": "string", "maxLength": 255 },
                    "content": { "type": "string" },
                    "encoding": {
                        "type": "string",
                        "enum": ["utf8", "base64"]
                    },
                    "content_type": { "type": "string", "maxLength": 100 }
                },
                "required": ["path", "content"],
                "additionalProperties": false
            }
        },
        "entrypoint": {
            "type": "string",
            "maxLength": 255,
            "default": "index.html"
        },
        "artifact_id": {
            "type": "string",
            "maxLength": 128,
            "description": "Publish a new version of an existing artifact."
        },
        "base_version": { "type": "integer", "minimum": 1 },
        "artifact_type": { "$ref": "#/$defs/artifactType" },
        "sharing": { "type": "string", "enum": ["private", "team", "public"] },
        "edit_access": { "type": "string", "enum": ["view", "edit"] },
        "tier": {
            "type": "string",
            "enum": ["public", "secure"],
            "description": "Deprecated alias for sharing."
        },
        "title": { "type": "string", "maxLength": 200 },
        "description": { "type": "string", "maxLength": 1000 },
        "model": { "type": "string", "maxLength": 100 },
        "collection": { "type": "string", "maxLength": 100 },
        "db": {
            "type": "object",
            "properties": {
                "collections": {
                    "type": "object",
                    "description": "Map of collection name to { index }."
                },
                "seed": {
                    "type": "array",
                    "maxItems": 200,
                    "items": {
                        "type": "object",
                        "properties": {
                            "collection": { "type": "string" },
                            "doc_id": { "type": "string" },
                            "body": { "type": "object" }
                        },
                        "required": ["collection", "doc_id", "body"]
                    }
                }
            },
            "required": ["collections"]
        }
    },
    "oneOf": [
        { "required": ["html"], "not": { "required": ["files"] } },
        { "required": ["files"], "not": { "required": ["html"] } }
    ],
    "$defs": {
        "artifactType": {
            "type": "string",
            "enum": [
                "report",
                "table",
                "document",
                "mockup",
                "slide_deck",
                "dashboard",
                "post",
                "design_system",
                "other"
            ]
        }
    }
}
```

Returns: `{ id, artifact_type, view_url, sharing, version, created, title, organization,
organization_name, warnings?, collection_id?, collection?, base_version_checked? }`.

### `artfct_search_artifacts`

```json
{
    "type": "object",
    "properties": {
        "query": { "type": "string", "minLength": 1, "maxLength": 500 },
        "repo": { "type": "string", "maxLength": 500 },
        "agent": { "type": "string", "maxLength": 100 },
        "since": { "type": "string", "format": "date-time" },
        "collection": { "type": "string", "maxLength": 100 },
        "artifact_type": { "$ref": "#/$defs/artifactType" },
        "changed_since": { "type": "string", "format": "date-time" },
        "limit": {
            "type": "integer",
            "minimum": 1,
            "maximum": 50,
            "default": 10
        }
    },
    "required": ["query"],
    "$defs": {
        "artifactType": {
            "type": "string",
            "enum": [
                "report",
                "table",
                "document",
                "mockup",
                "slide_deck",
                "dashboard",
                "post",
                "design_system",
                "other"
            ]
        }
    }
}
```

Returns: `{ results: [{ id, title, artifact_type, version, updated_at, description,
view_url, snippet, provenance, score }], hint? }`.

### `artfct_get_artifact`

```json
{
    "type": "object",
    "properties": {
        "id": { "type": "string", "minLength": 1, "maxLength": 128 },
        "version": { "type": "integer", "minimum": 1 }
    },
    "required": ["id"]
}
```

Returns: metadata + `view_url`, `version`, `sharing`, `edit_access`, `database`, `provenance`.

### `artfct_read_artifact`

```json
{
    "type": "object",
    "properties": {
        "id": { "type": "string", "minLength": 1, "maxLength": 128 },
        "version": { "type": "integer", "minimum": 1 },
        "offset": { "type": "integer", "minimum": 0 },
        "limit": {
            "type": "integer",
            "minimum": 1,
            "maximum": 8000,
            "default": 4000
        }
    },
    "required": ["id"]
}
```

Returns: paged text with `content.untrusted: true`, `locator`, `next_offset`, `stale`.

### `artfct_list_artifact_comments`

```json
{
    "type": "object",
    "properties": {
        "id": { "type": "string", "minLength": 1, "maxLength": 128 },
        "include_resolved": { "type": "boolean", "default": false }
    },
    "required": ["id"]
}
```

### `artfct_add_artifact_comment`

```json
{
    "type": "object",
    "properties": {
        "id": { "type": "string", "minLength": 1, "maxLength": 128 },
        "body": { "type": "string", "minLength": 1, "maxLength": 4000 },
        "parent_id": { "type": "integer", "minimum": 1 }
    },
    "required": ["id", "body"]
}
```

### `artfct_get_connection`

```json
{ "type": "object", "properties": {}, "additionalProperties": false }
```

Returns: `{ authenticated, organization, organization_name, user_id, scopes,
credential_source, agent, client, session_id, search, preferences }`. Use for the
"Which Artfct workspace am I connected to?" check and to read `save_to_artfct`.
