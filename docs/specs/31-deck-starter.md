# Spec 31: Official deck-stage HTML starter (RUB-558)

**Track:** Official artifact types (RUB-551 epic) · **Depends on:** 26 (publish contract validator, RUB-574) · **Blocks:** RUB-555 (deck skill), RUB-561 and RUB-562 (export clients of the bridge), RUB-571 · **Related:** RUB-444, RUB-445, spec 30 (doc starter, same bridge envelope)

This page is both the spec and the plan. It is one combined page because the issue is size M.

## What and why

A bot that publishes a slide deck should start from an official Artfct deck starter. The starter already handles stage size, scaling, navigation and print, and it passes the publish contract, so the bot writes slides instead of hand-rolling chrome.

Today the presentation skill's old single-file template (removed by RUB-555) has an inline `<script>`. Every author has to split it into `index.html` and `app.js` by hand. The deck starter replaces that step.

The starter also has an export bridge. PDF (RUB-561) and PowerPoint (RUB-562) exports run the deck in an iframe at its isolated artifact origin and drive it through the bridge. Epic decision 9 says artifact script never runs in the app origin, so the bridge is the only channel between the app page and the deck.

**2026-11-21:** the PowerPoint export (RUB-562) was removed, and the bridge's `capture` command and the vendored `capture.js` went with it. The bridge keeps `ready`, `describe` and `goTo`. Decks present through the viewer's Present button and the starter's `F` key instead.

Out of scope: the deck skill text and the removal of the old template (RUB-555), PDF and PowerPoint writers (RUB-561, RUB-562), speaker view, animations, and PNG capture.

## Decisions this spec applies (epic RUB-551)

- **5.** One shared contract checker: `PublishContractValidator` (RUB-574, `app/Services/Artifacts/PublishContractValidator.php`). Every starter test uses it.
- **6.** Starters live in skill folders. Chrome lives in `.css` and `.js` files. Author content lives only in `index.html`. Upgrading means replacing chrome files, never content.
- **7.** "Renders on staging" means a browser check: the page shows, the behaviour works, and the console has zero CSP errors. Passing publish validation is not enough, because blocked content renders silently.
- **9.** Export never runs artifact script in the app origin. The bridge answers `window.parent` only, after a handshake. Peers are identified by `event.source`. The bridge never trusts an origin the sender names. Replies go to the browser-reported `event.origin`, never `'*'`.

## Layout

```
skills/presentation/assets/deck-starter/
  index.html   author content only: three example slides; meta artfct-starter=deck@1; one stylesheet and one script tag
  deck.css     chrome: stage, unit system, scaling, navigation UI, print rules, --deck-* tokens
  app.js       chrome: scaling, navigation, export bridge, goTo / slideCount API
```

- `skills/presentation/` already exists (`SKILL.md`, the old single-file template (removed by RUB-555), `references/`). The starter is added to its `assets/` folder. No new top-level folder.
- `skills/presentation` is the deck skill (RUB-555 updates its text). It is not a guidance topic.

### index.html

- Standard doctype, `lang`, charset and viewport meta.
- `<meta name="artfct-starter" content="deck@1">`.
- A top HTML comment that names the content slots (one `<section class="slide">` per slide, a `<h1>` or `<h2>` title, an optional `<p class="notes">`) and the upgrade rule: replace `deck.css` and `app.js` only, never `index.html`.
- `<link rel="stylesheet" href="deck.css">` (relative).
- The only script tag is `<script src="app.js" defer></script>`. No inline `<script>`, no `on*=`, no `data:` URI, no external origin, no inline `<style>`.
- Stage root: `<main class="deck-stage">` holding the slides. `noscale` on the stage root disables scaling.
- The three example slides are the sample used by the browser tests and the staging check.

### deck.css

- Colours, fonts and spacing are `--deck-*` custom properties on `:root`. Neutral default palette. No Solarized. Fonts are the system stack (`ui-sans-serif, system-ui, sans-serif`). No `@font-face`, no external fonts, no `@import`.
- **Unit system.** The stage sets `--deck-unit`. With JavaScript it is `1px`, and `app.js` scales the stage with a transform. Without JavaScript it is `calc(100vw / 1920)`, so the stage fills the viewport width and every size (`calc(var(--deck-unit) * 48)`) scales with it.
- **With JavaScript** (`html.deck-js`): `.deck-stage` is 1920 by 1080 and absolutely positioned; each `.slide` fills it. Inactive slides are `visibility: hidden` (not `display: none`), so they keep a layout box and `describe` can measure every slide. The active slide has `data-deck-active` and is `visibility: visible`.
- **Without JavaScript**: slides stack vertically in document order, each 16:9 at the stage width, and stay readable, indexable and printable.
- **Notes.** `p.notes` is hidden on stage (`display: none`) but stays in the DOM, so search indexes it and exporters read it.
- **Print.** `@page { size: 1920px 1080px; margin: 0 }`. One slide per page: each `.slide` is `break-after: page` (and the last one is not), `visibility: visible`, no shadow, no transform. Print uses the unscaled stage (JavaScript removes the transform in `@media print` by CSS; the stage has no transform under `noscale`).

### app.js

- Wrapped in an IIFE, `'use strict'`, no network calls. It makes no `fetch`, `XMLHttpRequest`, `sendBeacon`, `WebSocket`, `EventSource` or `import(` call, and it never reads `document.cookie`, `localStorage` or `location` for export.
- Adds class `deck-js` to `<html>` on start. Adds `data-deck-ready` on the stage once laid out.
- **Scaling.** Unless the stage has `noscale`, it computes `min(vw / 1920, vh / 1080)` and sets a transform on the stage, recomputed on `resize`. With `noscale` it sets no transform.
- **Navigation.** ArrowLeft and ArrowRight, Space (next), PageUp and PageDown move between slides. Home and End go to the first and last slide. On-screen previous and next buttons. A visible `n / total` counter (1-indexed). `#n` deep links (1-indexed in the URL): `#3` opens slide 3. Out-of-range or non-numeric hashes show slide 1.
- **Navigation API** (documented in a comment at the top of `app.js`, used by RUB-561, RUB-562 and RUB-571): `window.artfctDeck = { goTo(index), slideCount }`. `goTo` is 0-indexed. Out-of-range or non-integer indexes do nothing and return `false`. `data-deck-active` marks the active slide.
- **Notes stay in the DOM.** `app.js` never writes slide text. Slides are literal static markup. No slide is generated by script (`createElement` and `innerHTML` are not used for slides).

## Export bridge contract

The envelope is the one spec 30 defines for the doc starter, so RUB-561 and RUB-562 need one client for both starters. Only the commands and result shapes differ.

### Who is who

- The **deck** (the starter, `app.js`) runs at its isolated artifact origin.
- The **runner** is the app page (the export code). It embeds the deck in an iframe; the iframe's `contentWindow` is the deck's `window.parent`.
- The Worker's `frame-ancestors` header is the enforcement that only the app origin can frame the artifact (`app_frame_ancestor` in `backend/src/artifact_origin.rs`). The bridge does not need to know the app origin.

### Rules

1. **Parent-only.** The bridge acts only on messages where `event.source === window.parent`. Any other source (sibling frame, child frame, opener) is ignored with no reply. A deck that is not framed (`window.parent === window`) registers nothing that responds. This is what "undeclared origin" means here. There is no origin allowlist, because the trust model in decision 9 has none.
2. **Handshake first.** Only `ready` is accepted before a successful handshake. Any other command sent earlier is dropped silently, with no reply.
3. **Origin never taken from the payload.** `ready` records only that `event.source` is `window.parent`. An origin named in the body is ignored.
4. **Reply target is `event.origin`.** Every reply is `event.source.postMessage(reply, event.origin)`. It never uses `'*'`. If `event.origin` is not an `http(s)` origin (for example `"null"`), the message is ignored.
5. **No DOM access outside the page.** The bridge never touches `parent.document`, and makes no network request.

### Envelope

Request from the parent:

```json
{ "channel": "artfct-export", "v": 1, "id": "<caller-chosen string>", "cmd": "ready" | "describe" | "goTo", "index": 0 }
```

Reply from the bridge:

```json
{ "channel": "artfct-export", "v": 1, "id": "<echoed>", "ok": true,  "result": { } }
{ "channel": "artfct-export", "v": 1, "id": "<echoed>", "ok": false, "error": { "code": "unknown_command" } }
```

- A message with `v` other than `1`, a non-object body, a `channel` other than `artfct-export`, or a non-string `id` is ignored with no reply.

### Commands

- **`ready`** returns `result: { starter: "deck@1", protocol: 1, commands: ["ready", "describe", "goTo"], slideCount }` and sets the handshaken flag. Idempotent.
- **`describe`** (after handshake) returns `result: { slides: [...] }`, one entry per slide in document order, in stage pixels (the 1920 by 1080 coordinate space, unscaled):
  - `index` (0-based), `notes` (the text of `p.notes`, `""` when absent);
  - `runs`: `{ text, x, y, w, h, font_family, font_size_px, color }` for each text node with visible text, positions relative to the stage;
  - `images`: `{ src, x, y, w, h }` for each `img` whose trimmed `src` passes an allowlist: `/^[A-Za-z0-9_][A-Za-z0-9_./-]*$/`, no `\`, and no `..` path segment. Anything else (a scheme or host, a leading space before `https`, a backslash, `../`, an absolute `/` path, an empty src) is dropped from `images`.
  - `droppedImages` (top level, next to `slides`): the count of `img` elements that failed the allowlist. Dropped images are counted, not listed.
  - `describe` reads layout only. It never writes to the page. A deck with zero slides returns `slides: []`.
- **`goTo`** (after handshake) takes `index`, an integer from 0 to `slideCount - 1`. It sets `data-deck-active` on that slide and returns `result: { index, slideCount }`. An index that is not an integer, is below 0, or is at or above `slideCount` returns `ok: false, error: { code: "out_of_range" }`, and the active slide is unchanged.
- Any other command after handshake returns `ok: false, error: { code: "unknown_command" }`.

## Edge cases and refusals

| Case | Expected |
|---|---|
| Inline `<script>` in `index.html` | Validator error (inline script) |
| `onclick=` in `index.html` | Validator error (`on*=` handler) |
| `data:` image in `index.html` | Validator error (`data:` URI) |
| External origin in `index.html` (for example an external image) | Validator error (external origin) |
| Message from a sibling or child frame, valid envelope | Ignored, no reply |
| `describe` or `goTo` before `ready` | Ignored, no reply |
| `ready` sent twice | Second is a success with the same result |
| `ready` with a payload naming an origin | Payload origin ignored; reply still goes to `event.origin` |
| Parent `event.origin` is `"null"` | Ignored, no reply |
| `goTo` with index -1, `slideCount`, 1.5, NaN or a string | `ok: false`, `out_of_range`; active slide unchanged |
| `goTo` on a deck with zero slides | `ok: false`, `out_of_range` |
| `describe` on a deck with zero slides | `ok: true`, `slides: []` |
| Unknown command after handshake | `ok: false`, `unknown_command` |
| Hash `#n` out of range on load | Slide 1 shown; counter reads `1 / 3` |
| `img` src with a leading space and `https`, a backslash host, `../../x`, or `/x` | Dropped from `describe` `images` and counted in `droppedImages`; `images/a.png` kept |
| Space keydown on the document with no focused button | Advances one slide (`2 / 3` from slide 1) |
| Deck with no notes | Valid; `notes` is `""` |
| Notes placed in a `<script>` | Rejected by the validator (no script without `src`) |
| `app.js` contains `fetch`, `XMLHttpRequest` or `import(` | Test fails |
| Upgrade that replaces `index.html` | Forbidden by the upgrade rule (comment in `index.html`); only `deck.css` and `app.js` are replaced |

## Done-when to test map

Each RUB-558 item with the test that proves it. Status is the result of running the test on this branch.

| Done when (RUB-558) | Proof | Status |
|---|---|---|
| Three files exist; no inline script, `on*=`, `data:` or external origin; loads `deck.css` and `app.js` by relative path | `DeckStarterTest` · `the deck starter has its three files`, `the deck starter passes the publish contract`, `index.html has one script tag, app.js, loaded with defer` | passed (Feature file 16/16) |
| `index.html` has `artfct-starter` `deck@1` meta and a top comment naming slots and upgrade rule | `DeckStarterTest` · `index.html carries the deck@1 meta and the slot and upgrade comment` | passed |
| Stage 1920 by 1080, scaled by `app.js`; `section.slide`; hidden `p.notes` present in DOM; `--deck-*` tokens, system font stack; no Solarized | `DeckStarterBrowserTest` · `the stage scales to the viewport`, `the notes are hidden on stage but stay in the DOM`; `DeckStarterTest` · `deck.css declares --deck tokens with the system font stack and no Solarized` | passed |
| Keyboard, buttons, `n / total` counter, `#n` deep link; no network calls in `app.js` | `DeckStarterBrowserTest` · `the keyboard moves the counter`, `a space key dispatched on the document moves the deck forward`, `the on-screen buttons move the counter`, `a deep link opens the slide it names and an out-of-range one shows slide 1`; `DeckStarterTest` · `app.js makes no network request and never touches the parent document` | passed. Space with no focus is tested with a dispatched keydown, not a physical key press (see Unverified) |
| `describe` images: relative sources only; bad forms dropped and counted | `DeckStarterBrowserTest` · `describe keeps only relative image sources and counts the rest` (fixture `images-fixture.html`, served by `starter-router.php`, four bad forms and one good) | passed. Written first, failed with the three bad forms in the output, then passed after the allowlist |
| `app.js` exposes `goTo(n)`, `slideCount`, `data-deck-active` | `DeckStarterBrowserTest` · `window.artfctDeck goTo and slideCount drive the active slide`; `DeckStarterTest` · `app.js exposes the navigation API the export clients drive` | passed |
| `noscale` disables scaling; slides at true 1920 by 1080 | `DeckStarterBrowserTest` · `noscale renders the stage at 1920 by 1080 in a narrower viewport` (uses `?variant=noscale` in the fixture router) | passed |
| Bridge: `ready`, `describe`, `goTo`; answers `window.parent` only; no `parent.document`; no network | `DeckStarterBrowserTest` · bridge tests below; `DeckStarterTest` · `app.js makes no network request and never touches the parent document` | passed |
| Parent on a different origin completes the handshake and gets three slides from `describe` | `DeckStarterBrowserTest` · `deck starter handshake works from a different origin`, `deck starter describe returns the three slides` | passed |
| Message from an undeclared (non-parent) source ignored; message before handshake ignored | `DeckStarterBrowserTest` · `deck starter ignores sibling-frame and pre-handshake messages` | passed. A null-origin parent is not testable (see Unverified) |
| Replies never use a wildcard target | `DeckStarterTest` · `app.js has one postMessage call and it targets the reply origin` (pins the call's argument text to `message, origin`) and `app.js replies to the parent origin and never with a wildcard target` | passed. Static only: the browser cannot see the target (see Unverified) |
| Independent review focused on origin isolation (big-PR rule) | Process check, recorded in the PR | pending (orchestrator) |
| Speaker notes only in `<p class="notes">`; no script block for them | `DeckStarterTest` · `the notes live only in p.notes and no script carries them`; `DeckStarterBrowserTest` · `the notes are hidden on stage but stay in the DOM` | passed |
| Slides literal static markup; no script-generated slides | `DeckStarterTest` · `app.js writes no markup from strings and builds no slides` | passed. Only the nav controls are built by script |
| `deck.css` prints one slide per page with `@page { size: 1920px 1080px; margin: 0 }` | `DeckStarterTest` · `deck.css declares the one-slide-per-page print rules` | text check only; print pagination not run |
| Contract tests: validator passes the bundle; four mutated copies refused; `app.js` relative `src`; no banned calls; `deck@1` meta | `DeckStarterTest`: `the publish contract refuses violating copies of the deck starter` (4 datasets) plus the tests above | passed |
| Staging render check (publish sample, counter, `#3`, zero CSP errors, print three pages) | After merge, on staging | not verified here |
| `npm run test:plugin` passes | Command | passed (3/3) |
| PR screenshot or `No UI changes`; `vendor/bin/pint --dirty --format agent` clean | PR check | Pint passes on each commit; screenshot is a PR step |
| CI green and approved; no PR preview environments | Process check | pending |

### Guard breaks run

Each row is one guard broken in `app.js` or the validator, the named test run, the failure seen, then the guard restored (`git diff` clean after each).

| Guard removed | Test that failed |
|---|---|
| Parent-source check (`event.source !== window.parent`) | `deck starter ignores sibling-frame and pre-handshake messages` |
| Handshake gate | same test |
| Protocol version check (`v !== 1`) | same test (the `v: 2` describe got a reply) |
| Index range and integer check | `deck starter goTo answers in range and refuses out-of-range indexes` |
| Reply target changed to `'*'` | `app.js replies to the parent origin and never with a wildcard target` and `app.js has one postMessage call and it targets the reply origin` (Feature). The browser suite still passes: it cannot see the target |
| Image allowlist removed (old scheme-only filter) | `describe keeps only relative image sources and counts the rest` (leaked three bad forms before the fix) |
| Validator inline-script check | `the publish contract refuses violating copies of the deck starter` (inline script dataset) |
| Origin check (`isHttpOrigin`) | No test failed. See Unverified: defence in depth, not independently tested |

### Bridge browser test cases

The parent fixture (`tests/Fixtures/deck-starter/parent.html`) runs on origin A. It frames the deck served from origin B by `starter-router.php`, which sends the artifact-style CSP with `frame-ancestors` set to origin A. A sibling frame on origin B sends a rogue `ready`. The fixture records every reply by `id`.

- Parent sends `describe` before `ready`: no reply.
- Sibling frame sends `ready`: no reply, no handshake.
- Parent sends `ready`: `ok`, `starter: "deck@1"`, `slideCount: 3`.
- Parent sends `describe`: three slides, notes present on slide 1, images empty.
- Parent sends `goTo` with `index: 2`: `ok`; the third slide has `data-deck-active`.
- Parent sends `goTo` with `index: 9`, `-1`, `1.5`: `out_of_range` each; active slide unchanged.
- Parent sends `explode`: `unknown_command`.
- Parent sends `v: 2`: no reply.
- Parent sends `ready` with a payload `origin`: reply reaches the parent (so it went to the parent origin), payload origin ignored.

## Negative tests and guard breaks

See the guard-break table under the Done-when map.

## Open questions

Resolved on this branch:

1. **Undeclared origin.** Resolved as parent-only (`event.source === window.parent`), with no origin allowlist (decision 9). The Done-when wording "undeclared origin" is tested as "non-parent source". Replies still go to `event.origin`.
2. **Viewer sandbox.** `resources/js/pages/artifacts/show.tsx` uses `allow-scripts allow-same-origin`, and `resources/js/components/artifact-preview.tsx` uses `sandbox=""`, so scripts do not run in the preview. The `event.source` check holds in both. The export iframe's sandbox is not known yet (RUB-561 decides it). Code comments must not claim the origin is `null`.
3. **Describe schema.** Defined here (see Commands). Stage pixels, `font_size_px`, `notes` included, images as bundle-relative paths. Spec 30 uses points because it is paged; a slide is one stage, so pixels match the 1920 by 1080 coordinate space.
4. **Protocol.** The same envelope as spec 30, `channel: "artfct-export"`. The draft's separate `artfct-deck` channel is dropped, so there is one bridge client for both starters.
5. **Reply target.** `event.origin`, as in spec 30.
6. **PNG return.** Not in RUB-558. PNG capture belongs to RUB-561 and RUB-562, which use the `noscale` attribute.

Open:

7. **Print in the browser suite.** Whether the Pest browser setup can emulate print media is not confirmed. If not, the print check stays a text check plus the staging step.
8. **Staging parameters.** `artifact_type: slide_deck` exists (`app/Enums/ArtifactType.php`). The `entrypoint` and `files` shape for `deploy_artifact` is checked during the staging step, not here.
9. **Screenshot.** Not captured on this branch. The PR needs a before-less screenshot of the sample (this is a new UI), or it must say why it is missing. `No UI changes` does not apply.

## Unverified

- **Null-origin parent (`isHttpOrigin`).** Not tested. The guard is defence in depth, not independently tested. The starter accepts a message only when `event.source === window.parent`, and the artifact CSP `frame-ancestors` admits only the app's http origin. A parent with origin `"null"` (a sandboxed frame) would need a `frame-ancestors` source that matches an opaque origin, which the CSP does not allow, so the starter would not load under it. A fixture with a sandboxed parent would therefore be blocked from loading, and any "no reply" result would come from that block, not from the guard. Removing the guard did not fail any test. This reasoning is not checked in a browser.
- **Space on the page.** The focused-button case is tested with a real key press. The document case is tested with a dispatched `keydown` event (`new KeyboardEvent`), not a physical key press, because Pest's key press on `body` did not reach the document listener. The dispatched event exercises the same listener, but it is not a native key event.
- **Print.** Only the CSS text is checked. Pagination (three pages) was not run.
- **Reply target.** The browser suite cannot see a `'*'` target: a reply to the parent reaches it whatever the target origin. The Feature test pins the single `postMessage` call's argument text to `message, origin`. It does not stop `origin` itself being reassigned to `'*'`, and it cannot prove a wildcard is never used in other code.
- **CSP console errors.** `assertNoJavaScriptErrors` runs on the navigation tests; CSP reports were not read from the console separately.
- **Staging.** Not run.

## Fix round (review findings on PR 157)

Commits on `rub-558-deck-starter` after the review:

- `4daa906` image allowlist and `droppedImages`. Test first: `describe keeps only relative image sources and counts the rest` failed with `" https://evil.example/x.png"`, `"\\evil.com/x"` and `"../../x"` in the output. Then fixed; passes.
- `9e1d75b` single `postMessage` target pinned. `app.js has one postMessage call and it targets the reply origin` passes.
- `ec08e0d` space keydown on the document. `a space key dispatched on the document moves the deck forward` passes.
- `6289942` removed the unused `$deckStarterServers` global.

Test results on this branch (`php artisan test --compact tests/Browser/DeckStarterBrowserTest.php tests/Feature/Starters/DeckStarterTest.php`): 31 passed, 102 assertions. Before the fix round it was 28 passed. Lint: `npx eslint skills/presentation/assets/deck-starter/app.js` passes. `npm run lint:check` and the push hook are run in the push step.
