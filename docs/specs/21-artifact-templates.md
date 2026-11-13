# Spec 21 — Artifact templates

**Track:** F (thesis) · **Depends on:** 20, 14, 07 · **Blocks:** team templates and a public gallery

## Scope

**In.** Artfct-authored templates a person can **pull** into their team's library: a reviewed set of files plus, for a template that keeps data, a `db` declaration with seed rows. One product-quality template to start, a kanban board. Two surfaces: the MCP tools `list_templates` and `pull_template`, and a "Use template" entry in the library. A pull creates a new artifact in the puller's team; nothing is shared between the template and the copy afterwards.

**Out.**

- Team templates (marking one of your own artifacts as a template) and a public gallery. Both put someone else's HTML and text in front of a team's agents and need moderation and sandboxing work; they come after this has proven useful.
- Templates that update their copies. A pulled artifact is a plain copy. The pull records the template key and version so an "update available" prompt can be added later.
- Paid templates, ratings and search over templates.
- A template editor. Templates are changed by changing files in the repository.

## Why

An empty library is the hardest moment for a new team, and a kanban board is the clearest demonstration of what the artifact database enables: a page and its agents sharing one live board. Pulling a template turns "an AI made me a page once" into "a thing my team uses every day", with no authoring step.

## Decisions implemented

- **20** — the artifact database. A template with `db` is a manifest with collections and seed rows; a pull never copies live rows, and the create path already skips content dedupe for `db` artifacts so every pull is its own artifact.
- **14** — quotas, the daily publish limit and plan gates. A pull is a publish and is subject to all of them.
- **07** — the pull runs as the caller. The new artifact is owned by the puller and their credential decides what they may create.

## Prerequisites found in review

An independent review of this spec found three problems that must be settled first. Each was checked against the running system where it could be.

1. **A version publish silently drops the database.** Checked on staging: republishing the kanban board with `deploy_artifact` and `artifact_id` but without `db` left all 4 rows in D1, removed `db` from the current manifest, and made `db_schema` answer `404`. Agents edit pages by version publish constantly ("make the board blue"), so any edit to a pulled board would break it. **Rule:** a version publish that omits `db` carries the previous declaration forward; removing a database takes an explicit `db: null`, which leaves the rows in place and the endpoint off. The carry-forward lives in the Worker (`post_version`), so every client gets it. This is a fix to spec 20, ships first as its own change, and also protects every `db` artifact already in production.
2. **A pull from the library has no Worker credential.** `deploy_artifact` runs as an MCP caller, with the bearer token, team, actor, provenance and telemetry all coming from the connection. A browser session has none of that. So `ArtifactPublisher` takes an **explicit team, actor and credential** instead of reading `McpContext`, and the library action mints a short-lived org JWT for the user with `OrgJwtService::mint` (scope `artifacts:deploy`, a few minutes). Provenance and telemetry come from a builder that works without an MCP connection. Nothing in the web controllers calls the Worker for a user today, so this pattern is new.
3. **"Reload to keep saving" may not be possible.** After the access TTL, both the iframe's `?token=` and the access cookie are expired, so reloading the frame would hit a 403, and the viewer's sandbox grants no top navigation, so the page cannot reload the viewer itself. Not yet verified end to end (shorten `services.artifact_access.token_ttl_minutes`, open a board, wait, reload the frame). Preferred mechanism, subject to that check: the **app shell** (which is not the artifact, so spec 05 is intact) re-mints the access token before it expires and swaps the iframe `src`. The page's own fallback, if the shell cannot, is a clear message to reload the whole tab.

## Design

### Where templates live

Reviewed files in the repository, never a copy of a live artifact:

```
resources/templates/<key>/
    template.json
    files/index.html, app.js, ...
```

`template.json`:

| Field | Meaning |
|---|---|
| `key` | stable slug, `[a-z0-9-]+`; the identity of the template |
| `version` | integer, bumped when the files change |
| `title`, `description` | shown in the gallery and used as the new artifact's title and description |
| `artifact_type` | one of the existing artifact types |
| `entrypoint` | path under `files/` |
| `sharing`, `edit_access` | defaults for the copy; a board needs `edit_access: edit` so teammates can move cards |
| `requires` | features that must be released to the team, for example `["artifact-db"]` |
| `db` | the spec 20 manifest section: `collections` and `seed` |

This keeps "Artfct-authored" literally true: nothing reaches a team that was not reviewed in a pull request, and there is no moderation step. A `TemplateCatalog` loads and validates every template (see Tests) so a malformed one fails CI, not a pull.

Constraints a template must meet, checked by the catalog and by a test that runs over every template:

- no inline `<script>`, no `on*=` handlers, no `data:` images, no external origins: the same rules `deploy_artifact` warns about, enforced as errors;
- fonts and images are bundled files, because the page is served with `script-src 'self'` and no external font host;
- total size within the bundle limit; `db` valid under spec 20's `validate_db_manifest` rules, including the 256 KiB seed cap.

### The pull

`TemplatePuller::pull(team, actor, template, options)`:

1. The template must be available to the team: every feature in `requires` is released for it (`FeatureFlags::enabled`), and `artifact-templates` itself is released. Otherwise it fails with `feature_not_enabled`.
2. It publishes through the **same path as `deploy_artifact`**: quota check, daily publish limit, plan gate, the Worker create and file uploads, audit event, analytics event. The implementation extracts the publish core of `DeployArtifactTool` into an `ArtifactPublisher` service that the tool and the puller both call. This is the main risk of the work, because it refactors a stable tool; it is done in its own commit and covered by the existing tool tests before anything else changes.
3. The new artifact has a new id, the puller as owner, the template's default sharing and edit access (the puller may choose `private` or `team`), and a title the puller may change. Seed rows are written once, fresh.
4. Provenance records how it was made: tool `pull_template`, and `source_path` set to `template:<key>@<version>`. No Worker change is needed, and a later "update available" can read it.

**Failed and half-finished pulls.** A pull is a create followed by file uploads, so it can fail between them. The pull takes a lock on `(team, template_key, request_id)`, writes the `template_pulls` row only after the artifact is complete, and on failure removes the pending artifact, so a retry starts clean and nothing counts against the org's quota. (A created-but-never-uploaded artifact keeps its seed rows and its byte count today; the pull must not leave one behind.)

**Idempotency.** Because a `db` artifact is never deduplicated by content, a retry or a double click would create two boards. A pull takes a `request_id` (the UI generates one when the dialog opens; the MCP tool accepts an optional one). A table `template_pulls (team_id, template_key, request_id, artifact_id)` with a unique key on the first three makes the same pull return the same artifact. Rows older than 24 hours are pruned.

### Surfaces

- **MCP.** `list_templates` (scope `artifacts:read`) returns key, title, description, version, what it requires and whether it is available to the team. `pull_template` (scope `artifacts:deploy`) takes `template`, optional `title`, `sharing`, `collection` and `request_id`, and returns the new artifact's id and `view_url`. Both are `FeatureFlaggedTool`s on a new `artifact-templates` feature, so they are hidden until released, and the release smoke leaves them out of the expected catalog (the existing flag-gated rule). They are documented in `AiToolSetup` and in a new guidance topic.
- **Library.** A Templates section in the library (the console artifacts list) with a card per available template, and a "Use template" dialog: title, sharing, confirm. The result opens the new artifact. It follows `DESIGN.md` (Oxblood & Bone, hairline dividers, no boxed-card gallery), shows an empty state when none is available, and is hidden entirely when `artifact-templates` is not released to the team. Coordinated with the sidebar-layout work already in progress.

**Agents must be able to find a board.** The cards live in database rows, which are not indexed, so `search_artifacts` and `read_artifact` only see a template's boilerplate. `get_artifact` and `list_artifacts` therefore report `database: {collections: {...}}` for an artifact that has one, and the `data` guidance says to call `db_schema` before answering a question about such an artifact.

**Scopes.** A connection made before the data scopes existed lacks `data:read` and `data:write` (we saw this in production), so a pulled board's tools would fail with `insufficient_scope`. `pull_template` checks the caller's scopes and says to reconnect to let the agent use the board; the `db_*` scope error carries the same next action.

### The kanban template

Product quality, not the soak page:

- Bundled fonts and the design tokens from `DESIGN.md`; no external requests.
- Columns, cards, add, move left and right, delete; keyboard operable; sensible mobile layout.
- **Session lifetime.** The page's database token comes from the host-only access cookie, which lives as long as the access token (60 minutes by default). A board left open past that stops being able to refresh its token. The page must show a clear "This board needs to be reloaded to keep saving" state instead of failing silently, and reload cleanly. Spec 05 forbids a postMessage refresh, so reloading is the mechanism. Verified by leaving a board open past the expiry (the access TTL is configurable, so the test shortens it).
- Seed: three starter cards that explain the board, in their own columns.
- A board lists at most 100 cards per page, so it pages, and shows how many are not shown.

### Gate

Production's Worker is in `true` mode so a team created after the last release sync can use the database (a pull would otherwise fail for exactly the new users it is for). The two Laravel features decide who sees templates: `artifact-db` for templates that need it, `artifact-templates` for the surface. Both ship dark and are released per team in `/ops`.

## Definition of done

- [ ] `TemplateCatalog` loads `resources/templates/kanban` and rejects a template with an inline script, an external origin, an invalid `db` section, a missing file or an oversized bundle.
- [ ] A team with both features released pulls `kanban` through `pull_template` and gets a working board: its own id, the puller as owner, the default sharing and edit access, three seed cards, and `source_path` `template:kanban@1`.
- [ ] Pulling twice with the same `request_id` returns the same artifact; with different ids it makes two, each with its own seed rows and none of the first's live rows.
- [ ] A pull is refused with `feature_not_enabled` when either feature is not released, `quota_exceeded` and the daily limit apply exactly as for `deploy_artifact`, and a viewer without `artifacts:deploy` is refused.
- [ ] `list_templates` and `pull_template` are absent for an unreleased team, and the release smoke's expected catalog is unchanged.
- [ ] The library shows the Templates section only when released, and "Use template" creates the artifact and opens it; a double click makes one board.
- [ ] A pulled board works in a real browser (add, move, an agent's change appears) and shows the reload state after its session expires.
- [ ] A version publish without `db` keeps the database reachable (the rows and the endpoint), and only `db: null` turns it off; checked on a pulled board edited through `deploy_artifact`.
- [ ] A library pull works with no MCP connection: it mints a short-lived credential, and a failed pull leaves no artifact, no seed rows and no `template_pulls` row.
- [ ] A board open past the access TTL recovers or shows the reload message, per the verified mechanism.
- [ ] `get_artifact` and `list_artifacts` report the database and its collections, and `pull_template` names the missing scope when the caller lacks one.
- [ ] The `deploy_artifact` tests pass unchanged after the `ArtifactPublisher` extraction.
- [ ] The existing catalog, docs, contract and guidance tests pass with the new tools.

## Tests

**Laravel (Pest)**
- `catalog_loads_the_kanban_template`
- `catalog_rejects_inline_script_external_origin_bad_db_missing_file_and_oversize` *(negative)*
- `every_shipped_template_passes_the_content_rules`
- `pull_creates_an_owned_artifact_with_seed_and_provenance`
- `pull_is_idempotent_per_request_id`
- `pull_without_request_id_twice_makes_two_artifacts`
- `pull_refused_when_artifact_db_or_templates_not_released` *(negative)*
- `pull_obeys_quota_daily_limit_and_scope` *(negative)*
- `list_templates_marks_unavailable_templates`
- `template_tools_hidden_until_released` *(negative)*
- `publisher_extraction_keeps_deploy_artifact_behaviour` (the existing tool tests)
- `library_pull_runs_without_an_mcp_connection_and_cleans_up_on_failure`
- `get_artifact_and_list_artifacts_report_the_database`
- `pull_template_names_the_missing_scope`
- `library_templates_section_visibility_and_pull_flow` (feature + browser)

**Worker**
- `version_publish_without_db_keeps_the_declaration` and `version_publish_with_db_null_turns_the_endpoint_off`
- Covered by spec 20's tests: a pulled `db` artifact gets its own rows and the seed once. One new integration test pulls the same template twice and asserts the live rows are independent.

**Browser (Pest 4)**
- `kanban_template_board_moves_cards_and_shows_reload_after_session_expiry`
- `use_template_dialog_double_click_makes_one_board`

## Rollout and rollback

**Order.** The version-publish fix to spec 20 ships first, because it protects every `db` artifact that exists now. Before widening the database to new sign-ups (the Worker's `true` mode), the independent review of the spec 20 agent API and `db_*` tools is run; it was flagged during that work and not completed. `resources/templates/` is a new top-level folder, called out here for approval.

Staging first, then production behind `artifact-templates` (off), then one team in `/ops`, then everyone. A migration adds `template_pulls`, additive. Rollback is releasing `artifact-templates` back to off, which hides the tools and the library section at once; pulled artifacts are ordinary artifacts and stay. Reverting the `ArtifactPublisher` extraction is a code revert with no data effect.

## Deferred

- Team templates and a public gallery, with the moderation and sandbox decisions they need.
- Updating a pulled artifact when its template changes.
- More templates (a tracker, a decision log, a weekly report), each with its own design and tests.
- Template usage analytics beyond the existing publish event.
