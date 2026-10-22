---
name: presentation
description: This skill should be used when the user asks to "create a presentation", "make slides", "build a slide deck", "turn this into slides", "present this as a slideshow", "make a pitch deck", or wants to share content as a navigable, fullscreen HTML presentation. Covers building self-contained HTML slide decks and deploying them via artfct's deploy_artifact tool.
---

# Presentation Skill

Build a self-contained HTML presentation and deploy it to a shareable URL via artfct in one step. The output is a fullscreen, keyboard-navigable slide deck — no PowerPoint, no Keynote, no build step.

## Workflow

1. **Read** `assets/presentation-template.html` — the canonical starting point
2. **Replace** all `{{PLACEHOLDER}}` tokens with real content
3. **Add or remove** `<section class="slide">` blocks to match the outline
4. **Deploy** via `deploy_artifact` as a bundle (see Deployment) with `sharing: "team"` by default, or `"public"` when the user wants a link anyone can open
5. **Present** the returned `view_url` — it opens fullscreen-ready in any browser

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

**First, check** whether `deploy_artifact` (or `mcp__artfct__deploy_artifact`) is in your available tools. If it is not, ask the user to connect the artfct MCP server rather than inventing a fallback publish path.

Permanent artifacts load only their own files (inline `<script>` blocks and outside images are blocked — RUB-444). Publish the deck as a **bundle**:

1. Put the filled HTML in `index.html` and move its navigation `<script>` body into `app.js`, loaded with `<script src="app.js"></script>`
2. Put any images in the bundle and reference them with relative paths
3. Call `deploy_artifact` with `files`, `entrypoint: "index.html"`, a short `title` and `description`, and the chosen `sharing`

```json
{
  "files": { "index.html": "...", "app.js": "..." },
  "entrypoint": "index.html",
  "title": "Q3 product review",
  "description": "Fullscreen slide deck for the quarterly product review.",
  "sharing": "team",
  "artifact_type": "slide_deck"
}
```

**Sharing guide:**
- `"team"` (default) — signed-in teammates. Use for drafts and internal decks.
- `"private"` — only the user and team admins.
- `"public"` — anyone with the link. Use only when the user asks for an external share.

To update a deck you already published, pass `artifact_id` and `base_version` from `get_artifact` or `read_artifact`.

After a successful deploy, present the `view_url` clearly:

```
Deployed → https://artfct.dev/a/4fA8gX9z

Open in any browser — fullscreen with F11, navigate with arrow keys.
```

## Authoring Guidelines

**Slide count:** 5–15 slides is typical. Fewer is better — each slide should make one point.

**Bullet points:** 3–5 per slide maximum. If there are more, split into two slides.

**Code slides:** Use `<pre><code>` inside `.slide-content`. Font size scales with viewport — keep code blocks to ~10 lines.

**Images:** Put image files in the `files` bundle and reference them with relative paths. Base64 `data:` images and images from other sites are blocked (RUB-444).

**Fonts:** The template uses the system sans-serif stack by default. Outside web fonts (including Google Fonts) are blocked — keep the system stack, or add font files to the bundle.

**Color customization:** The accent bar and heading underlines use Solarized palette CSS variables (`--blue`, `--cyan`, `--orange`, etc.). Override in the `<style>` block if the user requests a different theme.

## When Not to Use This Skill

- User wants a PDF — build a print-optimized HTML page with `@media print` styles instead, or note that `Ctrl+P → Save as PDF` works from the deployed URL
- User wants Keynote/PowerPoint format — artfct only hosts HTML; suggest they copy the outline into their preferred tool
- User wants speaker notes — use Reveal.js instead (see `references/presentation-libraries.md`)
- User wants slide animations/fragments — use Reveal.js instead

## Additional Resources

- **`assets/presentation-template.html`** — Complete working template; read this and modify rather than writing from scratch
- **`references/presentation-libraries.md`** — Reveal.js bundle setup, pure-CSS approach, customization patterns
