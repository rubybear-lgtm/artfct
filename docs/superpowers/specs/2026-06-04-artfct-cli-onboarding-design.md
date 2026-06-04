# Artifact CLI and MCP Onboarding Design

## Summary

Artifact Engine should ship as a single `artfct` CLI that is useful on its own and also acts as the MCP server entrypoint for coding agents. The CLI should let users publish self-contained HTML artifacts from files or stdin, manage local defaults, and install MCP configuration for supported agent tools through a guided onboarding flow.

The product shape follows the Laravel Boost installer model: detect the local environment, present toggles for supported tools, write only scoped configuration, and provide a `doctor` command that verifies the setup end to end.

## Goals

- Provide a standalone `artfct` command for publishing artifact previews without requiring an MCP client.
- Preserve the existing MCP tool behavior behind `artfct mcp serve`.
- Add guided onboarding through `artfct setup`.
- Let users choose which agent integrations to install.
- Support Claude Code, Codex, Gemini CLI, and Antigravity in the first implementation pass.
- Avoid corrupting existing agent configuration files.
- Make installation diagnosable with `artfct doctor`.

## Non-Goals

- Do not add preview-page authentication beyond the magic-link URL model.
- Do not implement paid accounts, teams, or dashboard flows.
- Do not require a Cloudflare account for CLI users.
- Do not ship MCPB packaging in the first pass; design should leave room for it.
- Do not require API keys for basic artifact creation while the backend remains public.

## User Model

The primary user is a developer or AI-agent user who wants generated HTML to become a shareable URL quickly. They may use several agent tools on the same machine and should not need to understand MCP configuration formats.

The CLI should serve two modes:

- Human mode: a developer runs `artfct deploy ./preview.html` or pipes HTML through stdin.
- Agent mode: an MCP client starts `artfct mcp serve` and calls `deploy_to_canvas`.

## Command Surface

```bash
artfct setup
artfct deploy <file>
artfct deploy --stdin
artfct delete <id>
artfct open <id-or-url>
artfct config get [key]
artfct config set <key> <value>
artfct mcp serve
artfct mcp install
artfct mcp uninstall
artfct doctor
```

### `artfct setup`

Runs guided onboarding:

1. Choose API endpoint, defaulting to `https://artfct.dev`.
2. Choose default TTL, defaulting to `60` minutes.
3. Select agent integrations with toggles.
4. Optionally install shell completions.
5. Write local CLI config.
6. Patch selected agent MCP configs.
7. Run `doctor`.

### `artfct deploy`

Publishes HTML through `POST /v1/artifacts` and prints the resulting URL. It should support file input and stdin:

```bash
artfct deploy ./dashboard.html
cat dashboard.html | artfct deploy --stdin
```

Output should be script-friendly by default:

```text
https://artfct.dev/p/<id>
```

Verbose and JSON output can be added later:

```bash
artfct deploy ./dashboard.html --json
```

### `artfct mcp serve`

Starts the stdio MCP server. This replaces the current standalone `artfct-mcp-server` binary entrypoint while preserving the existing JSON-RPC tool implementation.

### `artfct mcp install`

Runs the agent-selection portion of setup without changing general CLI defaults. This is useful when a user adds a new agent later.

### `artfct doctor`

Verifies:

- CLI config exists and is readable.
- API endpoint is reachable.
- A short-lived test artifact can be created.
- `artfct mcp serve` can initialize.
- Selected agent configs reference the expected command.

## Local Configuration

Use platform-standard config locations:

- macOS: `~/Library/Application Support/artfct/config.toml`
- Linux: `$XDG_CONFIG_HOME/artfct/config.toml`
- fallback: `~/.config/artfct/config.toml`

Example:

```toml
api_base_url = "https://artfct.dev"
default_ttl_minutes = 60
default_tier = "ephemeral"

[agents]
claude_code = true
codex = true
gemini = false
antigravity = false
```

Environment variables override config values:

- `ARTFCT_API_BASE_URL`
- `ARTFCT_API_TOKEN`
- `ARTFCT_DEFAULT_TTL_MINUTES`
- `ARTFCT_DEFAULT_TIER`

## Agent Installer Architecture

Agent support should be modular. Each supported agent implements the same interface:

```text
AgentInstaller
- key() -> AgentKey
- display_name() -> string
- detect() -> DetectionStatus
- read_config() -> ConfigState
- install(server_config) -> InstallResult
- uninstall() -> InstallResult
- manual_instructions(server_config) -> string
```

Initial implementations:

- `ClaudeCodeInstaller`
- `CodexInstaller`
- `GeminiInstaller`
- `AntigravityInstaller`

The installer should be conservative:

- If config is missing, create the minimum file needed.
- If config exists and is parseable, patch only the `artfct` MCP server entry.
- If config exists but is not parseable, create a backup and print manual instructions instead of writing.
- Before changing any file, write a timestamped backup next to it.

## MCP Server Configuration

Every agent should ultimately be configured to launch:

```bash
artfct mcp serve
```

With environment:

```text
ARTFCT_API_BASE_URL=https://artfct.dev
```

If a future API token becomes required for create/delete, installers should add it only through local config or explicit environment configuration, not by writing secrets into repository files.

## Data Flow

Standalone deploy:

```text
HTML file/stdin
  -> artfct deploy
  -> POST https://artfct.dev/v1/artifacts
  -> print magic-link URL
```

MCP deploy:

```text
Agent generates HTML
  -> MCP tools/call deploy_to_canvas
  -> artfct mcp serve
  -> POST https://artfct.dev/v1/artifacts
  -> MCP structuredContent contains id/url/tier/expires_at
```

Onboarding:

```text
artfct setup
  -> detect installed agents
  -> prompt toggles
  -> write artfct config
  -> patch selected agent configs
  -> run doctor
```

## Error Handling

Setup should distinguish:

- Agent not installed.
- Agent installed but config path unknown.
- Config path found but unparsable.
- Config written successfully.
- Config write skipped with manual instructions.

Deploy should distinguish:

- Empty HTML input.
- File not found.
- API unavailable.
- Payload too large.
- Backend validation error.
- Rate limited.

`doctor` should return a non-zero exit code if any selected integration is broken.

## Testing

Unit tests:

- Config path resolution.
- TOML config read/write.
- Agent installer patch logic.
- MCP JSON-RPC initialize/tools/list/tools/call behavior.
- CLI deploy argument parsing.

Integration tests:

- `artfct deploy --stdin` against a mock HTTP server.
- `artfct mcp serve` with sample JSON-RPC messages.
- Agent installer writes expected config into temp directories.

Manual smoke tests:

- Install Claude Code integration.
- Install Codex integration.
- Run `artfct doctor`.
- Create a live test artifact through CLI and through MCP.

## Distribution

First release:

- GitHub Releases with signed binaries for macOS and Linux.
- Manual install instructions for adding the binary to `PATH`.

Second release:

- Homebrew tap.
- Shell completions.

Later:

- MCPB bundle for Claude Desktop-style one-click installation.
- MCP Registry metadata once package identity is stable.

## Open Questions

- Exact config file paths and schemas for Gemini CLI and Antigravity need current verification before implementation.
- Whether `secure` tier should remain as an accepted alias for magic-link-only previews or be removed until stronger semantics exist.
- Whether setup should default all detected agents to selected or require opt-in per agent.

