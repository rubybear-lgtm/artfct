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
authentication error. The Worker-side denylist propagation is eventually
consistent: the revocation window is bounded in seconds, not zero seconds, by
Cloudflare KV propagation. JWT lifetimes are therefore minutes rather than
hours. Laravel writes the `jti` to the Worker KV denylist through the internal
revocation endpoint, and the MCP connection record is revoked at the same
time; both checks enforce the normal hosted path. The normative explanation
is in [spec 07](specs/07-auth-seam.md). If an environment's Worker or KV is
unavailable, treat the window as unverified and follow the incident runbook
rather than assuming immediate edge rejection.

A revoked or expired local session should be repaired
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

## Emergency response and key rotation

If a client credential may have leaked, revoke its connection immediately from
**Settings → MCP connections**. If the affected credential is a CI token,
revoke that token in the workspace console and disable the corresponding CI
secret before investigating logs. Do not delete database rows or edit JWTs by
hand. The edge denylist is eventually consistent; treat the documented KV
propagation window as active until a fresh `artfct doctor` or staging smoke
check confirms the replacement connection.

For a suspected signing-key compromise, pause new MCP connections, generate a
replacement `ORG_JWT_PRIVATE_KEY_B64`, and publish the new public key while
keeping the old key available for tokens that have not expired:

```sh
php artisan auth:publish-jwks --extra-jwks=/secure/path/previous-jwks.json
```

After the maximum old-token lifetime and the KV propagation window have
passed, publish the new key alone, then rotate the Laravel and Worker write
secrets (`ARTFCT_JWKS_WRITE_SECRET` and
`ARTFCT_REVOCATION_WRITE_SECRET`) through the deployment secret store. Never
put either value in a repository file or command history. Verify the result
with the staging smoke suite and keep only redacted output:

```sh
MCP_LIVE_BASE_URL=https://staging.artfct.dev \
MCP_LIVE_EXPECTED_ORG_A=acme \
MCP_LIVE_EXPECTED_ORG_B=beta \
npm run mcp:live
```

For an OAuth-provider or authorization-server incident, revoke affected MCP
connections first, then rotate the provider credentials in the deployment
secret store, redeploy the control plane, and rerun the same staging smoke
suite before restoring client access. The smoke suite must confirm discovery,
consent, tenant isolation, and revocation; a healthy `/up` response alone is
not evidence that OAuth is repaired.

## Rollback and release recovery

Keep the last known-good Worker version and CLI release identifier in the
deployment record. A Worker rollback is an operator action through Wrangler:
deploy the known-good version to 100%, then rerun the staging smoke suite and
check the running version before declaring recovery. `npm run worker:deploy`
publishes the current build; it is not a rollback command, so do not rerun it
against a broken checkout and call that a rollback.

For a published CLI release, the repository's guarded rollback workflow is
**Actions → rollback-cli-release**. Supply the tag, a public reason, and type
`WITHDRAW`; it marks the release draft so new installs cannot select it. A
withdrawn CLI does not revoke already-issued OAuth credentials, so perform the
connection or token revocation steps above separately.

## Protocol and tool-schema versioning

The hosted endpoint negotiates an explicitly supported protocol version during
`initialize`. Additive tool metadata and fields are backward-compatible, but
removing or changing the meaning of a tool argument requires a new tool/schema
version and a migration note in this runbook. Keep the legacy
`deploy_to_canvas` tool available while clients migrate to `deploy_artifact`;
its metadata is marked deprecated and it creates anonymous, expiring artifacts
that are not searchable in the workspace catalog.

When deprecating a protocol version or tool, record the first release that
announced the deprecation, the last release that accepts it, the replacement,
and the client remediation. Do not remove a version from the supported list
until the compatibility matrix and the staging smoke suite have been updated.
Unsupported versions must continue to return the structured
`unsupported_protocol_version` error with the supported list rather than a
500 or an ambiguous transport failure.

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

**`deploy_to_canvas` is the exception to both bullets.** Its artifacts are
anonymous, expiring records with no workspace row, so the open route could only
404 for them and no session can authorize one. Its `view_url` is the Worker's own
`/p/{id}` URL **with the decryption fragment** (`#<shareCode>`) at every tier it
accepts, and it opens on the Worker origin. The fragment is the decryption key —
strip it and the page renders only a placeholder.

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
`GET`/`DELETE` because there is no server-side event stream to resume. The
server keeps a short-lived correlation record for each issued
`MCP-Session-Id`, bound to the bearer credential and protocol version; it is
not an authentication token or a resumable event stream. If a connection drops
and the ID is stale, expired, or presented with another credential, the server
returns JSON-RPC `-32001` with `error.data.artfct.errorCode=session_expired` and
`nextAction=initialize`. Re-authenticate and send `initialize` to obtain a new
session before retrying the interrupted request. A stable `MCP-Request-Id`
continues to deduplicate supported tool retries across that new session.

Known, currently verified compatibility:

- **Local stdio** (the `artfct` CLI binary): fully supported and covered by
  the crate's unit tests in `mcp-server/src/mcp.rs` (protocol negotiation,
  tool listing, session/host capture); the `mcp-server/tests/` integration
  suite exercises the hosted Worker over HTTP, not stdio. This is the
  transport for clients that only speak stdio MCP.
- **Hosted Streamable HTTP with OAuth discovery** (PKCE, dynamic
  registration): the protocol and OAuth flow are covered by the automated
  contract, feature, and live two-organization suites. A live official MCP
  Inspector run on staging is not yet recorded here. Verification against
  specific hosted clients (official MCP Inspector, Claude Desktop/Code,
  Cursor, Codex CLI, Gemini CLI, OpenCode) is tracked as ongoing work on
  RUB-383 — do not assume a client works hosted until it has been run against
  staging and its result added to this list.

### Repeatable third-party client run

Run this checklist from a clean user profile against the staging issuer. Keep
tokens in the client's credential store and record only redacted output. The
run is successful only when the client completes OAuth consent, initializes
the hosted connection, calls `get_connection`, and reports the negotiated
protocol version.

```sh
export MCP_LIVE_BASE_URL=https://staging.artfct.dev
artfct setup --list
artfct setup --silent
artfct login --oauth --organization <staging-organization>
artfct doctor
```

Use the client-specific entrypoint below, then call `get_connection` and one
read-only tool such as `list_collections` from that client:

- **Official MCP Inspector** — open the hosted Streamable HTTP URL
  `https://staging.artfct.dev/mcp` in Inspector, complete discovery,
  registration, PKCE consent, and token exchange, then call `get_connection`.
- **Claude Code** — run `claude`, select the configured `artfct` MCP server,
  complete browser consent, and ask Claude to call `get_connection`.
- **Cursor** — open Cursor's MCP panel, enable the `artfct` entry written by
  `artfct setup`, complete browser consent, and invoke `get_connection` from
  Agent mode.
- **Codex CLI** — run `codex`, enable the configured `artfct` MCP server,
  complete browser consent, and request `get_connection`.
- **Gemini CLI** — run `gemini`, enable the configured `artfct` MCP server,
  complete browser consent, and request `get_connection`.
- **OpenCode** — run `opencode`, enable the configured `artfct` MCP server,
  complete browser consent, and request `get_connection`.

For each client, record: client name and version, date, transport, negotiated
protocol version, consent result, `get_connection` result, the read-only tool
result, and any workaround or failure. Add the redacted record to this
section only after the run is complete; an installed binary or a generated
config file is not compatibility evidence.

Record each additional client verified against staging here with the date,
protocol version it negotiated, and any workaround needed, so this table
stays a source of truth rather than a claim.

### Client preflight inventory (2026-09-23)

This is an installation and setup preflight, not a hosted compatibility result:
no client below completed OAuth consent or called a hosted tool during this
check. It records what is available for the next release-candidate run.

| Client                 | Installed version | Setup/config preflight                                               | Hosted session       |
| ---------------------- | ----------------- | -------------------------------------------------------------------- | -------------------- |
| Claude Code            | 2.1.280           | `artfct setup --list` recognized the JSON target                     | Not run              |
| Cursor                 | 2.6.22            | `artfct setup --list` recognized the JSON target                     | Not run              |
| Codex CLI              | 0.155.1           | `artfct setup --list` recognized the TOML target                     | Not run              |
| Gemini CLI             | 0.42.0            | `artfct setup --list` recognized the JSON target                     | Not run              |
| OpenCode               | 1.17.18           | `artfct setup --list` recognized the JSON target                     | Not run              |
| Official MCP Inspector | 2.7.0 via `npx`   | Local stdio `tools/list` passed; launcher and CLI help probes passed | Hosted OAuth not run |

The Inspector package was available from npm; its non-interactive help commands
and a local stdio `tools/list` probe against `target/debug/artfct mcp serve`
passed. Its hosted connection and OAuth flow were not run. The preflight also
observed non-fatal local-environment warnings from Cursor's
macOS code-sign check, Codex's PATH-alias setup, and Gemini's cleanup attempt.
None is a compatibility result; record any client-specific behavior only after
the hosted run above completes.

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
