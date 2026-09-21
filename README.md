```
 █████╗ ██████╗ ████████╗███████╗ ██████╗████████╗
██╔══██╗██╔══██╗╚══██╔══╝██╔════╝██╔════╝╚══██╔══╝
███████║██████╔╝   ██║   █████╗  ██║        ██║   
██╔══██║██╔══██╗   ██║   ██╔══╝  ██║        ██║   
██║  ██║██║  ██║   ██║   ██║     ╚██████╗   ██║   
╚═╝  ╚═╝╚═╝  ╚═╝   ╚═╝   ╚═╝      ╚═════╝   ╚═╝   
```

share encrypted html. get a link. that's it.

Drop a self-contained HTML file — via browser, CLI, API, or AI agent — and get back a
shareable encrypted link. No sign-up required.

---

- **Web** — [artfct.dev](https://artfct.dev)
- **Docs** — [artfct.dev/docs](https://artfct.dev/docs)
- **Releases** — [github.com/rubybear-lgtm/artfct/releases](https://github.com/rubybear-lgtm/artfct/releases)

---

## Web

Visit [artfct.dev](https://artfct.dev). Drop or select an `.html` file. You get a
link immediately — no account, no configuration.

```
 drop your .html file here
 or click to browse

 [ deploy → ]

 → https://artfct.dev/p/4fA8gX9z...#abC123xyZ9   ⎘ copy
   expires in 5 days
```

Links are encrypted and ephemeral by default: they expire 5 days after last
access unless you set a custom TTL. Public metadata stays visible for link
previews, while the fragment passcode is never sent to the server.

## CLI

Install the latest release:

```sh
curl -fsSL https://artfct.dev/install.sh | sh
```

The installer downloads the correct binary for macOS (Apple Silicon or Intel) or Linux (x86_64 or ARM64) and installs it to `~/.local/bin/artfct` by default. It also automatically runs `artfct setup --silent` to configure the MCP server for all detected AI agents (Cursor, Claude Desktop, Gemini, and Codex) without prompts.

If you want to skip automatic MCP configuration during installation, set `ARTFCT_INSTALL_SETUP=0`:

```sh
ARTFCT_INSTALL_SETUP=0 curl -fsSL https://artfct.dev/install.sh | sh
```

If `~/.local/bin` is not on your `PATH`, add it:

```sh
export PATH="$HOME/.local/bin:$PATH"
```

Install a specific version or to a custom directory:

```sh
ARTFCT_INSTALL_VERSION=v0.0.4 curl -fsSL https://artfct.dev/install.sh | sh
ARTFCT_INSTALL_DIR=/usr/local/bin curl -fsSL https://artfct.dev/install.sh | sh
```

### Deploy

```sh
# Deploy a file — prints the URL
artfct deploy ./dashboard.html

# Deploy from stdin
cat dashboard.html | artfct deploy --stdin
echo '<h1>hello</h1>' | artfct deploy --stdin

# Set tier and expiration
artfct deploy ./dashboard.html --tier ephemeral --ttl-minutes 30

# Deploy a readable permanent artifact (requires an organization token)
ARTFCT_ORG_TOKEN=token artfct deploy ./dashboard.html --tier permanent

# Deploy a Vite/static bundle (all files are uploaded individually)
ARTFCT_ORG_TOKEN=token artfct deploy ./dist/ --tier permanent
ARTFCT_ORG_TOKEN=token artfct deploy ./dist/ --tier permanent --entrypoint app.html

# Export an organization's permanent artifacts
ARTFCT_ORG_TOKEN=token artfct export acme ./artifact-export
```

Output:

```
https://artfct.dev/p/<artifact-id>#<passcode>
```

### Delete

```sh
# Delete an ephemeral artifact by its 10-character ID
artfct delete abc123def4

# Delete a permanent artifact by its 32-character ID
ARTFCT_ORG_TOKEN=token artfct delete abc123def456789012345678901234ab

# Delete an artifact by its preview URL
artfct delete https://artfct.dev/p/abc123def456789012345678901234ab
```

### Options

```
Usage: artfct deploy [OPTIONS] [FILE]

Arguments:
  [FILE]  Path to a self-contained HTML file

Options:
      --stdin                  Read HTML from stdin
      --tier <TIER>            public | secure | ephemeral | permanent  [default: ephemeral]
      --entrypoint <PATH>      Entrypoint path for a permanent directory bundle
      --ttl-minutes <MINUTES>  Minutes until expiry after last access
      --org-token <TOKEN>      Organization token for permanent artifacts
  -h, --help                   Print help
```

### MCP Server Setup

You can automatically register `artfct` as a local MCP server for all detected clients:

```sh
# Automatically find and configure all client config files (silent mode)
artfct setup --silent

# Preview which configuration files would be written
artfct setup --list
```

Or run the server manually over stdio:

```sh
artfct mcp serve --host cursor
```

`artfct setup --silent` writes the matching `--host` value for Claude Code,
Cursor, Gemini, Codex, and OpenCode. Project-local `.mcp.json` entries omit the
flag when the client cannot be identified safely.

To configure it manually in your client's settings file (Cursor's `mcp.json` or Claude Desktop's config file):

```json
{
    "mcpServers": {
        "artfct": {
            "command": "artfct",
            "args": ["mcp", "serve", "--host", "cursor"]
        }
    }
}
```

The local server exposes these tools:

- `deploy_artifact` — publish HTML as a permanent artifact in your workspace, so it can be searched, retrieved, collected and counted toward usage.
- `deploy_to_canvas` — **deprecated**, use `deploy_artifact`. Publishes an anonymous, encrypted, expiring artifact that the workspace cannot search or retrieve.
- `search_artifacts` — search previously deployed artifacts without returning HTML.
- `get_connection` — inspect the authenticated workspace, scopes, and client context.
- `get_usage` — inspect customer-safe storage, artifact, render, and quota totals.
- `get_artifact` — retrieve artifact metadata without exposing bundle contents.
- `list_collections` — list organization-scoped artifact collections with cursor pagination.
- `create_collection` — create a collection for an authenticated member or admin.
- `add_collection_artifact` — add an artifact to an organization-scoped collection.

Run `artfct login --oauth` for browser-based OAuth with PKCE. Use
`artfct login --oauth --organization acme` to pin consent to a workspace, or
`artfct login` to save an organization token. OAuth credentials use the macOS
Keychain or Linux Secret Service when available. If neither is available, the
CLI falls back to a 0600 file under the user config directory and warns.
`artfct logout` revokes the remote session and removes the local credential.
Run `artfct organizations` to inspect the organizations
available to the signed-in account and the currently selected context; run
`artfct login --oauth --organization <slug>` to switch. `ARTFCT_ORG_TOKEN` takes
precedence and is useful for CI.

For hosted MCP, use `https://artfct.dev/mcp` as the server URL. A client that
supports OAuth should discover authorization through
`https://artfct.dev/.well-known/oauth-protected-resource` and request only the
scopes it needs. The dashboard's **MCP connections** page shows the same setup
instructions and lets workspace administrators inspect, monitor, and revoke
connections. Hosted connections use Streamable HTTP; local setup uses stdio.
`deploy_artifact` is naturally idempotent: publishing identical content to the
same workspace returns the same artifact. For safe retries of the deprecated
`deploy_to_canvas`, send a stable `MCP-Request-Id` (or `Idempotency-Key`)
header. Reusing it with the same payload returns the original result; reusing
it with a different payload is rejected.

For release verification, run the live staging smoke suite with two isolated
organization credentials and a private artifact that belongs only to
organization A:

```sh
MCP_LIVE_BASE_URL=https://staging.artfct.dev \
MCP_LIVE_TOKEN_A=… \
MCP_LIVE_TOKEN_B=… \
MCP_LIVE_EXPECTED_ORG_A=acme \
MCP_LIVE_EXPECTED_ORG_B=beta \
MCP_LIVE_PRIVATE_ARTIFACT_A=… \
npm run mcp:live
```

The smoke check validates protocol/session continuity, the exact tool catalog,
usage reset metadata, collection discovery, and cross-organization artifact
isolation. The same check is available as the manual `mcp-live` GitHub Actions workflow.
Keep the credentials in the staging environment secrets; never commit them or
put them in ordinary pull-request CI.

See [the MCP and CLI launch runbook](docs/mcp-cli-runbook.md) for supported
client setup, recovery, policy errors, and incident procedures.

### Diagnostics

```sh
artfct doctor       # check connectivity and configuration
artfct --help
artfct deploy --help
artfct mcp --help
artfct setup --help
artfct delete --help
artfct uninstall --help
```

### Uninstall

Uninstall the CLI binary and remove MCP configurations from all supported client configuration files:

```sh
# Prompts for verification before removing the CLI binary
artfct uninstall

# Run without interactive prompts
artfct uninstall --silent
```

### Environment

```
ARTFCT_API_BASE_URL      API base URL. Defaults to https://artfct.dev
ARTFCT_INSTALL_VERSION   Release tag to install. Defaults to latest.
ARTFCT_INSTALL_DIR       Install directory. Defaults to ~/.local/bin.
ARTFCT_INSTALL_REPO      GitHub repo. Defaults to rubybear-lgtm/artfct.
ARTFCT_ORG_TOKEN         Organization token for permanent deploy, delete, export, and search.
ARTFCT_SEARCH_BASE_URL   search_artifacts endpoint base URL. Defaults to ARTFCT_API_BASE_URL.
```

## Production

The Laravel site is deployed with Railway. Required production environment:

```sh
APP_ENV=production
APP_DEBUG=false
APP_URL=https://artfct.dev
APP_KEY=<generated Laravel app key>
VITE_APP_NAME=artfct
```

The Worker API and previews are deployed with Wrangler from `backend/wrangler.jsonc`.
Before deploying, verify the Cloudflare route bindings and `ARTFCT_PUBLIC_BASE_URL`
still point at `https://artfct.dev`.

### Tenant provisioning

```sh
php artisan tenant:provision acme --release=v1.2.0  # idempotent; resumes a failed run
php artisan tenant:migrate --all                    # fleet migration; continues past a failed tenant
php artisan tenant:status                           # schema-version distribution across the fleet
php artisan tenant:deprovision acme                  # removes the script; D1/R2 retained for the retention window
```

Requires `services.cloudflare.api_token`/`account_id`/`dispatch_namespace`
configured to reach a real Workers for Platforms account; none of these
commands do anything against real Cloudflare infrastructure without it.

## API

Full reference at [artfct.dev/docs](https://artfct.dev/docs).

Quick reference:

```sh
# Create an artifact
curl -X POST https://artfct.dev/v1/artifacts \
  -H "Content-Type: application/json" \
  -d '{
    "body_ciphertext_b64": "<ciphertext>",
    "body_iv_b64": "<iv>",
    "tier": "ephemeral"
  }'

# Delete immediately
curl -X DELETE https://artfct.dev/v1/artifacts/<id>
```

The CLI handles encryption and metadata extraction for you. No authentication.
Rate limited to 60 creates / minute per IP.

## AI Agents

### MCP Tool

When artfct is configured as an MCP server, agents get the publishing,
retrieval, collection, usage, and connection tools documented in [MCP Server
Setup](#mcp-server-setup). `deploy_artifact` accepts a complete HTML payload
and publishes it to the workspace — agents should deploy instead of emitting raw
code blocks whenever they produce visual output. Artifacts are stored readable
by the workspace (that is what makes them searchable); `secure` limits who can
open the link, `public` does not.

```json
{
  "name": "deploy_artifact",
  "arguments": {
    "html": "<!DOCTYPE html>...",
    "tier": "secure",
    "model": "optional-agent-attested-model"
  }
}
```

The optional `model` value is recorded as agent-attested provenance and is kept
separate from process-observed identity.

`search_artifacts` searches the org's previously deployed artifacts — call it before building something the user references ("the billing dashboard", "that report from last week") instead of regenerating it from scratch. Results are a short list (title, description, URL, provenance summary, and a text snippet) — never the full HTML.

```json
{
  "name": "search_artifacts",
  "arguments": {
    "query": "billing dashboard",
    "repo": "https://github.com/acme/billing",
    "agent": "claude-code",
    "since": "2026-08-01",
    "collection": "reporting-formats",
    "limit": 5
  }
}
```

Requires `ARTFCT_ORG_TOKEN` (see [Environment](#environment) above) and, optionally, `ARTFCT_SEARCH_BASE_URL` if the search endpoint is hosted separately from `ARTFCT_API_BASE_URL`.

See [MCP Server Setup](#mcp-server-setup) above for configuration instructions.

### Skills

Install the artfct skill to give any compatible AI agent (such as Claude Code, Codex, or OpenCode) guidance on when and how to deploy artifacts:

```sh
npx skills add rubybear-lgtm/artfct@artfct
```

The skill teaches agents:

- When to deploy vs. when to return a code block
- How to choose the right tier (`public` / `secure` / `ephemeral`)
- How to author valid self-contained HTML with SRI-pinned CDN dependencies
- How to handle errors and present URLs clearly

Skills are resolved from the `skills/artfct/` directory in this repo and follow the [skills.sh](https://skills.sh) format — compatible with Claude Code, OpenAI Codex, OpenCode, and other agents that support the skills ecosystem.

## Development

The project is a Cargo workspace with two crates and a Laravel frontend.

```
Cargo.toml          # workspace root
backend/            # Cloudflare Worker (Rust, wasm32)
mcp-server/         # CLI + MCP server binary (Rust)
resources/          # Laravel frontend (React + Inertia + Tailwind)
```

### Frontend

Requirements: PHP 8.5, Composer, Node 22.

```sh
composer setup      # install deps, copy .env, generate key, run migrations
composer dev        # PHP server + queue + Vite dev server (all in one)
```

Set the worker URL so the browser can reach a local Cloudflare Worker:

```sh
# .env
VITE_WORKER_URL=http://localhost:8787
```

Login uses WorkOS AuthKit. Locally and in tests, an injectable fake client
stands in and needs no credentials — see "Identity (control plane)" in
[DOCUMENTATION.md](DOCUMENTATION.md). For a real WorkOS account, set:

```sh
# .env
WORKOS_CLIENT_ID=
WORKOS_API_KEY=
WORKOS_REDIRECT_URL="${APP_URL}/authenticate"
```

Run the frontend checks:

```sh
npm run lint:check      # ESLint
npm run format:check    # Prettier
npm run types:check     # TypeScript
npm run build           # Vite production build
php artisan test        # Pest
```

Or run them all at once:

```sh
composer ci:check
```

### Worker

Requirements: Rust stable, `wrangler`.

```sh
npm run worker:kv:create   # create the KV namespace (once)
npm run worker:dev         # local worker on http://localhost:8787
npm run worker:deploy      # deploy to Cloudflare
```

### CLI

```sh
cargo build -p artfct              # debug build
cargo test -p artfct               # run tests
cargo run -p artfct -- deploy --help
```
