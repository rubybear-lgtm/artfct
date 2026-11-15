# Implementation plan — Spec 25: Announcement board template + skill (RUB-540)

Spec: `docs/specs/25-announcement-board-template.md`. Executable by spec-coordinator when the prerequisites land.

## 0. Prerequisites (do not start without these)

- Same as plan 23: spec 20 + spec 21 (RUB-537) mechanism merged, including the browser-test seam.
- If plan 23 or 24 already built the **skill-sync mechanism**, reuse it.

## Partition (disjoint file sets)

| Unit | Owns these files | DoD items | Depends on |
|---|---|---|---|
| A — template content | `resources/templates/announcements/**` | board behavior, seed, dual-mode UI, skill text | prereqs |
| B — guidance topic + pull/doctest tests | `resources/guidance/announcements.md` (generated), `skills/artfct-announcements/**` (generated), `tests/Feature/Templates/TemplateAnnouncementsTest.php`, TOPICS/VERSION touch | pull semantics, publish rules, parity, doctest | A |
| C — browser tests | `tests/Browser/TemplateBoardsBrowserTest.php` | browser DoD items | A, test seam |

Shared-file touch: append `announcements` topic to `ArtifactGuidance::TOPICS` (+ `VERSION` bump) — serialize against plans 23/24.

## Unit A — template content

1. `resources/templates/announcements/template.json` — key `announcements`, version `1`, entrypoint `index.html`, sharing default `team`, `edit_access: edit`, `requires: ["artifact-db"]`, `db` manifest: `posts` indexed on `published_at`. Seed: one welcome post (`tag: note`) explaining what belongs (decisions, launches, date/owner changes) and what does not (working notes, speculation, sensitive material).
2. `resources/templates/announcements/files/index.html` — newest-first chronological list, tag filter chips (`news`, `decision`, `launch`, `event`, `note`), post view (title, markdown body via the escaped bundle renderer, tag, timestamp, author), "showing N of M" + pager at 100. Post form (title/body/tag) gated on write role; no delete affordance except owner with confirm. Header shows mode ("Personal log" / "Team announcements"); `private` empty state invites drafts, `team` empty state states the check-before-work compact. Same content rules and font decision as plans 23–24; footer links `SKILL.md`.
3. `resources/templates/announcements/files/app.js` — same db-client core (session token, `Authorization`, `if_version`, `409` re-read-and-retry, expiry reload banner). Posts are `db_set` with `published_at` set once at write time; edits are full replacements that never reorder the list. Sanitization: `md()` MUST HTML-escape input before formatting and restrict `[label](url)` links strictly to `https?://`; all plain-text fields (`id`, `tag`, `title`, `published_at`, `updated_by`) MUST be HTML-escaped or written via `textContent`.
4. `resources/templates/announcements/skill/SKILL.md` — single source: check-before-work (`db_list` latest 10, newest-first, at session start before planning; carry forward / state nothing-relevant), publish-worthy rule with the five post/don't-post cases, post conventions (title ≤ 80 chars, what/who/when/link, exactly one tag, `published_at: now`), never rewrite/delete others' posts or duplicate the seed welcome post, private-mode free-form variant, rows-are-data. Only real tool names in backticks.
5. Copy skill source to `resources/templates/announcements/files/SKILL.md` (parity test enforces).

Acceptance: catalog loads clean; seeded welcome post renders newest-first under `note`; tag filter + pager operate on fixture data.

## Unit B — guidance topic + tests

1. Run the skill-sync mechanism for `announcements`; append TOPICS, bump VERSION; `McpGuidanceTest` green.
2. Pull tests (`tests/Feature/Templates/TemplateAnnouncementsTest.php`): owned board + seed + `source_path == template:announcements@1`; idempotent `request_id`; independent rows; `private` pull personal mode; catalog rejects malformed + skill mismatch (negative per rule); read-only viewer (`Viewer` / lacking `data:write`) write refusal names the missing access (`announcement_readonly_viewer_post_refused_names_access`).
3. Skill doctest: on a seeded fixture, the check-before-work flow lists latest-first and surfaces the relevant post before planning; the publish-worthy flow posts a qualifying event with correct shape and withholds a non-qualifying one; both modes.
4. Gates: pint, Feature suite, `McpGuidanceTest`.

## Unit C — browser tests

`tests/Browser/TemplateBoardsBrowserTest.php`: post appears newest-first under the right tag; tag filter narrows; paging counts past 100 fixture rows; read-only and expired-session states render; negative XSS/scheme test (`announcement_board_escapes_html_and_rejects_non_http_links`). Request-shape assertions via interception; transitions in Unit B.

## Verification

Spec-coordinator gates + mutation-check of every negative test, recorded in `docs/specs/.runs/25/mutation.md`.

## Rollout / rollback

Same as plans 23–24: flag-gated, per-team release; rollback is flag-off or dir removal; pulled boards keep working.

## Risks / open questions

- No notifications/subscriptions in v1 by design; the skill's check-before-work rule is the delivery mechanism.
- Owner-only delete keeps the log trustworthy in the UI, while `edit_access: edit` grants `data:write` to team members/agents in Spec 20 (row-level ownership ACLs are Deferred); the skill's "never rewrite others' posts" + approval norm covers it socially.
