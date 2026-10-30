---
name: developer-tools
description: This skill should be used when the user asks to "compare two JSON responses", "diff these .env files", "test a regex", "show this data as a table", "visualize this CSV", "view this JSON as a table", "which env vars changed", "find matches for this pattern", or any request to compare, inspect, or visualize developer data. Deploys interactive HTML tools via artfct's deploy_artifact.
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
5. **Deploy** with `deploy_artifact` (see Deployment) — default `sharing: "team"`; use `"public"` only when the user asks for a link anyone can open

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

**First, check** whether `deploy_artifact` (or `mcp__artfct__deploy_artifact`) is in your available tools. If it is not, ask the user to connect the artfct MCP server rather than inventing a fallback publish path.

These tools include interactive JavaScript, so publish them as a **bundle** (inline `<script>` blocks do not run on permanent artifacts — see the `artfct` skill, RUB-444):

1. Put the filled HTML in `index.html` and move its `<script>` body into `app.js`, loaded with `<script src="app.js"></script>`
2. Call `deploy_artifact` with `files`, `entrypoint: "index.html"`, a short `title` and `description`, and `sharing: "team"` (or `"public"` when asked)

```json
{
  "files": { "index.html": "...", "app.js": "..." },
  "entrypoint": "index.html",
  "title": "Prod vs Staging API",
  "description": "Side-by-side JSON diff of the two responses.",
  "sharing": "team"
}
```

To update a tool you already published, pass `artifact_id` and `base_version` from `get_artifact` or `read_artifact`.

Present the result with a one-line description of what the tool does:

```
Deployed → https://artfct.dev/a/4fA8gX9z

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
