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

## Safe retries and policy errors

Remote mutation callers should send a stable `MCP-Request-Id` or
`Idempotency-Key` for retries. Repeating the same key with the same payload
returns the original result. Reusing the key with a different payload is
rejected. A concurrent duplicate reports an in-progress, retryable error.

Rate-limit responses include `Retry-After`; quota and policy errors include a
stable machine-readable error code and remediation-safe usage details. Retry
after the indicated delay, reduce the request rate, or ask a workspace admin
to review the plan and connection scopes. Do not retry invalid scope,
revoked-credential, or malformed-request errors.

## Staging verification

The live smoke suite must use two isolated staging organizations:

```sh
MCP_LIVE_BASE_URL=https://staging.artfct.dev \
MCP_LIVE_TOKEN_A=... \
MCP_LIVE_TOKEN_B=... \
MCP_LIVE_EXPECTED_ORG_A=acme \
MCP_LIVE_EXPECTED_ORG_B=beta \
MCP_LIVE_PRIVATE_ARTIFACT_A=... \
npm run mcp:live
```

The suite verifies protocol negotiation, session continuity, the exact tool
catalog, self-describing tool metadata, usage metadata, collection discovery,
and cross-organization artifact isolation. It also opens a bounded set of concurrent sessions for both
organizations and checks that every session retains its tenant context. Set
`MCP_LIVE_CONCURRENCY` to an integer from 2 to 32 to adjust that check; it
defaults to 8 sessions per organization. Run it manually through the
`mcp-live` GitHub Actions workflow when staging secrets are configured. Never
place those credentials in pull-request logs or repository files.

## Incident checklist

1. Run `artfct doctor` and capture only its redacted output.
2. Identify the workspace, connection name, client, transport, and request ID
   from the MCP connections page or activity view.
3. Revoke the affected connection if compromise is suspected.
4. Rotate the affected OAuth session or CI token through the normal credential
   owner; do not edit database rows manually.
5. Re-run the staging smoke suite before restoring the integration.
