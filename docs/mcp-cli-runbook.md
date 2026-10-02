# MCP and CLI launch runbook

This runbook covers the supported ways to connect an AI agent to artfct, verify
the connection, and recover from common authentication or policy failures.

## Choose a transport

- **Local stdio**: install the `artfct` CLI and run `artfct mcp serve`. The
  agent starts a local process; credentials stay in the user's local config.
- **Hosted Streamable HTTP**: for staging verification, configure
  `https://staging.artfct.dev/mcp` in an MCP client that supports OAuth
  discovery. The client authenticates in a browser and sends bearer access
  tokens to the hosted endpoint.

The production URL `https://artfct.dev/mcp` is not currently routed to the
hosted MCP handler; a read-only probe returns 404. Do not use it for the live
client matrix until the production route is deliberately deployed and verified.

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
curl -fsSL https://staging.artfct.dev/install.sh | sh
export ARTFCT_API_BASE_URL=https://staging.artfct.dev
artfct setup
```

For a staging-only session, point CLI authentication and API calls at staging
before signing in. Without this override, the CLI defaults to the production
API base URL:

```sh
export ARTFCT_API_BASE_URL=https://staging.artfct.dev
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

Hosted clients in the current staging environment should discover the
authorization server through:

```text
https://staging.artfct.dev/.well-known/oauth-protected-resource
https://staging.artfct.dev/.well-known/oauth-authorization-server
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

## Rate limits and data retention

Hosted MCP requests are limited per minute on two keys at once: the workspace
and the individual connection token. Both default to 120 requests per minute
(`MCP_THROTTLE_PER_MINUTE`), so one busy connection cannot exhaust the
workspace allowance on its own, and all connections together stay under the
workspace cap. A limited request returns `429` with `Retry-After`; wait that
long before retrying.

MCP activity records are kept for 90 days by default (`MCP_ACTIVITY_RETENTION_DAYS`)
and pruned daily by `mcp:prune-activity`. The window can be set between 1 and
3650 days. Artifact retention is a separate, per-workspace policy: `governance:retention
<workspace>` reports what it would delete by default and only removes artifacts
with `--apply`, always skipping artifacts under a legal hold.

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
`provenance_integration.rs` — 26 tests (25 and 1 respectively) that assert
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

## Production domain plan (draft, not applied)

This is the proposed RUB-366 production plan for human review. Production
changes remain on hold pending approval. This runbook does not assert the
current production runtime state. The staging shape was verified on
2026-09-30: app on `staging.artfct.dev`, artifact origins on
`<tenant>--<id>--stg.artfct.dev`, with 403 without a token, 200 with a minted
link, 403 when a token is presented on another tenant's host, and valid TLS.
The unauthenticated/invalid-token responses had no cookies; the valid root
document response sets only a host-only `artfct_access` cookie (`Secure`,
`HttpOnly`, `SameSite=Strict`, `Path=/`, with no `Domain`).

**Worker build boundary (verified 2026-10-01).** Cloudflare Workers Builds binds
each trigger to a Worker script tag; passing a staging Wrangler config to a
trigger attached to the production Worker does not retarget the build. The old
non-production trigger was therefore changed to fail closed, and its production
candidate version `41` remains undeployed. The staging Worker now has its own
build configuration, bound to the staging script tag and `develop` branch,
with previews disabled. A `develop` build succeeded and deployed staging
version `1ba2fe66-b842-4ac3-92c3-419697d20a4d` at 100%. Keep the production
trigger separate and do not enable production builds until the promotion gate
is approved. The staging trigger and successful deployment were verified on
2026-10-01; production candidate version `41` was undeployed at that check.

**Hazard and proposed resolution.** The production Worker config routes
`*.artfct.dev/*` for artifact origins (`<tenant>--<id>.artfct.dev`). That
pattern matches every single-label subhost of the zone, including
`staging.artfct.dev`, so deploying it as-is would route staging's app traffic
into the Worker. Use a production marker suffix that keeps artifact hosts one
label deep and gives the Worker a narrow route:

- Artifact host: `<tenant>--<id>--prod.artfct.dev`.
- Worker route: `*--prod.artfct.dev/*`.
- Set `ARTFCT_ARTIFACT_ORIGIN_SUFFIX=--prod.artfct.dev` on both Laravel and the
  Worker so minted links and host validation use the same suffix.

This follows the staging shape (`--stg.artfct.dev`), stays under the existing
`*.artfct.dev` certificate, and leaves `staging.artfct.dev`, `www.artfct.dev`,
and the apex app host outside the Worker route. This is the proposed plan for
review only; do not apply it until approved. After review, verify with `curl -I`
against each existing subhost and confirm a production artifact host reaches
the Worker.

**Required config replacement after approval.** In `backend/wrangler.jsonc`,
replace the existing `*.artfct.dev/*` route with `*--prod.artfct.dev/*`; do not
add the narrow route alongside the broad one. The broad route would continue to
match `staging.artfct.dev` even if the new production route is present. Before
uploading a production Worker version, also replace the all-zero placeholder
for `ARTIFACTS_DB` with the actual production D1 database ID and verify that
the KV namespace and R2 bucket bindings point to production resources. Never
reuse staging resource IDs.

**Steps, in order:**

1. Confirm the zone's wildcard DNS record is proxied and Universal SSL covers one
   label deep. Staging already proves this for the zone.
2. Provision the production Worker's secrets (the same names as staging:
   artifact token secret, governance, limits, JWKS and revocation write secrets,
   org token, event secret) through a one-time handoff, and set the matching
   values on the Laravel production service. Never paste them into an issue or log.
3. Add the custom domain to the Railway production web service with TLS. Set
   `APP_URL`, `OAUTH_ISSUER` and `WORKOS_REDIRECT_URL` to it, then re-register
   the WorkOS redirect URIs and allowed-callback lists.
4. Point `ARTFCT_WORKER_BASE_URL` (Laravel) and `ARTFCT_PUBLIC_BASE_URL` (Worker)
   at the production addresses. Set `ARTFCT_ARTIFACT_ORIGIN_SUFFIX` to
   `--prod.artfct.dev` on both services.
5. Replace the broad production Worker route with the marker-suffix route and
   verify the D1, KV, and R2 bindings target production resources. Review the
   resolved config to confirm there is no remaining `*.artfct.dev/*` route.
6. Deploy the Worker routes, then run the same checks as staging: an artifact
   host returns 403 without a token, 200 with a minted link, and 403 on another
   tenant's host. Verify Spec 05 origin isolation on the artifact responses:
   no `Set-Cookie` on unauthenticated/invalid-token responses; a valid root
   response may set only the host-only `artfct_access` cookie with the expected
   flags and no `Domain`. Verify the expected isolation and CSP headers, no
   console-cookie authentication, and that a browser `fetch()` from artifact A
   to artifact B is blocked by CORS. Confirm the console origin has no CORS
   allowance or `postMessage` bridge to artifact origins. Then confirm
   `/.well-known/oauth-authorization-server` advertises the production issuer
   and MCP Inspector and Claude Code connect through it.

**Rollback.** DNS, route and domain changes are reversible: remove the new
routes, revert the domain and environment variables, and keep the existing
Railway and `workers.dev` addresses working until cutover is verified.

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
`MCP-Session-Id`, bound to the authenticated MCP connection and organization;
it is not an authentication token or a resumable event stream. OAuth access
token refresh may rotate the token JTI without changing that connection, so
the active session remains usable after refresh. A different connection or a
stale/expired session ID returns JSON-RPC `-32001` with
`error.data.artfct.errorCode=session_expired` and `nextAction=initialize`.
Re-authenticate and send `initialize` to obtain a new session before retrying
the interrupted request. A stable `MCP-Request-Id` continues to deduplicate
supported tool retries across that new session.

Known, currently verified compatibility:

- **Local stdio** (the `artfct` CLI binary): fully supported and covered by
  the crate's unit tests in `mcp-server/src/mcp.rs` (protocol negotiation,
  tool listing, session/host capture). The `stdio_integration.rs`
  child-process test pipes JSON-RPC initialize and tools/list through the built
  binary and verifies stdout contains only parseable JSON lines. This is the
  transport for clients that only speak stdio MCP.
- **Streamable HTTP reconnection:** expired or credential-mismatched session
  IDs are rejected with reinitialization guidance, and a stable request ID
  deduplicates retries across newly initialized sessions. Feature tests cover
  those application-level recovery paths. The automated suite does not sever a
  live transport mid-request; it verifies the server's behavior after the
  client reconnects and presents an expired or mismatched session.
- **Hosted Streamable HTTP with OAuth discovery** (PKCE, dynamic
  registration): the protocol and OAuth flow are covered by the automated
  contract, feature, and live two-organization suites. Official MCP Inspector
  completed OAuth against staging on 2026-09-21, including discovery, dynamic
  registration, PKCE consent, token exchange, tool listing, and a
  `get_connection` call. The negotiated protocol version was not recorded.
  Claude Code 2.1.286 completed consent and read-only tool calls against
  staging after a refresh/session continuity fix. Its negotiated protocol
  version was not surfaced by the client or connection page, so its run remains
  incomplete against the recording checklist. Cursor is excluded from this
  validation per user direction. The remaining client matrix is tracked on
  RUB-383. Do not infer compatibility for a client until its staging result is
  recorded below.

### Repeatable third-party client run

Run this checklist from a clean user profile against the staging issuer. Keep
tokens in the client's credential store and record only redacted output. The
run is successful only when the client completes OAuth consent, initializes
the hosted connection, calls `get_connection`, and reports the negotiated
protocol version.

```sh
export MCP_LIVE_BASE_URL=https://staging.artfct.dev
export ARTFCT_API_BASE_URL=https://staging.artfct.dev
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

### Client preflight inventory (2026-10-01)

This is an installation and setup preflight, not a hosted compatibility result:
no client below completed OAuth consent or called a hosted tool during this
check. Installed versions and `artfct setup --list` targets were checked on
2026-10-01 for the installed clients. The Inspector row retains its
2026-09-23 preflight result. This records what is available for the next
release-candidate run.

| Client                 | Installed version           | Setup/config preflight                                               | Hosted session                      |
| ---------------------- | --------------------------- | -------------------------------------------------------------------- | ----------------------------------- |
| Claude Code            | 2.1.286                     | `artfct setup --list` recognized the JSON target                     | OAuth run recorded separately below |
| Cursor                 | Excluded per user direction | Not in this validation scope                                         | Not run                             |
| Codex CLI              | 0.159.2                     | `artfct setup --list` recognized the TOML target                     | Not run                             |
| Gemini CLI             | 0.42.0                      | `artfct setup --list` recognized the JSON target                     | Not run                             |
| OpenCode               | 1.17.18                     | `artfct setup --list` recognized the JSON target                     | Not run                             |
| Official MCP Inspector | 2.7.0 via `npx`             | Local stdio `tools/list` passed; launcher and CLI help probes passed | OAuth run recorded separately below |

The Inspector package was available from npm; its non-interactive help commands
and a local stdio `tools/list` probe against `target/debug/artfct mcp serve`
passed. This 2026-09-23 preflight did not repeat the hosted OAuth run recorded
below. It also observed non-fatal local-environment warnings from Cursor's
macOS code-sign check, Codex's PATH-alias setup, and Gemini's cleanup attempt.
The Cursor Agent CLI reports no configured MCP servers in the current shell,
even though the setup preflight detects a Cursor JSON target; this remains a
local setup discrepancy, not hosted compatibility evidence.
The Inspector's non-secret stored-auth probe reported no hosted server URLs,
so there was no existing OAuth session to reuse. The local preflight itself is
not a hosted compatibility result.

### Hosted OAuth runs

| Date       | Client                 | Transport       | Result                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                               |
| ---------- | ---------------------- | --------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| 2026-09-21 | Official MCP Inspector | Streamable HTTP | Staging OAuth completed: discovery, dynamic registration, PKCE consent for `zz-mcp-a`, token exchange, tool list, and `get_connection`; negotiated protocol version not recorded.                                                                                                                                                                                                                                                                                                                                    |
| 2026-10-01 | Official MCP Inspector 2.7.0 | Streamable HTTP | OAuth consent completed with only `artifacts:read artifacts:deploy`; refresh-token request was disabled. Negotiated protocol `2025-11-25`; `get_connection` succeeded and returned the granted scopes. `get_usage` was denied with `insufficient_scope` as expected. Direct Worker checks: artifact list 200, missing `usage:read` 403, cross-org list 404, and a deploy-scoped invalid manifest reached validation (422) before storage. No artifact was created; Inspector cleared its OAuth state and anonymous Worker access returned 401 afterward. A successful MCP read-only tool and a persisted staging write remain unverified. |
| 2026-10-01 | Claude Code 2.1.286    | Streamable HTTP | Consent succeeded for `artfct-dev` with all six advertised scopes. The first tool-list attempt returned HTTP 400 `session_expired` after token refresh. After deploying the session fix to staging, `list_collections` and `get_connection` succeeded; the latter confirmed `artfct-dev` and all six scopes. No mutating tool was called. The client and connection page did not expose the negotiated protocol version.                                                                                             |
| 2026-10-01 | Codex CLI 0.159.2      | Streamable HTTP | A second staging attempt added a global `artfct-staging` entry and Codex detected OAuth. The authorization request included all six advertised scopes, including artifact deploy/delete. No browser provider was available in the computer-use session, so consent, callback, token exchange, and tool call did not complete. No grant or token was received. This remains an incomplete environment attempt, not a compatibility result; obtain explicit action-time approval before granting the requested scopes. |
| 2026-10-02 | Codex CLI 0.159.3      | Streamable HTTP | Hosted OAuth completed against staging (`codex mcp login` with a command-line URL override, so the user's real config was untouched). Consent was approved for all six advertised scopes in the browser. `codex exec` then opened a session and `get_connection` and `list_collections` both succeeded (organization `artfct-dev`, 6 scopes, 0 collections). The first transport attempt reported `AuthRequired` once before the stored token was applied; the retry succeeded. The negotiated protocol version was not surfaced by the client. Credentials removed afterwards with `codex mcp logout`. |
| 2026-10-02 | OpenCode 1.17.18       | Streamable HTTP | **Not working yet.** `opencode mcp auth` performs dynamic registration and prints a valid authorization URL; consent is approved and OpenCode's callback page shows "Authorization successful", but the CLI then reports `OAuth completion failed: The authorization code is invalid or expired` and `opencode mcp list` still shows `needs authentication`. The server logs two `/oauth/token` requests within the same second for each attempt, and no OAuth token or connection is created for any of the three attempts. The code is single-use by design, so a duplicate exchange is rejected. Whether the first exchange also fails (for example on `redirect_uri` or PKCE verification) is not determined; capture the two requests to find out. Headless bearer-token runs (below) are unaffected. |
| 2026-10-02 | Gemini CLI 0.42.0      | Streamable HTTP | **Not run.** With `oauth.enabled` in a trusted project `.gemini/settings.json` the server is detected and reported as `requires authentication using /mcp auth`, but the OAuth step is interactive (`/mcp auth`) and the CLI refuses headless use without a model credential (`GEMINI_API_KEY`, Vertex or Google sign-in), which this machine does not have. The headless bearer run below still shows the connection works. |

This run exposed three staging defects, all fixed and deployed: the
path-suffixed protected-resource metadata advertised the wrong issuer; OAuth
discovery, registration, token, and MCP endpoints lacked CORS (including the
RFC 8414 path-inserted metadata URL); and the consent redirect to the local
client callback was blocked because it used XHR. The Inspector completed the
flow after those fixes. Claude Code's OAuth run is recorded above. Cursor
remains outside this validation scope per user direction.

### Headless bearer-auth runs (2026-09-30)

These runs authenticated with a short-lived organization token instead of OAuth
consent, so they are **not** the repeatable run above and do not close it: they
show that each client can open a Streamable HTTP session to
`https://staging.artfct.dev/mcp` and, where a model login was available, call a
tool. Each used a temporary config outside the user's real client config, and a
15-minute token that was never printed. The negotiated protocol version was not
recorded per client (the server default is `2025-11-25`).

| Client      | Version             | How it was run                                                                                   | Result                                                         |
| ----------- | ------------------- | ------------------------------------------------------------------------------------------------ | -------------------------------------------------------------- |
| Claude Code | 2.1.286             | `claude -p --mcp-config <temp.json> --strict-mcp-config` with an `Authorization` header from env | Session opened, `get_connection` returned the organization     |
| Codex CLI   | 0.159.2             | `codex exec -c mcp_servers.<name>.url=… -c mcp_servers.<name>.bearer_token_env_var=…`            | Session opened, `get_connection` returned the organization     |
| Gemini CLI  | 0.42.0              | project `.gemini/settings.json` with `httpUrl` and headers; `gemini mcp list`                    | `Connected`; tool call not run (no model login on the machine) |
| OpenCode    | 1.17.18             | `OPENCODE_CONFIG=<temp.json>` with a remote MCP entry and headers; `opencode mcp list`           | `connected`; tool call failed on OpenCode's own provider login |
| Cursor      | excluded from scope | Not run                                                                                          | Not run                                                        |

Client-specific notes from these runs:

- **Codex:** a URL override under the name `artfct` collides with the stdio
  entry that `artfct setup` writes, and Codex refuses it (`url is not supported
for stdio`). Use a different server name for the hosted entry.
- **Gemini:** project-level MCP settings are ignored in an untrusted folder, so
  `gemini mcp list` reports no servers until the folder is trusted (for a single
  session, set `GEMINI_CLI_TRUST_WORKSPACE=true`).
- **Claude Code and Codex** can both run headlessly with a temporary config, so
  they are candidates for an automated check (they need a model login in CI).

The Claude Code protocol-version record and OAuth-consent runs for other
clients remain the human steps tracked on RUB-383.

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
