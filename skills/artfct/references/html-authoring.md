# Self-Contained HTML Reference

## Permanent artifacts: a bundle with its own files

`deploy_artifact` serves each artifact from its own isolated origin with `default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'`. Only the artifact's own files load. An inline `<script>` block, an `onclick=` attribute and any script, image or font from another site are blocked, and so are base64 `data:` images and fonts. The page still shows, just without them, and nothing on the page says why. Inline `<style>` and `style=` attributes work.

For anything interactive, or with images or fonts, pass `files` and reference each by a relative path:

```
index.html      <script src="app.js"></script>, <img src="hero.png">
app.js          all application JavaScript
hero.png        images and fonts as files, not data: URIs
vendor/chart.umd.min.js   any library, vendored into the bundle
```

A page with no script, no images and no outside fonts can be a single `html` string. Checked on a live staging artifact on 2026-10-18: an inline script, a base64 image, an image from another site and a Google Fonts stylesheet were all blocked. See RUB-444.

## Single-file form (older, shared-origin preview)

Everything below describes one HTML file that inlines its resources or loads public CDNs. That form is served from the shared origin used by `deploy_to_canvas` (deprecated). Do not use it for `deploy_artifact`: its inline scripts, CDN libraries, Google Fonts and `data:` images are blocked there.

## Inlining Rules

| Resource | How to inline |
|----------|--------------|
| CSS | `<style>` block in `<head>` |
| JavaScript | `<script>` block before `</body>` |
| Images | Base64 data URIs (`data:image/png;base64,...`) or public CDN URLs |
| Fonts | Google Fonts `@import` or a system font stack |
| Libraries | CDN `<script src>` with Subresource Integrity (see below) |

Never reference local paths (`./style.css`, `../img/logo.png`) — they will 404 once hosted.

## Canonical HTML Template

```html
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Artifact Title</title>
  <!-- Google Fonts (no SRI needed — served over HTTPS from Google) -->
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600&display=swap" rel="stylesheet">
  <style>
    /* All styles here */
    body { font-family: 'Inter', sans-serif; margin: 0; padding: 2rem; }
  </style>
</head>
<body>

  <!-- Content -->

  <!-- CDN libraries with SRI (see below for how to get hashes) -->
  <script
    src="https://cdn.jsdelivr.net/npm/chart.js@4.4.9/dist/chart.umd.min.js"
    integrity="sha384-rvSzRCMGEfROMCJBQv/7SqRg/b3PpGXRk3nrKkHFy3mqlm7iIXOazd8usb+N5Xh"
    crossorigin="anonymous"
  ></script>
  <script>
    // All application JS here
  </script>
</body>
</html>
```

## Subresource Integrity (SRI)

Always pin a specific version and add `integrity` + `crossorigin="anonymous"` to CDN `<script>` and `<link>` tags. This protects against CDN compromise.

**Getting hashes — two methods:**

**1. jsDelivr integrity API** (preferred — returns the hash directly):
```
https://data.jsdelivr.com/v1/packages/npm/<pkg>@<version>/integrity
```
Example: `https://data.jsdelivr.com/v1/packages/npm/chart.js@4.4.9/integrity`

**2. jsDelivr package page:**  
Navigate to `https://www.jsdelivr.com/package/npm/<pkg>`, select the version, click a file — the SRI hash is shown in the panel.

## Common Library CDN Snippets

### Chart.js 4
```html
<script
  src="https://cdn.jsdelivr.net/npm/chart.js@4.4.9/dist/chart.umd.min.js"
  integrity="sha384-rvSzRCMGEfROMCJBQv/7SqRg/b3PpGXRk3nrKkHFy3mqlm7iIXOazd8usb+N5Xh"
  crossorigin="anonymous"
></script>
```

### Alpine.js 3
```html
<script
  src="https://cdn.jsdelivr.net/npm/alpinejs@3.14.9/dist/cdn.min.js"
  integrity="sha384-..."
  crossorigin="anonymous"
  defer
></script>
```

> Always fetch the current hash from the jsDelivr integrity API before using — hashes change with versions.

## Responsive & Accessible Defaults

Include these in every artifact:

```html
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="color-scheme" content="light dark">
```

For dark mode support:
```css
@media (prefers-color-scheme: dark) {
  body { background: #1a1a1a; color: #e0e0e0; }
}
```
