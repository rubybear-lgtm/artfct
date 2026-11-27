# Composio toolkit — Artfct tool spec (RUB-621)

Status: **draft spec** for RUB-619 (listing) and RUB-622 (execution). Transport is RUB-622.

**`resources/composio/toolkit.json` is the only schema source.** This page records the
decisions behind it and the behaviour an executor must implement; it deliberately does not
repeat the schemas. Change the JSON and the parity test together —
`tests/Feature/ComposioToolkitTest.php` fails when either drifts from the hosted MCP tools.

## v1 tool set (7)

| Composio tool                   | MCP tool                 | Scope              | Kind  | Idempotent                            |
| ------------------------------- | ------------------------ | ------------------ | ----- | ------------------------------------- |
| `artfct_deploy_artifact`        | `deploy_artifact`        | `artifacts:deploy` | write | yes for a new publish (see below)     |
| `artfct_search_artifacts`       | `search_artifacts`       | `artifacts:read`   | read  | yes                                   |
| `artfct_get_artifact`           | `get_artifact`           | `artifacts:read`   | read  | yes                                   |
| `artfct_read_artifact`          | `read_artifact`          | `artifacts:read`   | read  | yes                                   |
| `artfct_list_artifact_comments` | `list_artifact_comments` | `artifacts:read`   | read  | yes                                   |
| `artfct_add_artifact_comment`   | `add_artifact_comment`   | `comments:write`   | write | **no**                                |
| `artfct_get_connection`         | `get_connection`         | —                  | read  | yes                                   |

## Scope decision (needs a yes)

The hosted MCP server already uses **`artifacts:deploy`**, **`artifacts:read`** and
**`comments:write`**, so none are new scopes. The C1 listing copy and C2 OAuth config
currently name only `artifacts:deploy` + `artifacts:read`. Shipping **commenting** in v1
means adding **`comments:write`** to both (an existing Artfct scope, not a new surface).
Otherwise drop `artfct_add_artifact_comment` from v1 and keep two scopes.

Two deploy arguments are **out of v1 without adding scopes**:

- **`collection`** — a new publish that names a collection calls
  `McpContext::requireScope('collections:write', 'deploy_artifact')`
  (`app/Mcp/Tools/DeployArtifactTool.php:167`). The v1 connection does not request
  `collections:write`, so the argument is removed from `artfct_deploy_artifact` rather than
  widening the scope set. A caller that needs it uses the v2 collection tools.
- **`db`** — the artifact database is behind a workspace feature flag (`feature_not_enabled`)
  and the `db_*` tools are out of v1. `db` is removed from the deploy schema; there is no
  partial v1 surface for it.

The v1 input object is **closed** (`additionalProperties: false`), so `collection`, `db` and
any other undeclared key are rejected by the schema and never reach the server. Only declared
arguments are forwarded.

`search_artifacts.collection` stays: it is a **read-only filter**.
`SearchArtifactsTool` requires only `artifacts:read`, and an unknown collection returns
`collection_not_found` — no `collections:*` scope is consulted.

`list_collections` / `list_templates` / `get_guidance` / the `db_*` tools are a v2 follow-up.

## Connection and multi-account routing

The workspace is resolved **from the credential, never from a request parameter**
(`AuthenticateOrgToken`; spec 13: "resolved from the credential, never from a parameter").
No tool takes an organization or account id, and none should: an input that named a
workspace would be a caller-written claim and a cross-account hazard.

Composio selects one **connected account** before a tool runs. One connected account is one
Artfct workspace and user, with the scopes fixed at connection time. Multi-account therefore
means multiple connected accounts, each routed by Composio; a model cannot switch workspace
inside a call. `artfct_get_connection` reports the account actually in use: `organization`
(permanent id), `organization_name`, `user_id`, `scopes`, `credential_source`.

## Auth and error contract

There are three failure shapes, and an executor must handle all three. Only the third carries
a structured `code`.

1. **HTTP 401, before JSON-RPC.** A missing, invalid, expired or revoked token; an unknown
   org; or a revoked/expired MCP connection. `AuthenticateOrgToken.php:35-75` returns
   `{"error": "..."}` with bodies such as `Invalid token`, `Missing bearer token`,
   `Token revoked`, `Token expired`, `Unknown org`, `MCP connection revoked`,
   `MCP connection expired`. **No `code` field.** Map the HTTP status to re-auth.
2. **Scope refusal, inside a JSON-RPC result.** `McpContext::requireScope` throws
   `AuthorizationException`; Laravel MCP returns a normal result with `isError: true` and
   `result.content[0].text` starting `insufficient_scope: ...`. **No `structuredContent`
   and no `code`.** Treat as 403: re-auth with that scope, or tell the person which scope
   the connection is missing.
3. **Tool and publish errors.** `result.structuredContent` is
   `{ code, retryable, next_action?, ...details }` (also `_meta.artfct.errorCode`). Read
   `retryable`; do not key retries off the code. The same code carries both values: a
   failed version probe returns `deployment_rejected` with `retryable: true`, while a
   refused create returns `deployment_rejected` with `retryable: false`.

### Codes these 7 tools return

| `code`                     | Meaning                                                     | Status | Action                                                    |
| -------------------------- | ----------------------------------------------------------- | ------ | --------------------------------------------------------- |
| `invalid_request`          | bad argument shape, or `base_version` ahead of live          | 400    | fix the call; do not retry unchanged                      |
| `artifact_not_found`       | not visible, or missing (same shape, private-safe)           | 404    | stop                                                      |
| `version_not_found`        | requested version missing                                    | 404    | stop, or read the live version                            |
| `comment_not_found`        | `parent_id` names no comment on this artifact                | 404    | re-list comments                                          |
| `collection_not_found`     | search collection filter names no collection                 | 404    | drop or fix the filter                                    |
| `stale_base`               | `base_version` is behind the live version                    | 409    | do not replay; call `get_artifact(version=base_version + 1)` and reconcile provenance and time |
| `version_conflict`         | concurrent version publish                                   | 409    | retry (returns `retryable: true`)                         |
| `quota_exceeded`           | daily publish or storage cap                                 | 429    | read `reset_at` and `details`; after an ambiguous timeout, reconcile because the write may have landed |
| `rate_limited`             | per-minute cap                                               | 429    | retry after a short wait                                  |
| `plan_upgrade_required`    | plan gate on search                                          | 402    | stop; surface the plan requirement                        |
| `feature_not_enabled`      | feature flag off (for example artifact database)             | 422    | stop; do not retry                                        |
| `payload_too_large`        | `html` > 1 MB or bundle > 8 MB                               | 413    | shrink the payload                                        |
| `search_not_configured`    | indexing not enabled for the workspace                       | 409    | stop; `next_action: enable_indexing`                      |
| `content_not_indexed`      | text not indexed yet                                         | 409    | retry shortly (`retryable: true`)                         |
| `version_not_indexed`      | only the last indexed version can be read                    | 409    | stop; read the indexed version or the live one            |
| `content_not_available`    | text cannot be indexed at all                                | 422    | stop                                                      |
| `upstream_unavailable`     | artifact service down                                        | 503    | retry                                                     |
| `configuration_error`      | server misconfigured                                         | 500    | stop; report                                              |
| `artifact_retrieval_failed`| upstream failed to return the artifact                       | 502    | retry (`retryable: true`)                                 |
| `edit_forbidden`           | version publish without edit access                          | 403    | publish a new artifact instead                            |
| `public_sharing_disabled`  | team disabled public links                                   | 403    | publish as `team` or `private`                            |
| `deployment_rejected`      | Worker refused or failed the deploy                          | 422/503| read `retryable`; a 503 contention is safe to retry       |
| `collection_mutation_failed`| published, but not added to the collection                   | 500    | retry `add_collection_artifact` (v2); artifact exists     |
| `signed_link_unavailable`  | artifact stored but no view link could be minted             | 500    | retry `get_artifact` for a fresh link                     |

`insufficient_scope` (text, shape 2) is the 403 for a missing scope; HTTP 401 (shape 1) is
re-auth. A code absent from this table must still be treated as fatal unless its
`retryable` flag says otherwise.

## Idempotency and retries

The Worker de-duplicates by content hash on both write paths.

- **New publish** (`POST /v1/artifacts`): identical bytes in the same workspace return the
  existing artifact (`created: false`), and changed `title`, `sharing` and other metadata on
  the repeat are ignored. The match must be visible to the caller; content that matches an
  artifact the caller cannot see creates a new one. A `db` publish is never de-duplicated,
  but `db` is not declared in v1 and the closed schema rejects it.
- **Version publish** (`POST /v1/artifacts/{id}/versions`): if the bundle hash equals the
  artifact's current content, the Worker returns 200 with `created: false` and the current
  version and writes nothing (`backend/src/version_routes.rs`). The integration test
  `reposting_identical_bytes_creates_no_new_version`
  (`backend/tests/storage_integration.rs`) asserts no new version row; CI's MCP end-to-end
  job runs it with `--ignored`.

A successful `deploy_artifact` response is the only confirmed success. An ambiguous timeout
leaves the outcome unknown. `quota_exceeded` can mean the write already landed because Laravel
quota and storage checks run before Worker de-duplication. The server's `next_action: get_usage`
names a tool outside this v1 toolkit; use `reset_at` and the other error details instead.

What an executor should do:

- **New publish.** After an ambiguous timeout, call `search_artifacts` with the exact intended
  title. Add `agent` and `since` when available, then call `get_artifact` for each plausible
  result. Title, provenance and time are heuristic. Search can be disabled or lag. No result does
  not prove failure.
- **Version publish.** Do **not** blind-replay after an ambiguous timeout. The Laravel
  `base_version` pre-check runs before the Worker call and compares version numbers only. If the
  first attempt committed v4 and timed out, call `get_artifact` with `version: base_version + 1`.
  Compare `created_by` to the `user_id` returned by `get_connection` and `created_at` to the
  attempt window. Agent provenance is only a hint: `get_connection.agent` is an object or null,
  and its `client` can differ from the hosted connection name recorded by `deploy_artifact`.
  Treat an agent mismatch as inconclusive. Without `base_version`, first read the current
  version. An identical retry reaches the Worker's no-op only when quota and storage checks
  still allow it. A different bundle adds a version, so retry only when you know nothing else
  published.
- **Comments.** Never auto-retry `artfct_add_artifact_comment`; it is not idempotent and would post
  twice. The five read tools are safe to retry.

For either publish path, a metadata match means the publish likely landed. It does not confirm
success. `search_artifacts` and `get_artifact` cannot compare bundle bytes. `read_artifact` returns
extracted, indexed text and cannot prove byte equality. A mismatch, missing metadata, or unavailable
read means the outcome is uncertain. Do not merge or claim failure from that evidence.

## Endpoints behind the tools

Confirmed in the tool classes; this is what a transport must reach.

| Tool                                           | Backing call                                                                                                                       |
| ---------------------------------------------- | ---------------------------------------------------------------------------------------------------------------------------------- |
| `deploy_artifact`                              | Worker REST: `POST /v1/artifacts`, `POST /v1/artifacts/{id}/versions`, `PUT /v1/artifacts/{id}/files/{sha256}` (plus `GET /v1/artifacts/{id}` for the `base_version` pre-check) |
| `get_artifact`                                 | Worker REST: `GET /v1/artifacts/{id}`, `GET /v1/artifacts/{id}/versions/{version}`                                                 |
| `read_artifact`                                | Worker REST `GET /v1/artifacts/{id}` + the Laravel `ArtifactIndexEntry` row (indexed text)                                           |
| `search_artifacts`                             | Laravel `SearchService` (vector + lexical index); no Worker REST endpoint                                                            |
| `list_artifact_comments` / `add_artifact_comment` | Laravel `ArtifactComment` table; no Worker REST endpoint                                                                        |
| `get_connection`                               | Laravel session claims, team and preferences; no endpoint                                                                            |

**Flag for RUB-622:** comments, search and `get_connection` have no `/v1` REST route, so a
Proxy-Execute-only transport cannot implement three of the seven tools. That is the strongest
argument for option (1), JSON-RPC `tools/call`, below.

## Transport note (RUB-622, not solved here)

The MCP surface is **JSON-RPC at `/mcp`**; the Worker API at `/v1/*` expects an **org JWT**,
not the MCP OAuth bearer, so Composio cannot simply Proxy-Execute to `/v1`. C4 must pick one:

1. **Native Composio tools** that POST JSON-RPC `tools/call` to `https://artfct.dev/mcp`
   with the connected account's OAuth bearer.
2. A thin **REST facade** on the Artfct app that accepts the MCP OAuth token and maps to the
   tools — then auth-only + Proxy Execute works.
3. A custom executor that holds the token server-side and calls the Laravel MCP path.

Recommendation: (1) for the spike; revisit (2) only if the catalog needs a REST surface.

## Validation evidence

`tests/Feature/ComposioToolkitTest.php` asserts, against the live tool classes:

- every toolkit `inputSchema` matches the hosted MCP tool's schema after the documented
  transforms — every tool adds `additionalProperties: false`; deploy drops `collection` and
  `db`, adds `minItems: 1` to `files`, and adds a null-aware `oneOf` whose `html` branch
  rejects blank/whitespace-only values (matching `trim(...) !== ''`). Descriptions are
  curated and compared separately, so the stale `collection` wording cannot return;
- `readOnly`, `idempotent` and `scopes` match the tool's annotations and
  `_meta.artfct.requiredScopes`, and no tool requires a scope the toolkit does not request;
- negative fixtures prove undeclared `collection`, `db` and unknown keys are rejected, and
  the schema is closed on every tool;
- payload fixtures are classified as the server would: `files: []` rejected, explicit
  `null`s accepted, blank `html` treated as absent when `files` is present, `{}` reaches
  `oneOf`/`required` (and `get_connection` accepts it), and search `limit`/`query` bounds
  match the server rule (`limit` 1–50, `query` 1–500);
- a plain date and a date-time both validate (no `format` keyword).
- `identical new publish retry returns quota_exceeded before a second worker create` in
  `tests/Feature/Billing/DailyPublishLimitTest.php` proves that `per_user=1` consumes the slot on
  the first publish and the identical retry returns structured `quota_exceeded` with
  `retryable=false` before a second Worker create. Its `next_action=get_usage` points outside
  this v1 toolkit, so an executor must use the returned reset and limit details instead.
  `stale_base recovery exposes version provenance without content_hash` in
  `tests/Feature/McpArtifactVersioningTest.php` proves that a `base_version=3` publish landing as
  v4 makes the identical retry return `stale_base` with `live_version=4`, and
  `get_artifact(version=4)` returns version, `created_by`, agent, `created_at`, `updated_at` and
  current metadata without `content_hash`.

Run:

```sh
php artisan test --compact tests/Feature/ComposioToolkitTest.php tests/Feature/Billing/DailyPublishLimitTest.php
php artisan test --compact tests/Feature/McpArtifactVersioningTest.php --filter='deploy_artifact refuses a stale base_version|stale_base recovery'
```

Not covered here: Composio's own schema dialect and whether it enforces any keyword beyond
what `toolkit.json` declares; a live staging dry-run (docs-only change, isolated CI is the
gate).
