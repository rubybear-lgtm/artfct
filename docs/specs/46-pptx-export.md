# Spec 46: PowerPoint export from deck artifacts (RUB-562)

**Track:** Official artifact types (RUB-551 epic, Next) · **Depends on:** RUB-558 (deck starter and export bridge, spec 31) · **Related:** RUB-561 (PDF export, spec 45, unmerged: shares the iframe and bridge shape but this spec does not depend on it), RUB-445 (vendoring), RUB-551 decision 9 (export bridge, origin isolation), spec 05 · **Blocked by RUB-561 in Linear:** this branch does not wait for it.

This page is both the spec and the plan. The issue is size M.

## What and why

A seller downloads a deck as a PowerPoint file of full-bleed slide images, in order, with a link back to the live post in each slide's notes. The slides are pictures, so the text is not editable. Native editable PowerPoint is RUB-571, out of scope.

Artifact script never runs in the app origin. The app page embeds the deck in a sandboxed iframe at its isolated artifact origin, asks the starter's bridge for one PNG per slide, and posts the PNGs to the export route, which writes the `.pptx`. No headless browser anywhere in the stack.

## Decisions this spec applies

- **Owner approvals (RUB-562 comment, 2026-11-19).** The `.pptx` writer is `phpoffice/phppresentation`, a new composer dependency, pinned exactly. Slide images are captured client-side from the deck at its isolated origin.
- **RUB-551 decision 9.** The bridge answers `window.parent` only, after a handshake. Peers are identified by `event.source`. Replies go to the browser-reported `event.origin`, never `'*'`. The app page's writers only receive data.
- **Bridge addition (interpretation, see Bridge).** RUB-562's Done-when asks for a `capture` command on RUB-558's bridge. The brief for this branch says not to change the bridge's contract. This spec reads that as: keep the envelope, the rules and the existing `ready`, `describe` and `goTo` results exactly as they are, and add one command, `capture`, that returns a PNG. The `ready` result's `commands` list gains `"capture"`. `protocol` stays `1`.

## Package

| Item | Value |
|---|---|
| Package | `phpoffice/phppresentation` |
| Pinned version | **1.2.0** (exact, in `composer.json` as `"1.2.0"`) |
| Released | 2025-06-06 (the latest stable release on Packagist; no later stable exists) |
| Licence | LGPL-3.0-only |
| PHP constraint | `^7.1\|^8.0`, so PHP 8.5 is inside the constraint |
| Runtime check on PHP 8.5.12 | Writes and reads a three-slide deck. See Spike. **Emits PHP 8.5 deprecation notices** from its own `HashTable::add` (see Known issues) |

Transitive packages added by the require (all new, none upgraded):

| Package | Version | Licence |
|---|---|---|
| `phpoffice/common` | 1.1.0 | LGPL-3.0-only |
| `phpoffice/phpspreadsheet` | 3.10.9 | MIT |
| `maennchen/zipstream-php` | 3.2.2 | MIT |
| `markbaker/complex` | 3.0.2 | MIT |
| `markbaker/matrix` | 3.0.1 | MIT |
| `pclzip/pclzip` | 2.8.2 | LGPL-2.1 |

Extensions the writer needs (all present on this PHP build): `zip`, `xml` (with `dom` and `libxml`), `gd`, `xmlreader`.

**LGPL note for the owner.** The application uses the library unmodified as a server-side Composer dependency. The LGPL terms for that are the library's own source remaining available under LGPL. This spec does not modify it. Owner to confirm the licence is acceptable.

**Lock churn.** The brief's command is `composer require --with-all-dependencies`. That flag upgraded 22 unrelated transitive packages (guzzle, amphp, nikic/php-parser and others) in a dry run. The lock change in this branch is the exact require without that flag, which adds only the six packages above and upgrades nothing. Verified by diffing `composer.lock` against the base: the only changes are additions.

## Rasterizer

**Choice: html2canvas 1.4.1** (MIT, published 2022-01-22, the latest release).

- Paints the element to a `<canvas>` directly. It does not serialize the DOM into an SVG `foreignObject` data URL, which the artifact CSP and the starter's no-network rule would have to allow. Its fetches are only for images, and the starter's `describe` allowlist already limits images to relative, same-origin sources.
- Runs inside the artifact origin, in the deck iframe, against the deck's own DOM. The app page never runs it.
- Vendored per RUB-445 as a file in the deck bundle: `skills/presentation/assets/deck-starter/capture.js` (the minified UMD build from the npm tarball, unmodified, with its MIT licence header). Size: **198,689 bytes minified, 46,314 bytes gzipped** (measured from `npm pack html2canvas@1.4.1`).
- Loaded lazily by `app.js` on the first `capture` command. It is not loaded by `index.html`, so a deck that never exports never fetches it.
- **Fidelity gaps.** html2canvas does not render every CSS feature. Known gaps for the starter's CSS: `filter`, `backdrop-filter`, `mix-blend-mode`, `box-shadow` blur approximations, and text with `-webkit-background-clip`. The starter's `deck.css` uses plain colours, the system font stack and no filters, so the expected gaps are small. Screenshot comparison against a real render is not done in this branch (see Unverified).
- Rejected: `html-to-image` and `dom-to-image` (both serialize to `foreignObject` data URLs and fetch fonts and images, which fights the artifact CSP and the no-network rule); a headless browser (ruled out by the epic).

**Approval note.** RUB-562's Done-when item 1 asks for a Linear comment where Ruben approves the rasterizer, its pin and its bundle size. That comment is not posted by this branch and no approval is claimed here. The owner's comment approved client-side capture in principle; the specific library is this spec's choice and needs Ruben's sign-off before merge.

## Spike

Done before this spec was written, to choose the package and check the round trip. Scripts are in the scratchpad, not the repo.

- **Fixtures.** Three 1920 by 1080 PNGs made with PHP GD (solid colour and a label). **These are fixtures, not browser captures.** Real slide captures come from the browser test in the implementation.
- **Write.** A 16:9 layout (9144000 by 5143500 EMU, 960 by 540 px), one full-bleed `DrawingShape` per slide, one text box per slide, and the notes text `https://example.test/a/fixture-N` per slide. Output 15,954 bytes.
- **Result, slide count.** The reader returns **3 slides**. The sample has 3 slides. **Match.**
- **Result, images.** `ppt/media/` holds three PNGs. The reader returns one picture per slide. **Match.**
- **Result, text.** phppresentation's own reader returns **0 text elements** for every text run it wrote, including the plain slide text box. The text is in the XML: a DOM parse of `ppt/slides/slideN.xml` gives `Slide text 1`, `Slide text 2`, `Slide text 3`, and a DOM parse of `ppt/notesSlides/notesSlideN.xml` gives `https://example.test/a/fixture-1` through `-3`. **So the text is present and correct in the file, but the phppresentation reader cannot read it back.** The writer wraps text in `<a:t><![CDATA[...]]></a:t>`. The tests check text with a DOM parse, not the library reader. Whether PowerPoint and Keynote show the text as expected is **not verified here**.
- **Known issues.**
  - Three `Deprecated: Using null as an array offset` notices from `vendor/phpoffice/phppresentation/src/PhpPresentation/HashTable.php` line 74 on PHP 8.5.12, during slide creation (the `$hashIndex` is null for a new slide). The library runs and produces a valid file. It is an upstream PHP 8.5 issue, not a bug in this app. Laravel does not log deprecations by default, and `phpunit.xml` does not turn them into failures, so tests are not affected. Recorded here so the owner can decide whether to pin a later release or patch around it.
  - Text is written as CDATA, which the library's reader does not parse. Tests use a DOM parse.
- **Does it support PHP 8.5?** The composer constraint includes 8.5, the writer and reader run on 8.5.12, and the output is correct, but the library emits deprecation notices on 8.5. The brief says stop if the version does not support 8.5. This spec reads it as **supported with notices**, and flags it as an open item for the owner rather than stopping. There is no later stable release to pin instead.

## Bridge: the `capture` command

Additive to the RUB-558 contract (spec 31, "Export bridge contract"). Everything else is unchanged.

- `ready` returns `commands: ["ready", "describe", "goTo", "capture"]`. Its other fields are unchanged.
- **`capture`** (after handshake) takes `index`, an integer from 0 to `slideCount - 1`. It sets the slide active (the same way `goTo` does), waits for two animation frames, renders that slide at 1920 by 1080 with html2canvas, and replies with `result: { index, width: 1920, height: 1080, png_base64 }`.
  - The stage transform is removed during the render and restored after. The slide is rendered at scale 1.
  - An index that is not an integer, is below 0 or is at or above `slideCount` returns `ok: false, error: { code: "out_of_range" }`, with the active slide unchanged.
  - If the rasterizer file fails to load, the reply is `ok: false, error: { code: "capture_unavailable" }`.
  - If the render throws, the reply is `ok: false, error: { code: "capture_failed" }`.
- Only one `capture` runs at a time. A second request waits for the first.
- A deck published before this change has no `capture` in `commands`, and answers `capture` with `unknown_command`. The runner shows a message asking the owner to republish from the current starter.
- The envelope rules are the same: parent-only, handshake first, `event.source`, reply to `event.origin`, never `'*'`. `app.js` keeps exactly one `postMessage` call.

## Export route

- `POST a/{artifactId}/export/pptx`, route name `artifacts.export.pptx`, in the signed-in group next to `artifacts.download.handoff`, with throttle `artifact-pptx-export` at **10 requests per minute per user**.
- **Access.** Visibility is decided as for `download` and `downloadHandoff`. Anyone who cannot view the artifact gets the same **404** as a missing one. A non-deck artifact (type other than `slide_deck`) gets **422**, code `not_a_slide_deck`.
- **Body.** JSON `{ "captures": [ "<base64 PNG>", ... ] }`.
  - At most **30** captures, at least 1. Over the limit: 422, code `too_many_slides`.
  - Request body over **40 MB**: 422, code `payload_too_large`.
  - Each capture must decode as base64, start with the PNG signature, and have IHDR dimensions **1920 by 1080**. Otherwise 422: `invalid_png` for the signature or decode, `wrong_dimensions` for size.
- **Output.** A `.pptx` from `PptxExporter` with one full-bleed picture per capture, in order. Each slide's notes contain the absolute artifact view URL (`route('artifacts.show', ...)`). Response: `application/vnd.openxmlformats-officedocument.presentationml.presentation`, attachment, filename from the title slug.
- **CSRF.** The route is in the web group, so CSRF applies. The runner sends the `X-XSRF-TOKEN` header from the `XSRF-TOKEN` cookie.

## Done-when to test map

RUB-562 Done-when, with the test that proves each item. **Status** is the result of running the test on this branch. Nothing is marked passed unless its test was run and passed.

| Done-when (RUB-562) | Test | Status |
|---|---|---|
| Comment with the three checks, Ruben approves | Process: Linear comment, not posted by this branch | **not met**: no approval claimed |
| `capture(n)` returns a true 1920 by 1080 PNG; works cross-origin after handshake; ignored before handshake or from an undeclared source | see **Bridge** tests below | pending |
| Route accepts captures and returns the file; no access 404; non-deck 422 | Pest feature tests in `tests/Feature/Artifacts/PptxExportTest.php` | pending |
| Input limits: 30 slides, 40 MB, PNG magic and 1920 by 1080, distinct 422 codes | Pest feature tests, one per limit | pending |
| Pest: three access cases; a three-slide fixture returns a zip with exactly three `ppt/slides/slide*.xml`; each slide's notes contain the view URL | `PptxExportTest.php` | pending |
| Browser test: one capture per slide arrives at the route; download begins | `tests/Browser/PptxExportBrowserTest.php` | pending |
| Security: artifact script never runs in the app origin; `parent.document` gives `SecurityError` | Browser test, fixture deck | pending |
| Independent review, focused on origin isolation | Process, PR | not run here |
| Staging: export a staging deck, open in PowerPoint or Keynote, check count, order, full-bleed, notes | After merge, on staging | **not verified** |
| `presentation/SKILL.md` "When Not to Use" line points to Download PowerPoint | `tests/Feature/Skills/PresentationSkillTest.php` | pending |
| PR screenshot; Pint clean; CI green; approved | Process | pending |

Test names and results are added here as each one runs.

## Unverified (and why)

- **Staging export and PowerPoint/Keynote open.** Needs a staging deck and a desktop Office app. Not available to this run.
- **PR screenshots.** Require the viewer button running locally or on staging. Recorded for the PR step.
- **CSP console errors on a real artifact origin.** Only the test router's CSP is exercised.
- **html2canvas fidelity against the real starter.** Only the fixture deck is rendered. Not compared pixel by pixel.
- **PowerPoint and Keynote display of the phppresentation text (CDATA).** See Spike.
