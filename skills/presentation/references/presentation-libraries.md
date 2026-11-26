# Presentation Library Reference

## When to Use Each Approach

| Approach | Use when |
|----------|----------|
| **Deck starter** (`assets/deck-starter/`) | Default — no dependencies, fast, passes the publish contract; see SKILL.md |
| **Reveal.js** | User needs advanced features: fragments, themes, speaker notes, PDF export |
| **Pure CSS** | Ultra-minimal, user specifies a specific aesthetic |

## Default: Deck Starter

Fill only `index.html` from `assets/deck-starter/` and copy `deck.css` and `app.js` unchanged. Add or remove `<section class="slide">` blocks as the outline needs. The starter's navigation, scaling and print rules come with `deck.css` and `app.js`. Theme it with `--deck-*` and `--type-*` tokens on the stage, as SKILL.md describes.

---

## Reveal.js (bundle for deploy_artifact)

Use when the user explicitly asks for fragments, speaker notes, or needs PDF export.

`deploy_artifact` loads only the artifact's own files, so CDN scripts/styles and an inline `Reveal.initialize` are blocked (RUB-444). Publish a bundle: `files` with `index.html`, `reveal.js`, `reveal.css`, the theme CSS and an `init.js` containing `Reveal.initialize({ hash: true });`, loaded with `<script src="init.js"></script>` after `reveal.js`.

```html
<link rel="stylesheet" href="reveal.css">
<link rel="stylesheet" href="theme-white.css">
<script src="reveal.js"></script>
<script src="init.js"></script>
```

Fetch the library files from a pinned Reveal.js release (for example jsDelivr) when building the bundle locally, then include those file contents in `files` — do not leave CDN `<script src>` or `<link href>` tags in the published HTML.

### Minimal Reveal.js structure

```html
<div class="reveal">
  <div class="slides">
    <section>Slide 1</section>
    <section>Slide 2</section>
    <!-- Vertical stacks: -->
    <section>
      <section>2a</section>
      <section>2b</section>
    </section>
  </div>
</div>
```

---

## Pure CSS Approach (no JS)

For a minimal, JS-free presentation use CSS `:target` for navigation:

```html
<style>
  .slide { display: none; }
  .slide:target, .slide:first-child:not(:target ~ .slide) { display: flex; }
</style>

<section class="slide" id="s1">...</section>
<section class="slide" id="s2">...</section>

<nav>
  <a href="#s1">1</a>
  <a href="#s2">2</a>
</nav>
```

Limitation: no keyboard navigation without JS. Only use when the user specifically requests no JavaScript.
