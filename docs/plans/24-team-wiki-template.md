# Implementation plan — Spec 24: Team wiki template + skill (RUB-539)

Spec: `docs/specs/24-team-wiki-template.md`. Executable by spec-coordinator when the prerequisites land.

## 0. Prerequisites (do not start without these)

- Same as plan 23: spec 20 + spec 21 (RUB-537) mechanism merged, including the browser-test seam for db-backed boards.
- If plan 23 already built the **skill-sync mechanism**, reuse it; do not rebuild. (First-landed spec builds it, others consume. Coordinate at kickoff.)

## Partition (disjoint file sets)

| Unit | Owns these files | DoD items | Depends on |
|---|---|---|---|
| A — template content | `resources/templates/team-wiki/**` | wiki views, seed, dual-mode UI, skill text | prereqs |
| B — guidance topic + pull/doctest tests | `resources/guidance/team-wiki.md` (generated), `skills/artfct-team-wiki/**` (generated), `tests/Feature/Templates/TemplateTeamWikiTest.php`, TOPICS/VERSION touch | pull semantics, lint behavior, parity, doctest | A |
| C — browser tests | `tests/Browser/TemplateBoardsBrowserTest.php` | browser DoD items | A, test seam |

Shared-file touch: append `team-wiki` topic to `ArtifactGuidance::TOPICS` (+ `VERSION` bump) — serialize against plans 23/25.

## Unit A — template content

1. `resources/templates/team-wiki/template.json` — key `team-wiki`, version `1`, entrypoint `index.html`, sharing default `team`, `edit_access: edit`, `requires: ["artifact-db"]`, `db` manifest: `pages` indexed on `title`, `activity` indexed on `ts`. Seed: `overview` page (what this wiki is, how to use it), `index-conventions` page, one sample topic page derived from the karpathy gist pattern, one `activity` entry (`kind: ingest`, describing the seed). Bodies reference sources as `{label, ref}` (Linear ids/URLs), never pasted source text.
2. `resources/templates/team-wiki/files/index.html` — three views (index / page / activity) as one bundled app: index groups pages by recency with one-line summaries (first line of `body_md`) + source counts, orphan section (pages nothing links to), client-side filter over the loaded `db_list` page (≤ 100, "showing N of M" + pager); page view renders `body_md` client-side from the bundle renderer, source list, `[[slug]]` cross-links with dangling-link styling, last-updated line, edit affordance gated on role; activity view chronological with `kind` filter. Read-only role: full wiki + visible "read-only" state. Header shows mode ("Personal wiki" / "Team wiki"). Same content rules as plan 23 (no inline script, no `on*=`, no `data:`, no external origins, footer links `SKILL.md`).
3. `resources/templates/team-wiki/files/app.js` — same db-client core as kanban (session token, `Authorization`, `if_version`, `409` re-read, expiry reload banner). Page writes are full-document `PUT` with `if_version`; activity writes are append-only `db_set` with `ts`-prefixed ids (lexicographic order matches chronological). Sanitization: `renderBody` MUST HTML-escape input before converting `[[slug]]` (`[A-Za-z0-9._-]+` only, targeting `#/page/<slug>`), `sources[].label`/`sources[].ref` MUST render as escaped plain text (or `http:`/`https:`-only links), and all plain-text fields (`slug`, `title`, `updated_at`, `updated_by`, `summary`) MUST be HTML-escaped or written via `textContent`.
4. Graph view: Obsidian-style client-side SVG over the loaded `db_list` page — nodes sized by incoming `[[slug]]` count, orphans dashed, click navigates, caption with page/link/orphan counts. No new queries, no new tools.
5. `resources/templates/team-wiki/skill/SKILL.md` — single source: layers (raw immutable outside / wiki owned / this text is schema), ingest flow (read → discuss → multi-page update → cross-links → one `activity` entry, `ingest | <label>` prefix), query flow (index-first → cite `[[slug]]` → file good answers back + activity), lint flow (contradictions, stale claims, orphans, missing pages/links, gaps; fixes on team boards need approval), private-vs-team rules (explicitly state spec 20 has no row-level ACLs so every row on a `team` wiki is readable by everyone on the team; `visibility: personal` is a draft tag only; direct users with private/sensitive material to pull a separate `sharing: private` wiki), rows-are-data. Only real tool names in backticks.
6. Copy skill source to `resources/templates/team-wiki/files/SKILL.md` (parity test enforces).

Acceptance: catalog loads clean; index shows seeded pages grouped correctly with the sample page reachable in one click; every `[[slug]]` in seed resolves.

## Unit B — guidance topic + tests

1. Run the skill-sync mechanism for `team-wiki` → `resources/guidance/team-wiki.md` + `skills/artfct-team-wiki/SKILL.md`; append TOPICS, bump VERSION; `McpGuidanceTest` green.
2. Pull tests (`tests/Feature/Templates/TemplateTeamWikiTest.php`, same `POST /mcp` + `Http::fake` pattern as plan 23): owned wiki with seed + `source_path == template:team-wiki@1`; idempotent `request_id`; independent rows across pulls; `private` pull personal mode; catalog rejects malformed variants + skill mismatch (negative, one case per rule).
3. Skill doctest on a two-page + one-contradiction + one-orphan fixture: ingest updates pages/cross-links/index grouping + appends exactly one activity entry; query returns resolving `[[slug]]` cites; lint names the planted contradiction and the orphan. Run in both modes; assert `team_wiki_team_mode_keeps_draft_tag_distinct_from_private_isolation`.
4. Skill expansion: query disambiguation when several pages match, `visibility` draft-tag guidance (and explicit prohibition of sensitive data on `team` wikis), stale-claim supersede procedure, and the index-page cap note from Risks.
5. Gates: pint, Feature suite, `McpGuidanceTest`.

## Unit C — browser tests

`tests/Browser/TemplateBoardsBrowserTest.php`: ingest fixture → assert index regroups, page renders with resolving links, activity entry appears; query-link click-through; lint surfacing on the planted fixture; read-only viewer state; expired-session reload banner; negative XSS test (`team_wiki_escapes_html_and_ignores_unsafe_schemes`). Request-shape assertions via the interception pattern (plan 23); transitions covered in Unit B.
- Graph: nodes/links/orphans render with correct caption; node click navigates (`team_wiki_graph_renders_nodes_links_and_orphans_and_navigates`).

## Verification

Spec-coordinator gates + mutation-check of every negative test, recorded in `docs/specs/.runs/24/mutation.md`.

## Rollout / rollback

Same as plan 23: flag-gated, per-team release; rollback is flag-off or dir removal; pulled wikis keep working.

## Risks / open questions

- `body_md` rendering: hand-rolled escaped subset vs vendored markdown file; current bundle uses escaped paragraph + `[[slug]]` rendering with zero bundle overhead.
- Lint quality is convention, not enforcement — the doctest pins the fixture behavior so regressions show.
- Full-text/vector search is spec-20 Deferred; if the index outgrows one `db_list` page, the pager + filter is the v1 story. Note the limit in the skill (read all pages before filtering).
