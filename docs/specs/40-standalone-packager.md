# Spec 40: Standalone HTML packager that matches the publish contract (RUB-563)

**Track:** Official artifact types (RUB-551 epic) · **Depends on:** 04 (bundle format), 05 (origin isolation), 26 (publish contract validator, RUB-574), 30 (doc starter), 31 (deck starter) · **Blocks:** none in the epic · **Related:** RUB-444, RUB-445 (publish constraints)

This page is both the spec and the plan. It is one combined page because the issue is size M.

## What and why

A teammate opens an artifact, chooses "download as HTML", and gets one `.html` file that opens offline with no Artfct connection. The file keeps its styles, scripts, images and fonts, so a deck still navigates and a doc still reads with JavaScript off.

## Decisions

1. **Input is validated, output is not.** The bundle being packed must pass `PublishContractValidator` (RUB-574). It is the one shared checker. The packed output is a download format: it carries inline `<style>`, inline `<script>` and `data:` URIs, which the publish contract refuses. The output properties (no `http(s)://`, no relative `src`/`href`, the inline tags present) are asserted by test code, not by a second checker. This follows the grooming decision on RUB-563.
2. **The validator's errors are returned as they are.** A bundle that fails the validator gets `422` with the validator's strings in `errors`, and nothing is packed.
3. **The Worker must say what the bundle contains.** Today `GET /v1/orgs/{org}/artifacts/{id}/content` returns only the entrypoint text, so the app cannot learn which paths exist or their hashes. This spec adds an additive field set to that response: `entrypoint` and `files: [{path, sha256, content_type, size_bytes}]` (sorted by path, current version only). Bytes come from the existing `GET /v1/blobs/{sha256}`, whose visibility rule already covers the current copy of a file for a caller who can see the artifact.
   - **Deploy order.** This is an additive Worker API change. It needs a Worker deploy before the route works on staging or production. Until the Worker returns `files`, `HttpArtifactContentSource` reports the file list as `null`, and the route refuses with `503` and a clear message. It never packs a bundle with its assets silently missing.
   - It exposes nothing new: the same org credential already reads the content and the blobs.
4. **Access.** The route is `artifacts.download.html` at `a/{artifactId}/download.html`, inside the `auth` group next to `artifacts.download`. It reuses the same team resolution, `Gate::authorize('view', $team)` and `ArtifactVisibility::canView` as `download`. Every refusal is the same `404`.
5. **Headers.** `Content-Type: text/html; charset=UTF-8`, `Content-Disposition: attachment; filename="<slug>.html"`, `X-Content-Type-Options: nosniff`, and `Content-Security-Policy: sandbox` as a backstop. The packed file has inline script and is never rendered from the app origin (decision 9 of RUB-551). The file is a download, so the browser saves it and does not render it.
6. **Packing rules** (`App\Services\Artifacts\StandaloneBundlePacker`):
   - **Inline `<link rel=stylesheet>`** as `<style>` in the same position. The stylesheet's `url()` values are resolved against the stylesheet's own path, not `index.html`. Fonts in `fonts/` therefore resolve.
   - **Inline `<script src>`** as `<script>` in the same position. A script with `defer` or `async`, or one in `<head>`, moves to just before `</body>` in document order, so its DOM is ready. A `type="module"` script is refused, because import graphs are not packed.
   - **`</script` and `</style`** inside inlined bytes are escaped (`<\/script`, `<\/style`).
   - **`<img src>`, `<source src>`, `<video poster>`, `<link rel=icon>`** become `data:` URIs using the manifest's `content_type`. `srcset` candidates are rewritten the same way. Any candidate that cannot be resolved is refused.
   - **`href="#..."`** is left alone. A relative link to another bundle page (`<a href="other.html">`) is refused, because one file cannot hold two pages.
   - **Strings in JavaScript** are scanned: quoted literals (`"`, `'`, backtick) with an asset extension (png, jpg, jpeg, gif, webp, avif, svg, woff, woff2, ttf, otf, json, mp4, webm, mp3, wav, ogg), no scheme and no leading `/`. A literal that names a bundle file becomes a `data:` URI. A literal that names no file is reported in the error list. `/__artfct/...` and absolute paths never match.
   - **CSS `@import`** with a relative target is refused (not packed). `url()` in CSS and in inline `style` attributes is rewritten as above.
   - **Determinism.** Files are fetched once per `sha256`, in path order. There are no timestamps in the output, so packing the same artifact twice returns identical bytes.
7. **Limits before fetching.** Before any blob fetch, the manifest's file count and summed `size_bytes` are checked against `PublishContractValidator::MAX_BUNDLE_FILES` and `MAX_BUNDLE_BYTES`. Blobs are fetched sequentially, one per distinct `sha256`, within the existing HTTP timeouts.
8. **Unresolvable references** (JavaScript literal to a missing file, relative `@import`, relative `<a href>` to another page, a `src` or `href` on an element the packer does not inline, a module script) are collected into one list. Any entry refuses the whole pack with `422`, and the list is in `errors`. Nothing is packed.

## Out of scope

- An MCP tool for bots (the route is authenticated; add a tool later if asked).
- Loosening the publish contract.
- Packing another page, following `@import`, or packing `type="module"` scripts.
- Export through the sandboxed iframe bridge (RUB-561, RUB-562).

## Open questions (owner decisions, not resolved here)

1. **Worker deploy.** The additive `files` field needs a Worker deploy before staging can show the route working. Who runs that deploy, and in what order relative to the Laravel release? Until it lands, the route answers `503` by design.
2. **Relative links to other pages.** Decision 6 refuses them. Should a packed file instead keep the link and mark it as broken, or should the owner accept refusal? Refusal is the current choice.
3. **Code-referenced asset list.** The extension list in decision 6 is a guess at what a starter uses. Should the owner widen or narrow it?
4. **Staging and screenshots.** The issue asks for a staging pack of the deck starter, opened with the network disabled, and a screenshot. That needs a merged Worker and a staging deploy, which this branch cannot do. It is recorded as not run below.

## Plan (TDD order)

1. Commit this spec.
2. Worker (Rust): `GET /v1/orgs/{org}/artifacts/{id}/content` adds `entrypoint` and `files`. Red: a unit test on `build_org_content_response` for the new fields, and a contract test on the OpenAPI schema. Green: query and builder. Update `openapi/artfct.yaml`. `cargo test -p backend`.
3. Laravel contract: `ArtifactContentSource::fetch` returns `files` (`null` when the Worker does not send it). `HttpArtifactContentSource` parses it. `FakeArtifactContentSource::seed` takes an optional file list. `FakeArtifactDirectory::seedBlob` seeds bytes. Red then green with unit-level feature tests.
4. Packer: red tests for each packing rule and each refusal, using the real `deck-starter` and `doc-starter` folders and one small fixture bundle built in the test. Green: `StandaloneBundlePacker`.
5. Route: red tests for the route (200, 404 for no access, 422 with validator strings, 503 when `files` is missing, headers, determinism). Green: `ArtifactViewerController::downloadHtml` and the route.
6. Break each refusal guard once, confirm its test fails, restore.
7. `vendor/bin/pint --dirty --format agent`, the affected Pest files, `cargo test`, then push.

## Done-when to test map

Status values: **passed** (test ran and passed), **guard broken** (test failed when the guard was removed and then restored), **not run** (could not run here, with reason).

| Done when (RUB-563) | Proof | Status |
|---|---|---|
| Route `artifacts.download.html` returns one HTML file for a viewable artifact; no access gives `404` | `StandaloneHtmlDownloadTest` · `a member downloads a bundle as one HTML file with its assets inline` (200, headers); `the download is a 404 for a caller who cannot see the artifact` | passed; guard broken (visibility 404) failed |
| Packed fixture (index, CSS, JS, one PNG, one font) has no `http://`, `https://` or relative `src`/`href`; has `<style>`, `<script>`, `data:` URIs | `StandaloneHtmlDownloadTest` · `a member downloads a bundle as one HTML file with its assets inline`; `a font referenced from a stylesheet in a folder resolves against that stylesheet` | passed |
| Bundle failing the validator returns `422` with the validator's message; nothing packed | `StandaloneHtmlDownloadTest` · `a bundle that fails the publish contract is refused with the validator message and nothing is packed` | passed; guard broken (validator refusal) failed |
| Pest tests for the behaviours above and determinism | `StandaloneHtmlDownloadTest` · `the packed bytes are identical when the same artifact is packed twice` | passed |
| Staging: pack the deck sample, open with network disabled, navigation works, screenshot | Needs the Worker change deployed and a staging artifact; this branch cannot deploy | not run |
| Packed file readable with JavaScript disabled; no-JS stacking survives | `StandaloneHtmlDownloadTest` · `the real deck starter packs with its static slides and its no-JavaScript rules intact`, `the real doc starter packs with its static content intact` (static checks only: `html.deck-js` rules and slide markup survive, no external script). A browser run with JavaScript disabled was not done. | partly: static only |
| Unresolvable code-referenced assets reported, not dropped; resolving and refusal fixtures | `StandaloneHtmlDownloadTest` · `an image referenced from a script string resolves and is inlined` (resolving); `a script string naming a file that is not in the bundle is reported and nothing is packed` (refusal, lists `missing-logo.png`) | passed; guard broken (missing literal) failed |
| Pint clean; CI green; reviewed | `vendor/bin/pint --dirty --format agent` passes before each PHP commit; PHPStan clean on changed files. CI and review follow the push. | pint and PHPStan passed; CI and review pending |

Additional tests written for the spec's own rules:

| Rule | Proof | Status |
|---|---|---|
| Worker lists `entrypoint` and `files` (decision 3) | Rust `metadata_and_content_responses_match_documented_schemas` (`cargo test -p artfct-backend --lib`: 300 passed) | passed |
| Content source reads the file list; `null` when an older Worker omits it (decision 3) | `HttpArtifactContentSourceTest` · two tests | passed |
| Older Worker refused with 503 (decision 3) | `StandaloneHtmlDownloadTest` · `a bundle whose Worker does not list its files is refused with 503 and nothing is packed` | passed; guard not broken |
| Module scripts refused (decision 6) | `... a module script is refused ...` | passed; guard broken failed |
| Relative link to another page refused (decision 6) | `... a relative link to another bundle page is refused ...` | passed; guard broken failed |
| `srcset` candidate must resolve (decision 6) | `... a srcset candidate that names a missing file is refused` | passed; guard broken failed |
| Relative `@import` refused (decision 6) | `... a relative stylesheet import is refused ...` | passed |
| `</script` in inlined code is escaped (decision 6) | `... a script that closes its own element is escaped ...` | passed |
| Deferred script moves to the end of the body (decision 6) | `... a deferred script moves to the end of the body ...` | passed |
| `Content-Security-Policy: sandbox`, `nosniff`, attachment (decision 5) | asserted in `a member downloads a bundle ...` | passed |

### Guard breaks (each restored after the run)

| Guard broken | Test that failed |
|---|---|
| Validator refusal (`if ($errors !== [])`) | `a bundle that fails the publish contract ...` |
| Visibility `404` in `downloadHtml` | `the download is a 404 for a caller who cannot see the artifact` |
| Module script refusal | `a module script is refused ...` |
| Missing script literal refusal | `a script string naming a file that is not in the bundle ...` |
| `srcset` missing-candidate refusal | `a srcset candidate that names a missing file is refused` |
| Relative link refusal | `a relative link to another bundle page is refused ...` |

The guard breaks ran on the packer before one refactor that moved the final refusal check from `pack()` into `packDocument()`. They were rerun after that refactor, with the same results.

## Verification run

- `cargo test -p artfct-backend --lib`: 300 passed.
- `php artisan test --compact tests/Feature/Artifacts tests/Feature/ArtifactViewerTest.php tests/Feature/Indexing/EventDrivenIndexingTest.php tests/Feature/Starters`: 133 passed.
- `vendor/bin/phpstan analyse --memory-limit=1G` on the changed PHP files: no errors.
