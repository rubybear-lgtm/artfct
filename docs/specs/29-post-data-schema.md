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

Why not the Worker: spec 20 declares only an index per collection, so a per-field rule would be new Rust schema work. Agent writes enter through these tools, and the Worker still enforces scope, role, edit access, versions and quotas. Known gap: a direct REST caller holding a `data:write` token can skip the field rule.

Rules:
- `db_set` (replace) must carry all four fields.
- `db_update` checks the fields it sends.
- `db_batch` checks each write to `post/details`; a `delete` is not checked.
- `kind` and `status` must be in their sets. `replaced_by` must be null or a string. `owner` must be a string.

## Page

`skills/artfct-context-post/assets/post-starter/`:
- `index.html`: content slots only (title, body, and a `post-details` block holding a snapshot of the four fields).
- `post.css`: all chrome.
- `app.js`: fetches `/__artfct/db/post/details` on the same origin (sends `X-Artfct-Db: 1` and `Content-Type: application/json`) and fills the block. It makes no other request. With JavaScript off, the snapshot stays readable.
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
| Pre-code comment on the issue states where validation lives | Linear comment | Not met: the comment write was denied by the permission system. Decision recorded in this spec instead. |
| Manifest declares both collections and seeds `details` on first deploy only | `ContextPostStarterTest` · `context post manifest declares both collections and the details seed` | pending |
| `status` outside its values, `kind` outside the set returns `invalid_request`; `replaced_by` must be null or string | `PostDataSchemaTest` · `post details refuses an unknown kind`, `... an unknown status`, `... a non-string replaced_by` | pending |
| Starter's four files exist; validator clean; four violating copies refused | `ContextPostStarterTest` · `context post starter passes the publish contract`, `... refuses a copy with an inline script`, `... onclick handler`, `... data URI`, `... external origin` | pending |
| `validateDbManifest` accepts manifest; rejects unknown collection and invalid seed | `ContextPostStarterTest` · `validateDbManifest accepts the context post manifest`, `... rejects a seed naming an unknown collection`, `... rejects an invalid seed` | pending |
| `app.js` fills the block from the endpoint; no JS keeps snapshot; no other request | `ContextPostStarterTest` · `context post app.js reads only the same-origin db endpoint`, `... snapshot is in index.html` | pending |
| Feature tests: create; superseded with replaced_by; invalid kind/status; viewer forbidden; no data:write insufficient_scope; other team not_found | `PostDataSchemaTest` · `post details can be created`, `... set to superseded with replaced_by`, `... viewer write is forbidden`, `... a token without data:write gets insufficient_scope`, `... another team gets not_found` | pending |
| Guidance untouched, no version bump | `git diff` shows no change under `resources/guidance` or `ArtifactGuidance::VERSION` | pending |
| Staging publish and browser check | Not run here; needs merged code | not verified |
| Pint clean; CI green; reviewed | `vendor/bin/pint --dirty --format agent`; CI; review | pending |

## Out of scope

Tweak fields (RUB-560), notifications, read receipts (RUB-581), kinds beyond the set, and guidance files (RUB-553).
