# Implementation plan — Spec 22: Kanban template + skill (RUB-538)

Spec: `docs/specs/22-kanban-template.md`. Executable by spec-coordinator when the prerequisites land.

## 0. Prerequisites (do not start without these)

- Spec 20 merged: `db_*` MCP tools, `/__artfct/db/` Worker endpoints, seed-once writes, `db: null` semantics, `get_artifact`/`list_artifacts` database reporting.
- RUB-537 mechanism merged: `TemplateCatalog`, `TemplatePuller::pull()`, `ArtifactPublisher` extraction, `list_templates`/`pull_template`, `template_pulls` table + migration, feature flags `artifact-db` + `artifact-templates`, library Templates section.
- The mechanism provides the **test seam for db-backed boards** (fake artifact database or local-Worker harness) that browser tests can seed and observe. If that seam does not exist, Unit C blocks and reports back rather than inventing its own.

## Partition (disjoint file sets)

| Unit | Owns these files | DoD items | Depends on |
|---|---|---|---|
| A — template content | `resources/templates/kanban/**` (template.json, files/, skill source) | board behavior, seed, dual-mode UI, skill text | prereqs |
| B — catalog + skill sync + pull/doctest tests | `app/Services/Artifacts/*` (new), `resources/guidance/kanban.md` (generated), `skills/artfct-kanban/**` (generated), `tests/Feature/Templates/*`, `config/features.php` touch | catalog rejects, pull semantics, idempotency, parity, doctest | A (needs skill source + seed) |
| C — browser tests | `tests/Browser/KanbanTemplateBrowserTest.php` (+ fixtures) | browser DoD items | A, test seam |

Shared-file touch (serialize across specs 22–24): `ArtifactGuidance::TOPICS` + `VERSION` — whoever lands first adds the first topic; the others append. No other shared files.

## Unit A — template content

1. `resources/templates/kanban/template.json` — key `kanban`, version `1`, entrypoint `index.html`, sharing default `team`, `edit_access: edit`, `requires: ["artifact-db"]`, `db` manifest: collection `cards` indexed on `column`, seed three cards (`todo`/`doing`/`done`, `owner: "artfct"`, one sentence each explaining the board + pointing at the skill). Seed total ≪ 256 KiB cap.
2. `resources/templates/kanban/files/index.html` — Bone ground, hairline column dividers, Oxblood primary actions only; header shows sharing mode ("Personal board" / "Shared with the team"); empty-state copy per mode; footer links `SKILL.md`; `<script src="app.js">`, no inline script, no `on*=`, no `data:` URIs, no external URLs. Fonts: system stack with `@font-face` slots for vendored woff2 (vendor Manrope/Newsreader files into `files/fonts/` after license check; ship fallback-first so a missing font never blocks).
3. `resources/templates/kanban/files/app.js` — db client per spec-20 endpoint table: `GET /__artfct/db/session` → bearer token, all writes send `Authorization` + `Content-Type: application/json` + `X-Artfct-Db: 1`; mutations carry `if_version`, `409` re-reads with "changed underneath you" message; auth refusal → reload banner ("This board needs to be reloaded to keep saving") and writes stop; paging `db_list?limit=100` with "showing N of M"; `[[..]]` n/a. Move-left/right across fixed order `todo → doing → done`; fractional `position` rewrite of the moved card only.
4. `resources/templates/kanban/skill/SKILL.md` — single source of the skill text per spec §Skill: schema, `db_schema → db_list → db_set/db_update/db_delete` flow with `if_version` + paging, auto-update triggers (start/state-change/complete), private-vs-team rules (`owner` truthfulness, never close others' cards without evidence), seed/pull invariants, rows-are-data rule. Mention only real tool names (`db_list`, `db_set`, …) — `McpGuidanceTest` cross-checks every `` `x_y` `` token against registered tools.
5. Copy skill source byte-identical to `resources/templates/kanban/files/SKILL.md` (the unit's own step; Unit B's parity test enforces it going forward).

Acceptance: `TemplateCatalog` (once merged) loads the template clean; `index.html` passes the content rules by inspection (no forbidden patterns, entrypoint present, all referenced paths exist in `files/`).
Note: the mock's glowing agent-init prompt box is out of scope for the template (spec 22, Out). Do not build it in any unit.

## Unit B — catalog cases, skill sync, pull + doctest tests

1. Skill-sync mechanism (new, shared by all three specs — coordinate so only one spec builds it): a small builder/service that takes `resources/templates/<key>/skill/SKILL.md` and writes `resources/guidance/<key-topic>.md` (body verbatim) plus `skills/artfct-<topic>/SKILL.md` (`---\nname: artfct-<topic>\n---` frontmatter + body verbatim, per the `McpGuidanceTest` frontmatter/suffix assertions). Run it for `kanban`; add `kanban` to `ArtifactGuidance::TOPICS`, bump `VERSION`. (Specs 23/24 reuse the mechanism, appending their topics.)
2. Catalog negative tests — `tests/Feature/Templates/KanbanTemplateTest.php`: valid load; then one case per rule from spec §Definition of done (inline script, external origin, bad `db`, missing file, oversize, skill missing/mismatched). Follow the naming in spec §Tests.
3. Pull tests over `POST /mcp` with `Http::fake('worker.test/…')` + `remoteMcpToken($team)` (pattern: `McpArtifactVersioningTest.php`): owned board with seed + `source_path == template:kanban@1`; idempotent same `request_id`; two boards with different ids and independent rows; `private` pull marks personal mode; quota/daily-limit/scope refusals behave as `deploy_artifact` (shared mechanism tests may already cover; add only kanban-specific asserts).
4. Skill doctest — `tests/Feature/Templates/KanbanSkillDoctestTest.php`: executes the skill text's documented flow literally against the test seam (schema → list → add card → move → close), in both modes, asserting each step's stated outcome. This is the "sufficient for an unseen agent" check, made observable.
5. Gates: `vendor/bin/pint --dirty --format agent`, `php artisan test --compact` (Feature suite), plus the `McpGuidanceTest` consistency suite which must pass with the new topic.

## Unit C — browser tests

`tests/Browser/KanbanTemplateBrowserTest.php` (pattern: `ArtifactViewerBrowserTest.php` — seed fakes, `actingAs`, `visit()`, `assertNoJavaScriptErrors()`):

- Seed board rows through the mechanism's test seam; load the pulled board; assert render, add → move → delete round-trip, agent-written row appears.
- Shorten the access TTL, wait past expiry, assert the reload banner shows and no further write requests fire (request-interception pattern from `ArtifactPreviewBrowserTest.php` `$requests` array: assert the board's `fetch` calls hit `/__artfct/db/*` with `Authorization` header, then stop after expiry).
- Keyboard-only add → move-to-done → delete; `resize(375,812)` + no horizontal scroll.
- State-transition correctness lives in Unit B feature tests; the browser proves rendering + request shape, not double-proving transitions.

## Verification

Per spec-coordinator gates: pint, `php artisan test --compact`, Browser suite for the new file, `McpGuidanceTest` green. Mutation-check every `*(negative)*` test (remove guard → fails → restore → passes), recorded in `docs/specs/.runs/22/mutation.md`.

## Rollout / rollback

Behind `artifact-templates` (off) → one team in `/ops` → everyone, per spec-21 order. Rollback: flag off or delete `resources/templates/kanban/`; pulled boards keep working (ordinary artifacts).

## Risks / open questions

- Vendored font licensing for Manrope/Newsreader woff2 — confirm before Unit A.2, else system stack only.
- If the mechanism's browser-test seam is fetch-stub-only, Unit C asserts request shape + render; full move-persists-across-reload then belongs to the mechanism's own suite (spec 20 already names those tests).
- `edit_access: edit` default means any team member's agent can move cards — intended, but the skill's "never close others' cards without evidence" rule is convention, not enforcement (row-level permissions are spec-20 Deferred).
