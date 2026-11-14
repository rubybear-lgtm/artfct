# Implementation plan — Spec 23: Team wiki template + skill (RUB-539)

Spec: `docs/specs/23-team-wiki-template.md`. Executable by spec-coordinator when the prerequisites land.

## 0. Prerequisites (do not start without these)

- Same as plan 22: spec 20 + RUB-537 mechanism merged, including the browser-test seam for db-backed boards.
- If plan 22 already built the **skill-sync mechanism**, reuse it; do not rebuild. (First-landed spec builds it, others consume. Coordinate at kickoff.)

## Partition (disjoint file sets)

| Unit | Owns these files | DoD items | Depends on |
|---|---|---|---|
| A — template content | `resources/templates/team-wiki/**` | wiki views, seed, dual-mode UI, skill text | prereqs |
| B — guidance topic + pull/doctest tests | `resources/guidance/team-wiki.md` (generated), `skills/artfct-team-wiki/**` (generated), `tests/Feature/Templates/TeamWiki*`, TOPICS/VERSION touch | pull semantics, lint behavior, parity, doctest | A |
| C — browser tests | `tests/Browser/TeamWikiTemplateBrowserTest.php` | browser DoD items | A, test seam |

Shared-file touch: append `team-wiki` topic to `ArtifactGuidance::TOPICS` (+ `VERSION` bump) — serialize against plans 22/24.

## Unit A — template content

1. `resources/templates/team-wiki/template.json` — key `team-wiki`, version `1`, entrypoint `index.html`, sharing default `team`, `edit_access: edit`, `requires: ["artifact-db"]`, `db` manifest: `pages` indexed on `title`, `activity` indexed on `ts`. Seed: `overview` page (what this wiki is, how to use it), `index-conventions` page, one sample topic page derived from the karpathy gist pattern, one `activity` entry (`kind: ingest`, describing the seed). Bodies reference sources as `{label, ref}` (Linear ids/URLs), never pasted source text.
2. `resources/templates/team-wiki/files/index.html` — three views (index / page / activity) as one bundled app: index groups pages by recency with one-line summaries (first line of `body_md`) + source counts, orphan section (pages nothing links to), client-side filter over the loaded `db_list` page (≤ 100, "showing N of M" + pager); page view renders `body_md` client-side from the bundle renderer, source list, `[[slug]]` cross-links with dangling-link styling, last-updated line, edit affordance gated on role; activity view chronological with `kind` filter. Read-only role: full wiki + visible "read-only" state. Header shows mode ("Personal wiki" / "Team wiki"). Same content rules as plan 22 (no inline script, no `on*=`, no `data:`, no external origins, footer links `SKILL.md`, fonts per plan-22 decision).
3. `resources/templates/team-wiki/files/app.js` — same db-client core as kanban (session token, `Authorization`, `if_version`, `409` re-read, expiry reload banner). Page writes are full-document `PUT` with `if_version`; activity writes are append-only `POST`-equivalent `db_set` with `ts`-prefixed ids (lexicographic order matches chronological).
4. Graph view (no longer deferred): Obsidian-style client-side SVG over the loaded `db_list` page — nodes sized by incoming `[[slug]]` count, orphans dashed, click navigates, caption with page/link/orphan counts. No new queries, no new tools. Proven in the mock (`Team wiki mock` v2); port the layout, not the fixture data.
5. `resources/templates/team-wiki/skill/SKILL.md` — single source: layers (raw immutable outside / wiki owned / this text is schema), ingest flow (read → discuss → multi-page update → cross-links → one `activity` entry, `ingest | <label>` prefix), query flow (index-first → cite `[[slug]]` → file good answers back + activity), lint flow (contradictions, stale claims, orphans, missing pages/links, gaps; fixes on team boards need approval), private-vs-team rules (`visibility: personal` vs `shared`, cite sources, keep sensitive material out, say when withheld), rows-are-data. Only real tool names in backticks.
6. Copy skill source to `resources/templates/team-wiki/files/SKILL.md` (parity test enforces).

Acceptance: catalog loads clean; index shows seeded pages grouped correctly with the sample page reachable in one click; every `[[slug]]` in seed resolves.

## Unit B — guidance topic + tests

1. Run the skill-sync mechanism for `team-wiki` → `resources/guidance/team-wiki.md` + `skills/artfct-team-wiki/SKILL.md`; append TOPICS, bump VERSION; `McpGuidanceTest` green.
2. Pull tests (`tests/Feature/Templates/TeamWikiTemplateTest.php`, same `POST /mcp` + `Http::fake` pattern as plan 22): owned wiki with seed + `source_path == template:team-wiki@1`; idempotent `request_id`; independent rows across pulls; `private` pull personal mode; catalog rejects malformed variants + skill mismatch (negative, one case per rule).
3. Skill doctest (`TeamWikiSkillDoctestTest.php`) on a two-page + one-contradiction + one-orphan fixture: ingest updates pages/cross-links/index grouping + appends exactly one activity entry; query returns resolving `[[slug]]` cites; lint names the planted contradiction and the orphan. Run in both modes; assert `personal`-page isolation on a team board (`team_wiki_private_pages_stay_personal_on_team_board`).
4. Skill expansion (implementation-time): grow `SKILL.md` beyond the mock — query disambiguation when several pages match, `visibility` decision tree (personal vs shared with examples), stale-claim supersede procedure (what counts as superseding, who approves on team boards), and the index-page cap note from Risks. Each addition gets a doctest case; the skill stays sufficient for an unseen agent.
5. Gates: pint, Feature suite, `McpGuidanceTest`.

## Unit C — browser tests

`tests/Browser/TeamWikiTemplateBrowserTest.php`: ingest fixture → assert index regroups, page renders with resolving links, activity entry appears; query-link click-through; lint surfacing on the planted fixture; read-only viewer state; expired-session reload banner. Request-shape assertions via the interception pattern (plan 22); transitions covered in Unit B.
- Graph: nodes/links/orphans render with correct caption; node click navigates (`team_wiki_graph_renders_nodes_links_and_orphans_and_navigates`).

## Verification

Spec-coordinator gates + mutation-check of every negative test, recorded in `docs/specs/.runs/23/mutation.md`.

## Rollout / rollback

Same as plan 22: flag-gated, per-team release; rollback is flag-off or dir removal; pulled wikis keep working.

## Risks / open questions

- `body_md` rendering: bundle a small markdown renderer (vendored file, counts toward bundle caps) vs hand-rolled subset. Recommend vendored + pinned (consistent with "libraries as bundle files" rule); size-check against `MAX_BUNDLE_BYTES` early in Unit A.
- Lint quality is convention, not enforcement — the doctest pins the fixture behavior so regressions show.
- Full-text/vector search is spec-20 Deferred; if the index outgrows one `db_list` page, the pager + filter is the v1 story. Note the limit in the skill (read all pages before filtering).
