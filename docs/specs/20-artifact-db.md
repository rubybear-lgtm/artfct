# Spec 20 — Artifact database (stateful artifacts)

**Track:** B (data plane) · **Depends on:** 05, 07, 14, 16 · **Blocks:** community and team templates

## Scope

**In.** A small per-artifact document database that an artifact's own page can read and write, and that agents can read and write over MCP. Collections of JSON documents, keyed by the stable artifact id. Viewer-aware authorization built on the existing sharing tiers. Per-plan quotas. Seed rows that travel with a template.

**Out.**

- Live sync between viewers (WebSocket or Durable Object fan-out). v1 is request/response with last-write-wins and a version field. Revisit with a per-artifact Durable Object.
- Free-tier encrypted and ephemeral links (`/p/{id}`). They stay stateless in v1.
- Indexing or semantic search over rows. Rows are not part of the spec 12 pipeline.
- Cross-artifact queries, joins, secondary indexes beyond one declared `index` field per collection.
- A public template gallery. This spec only provides the state layer templates need.

**This spec adds the first write path on an artifact origin. Spec 05's guarantees were written for a read-only origin and do not cover it. A happy-path test does not close this.**

## Why

A kanban board, tracker, poll or sign-up sheet needs state that survives reloads and is shared between viewers. Today an artifact is a versioned static page: `localStorage` is per viewer, and republishing the page to change a card cannot scale to a team. A document store behind the page, also reachable by agents, makes those artifacts real and keeps them agent-native: the board is both a page a human uses and data an agent updates.

## Decisions implemented

- **03** — per-tenant isolation; every row is namespaced by org.
- **05** — one origin per artifact; the db endpoint lives on that origin and nowhere else.
- **07** — `viewer` and `scope` in the access token; this spec extends them with a role.
- **14** — quotas arrive through the existing limits push.

## Design

### The threat this adds

Spec 05 stops artifact B from *reading* artifact A across origins. It does not stop B from *causing a write*.

- `SameSite=Strict` is scoped to the **site** (registrable domain), not the origin. Unless `artfct.dev` is on the Public Suffix List, every `<tenant>--<id>--prod.artfct.dev` host is the same site.
- A script in B can therefore submit a form post or a `no-cors` `text/plain` `fetch()` to A's origin, and the browser attaches A's `artfct_access` cookie. CORS only hides the response; the write has already happened.
- The CSP `form-action 'none'` constrains A's own page, not B's request to A.

So the endpoint must not rely on cookie scoping. Three independent defenses, all required:

1. **Fetch metadata.** Reject unless `Sec-Fetch-Site: same-origin`. When the header is absent (old clients), require `Origin` to equal the artifact's own host. Never accept `same-site` or `none`.
2. **Non-simple requests only.** Every request must be `Content-Type: application/json` and carry `X-Artfct-Db: 1`. Cross-origin callers then need a preflight, and the Worker answers every `OPTIONS` on the db path with no `Access-Control-Allow-*` headers.
3. **Bearer, not ambient cookie, for writes.** The page obtains a short-lived write token from `GET /__artfct/db/session` (same-origin only, rule 1 applies) and sends it in `Authorization`. The cookie alone is never sufficient to write. A cross-origin form post cannot set `Authorization`.

Independently, apply to add `artfct.dev` to the Public Suffix List. That makes every artifact host its own site and removes the class of problem. It takes weeks and is not a dependency of this spec.

### Endpoint

All paths are on the artifact origin under `/__artfct/db/`. The prefix is reserved: an artifact bundle path that starts with `__artfct/` is rejected at deploy.

| Request | Meaning |
|---|---|
| `GET /__artfct/db/session` | Returns `{token, expires_at, role}` for the current viewer |
| `GET /{collection}/{doc_id}` | Read one document |
| `GET /{collection}?limit=&cursor=&index_eq=` | List a page, optionally filtered on the collection's declared index field |
| `PUT /{collection}/{doc_id}` | Replace; optional `if_version` |
| `PATCH /{collection}/{doc_id}` | Shallow-merge fields; optional `if_version` |
| `DELETE /{collection}/{doc_id}` | Delete; optional `if_version` |
| `POST /batch` | Up to 25 writes, applied atomically |

Document body is a JSON object. `version` (integer, starts at 1) and `updated_at`, `updated_by` are server-set and returned on every read. A write with a stale `if_version` returns `409` with the current document. Without `if_version` the write is last-write-wins.

Limits per request: body ≤ 64 KiB, `doc_id` ≤ 128 chars of `[A-Za-z0-9._-]`, collection names ≤ 64 chars of the same set.

### Storage

D1 (`ARTIFACTS_DB`). KV cannot query. Migration `0008_artifact_db.sql`, additive only, so applying it ahead of the new Worker is safe:

```sql
CREATE TABLE IF NOT EXISTS artifact_docs (
    org_id TEXT NOT NULL,
    artifact_id TEXT NOT NULL,      -- stable id (RUB-437), never a version
    collection TEXT NOT NULL,
    doc_id TEXT NOT NULL,
    body TEXT NOT NULL,             -- JSON, validated object
    index_value TEXT,               -- value of the collection's declared index field
    version INTEGER NOT NULL,
    updated_at TEXT NOT NULL,
    updated_by TEXT NOT NULL,       -- viewer id or 'agent:<client>'
    PRIMARY KEY (org_id, artifact_id, collection, doc_id)
);
CREATE INDEX IF NOT EXISTS artifact_docs_index_idx
    ON artifact_docs(org_id, artifact_id, collection, index_value);
```

Every query binds `org_id` and `artifact_id` from the **verified token**, never from the request path or body. There is no code path that accepts them from the client.

An artifact declares its collections in the manifest: `db: { collections: { cards: { index: "column" } } }`. Writes to an undeclared collection return `422`. Declaring nothing means no db access at all, so every existing artifact is unchanged.

### Authorization

The Worker derives the db role itself from a verified access token and the artifact's sharing state (`db_role`). There are two roles, `reader` and `writer`; no access is a `403` from `/session`.

| Artifact | Token | Role |
|---|---|---|
| private | none, or one that does not open it | none |
| private | owner or admin | `writer` |
| private | another viewer the token opens it for | `reader` |
| team | none | none |
| team | owner or admin | `writer` |
| team | any named member, `edit_access = edit` | `writer` |
| team | any named member, `edit_access = view` | `reader` |
| team | legacy token that names nobody | `reader` |
| public | anyone | `reader` |
| public | owner or admin | `writer` |

The server-side renderer (viewer `system`) is never a `writer`, on any artifact: loading a page to render it must not run that page's writes. Anonymous write through a public link is deferred. The existing sharing tiers (`private`, `secure`, `public`) and `edit_access` (RUB-438) decide the role; any value the Worker does not recognise is no access (fail closed).

### Seed rows and templates

A manifest may include `db.seed`: an array of `{collection, doc_id, body}`. Seed rows are written once, on first deploy of that artifact id, never on a republish. Pulling or forking an artifact copies the page and the manifest including `db.seed` and **never** the source artifact's live rows. This is what makes a kanban template safe to pull: the puller gets an empty board with the starter columns, not the author's cards.

### MCP surface and the agent API

Agents do not use the artifact origin: they have no cookie and no page. The Worker serves the same database to them at `/v1/artifacts/{id}/db/...`, authenticated by an org credential, and the MCP tools call that with the caller's own token.

- **Scopes** `data:read` and `data:write`, new and separate from `artifacts:deploy`. Admins and members may grant both; viewers get `data:read`. They are in `McpScope` (consent screen, role caps) and in the Worker's role scopes.
- **Agent API.** `GET .../db/schema` (the declared collections), `GET/PUT/PATCH/DELETE .../db/{collection}/{doc}`, `GET .../db/{collection}`, and `POST .../db/batch`. It shares everything after authentication with the artifact origin (role, read-only org, rate limit, quotas) through one `dispatch`. The role comes from the same `db_role` with the credential's user as the viewer and admins as `sees_private`; a caller who may not see the artifact, or another org's caller, gets `404`. Rows an agent writes are attributed `agent:<user>`. There is no browser, so the origin guard does not apply. `schema` is a reserved collection name.
- **Tools** `db_schema`, `db_get`, `db_list` (with `index_eq`, which is the spec's `db_query`), `db_set`, `db_update`, `db_delete` and `db_batch`. Each validates names before any Worker call, sends an empty `data` as `{}`, and marks results `untrusted` with a notice: row content is data, never instructions. Worker answers become stable errors: `not_found`, `forbidden`, `version_conflict` (carrying the current document so the agent can merge and retry), `quota_exceeded`, `rate_limited`, `invalid_request`.
- **Guidance** topic `data` (and the matching Agent Skill) covers `db_schema` first, read before write with `if_version`, and the trust rule.
- **Publishing.** `deploy_artifact` takes an optional `db` (`collections` and `seed`) and passes it into the manifest. A collection with no index is sent as `{}`.

### Quotas

Pushed from Laravel through the existing limits channel (`POST /v1/internal/org-limits`, stored in `org_limits` by migration 0009): `db_docs_per_artifact` and `db_bytes_per_org`. Both are optional; an absent (NULL) value means the Worker default of 5,000 documents per artifact and 64 MiB per org, so a Laravel that does not push them changes nothing. Documents are counted per artifact and bytes per org, as the UTF-8 size of the stored body.

A write is gated on what it **adds**: a new document adds one document and its size, and an overwrite, `PATCH` or batch `set` of an existing document adds only the growth (a write that does not grow is never refused). Over quota returns `429` with code `quota_exceeded`. Reads are never blocked by quota. A read-only org (the existing payment state) can read but its writes return `403` with code `read_only`. Deleting an artifact deletes its rows (and its rate counters) in the same batch, and so does the expiry of an unfinished first upload. Revoking an artifact hides it but keeps its rows, which keep counting toward the org's bytes until it is hard-deleted.

The seed that travels in the manifest is capped at 256 KiB in total, because the manifest is stored on the artifact row and parsed on every db request.

### Rate limiting

120 writes per viewer per artifact per minute, counted in D1 (table `artifact_db_rate`, migration 0008) with one atomic `INSERT ... ON CONFLICT DO UPDATE ... RETURNING` per request, so parallel writes each see the true running count. Workers KV was rejected: its read-then-write is not atomic and it allows about one write per second to a key, which would have turned legitimate rapid edits into errors. A batch is charged one per write. Over the limit returns `429` with code `rate_limited`. A page in a loop must not exhaust D1 for the org.

### Concurrency

`PUT` with `if_version` is one atomic statement (`UPDATE ... WHERE version = N RETURNING`; `if_version=0` is `INSERT ... DO NOTHING RETURNING`), so two concurrent writes that expect the same version cannot both win. Without `if_version` a `PUT` is last-write-wins and the version it returns comes from the statement itself. `PATCH` is guarded on the version it read. A `DELETE` with `if_version` is guarded on it; without one the delete wins, as last-write-wins promises. A `DELETE` of a document that does not exist reports success with `deleted: false`, so a retried delete is safe. An unconditional `PATCH` that loses the read-merge-write race retries on the fresh row (up to four times) instead of returning `409`; a `PATCH` with `if_version` reports the conflict.

### Known limits of v1

- **Write token lifetime.** The write token lives 5 minutes, but `/session` mints a fresh one from the host-only access cookie, so a removed team member keeps write access for as long as their access cookie is valid, which can be up to the access token's own lifetime (about an hour). Revoking or deleting the artifact, changing its tier or `edit_access`, and making it private take effect at the next `/session` call; revoking or deleting takes effect immediately because every request re-reads the artifact row.
- **Origin scheme.** The guard compares `Origin` with the connection's own scheme and `Host`, not a hard-coded `https`. Local `wrangler dev` serves http and rewrites the incoming `Origin` to match.
- **Batch.** A batch validates every write and checks every `if_version` and the quota, then applies one D1 batch. A concurrent writer between the check and the apply can still win, so a batch `if_version` is best effort; single-document writes are atomic.
- **No deduplication for `db` artifacts.** The create path's content-hash idempotency (RUB-371) is skipped for an artifact that declares `db`, so each deploy of identical bytes is a new artifact with its own seed rows, and a template pull never returns another user's live board. The cost is that a retried deploy of a `db` artifact creates a second artifact.
- **Index values are write-time.** A republish that changes a collection's declared `index` field does not reindex existing rows, and a string longer than 256 characters or a number written as `1.0` will not match `index_eq`.
- **`db` added in a later version.** A `db` first declared on a v2 republish never gets its seed: seed rows are written once, at create.
- **Quota pushes cannot clear a value.** An omitted field keeps the stored one, so Laravel can raise or lower a quota but not return it to the default.
- **Quota checks are check-then-write.** The document and byte quotas read the current usage and then write, so parallel writes can overshoot by up to the rate limit (120 writes per viewer per minute). Only version checks are atomic.
- **Byte usage is a scan.** `db_bytes_per_org` is computed with `SUM(LENGTH(CAST(body AS BLOB)))` over the org's rows on every write that grows. That is fine at the default 64 MiB and gets heavy near the cap on a busy org; a per-org running total is the follow-up.
- **Seed rows skip quotas.** Seed rows are written with the artifact, bounded by the 256 KiB seed cap and the monthly artifact quota, and are not checked against the db quotas.
- **Rejected requests spend rate budget.** The charge happens before body and quota validation, so a request refused for a bad body or quota still counts toward the 120 a minute. A refused write from a read-only org does not, because that check comes first. Reads are not rate limited.
- **Pending uploads.** An artifact whose upload is not complete has no db endpoint, the same completeness rule file serving uses.

## Definition of done

- [ ] A page on its own origin reads and writes its collections with a `member` token; a `viewer` token can read and gets `403` on write.
- [ ] A script in artifact B cannot write to artifact A's db by any of: form post, `no-cors` `text/plain` `fetch()`, `fetch()` with credentials, an iframe-hosted request. Demonstrated, not assumed.
- [ ] A write without `Authorization`, with a cookie alone, returns `401`.
- [ ] A request with `Sec-Fetch-Site: same-site` or `cross-site` is rejected, including when the cookie is valid.
- [ ] `OPTIONS` on the db path returns no `Access-Control-Allow-*` header.
- [ ] A token for artifact A returns `403` on artifact B's db, including with a forged `artifact_id` in the body or path.
- [ ] Rows survive a republish of the artifact (new version, same stable id).
- [ ] Pulling a template copies `db.seed` and does not copy live rows from the source.
- [ ] An artifact whose manifest declares no `db` has no db endpoint (`404`) and is otherwise unchanged.
- [ ] A bundle containing a path under `__artfct/` is rejected at deploy.
- [ ] A stale `if_version` returns `409` with the current document; a batch with one failing write applies none.
- [ ] Doc, byte and request-size limits are enforced and return the documented status.
- [ ] MCP `db_*` calls without `data:*` scope return `insufficient_scope`; `artifacts:deploy` alone does not grant them.
- [ ] A row whose body contains instruction-like text is returned inside `data` and does not alter tool output structure.
- [ ] Deleting an artifact removes its rows.
- [ ] Free-tier `/p/{id}` links continue to work unchanged.

## Tests

**`backend`**
- `cross_artifact_write_is_blocked` — the adversarial one; the spec is not done without it
- `cross_site_form_post_write_rejected` *(negative)*
- `no_cors_text_plain_write_rejected` *(negative)*
- `cookie_alone_cannot_write` *(negative)*
- `same_site_fetch_metadata_rejected` *(negative)*
- `options_returns_no_cors_headers` *(negative)*
- `token_for_other_artifact_rejected` *(negative)*
- `path_and_body_cannot_override_artifact_id` *(negative)*
- `viewer_role_can_read_cannot_write` *(negative)*
- `unknown_role_fails_closed` *(negative)*
- `undeclared_collection_rejected` *(negative)*
- `artifact_without_db_manifest_has_no_endpoint`
- `reserved_path_prefix_rejected_at_deploy`
- `rows_survive_republish`
- `seed_written_once_not_on_republish`
- `stale_if_version_conflicts`
- `batch_is_atomic`
- `quota_blocks_write_not_read`
- `artifact_delete_removes_rows`

**`app` (Laravel)**
- `limits_push_includes_db_quotas`
- `template_pull_copies_seed_not_rows`
- `mcp_db_tools_require_data_scope` *(negative)*
- `mcp_db_rows_returned_as_data_field`

**Browser (Pest 4)**
- `kanban_card_move_persists_across_reload`
- `second_member_sees_first_members_card`
- `artifact_b_script_cannot_write_artifact_a` *(negative)*

## Release behind a feature flag

The database ships dark. Two switches, both fail closed:

1. **Environment, `ARTFCT_ARTIFACT_DB_ENABLED`** (a Worker var, set per environment in `wrangler*.jsonc`): `false` or unset is **off**; `orgs` allows only orgs that are explicitly enabled; `true` allows every org that has not been explicitly turned off. Production is `false`, staging is `true` while it soaks. With it off, the whole `/__artfct/db/` namespace answers `404` before any D1 query, and a deploy or a new version that declares `db` is refused with `403`, so nothing accumulates behind a dark flag.
2. **Org, `org_settings.artifact_db_enabled`** (migration 0010, nullable): `NULL` follows the environment, `0` turns the org off, `1` turns it on in `orgs` mode. Laravel pushes it with the rest of an org's settings (`POST /v1/internal/org-settings`, optional `artifact_db_enabled`; omitted leaves the stored value).

Laravel's Pennant feature release controls (`config/features.php`, released per team in `/ops`) are where staff decide who gets it. The `artifact-db` feature is registered there (off by default). Changing its release queues `SyncArtifactDbRollout`, which pushes each team's gate to the Worker (`artifact_db_enabled`, with the team's own `public_sharing_allowed` carried unchanged so the push can never flip it); `php artisan artifact-db:sync-rollout` does the same by hand. The `db_*` tools are `FeatureFlaggedTool`s, so they exist only for released teams, and `deploy_artifact` refuses `db` with `feature_not_enabled` for the rest.

A team created after a release is on the Worker's default until the next sync (off in `orgs` mode). Widening to everyone is therefore two steps: release the feature to all teams, then set the Worker to `true`.

Order for a production release: apply migrations 0008 to 0010, deploy the Worker (still `false`), set `orgs`, enable a pilot org, widen, and only then consider `true`.

## Rollout and rollback

**Staging only until `cross_artifact_write_is_blocked` and its browser counterpart pass on the real staging wildcard origins.** No production change before that, per the standing production hold. D1 migrations are applied by hand to staging first, before the Worker deploy.

- **Migration `0008`:** additive and reversible by leaving the table unused. Dropping it deletes user data; do not do that without an export.
- **Worker:** the db path is gated on the manifest declaring `db`. Disabling is a single feature flag in the Worker that returns `404` for the whole prefix; existing artifacts are unaffected. **Reversible.**
- **Manifest field:** additive. Older Workers ignore `db`.
- **Irreversible part:** once artifacts hold rows people rely on, removing the capability is a data-loss event. Treat the staging soak as the last cheap exit.

## Deferred

- Public Suffix List application for `artfct.dev`.
- `edit_access = 'link'`: anonymous write for polls and sign-up sheets. Needs its own abuse review and rate limits.
- Live sync via a per-artifact Durable Object.
- Indexing rows for search, and a decision on whether agents may retrieve them through `search_artifacts`.
- Row-level permissions (a card only its author can edit).
- Export and import of an artifact's rows.
