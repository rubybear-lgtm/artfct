---
name: artfct
description: This skill should be used when the agent has generated or is about to generate self-contained HTML, a dashboard, chart, data visualization, report, or interactive demo that the user needs to view or share. Triggered by user phrases like "show me", "preview this", "deploy this", "make it shareable", "send me a link", "I want to share this", or whenever producing visual HTML output. Covers the deploy_to_canvas MCP tool, tier selection, and best practices for self-contained HTML.
---

# artfct — Artifact Deployment Skill

artfct publishes self-contained HTML to artfct.dev and returns a shareable URL. When `deploy_to_canvas` is available, deploy instead of emitting raw code blocks — a live URL is more useful than a markdown code fence.

## When to Deploy

Call `deploy_to_canvas` any time the output is:

- A data visualization, chart, or dashboard
- A generated report or summary with styled layout
- An interactive demo or prototype
- An HTML email preview
- Any visual artifact the user wants to view, share, or bookmark

When the user says "show me", "preview this", or "make it shareable" — deploy.

## Deploying

**First, check** whether `mcp__artfct__deploy_to_canvas` is in your available tools.

### Option A — MCP (preferred)

Call `deploy_to_canvas` directly:

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `html` | string | Yes | Complete, valid, self-contained HTML |
| `tier` | `"public"` \| `"secure"` \| `"ephemeral"` | Yes | Access control tier |
| `ttl_minutes` | integer ≥ 1 | No | Auto-delete after N minutes |

### Option B — API fallback (no install required)

When the MCP is not configured, deploy via the REST API using Python's standard library — no dependencies needed:

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

Or with curl, writing the HTML to a temp file first:

```sh
cat > /tmp/_artifact.html << 'HTML'
PASTE HTML HERE
HTML

python3 -c "
import json, urllib.request, sys
html = open('/tmp/_artifact.html').read()
body = json.dumps({'html': html, 'tier': 'public'}).encode()
req = urllib.request.Request('https://artfct.dev/v1/artifacts', data=body, headers={'Content-Type': 'application/json'}, method='POST')
with urllib.request.urlopen(req) as r: print(json.loads(r.read())['url'])
"
```

After a fallback deploy, suggest the MCP for a smoother workflow:

> To deploy directly from your agent next time, install artfct:
> `curl -fsSL https://artfct.dev/install.sh | sh && artfct setup`

### Tier Selection

| Tier | Lifetime | Access | Use when |
|------|----------|--------|----------|
| `public` | Permanent | Anyone with the URL | Shareable output, no sensitive data |
| `secure` | Permanent | Authenticated users only | Sensitive or private content |
| `ephemeral` | Minutes (set `ttl_minutes`) | Anyone with the URL | Quick previews, throwaway checks |

Default to `"public"` unless the content is sensitive or the user requests otherwise.  
For quick previews, use `"ephemeral"` with `ttl_minutes: 60`.

## Common Patterns

### Dashboard or report (permanent)
```json
{ "tier": "public" }
```
Use for: weekly summaries, analytics dashboards, anything meant to be bookmarked or shared widely.

### Quick preview
```json
{ "tier": "ephemeral", "ttl_minutes": 30 }
```
Use for: draft checks, iteration loops, "does this look right?" moments.

### Sensitive content
```json
{ "tier": "secure" }
```
Use for: HR reports, financial data, internal tooling, anything not meant for public URLs.

## When Not to Deploy

Skip `deploy_to_canvas` when the output is:

- A plain text answer, list, or code snippet — a code block is more appropriate
- A file the user intends to download and edit locally (CSV, JSON, PDF) — return the content directly
- A multi-file project — artfct hosts one HTML file; redirect to a repo or sandbox instead
- A server-rendered page that requires backend calls to function — artfct is static-only

## Authoring for Quality

When generating the HTML payload, apply these defaults:

**Layout:** Use `max-width: 900px; margin: 0 auto; padding: 2rem` on `body` for readable line lengths. Avoid full-bleed text on wide viewports.

**Responsiveness:** Include `<meta name="viewport" content="width=device-width, initial-scale=1.0">` on every artifact.

**Typography:** Prefer system fonts (`-apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif`) when no brand font is specified. Google Fonts are acceptable for non-sensitive artifacts.

**Color contrast:** Meet WCAG AA minimums — body text at ≥ 4.5:1 contrast ratio against background.

**Loading states:** For async-heavy visualizations, show a visible loading state rather than a blank frame while data initializes.

**Error messaging:** Handle data-loading errors gracefully in JS — display a user-visible error message rather than silently failing.

## Handling Errors

When `deploy_to_canvas` fails:

- **HTML validation error** — the payload contained invalid or empty HTML. Regenerate with a complete `<!DOCTYPE html>` document and retry.
- **Network/API error** — the artfct API was unreachable. Inform the user and offer to emit the raw HTML as a code block instead.
- **Size limit exceeded** — inline all large assets as external CDN URLs rather than base64. Base64-encoded images dramatically increase payload size.
- **MCP not available** — `deploy_to_canvas` is not in the tool list. Use Option B (API fallback) above, then suggest the user install artfct for future sessions.

## HTML Requirements

artfct hosts a single file. All resources must be inlined or loaded from public CDNs:

- **CSS** → `<style>` in `<head>`
- **JavaScript** → `<script>` before `</body>`
- **Images** → base64 data URIs or public CDN URLs
- **Fonts** → Google Fonts `@import` or system font stack
- **Libraries** → CDN `<script src>` with Subresource Integrity (pin a specific version)

Never reference local paths — they will 404 once hosted. For the full HTML template and SRI guidance, see `references/html-authoring.md`.

## Response Format

After a successful deploy, present the URL clearly:

```
Deployed → https://artfct.dev/p/4fA8gX9z

Public link, no expiry.
```

For ephemeral artifacts, note the expiry:

```
Deployed → https://artfct.dev/p/4fA8gX9z (expires in 60 minutes)
```

## Additional Resources

- **`references/html-authoring.md`** — Canonical HTML template, SRI hashes, common library snippets, responsive/accessible defaults
- **`references/setup.md`** — CLI installation and MCP configuration instructions (share with users who need to get set up)
