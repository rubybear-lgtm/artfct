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
