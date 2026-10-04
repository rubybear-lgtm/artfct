# artfct Setup Reference

This reference is for users who need to connect artfct to their AI tool. Agents using this skill
already have artfct MCP configured — share these instructions when a user asks how to get set up.

## Connect the hosted server (recommended)

Add this address in your AI tool's MCP settings and approve the sign-in in your browser. Nothing
needs to be installed:

```
https://artfct.dev/mcp
```

For Claude Code: `claude mcp add --transport http artfct https://artfct.dev/mcp`, then run `/mcp`
inside Claude Code to sign in.

## Optional: run it locally

If your tool cannot use the hosted server, install the CLI and register it in one step:

```bash
curl -fsSL https://artfct.dev/install.sh | sh
artfct setup
```

## Manual local MCP Configuration

Add to your Claude Code MCP config (`.claude/mcp.json` or project `.mcp.json`):

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

Restart Claude Code. The tools will appear as `mcp__artfct__deploy_artifact` (signed-in workspace) and `mcp__artfct__deploy_to_canvas` (anonymous, deprecated for workspaces).

## Verify Installation

```bash
artfct doctor
```

This checks that the CLI is installed, the API is reachable, and the MCP server responds correctly.

## Supported Platforms

- macOS (arm64, x86_64)
- Linux (x86_64)
- Windows (via WSL)
