# artfct Setup Reference

This reference is for users who need to connect artfct to their AI tool. Agents using this skill
already have artfct MCP configured — share these instructions when a user asks how to get set up.

## Connect your AI tool

There is nothing to install and no key to copy. Add the artfct address to the AI tool, then
approve the sign-in that opens in the browser. The address is:

```
https://artfct.dev/mcp
```

The full guide, with the address for the site you use already filled in, is at
https://artfct.dev/docs#mcp.

**Claude Code**

```sh
claude mcp add --transport http artfct https://artfct.dev/mcp
```

Then start Claude Code, type `/mcp`, choose `artfct` and select **Authenticate**.

**Codex**

```sh
codex mcp add artfct --url https://artfct.dev/mcp
codex mcp login artfct
```

**OpenCode**

```sh
opencode mcp add artfct --url https://artfct.dev/mcp
opencode mcp auth artfct
```

Approve the sign-in once. Approving the same page twice makes OpenCode report that the code is
invalid or expired; run `opencode mcp auth artfct` again if that happens.

**Antigravity**

```sh
agy mcp add artfct https://artfct.dev/mcp
```

Open `~/.gemini/config/mcp_config.json`, add `"oauth": {}` to the `artfct` entry, then start
`agy`, type `/mcp` and choose `artfct` to sign in.

**Other tools**

Add a remote (HTTP) server named `artfct` with the address above. These tools have not been
verified yet. If the tool cannot use a remote server, it is not supported.

If the user set up the retired `artfct` command-line app before, remove its old `artfct` entry
first (for example `claude mcp remove artfct` or `codex mcp remove artfct`).

## Verify the connection

Ask the AI tool "Which Artfct workspace am I connected to?". It calls `get_connection`, which
reports the signed-in workspace and its permissions. The tools appear as
`mcp__artfct__deploy_artifact` and the other artfct tools.
