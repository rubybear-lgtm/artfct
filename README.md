```
 █████╗ ██████╗ ████████╗███████╗ ██████╗████████╗
██╔══██╗██╔══██╗╚══██╔══╝██╔════╝██╔════╝╚══██╔══╝
███████║██████╔╝   ██║   █████╗  ██║        ██║   
██╔══██║██╔══██╗   ██║   ██╔══╝  ██║        ██║   
██║  ██║██║  ██║   ██║   ██║     ╚██████╗   ██║   
╚═╝  ╚═╝╚═╝  ╚═╝   ╚═╝   ╚═╝      ╚═════╝   ╚═╝   
```

share html. get a link. that's it.

Drop a self-contained HTML file — via browser, CLI, API, or AI agent — and get back a
shareable link. No sign-up required.

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

 → https://artfct.dev/p/4fA8gX9z...   ⎘ copy
   expires in 60 min
```

Links are ephemeral and secure by default. The URL is a 32-character random token —
not guessable, not indexed, gone after the TTL.

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
ARTFCT_INSTALL_VERSION=v0.0.1 curl -fsSL https://artfct.dev/install.sh | sh
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
```

Output:

```
https://artfct.dev/p/<artifact-id>
```

### Delete

```sh
# Delete an artifact by its 32-hex ID
artfct delete abc123def456789012345678901234ab

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
      --tier <TIER>            public | secure | ephemeral  [default: ephemeral]
      --ttl-minutes <MINUTES>  Minutes until expiry
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
artfct mcp serve
```

To configure it manually in your client's settings file (Cursor's `mcp.json` or Claude Desktop's config file):

```json
{
    "mcpServers": {
        "artfct": {
            "command": "artfct",
            "args": ["mcp", "serve"]
        }
    }
}
```

The server exposes a single tool — `deploy_to_canvas` — which accepts a complete HTML payload and returns a preview URL.

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
```

## API

Full reference at [artfct.dev/docs](https://artfct.dev/docs).

Quick reference:

```sh
# Create an artifact
curl -X POST https://artfct.dev/v1/artifacts \
  -H "Content-Type: application/json" \
  -d '{"html": "<h1>hello</h1>", "tier": "ephemeral"}'

# Delete immediately
curl -X DELETE https://artfct.dev/v1/artifacts/<id>
```

No authentication. Rate limited to 60 creates / minute per IP.

## AI Agents

### MCP Tool

When artfct is configured as an MCP server, agents get access to `deploy_to_canvas` — a single tool that accepts a complete HTML payload and returns a preview URL. Agents should deploy instead of emitting raw code blocks whenever they produce visual output.

```json
{
  "name": "deploy_to_canvas",
  "arguments": {
    "html": "<!DOCTYPE html>...",
    "tier": "public"
  }
}
```

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

## Why artfct?

There are plenty of ways to share HTML. Here's how artfct compares:

|                    | artfct | GitHub Gist | CodePen | Pastebin | Dropbox |
|--------------------|--------|-------------|---------|----------|---------|
| **No sign-up**     | ✅     | ❌          | ❌      | ✅       | ❌      |
| **Ephemeral by default** | ✅ | ❌     | ❌      | ❌       | ❌      |
| **CLI-first**      | ✅     | ❌*         | ❌      | ❌       | ❌      |
| **AI agent native (MCP)** | ✅ | ❌    | ❌      | ❌       | ❌      |
| **Renders HTML**   | ✅     | ❌          | ✅      | ❌       | ✅      |
| **Self-contained only** | ✅ | ❌       | ❌      | ❌       | ❌      |
| **API**            | ✅     | ✅          | ✅      | ✅       | ✅      |
| **Open source**    | ✅     | ❌          | ❌      | ❌       | ❌      |

*\*GitHub has a CLI but no dedicated "share HTML snippet" workflow.*

**artfct is for the gap between "too simple for a full repo" and "too heavy for a pastebin."**

### Use cases

- **Share a dashboard or chart** — Your agent generates a data viz. Deploy it, share the link.
- **Preview an HTML email** — Render it in a browser before sending.
- **Share a UI mockup** — Quick prototype, no account needed on the recipient's side.
- **Debug output** — Your agent returns structured HTML. Instead of a code block, get a live page.
- **Throwaway snippets** — Ephemeral by default. 60 minutes and it's gone.
- **CI/CD artifacts** — Deploy HTML reports from your pipeline via the API or CLI.
