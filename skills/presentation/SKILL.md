---
name: presentation
description: This skill should be used when the user asks to "create a presentation", "make slides", "build a slide deck", "turn this into slides", "present this as a slideshow", "make a pitch deck", or wants to share content as a navigable, fullscreen HTML presentation. Covers building self-contained HTML slide decks and deploying them via artfct's deploy_artifact tool.
---

# Presentation Skill

Turn a win, a talk track or a decision into a short slide deck, and deploy it to a shareable URL in one step. The output is a fullscreen, keyboard-navigable `slide_deck` with a talk track for each slide. No PowerPoint, no Keynote, no build step.

## Workflow

1. **Outline.** Draft the slide titles, each with a one-line point. Show the outline to the person and wait for their confirmation before building. Skip the confirmation only when they said to just build it.
2. **Find an existing deck.** Call `search_artifacts` with the deck's title. If a deck with the same title exists, read it with `get_artifact` for its `base_version` and update that deck (step 4) instead of creating a new one.
3. **Fill the starter.** Copy `assets/deck-starter/index.html`, `assets/deck-starter/deck.css` and `assets/deck-starter/app.js` unchanged. Edit only `index.html`: one `<section class="slide">` per slide, the title in an `<h1>` (first slide) or `<h2>` (the rest), the body, and a talk track in `<p class="notes">`. Keep 5 to 15 slides, one point each.
4. **Deploy** with `deploy_artifact` as a bundle (see Deployment), with `sharing: "team"` by default or `"public"` when the user wants a link anyone can open.
5. **Check it can be found.** Run one findability search: `search_artifacts` with the deck's title. Confirm the deck comes back.
6. **Present** the returned `view_url`.

## Slide Writing

**Title grammar.** Pick one title grammar for the whole deck: short topic noun-phrases ("Q3 pipeline", "Pricing options"), or short action sentences ("We cut churn by a third"). Keep every title in it, so the titles alone tell the story.

**Keep to one idea per slide.** Each slide makes one point, with 3 to 5 short bullets at most. Split a slide that needs two points.

**No punchline titles.** Do not write titles such as "The magic moment", "It's not X, it's Y", or a forced tension or reframing. State the point plainly.

**Type at projection scale.** Text is sized for a room, not a web page. The starter's `--type-title`, `--type-heading`, `--type-body` and `--type-kicker` set the sizes. To change a size, set the token on the stage with a `style` attribute, for example `style="--type-body: calc(var(--deck-unit) * 48)"`. A request for "36pt" means points: convert at 1pt = 1.333px, so 36pt is 48 stage units.

**Images.** For a photo that fills its box, use `style="object-fit: cover"` on the `<img>`. For a screenshot or a diagram, use `style="object-fit: contain"` with a contrasting `background` on its box, so none of it is cropped.

**Parallel slides.** Section headers and repeated rows share identical markup and position, so moving between them does not jump.

**Literal static markup.** Write the slide content as literal HTML: each piece of text in its own leaf element, and repeated structure written out (three bullets are three `<li>` elements, never one generated from an array). A static slide can be edited word by word in place; a script-built one cannot. Do not add script to build slides.

**Plain wording.** Use the words the reader uses. Avoid filler openers, stacked adjectives and hype words such as "unlock", "seamless" and "game-changing".

**Theming.** Set the `--deck-*` colour tokens on the stage with one `style` attribute, for example `style="--deck-accent: #7a2e2e"` on `<main class="deck-stage">`. The tokens are `--deck-ink`, `--deck-muted`, `--deck-paper`, `--deck-ground`, `--deck-accent`, `--deck-rule` and `--deck-font`. Never edit `deck.css` or `app.js` to restyle a deck.

**Talk track.** Each slide's `<p class="notes">` holds the two or three sentences the presenter says for that slide. The notes stay hidden on stage and are indexed.

## Deployment

**First, check** whether `deploy_artifact` (or `mcp__artfct__deploy_artifact`) is in your available tools. If it is not, ask the user to connect the artfct MCP server rather than inventing a fallback publish path.

Permanent artifacts load only their own files: inline `<script>` blocks and outside images are blocked (RUB-444). Deploy the deck as a bundle with the three starter files:

```json
{
  "files": {
    "index.html": "<the filled index.html>",
    "deck.css": "<assets/deck-starter/deck.css, unchanged>",
    "app.js": "<assets/deck-starter/app.js, unchanged>"
  },
  "entrypoint": "index.html",
  "title": "Q3 product review",
  "description": "Fullscreen slide deck for the quarterly product review.",
  "sharing": "team",
  "artifact_type": "slide_deck"
}
```

Images go in the bundle too, referenced by relative path. Base64 `data:` images and images from other sites are blocked.

To update a deck you already published, pass `artifact_id` and `base_version` from `get_artifact` or `read_artifact`.

**Sharing guide:**
- `"team"` (default): signed-in teammates. Use for drafts and internal decks.
- `"private"`: only the user and team admins.
- `"public"`: anyone with the link. Use only when the user asks for an external share.

After a successful deploy, present the `view_url` clearly:

```
Deployed → https://artfct.dev/a/4fA8gX9z

Open in any browser — fullscreen with F11, navigate with arrow keys.
```

## Navigation

The starter ships with keyboard, button and touch navigation. No changes are needed.

| Input | Action |
|-------|--------|
| `→` / `Space` / `↓` | Next slide |
| `←` / `↑` | Previous slide |
| `Home` / `End` | First / last slide |
| Touch swipe | Next / previous |
| On-screen `←` `→` buttons | Next / previous |

The counter (`1 / N`) updates when slides are added or removed. A deep link such as `#3` opens slide 3.

## When Not to Use This Skill

- User wants a PDF — build a print-optimized HTML page with `@media print` styles instead, or note that `Ctrl+P → Save as PDF` works from the deployed URL
- User wants Keynote/PowerPoint format — artfct only hosts HTML; suggest they copy the outline into their preferred tool
- User wants a speaker view or a timed presenter screen — not in the starter. The talk track sits in each slide's notes, so the presenter can read it from their own notes.
- User wants slide animations/fragments — use Reveal.js instead (see `references/presentation-libraries.md`)

## Additional Resources

- **`assets/deck-starter/`** — the official deck starter: `index.html` (the only file you fill), `deck.css` and `app.js` (chrome; copy unchanged)
- **`references/presentation-libraries.md`** — Reveal.js bundle setup for animations and fragments
