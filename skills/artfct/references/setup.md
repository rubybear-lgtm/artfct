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

If your AI tool cannot use a hosted server, it is not supported.

## Verify the connection

Ask your AI tool to call the `get_connection` tool. It reports the signed-in workspace and the
tools available. The tools appear as `mcp__artfct__deploy_artifact` and the other artfct tools.
