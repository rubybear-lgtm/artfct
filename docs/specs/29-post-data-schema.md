# Spec 29: Context post data schema (RUB-559)

**Track:** Context posts (RUB-551 epic) · **Depends on:** 20 (artifact database), RUB-572 (bot `data:read`/`data:write`) · **Blocks:** RUB-553 (context-post guidance), RUB-560 (tweak fields), RUB-581 (read receipts)

This page is both the spec and the plan. It is one combined page because the issue is small.

## What and why

A context post (a decision, announcement, topic, policy, options page or brand post) must show who owns it, what kind it is, whether it is a draft, current or superseded, and which newer post replaced it. A person or bot changes that state with a data write. The HTML does not need republishing.

Posts are data-backed from day one. There is no HTML-first phase and no migration.

## Data shape

Collection `post`, document `details`, body:

| Field | Type | Rule |
|---|---|---|
| `kind` | string | one of `decision`, `announcement`, `topic`, `policy`, `options`, `brand` |
| `owner` | string | free text; may be empty |
| `status` | string | one of `draft`, `current`, `superseded` |
| `replaced_by` | string or null | an artifact id string, or null |

Collection `post.reads` is declared only (enforcement belongs to RUB-581). The manifest names it `post.reads`, because collection names allow `[A-Za-z0-9._-]` and not `/`. Index fields: `post` indexes `kind`; `post.reads` indexes `reader`.

Seed for `post` / `details`: `{kind: "decision", owner: "", status: "draft", replaced_by: null}`. Seed rows are written once, on first deploy (spec 20).

## Where validation lives

Decision: the MCP tools `db_set`, `db_update` and `db_batch` check writes to `post/details` in a new class, `App\Services\Artifacts\ContextPostDetails`, before any Worker request. A refusal returns `invalid_request` and sends nothing.

Known gaps (recorded, not closed here):
- In-page writes: the Worker's `/__artfct/db/*` route accepts browser writes from a Writer (owner, admin, or member with `edit_access=edit`). Script in a published page, or a tweaked copy of the starter, can write `{kind:"memo"}` without passing these tools.
- Seed rows: `deploy_artifact` takes `db.seed` as the publisher's JSON. `validateDbManifest` checks shape only, so a first deploy can seed invalid post details.
- Direct REST writes with a `data:write` token skip the field rule.
- `db_delete` of `post/details` is allowed; the document can be removed.
- Other documents in the `post` collection are not validated. Only `post/details` has field rules; any other doc_id passes the tools unchecked.
- The whole `post.reads` collection is not validated. It is declared only (RUB-581 enforces it), so any write to it passes the tools unchecked.
- `replaced_by` is checked only for shape: no existence, same-team or self-reference check.
- Cross-field rules are not enforced (for example `superseded` without `replaced_by`). A partial update would need the stored document to check them.
- The starter's "no other request" check is a regex and would miss dynamic request forms. The CSP and the two same-origin GETs are the real control.

Why not the Worker: spec 20 declares only an index per collection, so a per-field rule would be new Rust schema work. Agent writes enter through these tools, and the Worker still enforces scope, role, edit access, versions and quotas. Known gap: a direct REST caller holding a `data:write` token can skip the field rule.

Rules:
- `db_set` (replace) must carry all four fields.
- `db_update` checks the fields it sends.
- `db_batch` checks each write to `post/details`; a `delete` is not checked.
- `kind` and `status` must be in their sets. `replaced_by` must be null or a permanent artifact id. `owner` must be a string.

## Page

`skills/artfct-context-post/assets/post-starter/`:
- `index.html`: content slots only (title, body, and a `post-details` block holding a snapshot of the four fields).
- `post.css`: all chrome.
- `app.js`: makes two same-origin GET requests and no others. First `GET /__artfct/db/session` for the short-lived token (spec 20), then `GET /__artfct/db/post/details` with `Authorization: Bearer <token>` (the Worker returns 401 without it). It sends `X-Artfct-Db: 1` on both. If the session call fails, the snapshot stays in place. With JavaScript off, the snapshot stays readable.
- `manifest.json`: the `db` declaration (both collections, the seed above). Passed as the `db` argument of `deploy_artifact`.

The snapshot is display-only. Data is the source of truth.

## Plan (TDD order)

1. Commit this spec.
2. Red: `tests/Feature/PostDataSchemaTest.php` covering the MCP refusals and successes. Green: `ContextPostDetails` and the three tool hooks.
3. Red: `tests/Feature/Templates/ContextPostStarterTest.php` for the manifest and the starter bundle. Green: the starter files.
4. Break each refusal guard once and confirm its test fails, then restore.

## Done-when to test map

| Done when (RUB-559) | Proof | Status |
|---|---|---|
| Pre-code comment on the issue states where validation lives | Linear comment | Not met: the Linear comment write was denied by the permission system. The decision is recorded in the Where validation lives section instead. |
| Manifest declares both collections and seeds `details` on first deploy only | `ContextPostStarterTest` · `the context post manifest declares both collections and seeds details once` | passed (12/12 file) |
| `status` outside its values, `kind` outside the set returns `invalid_request`; `replaced_by` must be null or a permanent artifact id | `PostDataSchemaTest` · `post details refuses an unknown kind before anything is sent`, `... an unknown status before anything is sent`, `... refuses a replaced_by that is neither null nor a string before anything is sent` | passed; guard broken: 4 refusal tests failed |
| Starter's four files exist; validator clean; four violating copies refused | `ContextPostStarterTest` · `the context post starter has its four files`, `the context post starter passes the publish contract`, `the publish contract refuses violating copies of the starter` (dataset: inline script, onclick handler, data uri image, external origin) | passed; guard broken: inline-script and data-URI tests failed |
| `validateDbManifest` accepts manifest; rejects unknown collection and invalid seed | `ContextPostStarterTest` · `validateDbManifest accepts the context post manifest`, `validateDbManifest rejects a seed row naming an unknown collection`, `validateDbManifest rejects an invalid seed row` | passed; guard broken: both rejection tests failed |
| `app.js` fills the block from the endpoint; no JS keeps snapshot; only two same-origin GETs (session, then details) | `ContextPostStarterTest` · `context post app.js makes exactly two same-origin GETs: the session, then the post details`, `the context post snapshot is in index.html for readers without JavaScript`. The fill-in-browser behaviour is not run (no browser test). | partly: static tests passed; browser fill not verified |
| Feature tests: create; superseded with replaced_by; invalid kind/status; viewer forbidden; no data:write insufficient_scope; other team not_found (Worker faked; the caller's token is asserted to reach the Worker, not isolation itself) | `PostDataSchemaTest` · `post details can be created with all four fields`, `post details can move to superseded with a replaced_by id`, `a viewer write is refused with forbidden`, `a token without data:write gets insufficient_scope on post details`, `another team gets not_found on post details` | passed; guard broken (403 mapping): viewer test failed |
| Guidance untouched, no version bump | `git diff abc5f0d HEAD -- resources/guidance app/Mcp/Support/ArtifactGuidance.php` is empty | passed (checked by diff) |
| Staging publish and browser check | Not run here; needs merged code | not verified |
| Pint clean; CI green; reviewed | `vendor/bin/pint --dirty --format agent` passed; CI and review pending the push | pint passed |

## Out of scope

Tweak fields (RUB-560), notifications, read receipts (RUB-581), kinds beyond the set, and guidance files (RUB-553).
