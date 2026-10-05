```
 █████╗ ██████╗ ████████╗███████╗ ██████╗████████╗
██╔══██╗██╔══██╗╚══██╔══╝██╔════╝██╔════╝╚══██╔══╝
███████║██████╔╝   ██║   █████╗  ██║        ██║   
██╔══██║██╔══██╗   ██║   ██╔══╝  ██║        ██║   
██║  ██║██║  ██║   ██║   ██║     ╚██████╗   ██║   
╚═╝  ╚═╝╚═╝  ╚═╝   ╚═╝   ╚═╝      ╚═════╝   ╚═╝   
```

share encrypted html. get a link. that's it.

Drop a self-contained HTML file — via browser, API, or AI agent — and get back a
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

## Connect your AI tool

Artfct has one way to connect an AI tool: the hosted MCP server. Add the server address to your
AI tool and approve the browser sign-in; nothing needs to be installed and no key is copied. The
address is `<site>/mcp` for the environment you use: `https://staging.artfct.dev/mcp` today, and
`https://artfct.dev/mcp` once the production route is deployed (it currently returns 404).

| Tool        | Add the server                                           | Sign in                                       |
| ----------- | -------------------------------------------------------- | --------------------------------------------- |
| Claude Code | `claude mcp add --transport http artfct <address>`       | `/mcp` → `artfct` → Authenticate              |
| Codex       | `codex mcp add artfct --url <address>`                   | `codex mcp login artfct`                      |
| OpenCode    | `opencode mcp add artfct --url <address>`                | `opencode mcp auth artfct` (approve once)     |
| Antigravity | `agy mcp add artfct <address>`, then add `"oauth": {}` to the entry in `~/.gemini/config/mcp_config.json` | `/mcp` → `artfct` |

Then ask the tool "Which Artfct workspace am I connected to?" to confirm. Other tools (Cursor,
desktop apps) take the same address as a remote server but have not been verified yet. The `/docs`
page shows these steps with the right address filled in, plus troubleshooting. If you used the
retired `artfct` command-line app, remove its old `artfct` entry first (`claude mcp remove artfct`,
`codex mcp remove artfct`).

An AI tool that cannot use a hosted server is not supported. Workspace administrators download an
organization's permanent artifacts from the console as a zip, and automation uses the REST API
with an organization token (team settings, **API tokens**).

The hosted server exposes these tools:

- `deploy_artifact` — publish HTML, or a multi-file bundle (`files` and `entrypoint`, up to 500 files and 8 MB, base64 for binaries), as a permanent artifact in your workspace, so it can be searched, retrieved, collected and counted toward usage. Returns a `view_url`: the app's own open route for a secure artifact, the workspace's public artifact URL for a public one.
- `deploy_to_canvas` — **deprecated**, use `deploy_artifact`. Publishes an anonymous, encrypted, expiring artifact that the workspace cannot search or retrieve.
- `search_artifacts` — search previously deployed artifacts without returning HTML. Each result carries a `view_url` on the app's open route.
- `get_connection` — inspect the authenticated workspace, scopes, and client context.
- `get_usage` — inspect customer-safe storage, artifact, render, and quota totals.
- `get_artifact` — retrieve artifact metadata without exposing bundle contents, plus a `view_url` under the same rule as `deploy_artifact`.
- `list_collections` — list organization-scoped artifact collections with cursor pagination.
- `create_collection` — create a collection for an authenticated member or admin.
- `add_collection_artifact` — add an artifact to an organization-scoped collection.

Clients discover authorization through `<site>/.well-known/oauth-protected-resource` and request
only the scopes they need. The dashboard's **MCP connections** page shows the address and lets
workspace administrators inspect, monitor, and revoke connections. Connections use Streamable HTTP.
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

See [the MCP launch runbook](docs/mcp-runbook.md) for supported
client setup, recovery, policy errors, and incident procedures.

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

Encryption happens in the browser. No authentication.
Rate limited to 60 creates / minute per IP.

## AI Agents

### MCP Tool

When artfct is configured as an MCP server, agents get the publishing,
retrieval, collection, usage, and connection tools documented in [Connect your AI
Tool](#connect-your-ai-tool). `deploy_artifact` accepts a complete HTML payload
or a multi-file bundle and publishes it to the workspace — agents should deploy instead of emitting raw
code blocks whenever they produce visual output. Artifacts are stored readable
by the workspace (that is what makes them searchable); `secure` limits who can
open the link, `public` does not. A secure artifact's `view_url` is the app's
own open route (`/settings/teams/<org>/console/artifacts/<id>/open`), so an
agent can hand it to a colleague and it works when that colleague is signed in;
a public artifact's `view_url` is the artifact's public URL, which needs no
session and gets no token minted for it.

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

A multi-file bundle passes `files` instead of `html`; the entrypoint defaults to `index.html`:

```json
{
  "name": "deploy_artifact",
  "arguments": {
    "files": [
      { "path": "index.html", "content": "<!DOCTYPE html><link rel=\"stylesheet\" href=\"assets/app.css\">..." },
      { "path": "assets/app.css", "content": "body { margin: 0 }" },
      { "path": "assets/logo.png", "content": "<base64>", "encoding": "base64" }
    ]
  }
}
```

The optional `model` value is recorded as agent-attested provenance and is kept
separate from process-observed identity.

`search_artifacts` searches the org's previously deployed artifacts — call it before building something the user references ("the billing dashboard", "that report from last week") instead of regenerating it from scratch. Results are a short list (title, description, `view_url`, provenance summary, and a text snippet) — never the full HTML.

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

Requires the `artifacts:read` scope. See [Connect your AI tool](#connect-your-ai-tool) above for configuration instructions.

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

The project is a Cargo workspace with one crate and a Laravel frontend.

```
Cargo.toml          # workspace root
backend/            # Cloudflare Worker (Rust, wasm32)
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
