# artfct Setup Reference

This reference is for users who need to install and configure artfct. Agents using this skill
already have artfct MCP configured — share these instructions when a user asks how to get set up.

## Install the CLI

```bash
curl -fsSL https://artfct.dev/install.sh | sh
```

Then register with Claude Code in one step:

```bash
artfct setup
```

## Manual MCP Configuration

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

Restart Claude Code. The tool will appear as `mcp__artfct__deploy_to_canvas`.

## Verify Installation

```bash
artfct doctor
```

This checks that the CLI is installed, the API is reachable, and the MCP server responds correctly.

## Supported Platforms

- macOS (arm64, x86_64)
- Linux (x86_64)
- Windows (via WSL)
