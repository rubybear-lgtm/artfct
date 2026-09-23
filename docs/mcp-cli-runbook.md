# MCP and CLI launch runbook

This runbook covers the supported ways to connect an AI agent to artfct, verify
the connection, and recover from common authentication or policy failures.

## Choose a transport

- **Local stdio**: install the `artfct` CLI and run `artfct mcp serve`. The
  agent starts a local process; credentials stay in the user's local config.
- **Hosted Streamable HTTP**: configure `https://artfct.dev/mcp` in an MCP
  client that supports OAuth discovery. The client authenticates in a browser
  and sends bearer access tokens to the hosted endpoint.

Both transports expose the same versioned tool catalog and organization-scoped
behavior. Hosted sessions are registered in the workspace's MCP connections
view; local sessions are identified by their client metadata and connection
registration calls. Hosted MCP is stateless between HTTP requests: the
`MCP-Session-Id` header is used for correlation and telemetry, never for
authorization. Every request resolves its organization from the bearer
credential, so copying a session ID cannot cross tenant boundaries.
The endpoint intentionally supports POST for JSON-RPC messages only; GET and
DELETE return `405 Allow: POST` because this deployment does not maintain a
server-side event stream or session store.

## Local setup

```sh
curl -fsSL https://artfct.dev/install.sh | sh
artfct setup
artfct login --oauth --organization <organization-slug>
artfct doctor
```

`artfct setup --list` previews detected agent configuration files. Setup keeps
timestamped backups of valid files and never overwrites malformed JSON or TOML
without preserving a recovery copy. `artfct doctor` reports configuration,
credential state, selected organization, available organizations, and hosted
MCP initialize/tool discovery health without printing tokens.

For CI or other non-interactive environments, use an explicitly provided
`ARTFCT_ORG_TOKEN`. Environment credentials take precedence over the saved
interactive session and should be injected through the CI secret store.

Interactive credentials are stored in the macOS Keychain or Linux Secret
Service when available. The CLI falls back to a 0600 file only when the
platform store is unavailable, and `artfct doctor` reports the credential
state without printing its value.

## OAuth and organization context

Hosted clients should discover the authorization server through:

```text
https://artfct.dev/.well-known/oauth-protected-resource
https://artfct.dev/.well-known/oauth-authorization-server
```

The flow is authorization-code OAuth with S256 PKCE. Consent displays the
client, selected workspace, requesting user, and requested scopes. Access
tokens are short-lived; refresh tokens rotate on use. Authorization codes are
single-use, and redirect URIs must be registered and use HTTPS or loopback
HTTP.

Supported scopes:

| Scope               | Capability                                 |
| ------------------- | ------------------------------------------ |
| `artifacts:read`    | Search and retrieve safe artifact metadata |
| `artifacts:deploy`  | Deploy artifacts to the workspace          |
| `artifacts:delete`  | Delete artifacts when policy permits       |
| `collections:read`  | List workspace collections                 |
| `collections:write` | Create collections and add artifacts       |
| `usage:read`        | Read customer-safe usage and quota totals  |

Request the smallest set needed. Viewer accounts cannot receive mutation
scopes; collection mutations additionally require `collections:write` and the
workspace policy. The CLI can show the authenticated context with:

```sh
artfct organizations
artfct login --oauth --organization <organization-slug>
```

## Revocation and recovery

Workspace administrators can inspect and revoke hosted connections from
**Settings → MCP connections**. Revocation invalidates the associated access
credential and connection record; subsequent hosted requests fail with an
authentication error. A revoked or expired local session should be repaired
with:

```sh
artfct login --oauth --organization <organization-slug>
artfct doctor
```

Refresh tokens rotate on every use. If a previously rotated refresh token is
presented again, artfct treats it as possible credential reuse and revokes the
entire linked connection; sign in again rather than retrying the old token.

To remove the local session and request server-side OAuth revocation:

```sh
artfct logout
```

If a client has cached an old connection, remove its MCP server entry, restart
the client, and complete OAuth again. Never paste a bearer token into an agent
configuration file, repository, issue, or support ticket.

## Opening an artifact

Tools that publish or describe an artifact return it as `view_url`. Which URL
that is depends on the artifact's tier:

- **`secure`** — the app's own open route,
  `/settings/teams/<org>/console/artifacts/<id>/open`. It authorizes the viewer
  and only then mints a short-lived signed link to the artifact's isolated
  origin, so the link is safe to hand to a colleague: it works when that
  colleague opens it in a browser while signed in to the workspace, and it
  carries no credential itself. The mint is audited (`artifact.link_minted`,
  with the actor, the artifact and the expiry) and the token appears in the
  redirect only — never in the tool result.
- **`public`** — the artifact's public URL. The Worker serves a public artifact
  to anyone, so no token is minted for it and no session is needed.

`view_url` replaces the earlier `url` field, on both the hosted and the local
stdio server. An agent should present `view_url` rather than reconstructing a
`/p/{id}` URL from an artifact id: the raw URL carries no credential, so a
browser cannot open a secure artifact with it.

If a tool call fails with the non-retryable `signed_link_unavailable` code, the
environment has no signing secret configured, so no openable link exists —
retrying cannot help.

The local stdio server builds a secure `view_url` against `ARTFCT_APP_BASE_URL`
(default `https://artfct.dev`); point it at your control plane when you run a
local or staging deployment.

## Safe retries and policy errors

`deploy_to_canvas` is the only tool that deduplicates retries. Send a stable
`MCP-Request-Id` (or `Idempotency-Key`) header to opt in: repeating the same
key with the same payload returns the original result, reusing the key with a
different payload is rejected with the non-retryable
`idempotency_key_reused` code, and a concurrent duplicate reports the
retryable `idempotency_in_progress` code. Cached results are kept for
`auth.mcp_idempotency_ttl_seconds` (default 600). `deploy_artifact`,
`create_collection`, and `add_collection_artifact` do not deduplicate retries,
and `deploy_to_canvas` itself is deprecated in favor of `deploy_artifact`.

Rate-limit responses include `Retry-After`; quota and policy errors include a
stable machine-readable error code and remediation-safe usage details. Retry
after the indicated delay, reduce the request rate, or ask a workspace admin
to review the plan and connection scopes. Do not retry invalid scope,
revoked-credential, or malformed-request errors.

## Local end-to-end verification

`scripts/mcp-e2e-stack.sh run` boots a full local stack — Postgres (not
SQLite, matching staging/production), a `wrangler dev` Worker with D1/R2
persisted, and Laravel with `auth:publish-jwks` wiring its org-JWT signing
key into that Worker — seeds two synthetic organizations
(`zz-mcp-e2e`/`zz-mcp-e2e-b`), and runs the live smoke suite against it, all
without touching staging or your real `.env`/database:

```sh
scripts/mcp-e2e-stack.sh run    # up, smoke suite, Rust integration tests, tear down
scripts/mcp-e2e-stack.sh up     # start the stack and leave it running
scripts/mcp-e2e-stack.sh rust   # run the Rust storage/provenance integration tests against a running stack
scripts/mcp-e2e-stack.sh down   # stop everything, including Postgres
```

`run` also drives `mcp-server/tests/storage_integration.rs` and
`provenance_integration.rs` — 22 tests (21 and 1 respectively) that assert
real production-path behavior (blob refcounting, concurrent creates/deletes,
export round-trips, bundle redeploys) against a live Worker, and that were
previously `#[ignore]`d with nothing in CI ever running them. They share one
org on one live Worker rather than getting isolated per-test state, so `run`
(`--test-threads=1`) always serializes them; running `rust` standalone
against a stack you've already exercised once is not supported — the tests
assume fresh D1/R2 state, and rerunning against already-populated state
produces failures that are about reused fixtures, not real bugs. Tear down
and `up` again for a clean run.

It authenticates through `MCP_LIVE_DEV_LOGIN_EMAIL`, which drives the same
`/oauth/authorize` consent decision as a real browser but over plain HTTP,
using `authkit/dev-login` (`AuthKitDevLoginController`) — a stand-in for
WorkOS that is only ever registered outside production and is force-enabled
by this script regardless of what your real `.env` has configured, so a
developer machine with real WorkOS credentials still gets the fake client
(otherwise `/authenticate` hands the fake login code to the real WorkOS
client, which rejects it). Set `MCP_E2E_LARAVEL_PORT`/`MCP_E2E_WORKER_PORT`
to change the default ports (8990/8991), or `MCP_E2E_EXTERNAL_POSTGRES=true`
plus `MCP_E2E_DB_*` to point it at a Postgres you're already running (CI does
this with a native GitHub Actions service container instead of
`docker-compose.e2e.yml`).

This is what the `mcp_e2e` CI job runs on every push/PR — the same script,
not a separate reimplementation, so a local failure reproduces the CI one.

## Staging verification

The live smoke suite must use two isolated staging organizations, and the
signed-in user must be a member of both. By default it authenticates through
OAuth with PKCE: it opens the consent page for each organization in turn, you
approve, and the tokens stay in memory (nothing is written to disk or logs).

```sh
MCP_LIVE_BASE_URL=https://staging.artfct.dev \
MCP_LIVE_EXPECTED_ORG_A=acme \
MCP_LIVE_EXPECTED_ORG_B=beta \
npm run mcp:live
```

Without `MCP_LIVE_PRIVATE_ARTIFACT_A` the suite deploys a secure fixture as
organization A and checks that organization B cannot read it. For
non-interactive runs (the `mcp-live` workflow) set `MCP_LIVE_TOKEN_A` and
`MCP_LIVE_TOKEN_B` instead, plus `MCP_LIVE_PRIVATE_ARTIFACT_A` if you want to
reuse an existing artifact.

The suite verifies protocol negotiation, session ID issuance on initialize, the exact tool
catalog, self-describing tool metadata, usage metadata, collection discovery,
and cross-organization artifact isolation. It also opens a bounded set of concurrent sessions for both
organizations and checks that every session retains its tenant context. Set
`MCP_LIVE_CONCURRENCY` to an integer from 2 to 32 to adjust that check; it
defaults to 8 sessions per organization.

Finally, on organization B's connection it bursts `get_connection` calls
until the server returns `429` and asserts the `Retry-After` header and the
stable JSON-RPC rate-limit envelope (`-32029`, `data.artfct.errorCode =
"rate_limit"`). Set `MCP_LIVE_RATE_LIMIT_BURST` (default 160, range 20-400)
if the configured `auth.mcp_throttle_per_minute` limit needs more requests to
trip, or set `MCP_LIVE_RATE_LIMIT_CHECK=false` to skip it. This burst counts
against organization B's per-minute quota for the rest of that minute, so it
runs last.

Run the suite manually through the `mcp-live` GitHub Actions workflow when
staging secrets are configured. Never place those credentials in
pull-request logs or repository files.

## Client compatibility

The hosted endpoint negotiates protocol versions `2025-11-25` (default),
`2025-06-18`, `2025-03-26`, and `2024-11-05`; `initialize` rejects any other
value with JSON-RPC error `-32602` (`Unsupported protocol version`, with the
supported list in `error.data.supported`). It only implements the stateless
Streamable HTTP shape described above: `POST /mcp` for JSON-RPC, and `405` on
`GET`/`DELETE` because there is no server-side event stream or session store
to resume. A client that assumes it can open a long-lived SSE stream or
reconnect a session by ID against this deployment will not work; it must
re-authenticate and re-initialize instead.

Known, currently verified compatibility:

- **Local stdio** (the `artfct` CLI binary): fully supported and covered by
  the crate's unit tests in `mcp-server/src/mcp.rs` (protocol negotiation,
  tool listing, session/host capture); the `mcp-server/tests/` integration
  suite exercises the hosted Worker over HTTP, not stdio. This is the
  transport for clients that only speak stdio MCP.
- **Hosted Streamable HTTP with OAuth discovery** (PKCE, dynamic
  registration): verified against the official MCP Inspector on staging
  (see RUB-355). Verification against other specific hosted clients (Claude
  Desktop/Code, Cursor, Codex CLI, Gemini CLI, OpenCode) is tracked as
  ongoing work on RUB-383 and not yet recorded here — do not assume a client
  works hosted until it has been run against staging and its result added to
  this list.

Record each additional client verified against staging here with the date,
protocol version it negotiated, and any workaround needed, so this table
stays a source of truth rather than a claim.

## Incident checklist

1. Run `artfct doctor` and capture only its redacted output.
2. Identify the workspace, connection name, client, and transport from the
   MCP connections page. Each activity row also carries the request ID
   (`activity[].requestId`, from `mcp_activities.request_id`) but the table
   does not render it and the audit/SIEM export does not include it, so read
   it from the page payload or the database row.
3. Revoke the affected connection if compromise is suspected.
4. Rotate the affected OAuth session or CI token through the normal credential
   owner; do not edit database rows manually.
5. Re-run the staging smoke suite before restoring the integration.
