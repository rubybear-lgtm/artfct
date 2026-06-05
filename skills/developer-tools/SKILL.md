---
name: developer-tools
description: This skill should be used when the user asks to "compare two JSON responses", "diff these .env files", "test a regex", "show this data as a table", "visualize this CSV", "view this JSON as a table", "which env vars changed", "find matches for this pattern", or any request to compare, inspect, or visualize developer data. Deploys interactive HTML tools via artfct's deploy_to_canvas.
---

# Developer Tools Skill

Build and deploy interactive developer utilities as shareable HTML tools via artfct. Each tool is self-contained, uses the Solarized palette, and requires no backend.

## Available Tools

| Tool | Asset | Trigger phrases |
|------|-------|-----------------|
| JSON / CSV table | `assets/json-table.html` | "show as table", "visualize this data", "CSV viewer" |
| API diff | `assets/api-diff.html` | "diff these responses", "what changed between", "compare JSON" |
| ENV diff | `assets/env-diff.html` | "diff .env files", "which vars changed", "compare environments" |
| Regex tester | `assets/regex-tester.html` | "test this regex", "does this pattern match", "regex for X" |

## Workflow

1. **Identify** which tool fits the request (table above)
2. **Read** the corresponding asset file from `assets/`
3. **Replace** `{{PLACEHOLDER}}` tokens with context-specific values
4. **Optionally pre-populate** data if the user provided it inline
5. **Deploy** via MCP or API fallback (see Deployment section) — default tier `"public"`, use `"ephemeral"` for quick reviews

## Placeholder Tokens

All tools share these tokens:

| Token | Purpose | Example |
|-------|---------|---------|
| `{{TITLE}}` | Page title and header label | `"Prod vs Staging API"` |
| `{{LABEL_LEFT}}` | Left pane label (diff tools) | `"production"` |
| `{{LABEL_RIGHT}}` | Right pane label (diff tools) | `"staging"` |
| `{{PATTERN}}` | Pre-filled regex (regex tester) | `"\\d{4}-\\d{2}-\\d{2}"` |
| `{{TEST_STRING}}` | Pre-filled test text (regex tester) | `"Order placed 2026-06-04"` |
| `{{DATA}}` | Pre-filled JSON/CSV (table viewer) | JSON array string |

When the user provides data inline (e.g., pastes a JSON array into the conversation), pre-populate `{{DATA}}` so the tool loads ready to use. Otherwise leave the field empty — the tool has a paste area.

## Deployment

**First, check** whether `mcp__artfct__deploy_to_canvas` is in your available tools.

### Option A — MCP (preferred)

```json
{ "html": "...", "tier": "public" }
```

### Option B — API fallback (no install required)

```python
import json, urllib.request

html = """PASTE HTML HERE"""
body = json.dumps({"html": html, "tier": "public"}).encode()
req = urllib.request.Request(
    "https://artfct.dev/v1/artifacts",
    data=body,
    headers={"Content-Type": "application/json"},
    method="POST",
)
with urllib.request.urlopen(req) as r:
    print(json.loads(r.read())["url"])
```

After a fallback deploy, suggest the MCP:

> To deploy tools directly from your agent next time, install artfct:
> `curl -fsSL https://artfct.dev/install.sh | sh && artfct setup`

Present the result with a one-line description of what the tool does:

```
Deployed → https://artfct.dev/p/4fA8gX9z

JSON table — paste your data and click parse →
```

## Tool Behaviours

**json-table.html**
- Auto-detects JSON array of objects or CSV
- Columns are sortable (click header), rows are filterable (search box)
- Click any cell to copy its value
- Pre-populate `{{DATA}}` with a JSON array string if data is available

**api-diff.html**
- Deep recursive JSON diff — detects added, removed, and changed keys at any nesting level
- Groups changes by path
- Swap button reverses left/right
- Set `{{LABEL_LEFT}}` / `{{LABEL_RIGHT}}` to describe the two sides (e.g., `"v1"` / `"v2"`, `"prod"` / `"staging"`)

**env-diff.html**
- Parses `KEY=VALUE` pairs; ignores comments (`#`) and blank lines; strips quotes
- Values are redacted by default — user can toggle to reveal
- Toggle to hide unchanged keys for a cleaner view
- Set `{{LABEL_LEFT}}` / `{{LABEL_RIGHT}}` to describe the environments

**regex-tester.html**
- Updates live as the user types
- Flags: `g` (always on), `i`, `m`, `s` — toggleable buttons
- Shows match index, length, and capture groups per match
- Highlights matches in the test string
- Pre-populate `{{PATTERN}}` and `{{TEST_STRING}}` when the user provides them

## Additional Resources

- **`references/customization.md`** — how to restyle tools, add columns, modify the diff algorithm, and other common customizations
