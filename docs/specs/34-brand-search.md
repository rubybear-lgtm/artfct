# Spec 34: Publish-time search follows the team brand post (RUB-556)

**Track:** Official artifact types (RUB-551 epic) · **Depends on:** 29 (post data schema, RUB-559), 32 (context-post skill, RUB-553), 27 (first-class types, RUB-575) · **Blocks:** RUB-554, RUB-568, RUB-577, RUB-573 (they edit the same publish guidance; RUB-551 decision 8) · **Related:** RUB-551 decisions 4 and 8, spec 30 (doc starter), spec 31 (deck starter)

This page is both the spec and the plan. It is one combined page because the issue is size S.

## What and why

A sales lead publishes one "Brand and voice" post: colours, typography, logo rules, tone, examples. Later a teammate or a bot publishes a one-pager, a deck or an announcement for customers. Today the publish guidance says nothing about brand, so the bot guesses. This spec adds a "Brand and voice" section to the publish guidance and its skill mirror. Before customer-facing work, the bot finds the brand post by search, confirms its kind and status from post data, follows a supersession chain in data, and uses the brand content. If there is no brand post, it says so and uses the neutral starter default.

Out of scope: a design-token compiler, the design-system artifact type (RUB-568), a reserved label or collection, a bot-run check on staging (a step after merge, see Unverified), and any change to the MCP tools' behaviour. This is guidance text plus tests. No runtime code changes.

## Decisions this spec applies

- **RUB-551 decision 4.** The brand artifact is a `post` titled exactly "Brand and voice". The bot finds candidates by `search_artifacts` for that title with `artifact_type: post`. It then confirms kind `brand` and status `current` from post data with `db_get` on `post/details`. The page's `post-details` snapshot is display only and may be stale.
- **RUB-551 decision 8.** This change edits `resources/guidance/publish.md` and `skills/artfct-publish/SKILL.md`, so it bumps `ArtifactGuidance::VERSION` one minor. The base is 1.5.0, so the new value is **1.6.0**. Every guided tool's `guidanceVersion` moves with it. `tests/Feature/AgentInstructionsTest.php` also pins the version and moves too.
- **Base versus develop.** The issue says the bump is one minor above develop's value at merge time. develop reads 1.4.1 today; the branch base reads 1.5.0 (RUB-575 and RUB-553 are in the base). This branch bumps from the base, as the orchestrator asked. The merge will need a rebase onto whatever develop carries, and the version may need to be re-checked then.
- **Decision 2 (data first).** Status and replaced-by are read from data, never from the snapshot. The snapshot is not used to decide anything except in the stated fallback.

## The rule, as the guidance will state it

1. **Find.** Before writing customer-facing work (one-pager, deck, flier, email, announcement to customers), call `search_artifacts` with query `Brand and voice` and `artifact_type: post`. Keep the results whose title is exactly `Brand and voice`.
2. **Confirm.** For each candidate, `db_get` with `collection: post` and `doc_id: details` reads `kind` and `status` from data. Follow the candidate whose kind is `brand` and whose status is `current`. If more than one current brand post remains, stop and tell the person.
3. **Supersession.** If the chosen post's data status is `superseded`, follow its `replaced_by` in data, reading `post/details` on each hop, up to 3 hops. If the chain is longer than 3, broken, or the named artifact is unreadable, stop and tell the person. The post reached must have kind `brand` and status `current`.
4. **Snapshot fallback.** If a candidate has no readable `post/details` data, use its snapshot and say so plainly. No snapshot and no data is not a candidate.
5. **Read content.** `read_artifact` is for the brand content only (voice, colours, typography). It is never used for status or kind.
6. **No post.** If no candidate exists, say "no Brand and voice post found", use the starter's neutral default, do not describe the result as the team brand, and offer to create the post.
7. **What a brand post contains,** in this order: a one-paragraph voice summary with two or three do/don't example pairs (personality, casing, pronouns, emoji, greetings); visual foundations (colours with hex values and when to use each, typography families and sizes, spacing and corner-radius habits, imagery tone, iconography rules); logo rules; one or two worked examples (a headline or sentence rewritten on-brand and off-brand). Every theme token the doc and deck starters declare (`--doc-*`, `--deck-*`, `--type-*`) is named in the visual foundations section, so a bot can wire values into the starters without guessing.

Data values are lowercase (`brand`, `current`, `superseded`), as the post schema defines them in `ContextPostDetails`.

## Layout

- `resources/guidance/publish.md`: new `## Brand and voice` section, placed before `## Do not publish`.
- `skills/artfct-publish/SKILL.md`: same text, kept verbatim. The existing mirror test requires the file to end with the served guidance.
- `app/Mcp/Support/ArtifactGuidance.php`: `VERSION` 1.5.0 → 1.6.0.
- `app/Mcp/Tools/*.php` (13 files that carry `'guidanceVersion' => '1.5.0'`): → `1.6.0`.
- `tests/Feature/AgentInstructionsTest.php`: the pin `toBe('1.5.0')` → `toBe('1.6.0')`.
- `tests/Feature/McpGuidanceTest.php`: new tests below, in a block that names RUB-556.

No new files except this spec. No new dependencies, no new folders.

## Done-when to test map

| Done when (RUB-556) | Proof | Status |
|---|---|---|
| publish.md has "Brand and voice"; SKILL.md carries the same text; VERSION bumped one minor; every tool text naming the version updated | `McpGuidanceTest` · `the Agent Skills files carry exactly the served guidance` (dataset `publish`); `McpGuidanceTest` · `every tool that names a guidance version names the current one`; `AgentInstructionsTest` · `the guidance version moved with the text` | pending |
| Search for "Brand and voice" with `artifact_type: post`; confirm kind `brand` and status `current` with `db_get` on `post/details`; `read_artifact` only for content | `McpGuidanceTest` · `the brand section searches by the exact title and the post type`; `the brand section reads kind and status from post data with db_get`; `the brand section says read_artifact is for content only` | pending |
| Superseded: follow `replaced_by` in data, up to 3 hops, `post/details` on each hop; stop on longer, broken or unreadable chains; snapshot fallback said plainly | `McpGuidanceTest` · `the brand section follows replaced_by up to three hops and stops when the chain fails`; `the brand section falls back to the snapshot only when data is unreadable` | pending |
| No post: say "no Brand and voice post found", use the neutral default, do not call it the team brand, offer to create | `McpGuidanceTest` · `the brand section says what to do when no brand post exists` | pending |
| Contents list in order; every `--doc-*`, `--deck-*` and `--type-*` token the starters declare is named | `McpGuidanceTest` · `the brand section lists what a brand post contains, in order`; `the brand section names every theme token the starters declare` | pending |
| McpGuidanceTest asserts `Brand and voice`, `db_get`, `post/details`, `brand`, `current`, `superseded`, `replaced_by` and the no-post sentence | the tests above | pending |
| `php artisan test --compact tests/Feature/McpGuidanceTest.php` passes | command | pending |
| `npm run test:skills` and `npm run test:plugin` pass | command | pending |
| `vendor/bin/pint --dirty --format agent` clean | command | pending |
| Staging: two recorded runs (brand search and db_get visible in MCP activity; superseded post followed via `replaced_by` in data) | After merge, staging | not verified here |
| CI green; reviewed and approved; no preview environments | Process | pending (orchestrator) |

Test names above are the exact Pest names. Test file: `tests/Feature/McpGuidanceTest.php`.

## Negative tests and guard breaks

Each guard is broken on purpose, the named test is run and must fail, then the guard is restored and the test passes again. Recorded in the PR and in this table once run.

| Guard | Break | Test expected to fail |
|---|---|---|
| Kind and status come from data | Remove "read kind and status" wording | `the brand section reads kind and status from post data with db_get` |
| Content-only read | Remove "never for status or kind" sentence | `the brand section says read_artifact is for content only` |
| Hop limit | Change "up to 3 hops" to "up to 5 hops" | `the brand section follows replaced_by up to three hops and stops when the chain fails` |
| Stop rule | Remove "stop and tell the person" after the chain | same test |
| Snapshot fallback | Remove "say so plainly" | `the brand section falls back to the snapshot only when data is unreadable` |
| No-post sentence | Remove the exact sentence `no Brand and voice post found` | `the brand section says what to do when no brand post exists` |
| Token coverage | Remove `--type-body` from the visual foundations text | `the brand section names every theme token the starters declare` |

## Open questions

None open for this spec. Tool parameter names come from `DbGetTool` (`id`, `collection`, `doc_id`) and `SearchArtifactsTool` (`query`, `artifact_type`).

## Unverified (recorded in the PR)

- The two staging runs (artifact ids, MCP activity log). These need a deployed staging worker and a bot, and cannot be run from this worktree.
- Whether a bot follows the section. The tests check the text, not model behaviour.
- The version number may need to be re-based onto develop at merge time (see Decisions).
