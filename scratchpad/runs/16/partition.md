# Spec 16 partition

Solo session (no subagent budget since spec 08). One unit — collections
and usage ranking share `SearchService`/`SearchRanking`, so splitting
them would touch the same files twice.

## Closable honestly in this environment

- `UsageScorer::score()`: pure, no I/O — distinct-viewer counting (not
  raw view count, so a reloading single user never beats five distinct
  viewers), Slack-share weight, retrieved-then-opened weight, exponential
  decay (30-day half-life), and a hard zero for any artifact carrying a
  `Superseded` event. Four of five DoD usage-signal rows mutation-checked
  directly against this function.
- `Collection`/`CollectionArtifact`: "kept deliberately thin" — any
  member can create one and add/remove artifacts (no policy check at
  all); only `pinCanonical`/`unpinCanonical` are gated
  (`pinCanonicalCollection` permission, Admin-only by the existing role
  table) — mutation-checked.
- `SearchService`/`SearchRanking` extended, not replaced: `collection`
  filter scopes candidates before ranking (mutation-checked); usage score
  and a canonical boost (2.0, deliberately larger than every other boost
  combined) are added to spec 13's existing semantic+recency+repo-match
  formula. `ranking_is_useful_with_no_collections` and the pre-existing
  spec 13 `SearchTest` suite (unchanged, still green) together prove the
  automatic-signal path works with zero collections defined.
- Org isolation for collections is a direct consequence of `team_id`
  scoping every query — same shape as every other org-boundary test in
  this project (spec 07/08/12/13's isolation tests); not separately
  mutated since there's no removable guard distinct from the schema
  itself.

## Structural / documented, not fully wired

- **The Worker-side view hook and MCP-side "retrieved then opened"
  correlation are not wired.** `UsageEventLogger` is real and tested;
  `SlackPostService` calls `recordSlackShare()` for real (the one signal
  this session could wire end to end, since Slack posting is already
  Laravel-owned). The `/p/{id}` view signal and the "agent retrieved via
  `search_artifacts`, then the returned URL was actually opened"
  correlation both need the same Worker↔Laravel plumbing named in
  DOCUMENTATION.md's "the recurring gap" section (specs 11/12/14) — a
  sixth and seventh instance of the identical gap. `usage:record` (not
  built this session, unlike spec 12's `indexing:index`) would be the
  manual/ops entry point; given time constraints this session stopped at
  the tested `UsageEventLogger` methods themselves.
- No collections console UI (create/list/pin) was built — `CollectionService`
  is real and tested; a React panel is a follow-up, same pattern as
  specs 12/13/14's UI deferrals.

## Not closable here

- Nothing requiring live infrastructure — this spec is the most
  self-contained of the run (pure Laravel, no external service).
