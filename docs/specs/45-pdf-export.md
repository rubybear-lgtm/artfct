# Spec 45: PDF export from doc, flier and deck artifacts (RUB-561)

**Track:** Official artifact types (RUB-551 epic, Next) · **Depends on:** RUB-557 (doc starter, spec 30, bridge `doc@1`), RUB-558 (deck starter, spec 31, bridge `deck@1`) · **Blocks:** RUB-562 (PowerPoint reuses the iframe and bridge) · **Related:** RUB-445 (vendoring), RUB-551 decision 9 (export bridge), spec 05 (origin isolation)

This page is both the spec and the plan. The issue is size L.

## What and why

A seller exports a paginated, selectable PDF of a one-pager, flier or deck from the same Artfct post the team searches, and sends that file to a customer who never opens Artfct. The PDF is written in the browser. No server renderer, no headless browser, no per-export cost.

Artifact script never runs in the app origin. The app page embeds the artifact in a sandboxed iframe at its isolated artifact origin, asks the starter's export bridge for structured page data with `postMessage`, and writes the PDF from that data only.

## Decisions

1. **Writer: `@cantoo/pdf-lib` 2.11.1 (MIT), with `@cantoo/fontkit` 2.0.12 (MIT) for font parsing.** Chosen over `jsPDF` 4.2.1 (MIT) because pdf-lib embeds TrueType and WOFF fonts as subsets with a ToUnicode map, which is what makes text selectable and fonts embedded. `jsPDF` needs its own font-conversion step for custom fonts. The original `pdf-lib` 1.17.1 was last published in 2022, so the maintained fork is used.
   - **Deviation from the issue text.** The issue asks for a WASM writer. The candidates that fit (MIT or Apache or BSD, runs in a browser without a server, maintained) are all JavaScript. No WASM PDF writer with a permissive licence and a current release was found. The writer is JS. Owner to confirm this deviation.
2. **Vendoring.** The UMD builds are copied (not installed from npm) into `public/vendor/pdf-export/`, which is an existing top-level folder (`public/`) with a new subfolder. Both UMD builds expose globals (`PDFLib`, `fontkit`) and load with plain `<script>` tags. No Vite bundling, no npm dependency, no change to `package.json` or the lockfile.
3. **Glue code.** `public/vendor/pdf-export/export.js` is the runner (bridge client and PDF writer glue), plain browser JS in the app origin. Its only network request is one `fetch` of the bundled font files from its own folder. The app page loads it on demand. It is not part of the skill bundles.
4. **Fonts.** Only fonts under the SIL Open Font License are bundled. Latin subsets only, in WOFF (fontkit reads WOFF without a brotli step). See the font table below.
5. **Font mapping.** A run's computed `font_family` that names Georgia or a serif stack maps to Newsreader. Anything else maps to Manrope. The bridge does not send font weight, so every run is embedded at weight 400 in this version; headings are not bold in the PDF. Open question 2.
6. **Glyphs outside the bundled subset** (for example CJK, most emoji) are not covered by the bundled fonts. They are replaced with `?` before drawing, and the count is returned to the caller. No glyph fallback font is bundled.
7. **Frame sandbox.** The export frame uses `sandbox="allow-scripts allow-same-origin"`, the same as the viewer. The frame's origin is then the artifact origin, not the app origin, so `postMessage` can target it by origin (`artifactOrigin`, never `'*'`). A sandbox without `allow-same-origin` would make the frame's origin `null`, and no explicit target would reach it. Both checks run on every reply: `event.source === frame.contentWindow` and `event.origin === artifactOrigin`.
8. **Bridge is unchanged.** `export.js` on the starters already answers `ready` and `describe`. This issue does not change the bridge envelope. The writer requests `ready`, then `describe`, and only accepts replies whose `event.source` is the iframe's `contentWindow` and whose `event.origin` is the artifact origin.
9. **Non-starter artifacts** get the browser print dialog and a one-line explanation, not a PDF. A starter is an artifact whose frame answers `ready` with `starter` `doc@1` or `deck@1`.

## Vendored files

| File | Source | Version | Licence | Size on disk |
|---|---|---|---|---|
| `public/vendor/pdf-export/pdf-lib.min.js` | `@cantoo/pdf-lib` `dist/pdf-lib.min.js` | 2.11.1 | MIT (`pdf-lib-LICENSE.md`) | see Results |
| `public/vendor/pdf-export/fontkit.umd.min.js` | `@cantoo/fontkit` `dist/fontkit.umd.min.js` | 2.0.12 | MIT (`fontkit-LICENSE`) | see Results |
| `public/vendor/pdf-export/fonts/manrope-latin-400-normal.woff` | `@fontsource/manrope` `files/` | 5.3.0 | OFL-1.1 | see Results |
| `public/vendor/pdf-export/fonts/newsreader-latin-400-normal.woff` | `@fontsource/newsreader` `files/` | 5.3.0 | OFL-1.1 | see Results |
| `public/vendor/pdf-export/export.js` | written for this issue | n/a | the repo's licence | see Results |

Only the 400 weights are vendored, because version 1 draws only the 400 weight (decision 5). Bold and semibold would need the bridge to send `font_weight`, which is open question 2.

Each font's licence text is in `public/vendor/pdf-export/fonts/OFL-<family>.txt`, copied from the `@fontsource` package.

## Done-when to test map

Linear RUB-561 Done-when, mapped to tests. Tests named below are planned. Status is updated in Results.

| Done-when (Linear RUB-561) | Test name | Status |
|---|---|---|
| Spike: three fixtures (flowing doc with a long table, fixed sheet, three-slide deck) produce PDFs; `pdftotext` returns headings and body; page counts exact; table header repeats | `the spike renders three fixtures with exact page counts` (manual, see Spike) | pending |
| Writer pick, version and bundle size recorded; font decision recorded | this spec, Decisions 1 to 6 and the vendored files table | done in this commit |
| The action exists for official-starter `document` and `slide_deck` artifacts; non-starter shows the print fallback | `the export action appears for a starter document and a starter deck` and `the export action falls back to print for a non-starter artifact` in `tests/Browser/PdfExportBrowserTest.php` | pending |
| Security: artifact renders only in its isolated iframe; a bundle script that reads `parent.document` gets `SecurityError` and export still completes | `a fixture bundle script that reads parent.document gets SecurityError and the export completes` in `tests/Browser/PdfExportBrowserTest.php` | pending |
| Security: no artifact script runs in the app origin | `the export page loads no artifact script in the app origin` in `tests/Browser/PdfExportBrowserTest.php` | pending |
| Browser test on a fixture doc and a fixture three-slide deck: download fires and the file starts with `%PDF-` | `exporting the doc fixture produces a PDF that starts with %PDF-` and `exporting the deck fixture produces a PDF that starts with %PDF-` in `tests/Browser/PdfExportBrowserTest.php` | pending |
| Access: bundle data requested with the viewer's own access; viewer succeeds, outside caller gets 404 | none yet | not started, see Open questions |
| Staging `pdfinfo` and `pdftotext` output, page counts, footer URL | none in CI | not verifiable here, see Results |
| `presentation/SKILL.md` "When Not to Use" line points to the Download PDF action | `the presentation skill points PDF requests to the Download PDF action` in `tests/Feature/Skills/PresentationSkillTest.php` | pending |
| Independent review, screenshot, Pint clean, CI green, approved | none | owned by the orchestrator |

Vendoring guards (Feature, `tests/Feature/PdfExport/VendoredWriterTest.php`):

| Guard | Test name | Status |
|---|---|---|
| Every vendored file exists and has a licence text beside it | `every vendored pdf export file exists with its licence` | pending |
| Every bundled font is OFL-1.1 | `every bundled pdf export font has an OFL licence text` | pending |
| `export.js` posts with an explicit origin and never `'*'` | `export.js never posts to a wildcard origin` | pending |
| `export.js` makes no network request and never reads `parent.document` | `export.js makes no network request and never touches the parent document` | pending |
| `export.js` only accepts replies whose source is the iframe window | `export.js checks event.source against the frame it created` | pending |

Negative tests (guards to break and restore): the wildcard-origin test, the no-network test and the `event.source` test, each broken on purpose in a copy of `export.js`, shown to fail, then restored. The `SecurityError` browser test is a guard for origin isolation: it is broken by serving the fixture bundle without the isolated origin, shown to fail, then restored.

## Plan

1. Commit this spec (first commit, plan and decisions).
2. Red: write `tests/Feature/PdfExport/VendoredWriterTest.php` with the guard names above. Run it; show each fails for its stated reason (files missing).
3. Green, one commit: copy the UMD builds, fonts and licence texts into `public/vendor/pdf-export/`; write `export.js`.
4. Red: `tests/Browser/PdfExportBrowserTest.php` with the fixture parent page and the doc and deck cases. Show it fails.
5. Green: the export runs in the fixture, produces `%PDF-` output and the page counts.
6. Add the "Download PDF" action to the artifact page for starter documents and decks, and the print fallback for the rest. Add a browser test for each branch.
7. Update the presentation skill "When Not to Use" line; `npm run test:skills` and `npm run test:plugin`.
8. Run `vendor/bin/pint --dirty --format agent` on any PHP change, then push.

## Open questions for the owner

1. **Writer deviation.** The issue asks for a WASM writer. The chosen writer is JS (no permissive, maintained WASM writer was found). Confirm, or name a WASM candidate to evaluate.
2. **Font weight.** The bridge sends no `font-weight`, so headings render at weight 400. Adding `font_weight` to the `describe` runs is a change to `export.js` in both starters (doc@1 and deck@1) and needs the owner's sign-off on the protocol. Not done in this issue.
3. **Images.** The bridge sends bundle-relative image paths. Fetching them from the artifact origin needs CORS, which the artifact origin does not grant. Version 1 does not embed images. Record what the spike shows.
4. **Access check (Done-when).** The export uses the viewer's existing frame URL, so no new bundle-data endpoint is added. The 404 feature test for an outside caller needs an endpoint that serves bundle data; none was found in this issue's scope.

## Spike

To be recorded in the Results section after the spike runs. The spike is time-boxed to two days in the issue; this run covers the three fixtures in one pass.

## Results

Recorded during the run on branch `rub-561-pdf-export`.

(pending)
