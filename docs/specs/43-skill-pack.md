# Spec 43: Cross-host Artfct skill pack (RUB-567)

**Track:** Official artifact types (RUB-551 epic, Now) · **Depends on:** 34 (brand and voice rule, RUB-556), 35 (team-doc topic, RUB-554), RUB-553 (context-post skill), RUB-555 (presentation skill), RUB-573 (tool and skill descriptions) · **Blocks:** none in the epic · **Related:** RUB-551 decisions 1, 6, 8; RUB-504, RUB-453, RUB-526 (distribution, out of scope here)

This page is both the spec and the plan. The issue is size S.

## What and why

A two-to-ten person team connects Artfct once. Their bots, on whichever host they already use, load official SKILL.md files and know how to search, cite, publish a decision post, a team doc or a deck, and update safely when others have edited. This issue defines that pack as a manifest, checks it, and documents it on the README. It does not write new skills and does not rewrite existing ones.

## Decisions

1. **The pack is a manifest, `skills/pack.json`.** It holds one key, `skills`, a list of the seven skill folder names: `artfct`, `artfct-search`, `artfct-publish`, `artfct-collaborate`, `artfct-context-post`, `artfct-team-doc`, `presentation`. The manifest sits at the root of `skills/` because the skills folder is already the home of every skill. It is a file, not a folder, so no top-level folder is added.
2. **Names are not changed (open question 1).** The Linear Done-when and RUB-551 decision 1 agree on the three type skills by these names: `artfct-context-post`, `artfct-team-doc` and `presentation`. The deck skill keeps the `presentation` name and has no `artfct-` prefix, as RUB-551 decision 1 says. The manifest uses the names that exist on base.
3. **Checks are a Node test under `scripts/plugin/`**, run by `npm run test:plugin`: `scripts/plugin/skill-pack.test.mjs`. It uses only `node:test` and `node:fs`. No new dependency.
4. **Frontmatter is read with a small parser.** Several descriptions are wrapped in double quotes in the frontmatter, so the parser strips one pair of quotes before it checks the "Use when" start and the length.
5. **`presentation` is exempt from the "Use when" start only.** Its length limit still applies. Its description keeps its current form (RUB-573 may change it later).
6. **The forbidden-pattern check allows negated mentions.** The type skills describe the rule in order to forbid it (`presentation` says inline `<script>` blocks and `data:` images "are blocked"; `artfct-team-doc` says "do not add a `<script>`"). The check flags a line that contains `<script`, an `on<name>=` handler or a `data:image/` source, unless that same line contains a negation word (`not`, `never`, `no`, `don't`, `blocked`, `without`, `avoid`, `cannot`).
7. **One guidance change, needed by the Done-when.** `artfct-context-post` never names `get_guidance` topic `publish`, which the Done-when requires. The fix is one sentence, the same pattern `artfct-team-doc` already uses ("This topic does not repeat its rules"). It is added to both `resources/guidance/context-post.md` and the mirrored `skills/artfct-context-post/SKILL.md`, so `McpGuidanceTest` keeps them identical. Because guidance changes, `ArtifactGuidance::VERSION` moves from `1.12.0` to `1.13.0`, and every `guidanceVersion` in `app/Mcp/Tools/*.php` and the `AgentInstructionsTest` pin move with it. No other skill or guidance text changes.
8. **README "Skill pack" section.** It is a `### Skill pack` section placed after `### Skills`. It lists the seven skills, one line each, and links the four connect pages that already exist in `AiToolSetup`: `/connect/connect-claude`, `/connect/connect-cursor`, `/connect/connect-codex`, `/connect/connect-chatgpt`. It gives no install commands, so the connect pages stay the only load instructions.

## Done-when to test map

Each item is checked by a test in `scripts/plugin/skill-pack.test.mjs` unless noted.

| Done-when (Linear RUB-567) | Test name | Status |
|---|---|---|
| `skills/pack.json` exists and lists exactly the seven skills | `the pack lists exactly the seven skills, each with a folder and SKILL.md` | passes |
| Each listed skill: folder exists, frontmatter `name` equals folder, `description` starts "Use when" and is at most 1024 characters (`presentation` exempt from the start) | `each pack skill has a folder, a SKILL.md with name equal to its folder, and a description of at most 1024 characters` and `every pack description starts with "Use when" except presentation` | passes |
| No type skill tells a bot to use an inline `<script>`, an `on*=` handler or a `data:` image | `no type skill tells a bot to use a script, an on-handler or a data image` | passes |
| `artfct-context-post` and `artfct-team-doc` each name `get_guidance` topic `publish` | `the context-post and team-doc skills name get_guidance topic publish` | passes (failed red, see Results) |
| README has a "Skill pack" section: seven skills one line each, links to the four connect pages, no duplicated install steps | `README has a Skill pack section with the seven skills and the four connect pages, and no install steps` | passes |
| `npm run test:skills` and `npm run test:plugin` pass | run both commands | passes |
| Staging: nothing served from a hosted endpoint beyond RUB-553 and RUB-554 | none | not verifiable in this session, see below |
| CI green; PR reviewed and approved; no PR preview environments | none | owned by the orchestrator and reviewers |

Negative tests (guards to break and restore): the "Use when" check, the length check, the name-equals-folder check, the exact-seven check, the forbidden-pattern check, the `publish` reference check and the README install-step check. Each is broken on purpose, shown to fail, then restored.

## Plan

1. Commit this spec.
2. Red: write `scripts/plugin/skill-pack.test.mjs` with the test names above. Run it and show each test fails for its stated reason (the manifest is missing; `context-post` lacks the `publish` reference; the README section is missing).
3. Green, in separate commits: add `skills/pack.json`; add the context-post `publish` sentence with the `1.13.0` bump and pins; add the README section.
4. Run `npm run test:plugin`, `npm run test:skills`, and the affected PHP tests (`McpGuidanceTest`, `AgentInstructionsTest`) with `php artisan test --compact`.
5. Break each guard, confirm the matching test fails, restore, confirm green again.
6. Push. The pre-push hook runs the full suite.

## Open questions for the owner

1. **Naming.** Linear says "exactly the seven skills" and names them as above. The skill folders on base are `artfct-context-post` and `artfct-team-doc`, while the deck skill is `presentation`. RUB-551 decision 1 keeps the deck skill as `presentation`. Confirm this is final. Renaming `presentation` to `artfct-presentation` would be a separate change to every skill reference.
2. **Plugin shipping.** `scripts/plugin/sync-skills.mjs` lists `PLUGIN_SKILLS`, and `presentation` is not in it, so the Claude plugin does not ship the deck skill that the pack lists. Confirm whether the plugin should carry the pack.
3. **Manifest consumer.** `skills/pack.json` is only read by the new test. Confirm whether `sync-skills.mjs` or the connect pages should read it.


## Results

Recorded during the run on branch `rub-567-skill-pack`.

**Red (before any implementation).** `node --test scripts/plugin/skill-pack.test.mjs` on the spec commit: three tests failed for their stated reasons.

- `the pack lists exactly the seven skills, each with a folder and SKILL.md`: `skills/pack.json is missing`.
- `the context-post and team-doc skills name get_guidance topic publish`: `artfct-context-post does not name get_guidance topic publish`. This is a real gap on base, not a test error.
- `README has a Skill pack section with the seven skills and the four connect pages, and no install steps`: `README has no "### Skill pack" section`.

Three guard tests passed on base, before the fix: the name and length check, the "Use when" check and the forbidden-pattern check. They are guards, so they were checked by breaking them (below) rather than by a red run.

**Green.** Commits: manifest, then the context-post guidance with the version bump to 1.13.0, then the README section. The full `skill-pack.test.mjs` file: 6 of 6 pass. `npm run test:plugin`: 9 of 9 pass. `npm run test:skills`: 8 of 8 test files pass. `php artisan test --compact tests/Feature/AgentInstructionsTest.php`: 48 passed. `php artisan test --compact tests/Feature/McpGuidanceTest.php`: 168 passed.

**Guards broken and restored.** Each break was run against `scripts/plugin/skill-pack.test.mjs` and restored from git.

| Guard | Break | Test that failed |
|---|---|---|
| exact seven | extra `artfct-data` in `pack.json` | exact seven |
| name equals folder | `name: artfct-team-docs` | name and length |
| length at most 1024 | description value of 1109 characters | name and length |
| "Use when" start | `description: "Use it when` on `artfct-publish` | "Use when" start |
| forbidden pattern | unnegated `<script>` and `onclick=` in `artfct-team-doc` | forbidden pattern |
| forbidden pattern, data image | unnegated `data:image/png;base64` in `presentation` | forbidden pattern |
| publish reference | `topic \`search\`` in place of `publish` in `artfct-team-doc` | publish reference |
| README install step | `npx skills add` line in the section | README section |
| README connect link | ChatGPT link removed | README section |
| README skill line | `presentation` line removed | README section |

**Known pre-existing issue, not changed.** `npx prettier --check README.md` fails on base too (the connect table and the example blocks). The new section adds no Prettier diff of its own.

## Unverified in this session

- Staging: no hosted check was run. The Done-when staging item needs nothing beyond RUB-553 and RUB-554, which are already verified.
- CI, PR review and approval: owned by the orchestrator.
