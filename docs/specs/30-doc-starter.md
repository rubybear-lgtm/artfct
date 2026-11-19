# Spec 30: Official doc-page HTML starter (RUB-557)

**Track:** Official artifact types (RUB-551 epic) · **Depends on:** 26 (publish contract validator, RUB-574), 27 (first-class types) · **Blocks:** RUB-561 (PDF export), RUB-565 (flier), RUB-554 (team-doc skill) · **Related:** RUB-444, RUB-445, RUB-558 (deck starter, same bridge envelope)

This page is both the spec and the plan. It is one combined page because the issue is size M.

## What and why

A bot that publishes a memo, one-pager or flier should start from an official Artfct doc starter. The starter already owns the paper geometry and already passes the publish contract, so the bot fills content instead of building a page box each time.

The starter has two jobs beyond the page itself:

1. **Print geometry is fixed here.** PDF export (RUB-561) relies on the sheet size, margins and print discipline defined in this spec.
2. **An export bridge.** `export.js` answers a `postMessage` protocol so the export runner can read the page's structure. The bridge is defined here because RUB-561 and RUB-558 both consume it.

Out of scope: the team-doc skill text (RUB-554), PDF export itself (RUB-561), flier and email variants (RUB-565), brand overrides (RUB-556 and RUB-568).

## Decisions this spec applies (epic RUB-551)

- **5.** One shared contract checker: `PublishContractValidator` (RUB-574). Every starter test uses it.
- **6.** Starters live in skill folders. Chrome lives in `.css` and `.js` files. Author content lives only in `index.html`. Upgrading means replacing chrome files, never content.
- **7.** "Renders on staging" means a browser check: the page shows, the behaviour works, and the console has zero CSP errors. Passing publish validation is not enough, because blocked content renders silently.
- **9.** Export never runs artifact script in the app origin. The starter's script runs only inside its own isolated artifact origin. The bridge answers `window.parent` only, after a handshake. Peers are identified by `event.source`. The bridge never trusts an origin that the sender names. Replies go to the origin the browser reports for the incoming message, never to `'*'`.

## Layout

```
skills/artfct-team-doc/assets/doc-starter/
  index.html   author content only (plus the one script and one stylesheet tag)
  doc.css      all chrome: paper, print rules, type, colours
  export.js    export bridge; no network requests; no effect on reading the page
```

Layout conventions follow `skills/artfct-context-post/assets/post-starter/` (RUB-559): one folder with the starter files and no new top-level folder.

- `skills/artfct-team-doc/` is created here with assets only. RUB-554 adds its `SKILL.md` and the `team-doc` guidance topic.
- `skills/artfct-team-doc` is not in `PLUGIN_SKILLS` in `scripts/plugin/sync-skills.mjs`, so `npm run test:plugin` cannot be broken by its absence or presence. The folder must still not break `npm run test:plugin`; that is run and recorded.

### index.html

- Standard doctype, `lang`, charset and viewport meta.
- `<meta name="artfct-starter" content="doc@1">`.
- A top HTML comment that names the content slots (title, byline, body, footer) and the upgrade rule: replace `doc.css` only, never `index.html`.
- `<link rel="stylesheet" href="doc.css">` (relative).
- The only script tag is `<script src="export.js" defer></script>`. No inline `<script>`, no `on*=`, no `data:` URI, no external origin, no inline `<style>`.
- Slots: `<main class="sheet">` containing `<header class="doc-title">`, `<p class="doc-byline">`, `<article class="doc-body">` and `<footer class="doc-footer">`.
- A4 is selected by the class `sheet-a4` on `<html>`. A fixed one-page sheet adds `sheet--fixed` to `<main>`.

### doc.css

- Colours, fonts and spacing are `--doc-*` custom properties on `:root`. Neutral default palette. No Solarized. The brand rule (RUB-556) overrides these properties.
- Fonts are the system stack. No font files, no `@font-face`, no external fonts.
- **One geometry source.** `--doc-page-margin` (default 0.75in) feeds both the `@page` margin and the on-screen sheet padding. In print the sheet padding is zero, so the margin is not doubled.
- `@page { size: Letter; margin: var(--doc-page-margin) }` by default.
- **A4.** A class on `<html>` cannot change `@page size` through a selector. Mechanism: two named pages, `@page doc-letter { size: Letter }` and `@page doc-a4 { size: A4 }`. `.sheet { page: doc-letter }` by default and `html.sheet-a4 .sheet { page: doc-a4 }`. Browser support for the `page` property is a browser check (see Open questions).
- On screen `.sheet` is 8.5in wide and at least 11in tall; `html.sheet-a4 .sheet` is 210mm by 297mm. Under `@media print` the sheet has no shadow and fills the page.
- **Fixed sheet.** `.sheet--fixed` on screen is exactly one Letter sheet (height 11in, padding = margin, `overflow: hidden`). In print its height is the printable height (`11in - 2 × margin`, or `297mm - 2 × margin` for A4), with zero padding and `overflow: hidden`. A one-page sample then prints on exactly one page. Overflow is clipped, never reflowed.
- **Flowing print discipline** (needed by RUB-561):
  - Multi-column text uses `column-count` and `column-gap`. Spanning headings use `column-span: all`. No side-by-side flex or grid columns.
  - Long tables carry `<thead>`, so headers repeat on each printed page.
  - `break-before: page` on chapter or appendix starts.
  - `break-inside: avoid` on callouts, cards, figures and code blocks. Each must be shorter than a page; a comment in the CSS says so.
  - `orphans: 3; widows: 3`.
  - No `position: fixed` and no viewport units (`vh`, `vw`, `dvh`, `svh`, `lvh`) in content.

## Export bridge contract

### Who is who

- The **artifact page** (the starter, `export.js`) runs at its isolated artifact origin.
- The **runner** is the app page (the RUB-561 or RUB-558 export code). It embeds the artifact in an iframe; the iframe's `contentWindow` is the artifact page's `window.parent`.
- The Worker's `frame-ancestors` header is the enforcement that only the app origin can frame the artifact (`app_frame_ancestor` and `isolated_content_security_policy_framed_by` in `backend/src/artifact_origin.rs`). The bridge does not need to know the app origin.

### Rules

1. **Parent-only.** The bridge acts only on messages where `event.source === window.parent`. Any other source (sibling frame, child frame, opener) is ignored, with no reply. This is what "undeclared origin" means in this spec. There is no origin allowlist.
2. **Handshake before anything.** Only the `ready` command is accepted before a successful handshake. Every other command sent earlier is dropped silently, with no reply and no error.
3. **Origin never taken from the payload.** `ready` does not record any origin named in the message body. The handshake records only that `event.source` is `window.parent`.
4. **Reply target is the browser-reported parent origin.** Every reply is sent with `event.source.postMessage(reply, event.origin)`, where `event.origin` is the origin the browser reports for the incoming message. The reply never uses `'*'`. If `event.origin` is not an `http(s)` origin (for example `"null"`), the message is ignored, because `postMessage` rejects it as a target and no safe reply exists.
5. **Inert when unframed.** If `window.parent === window`, the bridge registers nothing that responds.
6. **No DOM access outside the page.** The bridge never touches `parent.document`, never reads `document.cookie`, `localStorage` or `location` for export, and makes no network request (`fetch`, `XMLHttpRequest`, `sendBeacon`, `WebSocket`, `EventSource`, dynamic `import()`).

### Envelope

Requests from the parent:

```json
{ "channel": "artfct-export", "v": 1, "id": "<caller-chosen string>", "cmd": "ready" | "describe" }
```

Replies from the bridge:

```json
{ "channel": "artfct-export", "v": 1, "id": "<echoed>", "ok": true,  "result": { } }
{ "channel": "artfct-export", "v": 1, "id": "<echoed>", "ok": false, "error": { "code": "unknown_command" } }
```

- `v` is the protocol version. A message with `v` other than `1` is ignored. Later commands (PDF, PowerPoint) extend the commands list in `ready`, not the envelope.
- `id` correlates request and reply. The bridge echoes it and does not interpret it. A missing or non-string `id` is ignored.
- Anything that is not an object with `channel === "artfct-export"` is ignored with no reply.

### Commands

- **`ready`** returns `result: { starter: "doc@1", protocol: 1, commands: ["ready", "describe"] }` and sets the handshaken flag.
- **`describe`** (after handshake) returns `result: { pages: [...] }`. For each printed page:
  - `index`, `width_pt`, `height_pt`;
  - `runs`: `{ text, x, y, w, h, font_family, font_size_pt, color }` in page points;
  - `images`: `{ src, x, y, w, h }` where `src` is a bundle-relative path (never a `data:` URI or absolute URL);
  - `breaks`: the page-break positions the bridge computed.
- `describe` reads layout only. It never writes to the page.
- Any other command after handshake returns `ok: false`, `error.code: "unknown_command"`.

### What describe can and cannot compute

Script cannot observe where the print engine breaks pages. `describe` computes breaks by slicing the laid-out content at the printable height. These are predicted breaks, not observed ones (see Open questions).

### Artifact data

The starter makes no data calls: `export.js` does no fetch or XHR, and the starter declares no `db`. The reserved `__artfct/` prefix is never used by the starter.

## Edge cases and refusals

| Case | Expected |
|---|---|
| Inline `<script>` in `index.html` | Validator error (inline script) |
| `onclick=` in `index.html` | Validator error (`on*=` handler) |
| `data:` image in `index.html` | Validator error (`data:` URI) |
| External font URL in `doc.css` (`@import` or `url()`) | Validator error (external origin) |
| `index.html` missing the `doc@1` meta | Starter test fails |
| Relative reference that does not resolve inside the bundle | Validator error (unresolved reference) |
| Message from a sibling or child frame, valid envelope | Ignored, no reply |
| `describe` before `ready` | Ignored, no reply |
| Non-parent `event.source` sends `ready` | Ignored; no handshake |
| `ready` with a payload naming an origin | Payload origin ignored; reply still goes to `event.origin` |
| Parent `event.origin` is `"null"` | Ignored, no reply |
| `window.parent === window` | Inert |
| Unknown command after handshake | `ok: false`, `unknown_command` |
| Message with `v` other than 1, or no `id` | Ignored |
| Fixed sheet with too much content | Clipped, never reflowed; prints as one page |
| Flowing sheet with a long table | Header row repeats on each printed page (`thead`) |

Refusal boundary: the bridge refuses by ignoring, not by replying with an error, for every case a sender could use to probe it. An error reply goes only to a parent that has completed the handshake.

## Done-when to test map

Each RUB-557 item with the test that proves it. Results were recorded by running the tests on this branch. Guard breaks were run as described in the Status column.

| Done when (RUB-557) | Proof | Status |
|---|---|---|
| `index.html` and `doc.css` exist; no inline script, no `on*=`, no `data:`, no external origin; only script is `export.js`; links `doc.css` relatively | `DocStarterTest` · `the doc starter has its three files`, `the doc starter passes the publish contract`, `index.html has one script tag, export.js, loaded with defer`, `every relative reference in the doc starter resolves inside the bundle` | passed (14/14 file) |
| `index.html` carries `doc@1` meta and a top comment naming the slots and upgrade rule | `DocStarterTest` · `index.html carries the doc@1 meta and the slot and upgrade comment` | passed |
| `@page` Letter with margin; `.sheet` 8.5in by at least 11in; print no shadow and fills page; `sheet-a4` 210mm by 297mm; `--doc-*` tokens on `:root`; system fonts | `DocStarterTest` · `the doc starter stylesheet declares Letter paper and print geometry`, `the doc starter uses no external fonts and no viewport units or fixed positioning` | passed (CSS text checks only; computed layout not measured) |
| Print discipline: real columns, `column-span: all`, `thead`, `break-before: page`, `break-inside: avoid`, `orphans`/`widows` 3, no `position: fixed` or viewport units | `DocStarterTest` · `the doc starter stylesheet carries the flowing print discipline` | passed (text checks only) |
| Both page shapes: flowing and `.sheet--fixed` | `DocStarterTest` checks the `.sheet--fixed` rules | partly: rules present; print pagination not run |
| Bridge: `ready` handshake works cross-origin; `describe` returns runs; undeclared-origin and pre-handshake messages ignored | `DocStarterBrowserTest` · `doc starter handshake works from a different origin`, `doc starter describe returns the fixture runs`, `doc starter ignores undeclared-origin and pre-handshake messages` | passed (4/4 file) |
| Replies go to the browser-reported parent origin, never `'*'` | `DocStarterTest` · `export.js replies to the parent origin and never with a wildcard target` | passed; guard broken (`'*'`): one test failed (13/14); the browser test does not catch this, the string test does |
| Bridge makes no network request and never touches the parent document | `DocStarterTest` · `export.js makes no network request and never touches the parent document` | passed |
| Starter script runs under the artifact CSP (no blocked script) | `DocStarterBrowserTest` · `doc starter script runs under the artifact CSP` | passed; a blocked script would fail the handshake. Console CSP-error capture is not wired, so "zero CSP console errors" is not literally checked |
| Four violating copies refused with the matching error | `DocStarterTest` · `the publish contract refuses violating copies of the doc starter` (inline script, `onclick=`, `data:` image, external font URL) | passed; each validator guard broken in turn: one test failed (13/14), restored |
| Flowing and fixed fixtures print one or two pages | Not built | not verified: no print-emulation test |
| Independent review focused on origin isolation | Process check: fresh reviewer, recorded in the PR | pending |
| Staging render check (`deploy_artifact`, `files`, `entrypoint: index.html`, `artifact_type: document`) | After merge, on staging | not verified here |
| `npm run test:plugin` passes | `npm run test:plugin`: pass 3, fail 0 | passed |
| PR screenshot or `No UI changes`; `vendor/bin/pint --dirty --format agent` clean | Pint passed on each commit. Screenshot: not taken (no print or desktop capture was run) | partly |
| CI green and approved; no PR preview environments | Process check after push | pending |

## Open questions

1. **Spec number.** Spec 26 is the validator, 27 first-class types, 29 post data schema. This spec is 30.
2. **Deck starter (RUB-558).** No deck spec is in this repo. The envelope above (`artfct-export`, `v: 1`, reply to `event.origin`) is the proposal to match. Both starters must agree before either merges, or there are two bridges.
3. **Viewer iframe sandbox.** The viewer uses `allow-scripts allow-same-origin`, so its origin is not `null`. The preview uses `sandbox=""`, whose origin is `null`. RUB-561's comment about a sandboxed iframe's origin should be checked. The bridge depends only on `event.source` and `event.origin` from the parent, so this spec is unaffected.
4. **Page breaks.** `describe` predicts breaks by layout slicing; the print engine may break differently. This is a fidelity risk to RUB-561, not blocking here.
5. **A4 via named pages.** The `page` property and the `html.sheet-a4` switch need a browser check in Chrome at implementation time.
6. **Two-origin browser harness.** How the fixtures are served from a second origin under Pest 4 and this environment is not yet confirmed. If it cannot be set up, the cross-origin browser items stay unverified and are reported as such.
7. **`describe` output size.** No cap on runs or pages is set. A large document could return a large reply.
