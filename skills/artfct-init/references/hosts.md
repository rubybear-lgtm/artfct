# Host reference

Used by the artfct-init skill. Grants are written only by `scripts/permissions.mjs`, and only for tools the user approved.

| | Claude Code | Codex |
|---|---|---|
| Invoke the skill | `/artfct-init` | `$artfct-init` |
| Where servers are configured | `.mcp.json` in the repo, and `~/.claude.json` top level or under `projects["<repo>"]` | `~/.codex/config.toml` and `.codex/config.toml` (trusted projects only), tables `[mcp_servers.<name>]` |
| How servers are detected | Name contains "artfct", or `url` or `command` contains "artfct" | Same rule, read from the table's `url` or `command` |
| How grants are expressed | Entries `mcp__<server>__<tool>` in `permissions.allow` of a settings JSON file | One table per tool: `[mcp_servers.<server>.tools.<tool>]` with `approval_mode = "approve"` |
| Default grant file | `.claude/settings.local.json` (personal). `settings.json` only if the user picks it | `~/.codex/config.toml` |
| Server-wide default | Not used | Never written (`default_tools_approval_mode` is not touched) |
| Unsupported | claude.ai connectors: they live in no file, so they cannot be detected or granted. With no server found, the result is `undetectable` | Server-wide `default_tools_approval_mode`: writing it would grant tools the user did not approve. Inline-table server forms (`mcp_servers.x = { ... }`) are not detected |

## Per-tool `approve` on Codex: confirmed

Confirmed 2026-10-19 on `codex-cli 0.163.0`, with a throwaway stdio MCP server exposing two tools and `[mcp_servers.<name>.tools.<tool>] approval_mode = "approve"` on one of them:

- The approved tool ran. No approval request was raised.
- The unlisted tool failed with `MCP tool call requires approval, but approval policy is never`.

So the per-tool table is what suppresses the approval request, and a tool with no table still needs one. That is why the adapter writes per-tool entries and never `default_tools_approval_mode`.

Notes:

- Markdown instructions (AGENTS.md, CLAUDE.md, GUIDELINES.md, DESIGN.md) never grant permissions. Only the entries above do.
- Delete and public publishing are never granted on any host.
- Codex parsing reads table-form servers only. Inline-table forms such as `mcp_servers.x = { ... }` are not detected.

## Instructions file by agent and level

Checked against each agent's docs on 2026-10-21 (RUB-502). `scripts/levels.mjs` holds the same table; a dash means the agent has no file for that level, so init reports it as blocked instead of guessing.

| Agent (`--host`) | Global: me, every project | User: me, this repo (git-excluded) | Repo: committed | Source |
|---|---|---|---|---|
| Claude Code (`claude`) | `~/.claude/CLAUDE.md` | `CLAUDE.local.md` | `CLAUDE.md` (or `AGENTS.md` it imports) | code.claude.com/docs/en/memory |
| Codex (`codex`) | `$CODEX_HOME/AGENTS.md` (default `~/.codex`) | - | `AGENTS.md` | learn.chatgpt.com/docs/agent-configuration/agents-md |
| Cursor (`cursor`) | - (user rules live in Customize, then Rules) | - | `AGENTS.md` | cursor.com/docs/context/rules |
| OpenCode (`opencode`) | `~/.config/opencode/AGENTS.md` | - | `AGENTS.md` | opencode.ai/docs/rules |
| VS Code with Copilot (`vscode`) | `~/.copilot/copilot-instructions.md` | - | `.github/copilot-instructions.md` | code.visualstudio.com/docs/copilot/customization/custom-instructions |
| Windsurf (`windsurf`) | `~/.codeium/windsurf/memories/global_rules.md` (6,000 characters) | - | `AGENTS.md` | docs.windsurf.com/windsurf/cascade/memories, docs.devin.ai/desktop/cascade/agents-md |
| Zed (`zed`) | - (Rules Library in the app) | - | the first of `.rules`, `.cursorrules`, `.windsurfrules`, `.clinerules`, `.github/copilot-instructions.md`, `AGENT.md`, `AGENTS.md`, `CLAUDE.md`, `GEMINI.md`; `AGENTS.md` when none exists | zed.dev/docs/ai/rules |
| Antigravity (`antigravity`) | `~/.gemini/GEMINI.md` | - | `AGENTS.md` | antigravity.google/docs/rules |

`GUIDELINES.md` and `DESIGN.md` are installed only at the Repo level. The Global block points to them only when a project has them.

## Permissions file by level

| Agent | Global | User | Repo |
|---|---|---|---|
| Claude Code | `~/.claude/settings.json` | `.claude/settings.local.json` | `.claude/settings.json` |
| Codex | `$CODEX_HOME/config.toml` | - | `.codex/config.toml` (trusted projects only) |
| Every other agent | - | - | - |

Agents without a row approve the artfct tools themselves the first time they are used.
