# Presentation Library Reference

## When to Use Each Approach

| Approach | Use when |
|----------|----------|
| **Template** (`assets/presentation-template.html`) | Default — no dependencies, fast, solarized aesthetic matches artfct |
| **Reveal.js** | User needs advanced features: fragments, themes, speaker notes, PDF export |
| **Pure CSS** | Ultra-minimal, user specifies a specific aesthetic |

## Default: Built-in Template

Read `assets/presentation-template.html` as the starting point. Replace all `{{PLACEHOLDER}}` tokens and add/remove `<section class="slide">` blocks as needed. No external libraries required.

### Slide types available

- `.slide-title` — centered title slide with kicker line and subtitle
- `.slide-content` — left-aligned content slide with heading + bullet list or paragraph
- `.slide-content` with `<pre><code>` — code slide

### Adding slides

Copy a `<section class="slide">` block and insert it before the `<!-- Add more slides -->` comment. The JS counter updates automatically.

### Customizing accent color

The top accent bar uses a `linear-gradient` on `.accent-bar`. Change the color stops to match the content's theme:

```css
.accent-bar {
  background: linear-gradient(to right, var(--orange), var(--red), var(--magenta));
}
```

The `h2::after` underline uses `var(--blue)` — change to match:
```css
.slide-content h2::after { background: var(--orange); }
```

### List item prefix

The `li::before` arrow (`→`) and the `h2::after` bar share a color. Override both if customizing:
```css
.slide-content li::before { color: var(--cyan); }
.slide-content h2::after  { background: var(--cyan); }
```

---

## Reveal.js (CDN + SRI)

Use when the user explicitly asks for fragments, speaker notes, or needs PDF export.

```html
<link
  rel="stylesheet"
  href="https://cdn.jsdelivr.net/npm/reveal.js@5.1.0/dist/reveal.css"
  integrity="sha384-..."
  crossorigin="anonymous"
>
<link
  rel="stylesheet"
  href="https://cdn.jsdelivr.net/npm/reveal.js@5.1.0/dist/theme/white.css"
  integrity="sha384-..."
  crossorigin="anonymous"
>
<script
  src="https://cdn.jsdelivr.net/npm/reveal.js@5.1.0/dist/reveal.js"
  integrity="sha384-..."
  crossorigin="anonymous"
></script>
<script>Reveal.initialize({ hash: true });</script>
```

> Always fetch current SRI hashes from `https://data.jsdelivr.com/v1/packages/npm/reveal.js@5.1.0/integrity` before using. The hashes above are placeholders.

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
