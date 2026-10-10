# Spec 4 — Bundle format and API

**Track:** B (data plane) · **Depends on:** 0, 3 · **Blocks:** 5

## Scope

**In.** Multi-file bundles with JavaScript, CSS and assets. The manifest upload flow. Entrypoint resolution. Declared external origins. The CLI speaking both dialects. Size and file-count limits.

**Out.** Serving them from isolated origins — spec 5. Until then bundles serve from the existing path, which is acceptable only because no org-private artifacts exist yet.

## Decisions implemented

- **05** — the tagged two-mode request; this spec fills in the `permanent` variant.
- **11** — open formats; a bundle is files, not an archive.

## Design

### Upload flow

Two steps, mirroring how the platform handles assets elsewhere:

1. `POST /v1/artifacts` with `mode: permanent` and a manifest. Server responds with the artifact id and a list of `sha256`s it does **not** already hold.
2. `PUT /v1/artifacts/{id}/files/{sha256}` for each missing file.

Content-addressing from spec 3 means a redeploy of a bundle where only `index.html` changed uploads one file. Agents redeploy near-identical artifacts constantly; this is where that pays.

An artifact is not servable until every manifest entry is present. Incomplete uploads expire after one hour.

### Manifest validation

- `entrypoint` must appear in `files[]`.
- Paths are relative, no leading `/`, no `..`, no absolute or Windows-drive forms. Traversal rejected at validation, not at serve time.
- Duplicate paths rejected.
- `sha256` recomputed server-side on receipt; a mismatch rejects the file.
- `content_type` from an allowlist; anything else stored as `application/octet-stream` and served with `X-Content-Type-Options: nosniff`.

### Limits

| Limit | Value | Rationale |
|---|---|---|
| Bundle total | 50 MB | Enterprise dashboards with embedded data blow through 1 MB immediately; 50 MB is generous without being a cost bomb |
| Single file | 25 MB | |
| File count | 500 | |
| Path length | 255 | |

Per-tenant quotas come in spec 14; these are absolute ceilings that protect the platform regardless of plan.

### External origins

`external_origins` is declared, not sniffed. Spec 5 derives each artifact's CSP from it. Declaring an origin does not make it safe — it makes it *visible*, which is what a security reviewer asks for.

### CLI dual dialect

`artfct deploy ./file.html` — ephemeral, E2EE, unchanged.
`artfct deploy ./dist/ --tier permanent` — walks the directory, builds the manifest, uploads missing files.
Entrypoint defaults to `index.html`, overridable with `--entrypoint`.

## Definition of done

- [ ] `artfct deploy ./dist/ --tier permanent` on a real Vite React build (HTML + JS + CSS + fonts) returns a URL that renders the working app.
- [ ] Re-running it after changing one file uploads exactly one file — verified in CLI output.
- [ ] A manifest whose entrypoint is absent from `files[]` is rejected with `code: entrypoint_missing`.
- [ ] A manifest containing `../etc/passwd` is rejected with `code: invalid_path`.
- [ ] A file whose bytes do not match its declared `sha256` is rejected with `code: hash_mismatch`.
- [ ] A 60 MB bundle is rejected with `code: bundle_too_large`.
- [ ] An artifact with an incomplete upload returns 404 from `/p/`, not a partial render.
- [ ] `artfct deploy ./x.html` with no tier still produces today's ephemeral fragment URL, byte-identical behaviour.
- [ ] All request and response shapes validate against the spec 0 contract.

## Tests

**`backend`**
- `manifest_missing_entrypoint_rejected` *(negative)*
- `manifest_path_traversal_rejected` *(negative)*
- `manifest_absolute_path_rejected` *(negative)*
- `manifest_duplicate_paths_rejected` *(negative)*
- `file_hash_mismatch_rejected` *(negative)*
- `bundle_over_limit_rejected` *(negative)*
- `file_count_over_limit_rejected` *(negative)*
- `incomplete_bundle_returns_404`
- `incomplete_upload_expires_after_one_hour`
- `only_missing_files_are_requested`
- `unknown_content_type_served_with_nosniff`

**`mcp-server`**
- `cli_builds_manifest_from_directory`
- `cli_skips_files_server_already_has`
- `cli_ephemeral_path_unchanged`
- `entrypoint_override_respected`

**Integration**
- `real_vite_build_deploys_and_renders`

## Rollback

**Irreversible for issued URLs.** Bundles stored under this spec are addressed by content hash and their URLs are permanent. The ephemeral path is untouched.

## Deferred

- Server-side build steps. Explicitly a non-goal: not static site hosting.
- Archive upload (tar/zip). A manifest gives dedupe; an archive does not.
