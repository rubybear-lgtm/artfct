---
name: presentation
description: This skill should be used when the user asks to "create a presentation", "make slides", "build a slide deck", "turn this into slides", "present this as a slideshow", "make a pitch deck", or wants to share content as a navigable, fullscreen HTML presentation. Covers building self-contained HTML slide decks and deploying them instantly via artfct's deploy_to_canvas tool.
---

# Presentation Skill

Build a self-contained HTML presentation and deploy it to a shareable URL via artfct in one step. The output is a fullscreen, keyboard-navigable slide deck — no PowerPoint, no Keynote, no build step.

## Workflow

1. **Read** `assets/presentation-template.html` — the canonical starting point
2. **Replace** all `{{PLACEHOLDER}}` tokens with real content
3. **Add or remove** `<section class="slide">` blocks to match the outline
4. **Deploy** via `deploy_to_canvas` with `tier: "public"` (permanent) or `tier: "ephemeral"` for draft reviews
5. **Present** the returned URL — it opens fullscreen-ready in any browser

## Slide Structure

Each slide is a `<section class="slide">` element. Two layouts are available:

**Title slide** (`.slide-title`) — centered, used for the opening slide only:
```html
<section class="slide slide-title active">
  <p class="kicker">TOPIC · DATE</p>
  <h1>Presentation Title</h1>
  <p class="subtitle">Tagline or speaker name</p>
</section>
```

**Content slide** (`.slide-content`) — left-aligned, used for all other slides:
```html
<section class="slide slide-content">
  <h2>Slide Heading</h2>
  <ul>
    <li>Key point one</li>
    <li>Key point two</li>
    <li>Key point three</li>
  </ul>
</section>
```

Also available in `.slide-content`: `<p>` for prose, `<pre><code>` for code blocks.

The first `.slide` must have `class="slide slide-title active"`. All others omit `active`.

## Navigation

The template ships with keyboard, button, and touch navigation — no changes needed:

| Input | Action |
|-------|--------|
| `→` / `Space` / `↓` | Next slide |
| `←` / `↑` | Previous slide |
| `Home` / `End` | First / last slide |
| Touch swipe | Next / previous |
| On-screen `←` `→` buttons | Next / previous |

The slide counter (`1 / N`) updates automatically when slides are added or removed.

## Deployment

**First, check** whether `mcp__artfct__deploy_to_canvas` is in your available tools.

### Option A — MCP (preferred)

```json
{ "html": "<!DOCTYPE html>...", "tier": "public" }
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

> To deploy presentations directly from your agent next time, install artfct:
> `curl -fsSL https://artfct.dev/install.sh | sh && artfct setup`

**Tier guide:**
- `"public"` — permanent link, shareable with anyone. Use for finished decks.
- `"ephemeral"` with `ttl_minutes: 60` — use for draft reviews and iteration.
- `"secure"` — permanent, requires auth. Use for internal or sensitive content.

After a successful deploy, present the URL clearly:

```
Deployed → https://artfct.dev/p/4fA8gX9z

Open in any browser — fullscreen with F11, navigate with arrow keys.
```

## Authoring Guidelines

**Slide count:** 5–15 slides is typical. Fewer is better — each slide should make one point.

**Bullet points:** 3–5 per slide maximum. If there are more, split into two slides.

**Code slides:** Use `<pre><code>` inside `.slide-content`. Font size scales with viewport — keep code blocks to ~10 lines.

**Images:** Inline as base64 data URIs or load from public CDN URLs. Do not reference local paths.

**Fonts:** The template uses the system sans-serif stack by default. To add Google Fonts, add a `<link>` in `<head>` — no SRI required for Google Fonts served from `fonts.googleapis.com`.

**Color customization:** The accent bar and heading underlines use Solarized palette CSS variables (`--blue`, `--cyan`, `--orange`, etc.). Override in the `<style>` block if the user requests a different theme.

## When Not to Use This Skill

- User wants a PDF — build a print-optimized HTML page with `@media print` styles instead, or note that `Ctrl+P → Save as PDF` works from the deployed URL
- User wants Keynote/PowerPoint format — artfct only hosts HTML; suggest they copy the outline into their preferred tool
- User wants speaker notes — use Reveal.js instead (see `references/presentation-libraries.md`)
- User wants slide animations/fragments — use Reveal.js instead

## Additional Resources

- **`assets/presentation-template.html`** — Complete working template; read this and modify rather than writing from scratch
- **`references/presentation-libraries.md`** — Reveal.js CDN setup, pure-CSS approach, customization patterns
