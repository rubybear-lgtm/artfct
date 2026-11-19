# Spec 33: Slide deck skill on the deck starter (RUB-555)

**Track:** Official artifact types (RUB-551 epic) · **Depends on:** 31 (deck starter, RUB-558) · **Blocks:** RUB-561 and RUB-562 (export clients need the skill's output shape) · **Related:** RUB-551 decisions 1, 6 and 9, spec 30 (doc starter)

This page is both the spec and the plan. It is one combined page because the issue is size S.

## What and why

A seller asks their bot to turn a win, a talk track or a decision into a short slide deck. Today the `presentation` skill points at `assets/presentation-template.html`, which has an inline `<script>` that the author must split into `index.html` and `app.js` by hand. It also has `{{PLACEHOLDER}}` tokens and Solarized colours. The skill moves to the deck starter from spec 31, so the bot fills one file, deploys a `slide_deck` bundle, and checks that the deck can be found.

The skill also carries the slide-writing craft from the epic's provenance note: title grammar, one idea per slide, projection-scale type, image fit, static markup, and avoiding AI-style phrasing.

Out of scope: PDF and PowerPoint export (RUB-561, RUB-562; their "When Not to Use" lines stay exactly as they are), speaker view, animations, and any change to the bridge contract in spec 31. The staging check is a step after merge.

## Decisions this spec applies

- **RUB-551 decision 1.** The deck skill is the existing `presentation` skill. It is a plain skill, not a guidance topic: no `ArtifactGuidance` change, no version bump, no `skills/artfct-*` mirror. `presentation` is not in `PLUGIN_SKILLS` in `scripts/plugin/sync-skills.mjs` and this spec does not add it.
- **RUB-551 decision 6.** The starter lives at `skills/presentation/assets/deck-starter/`. The skill copies its three files unchanged and fills only `index.html`.
- **RUB-551 decision 9.** Unchanged. The skill does not add scripts or network calls to the deck.

## Token and theming facts (checked, not assumed)

- **`--type-*` tokens did not exist.** Spec 31's `deck.css` had only `--deck-*` tokens, with hard-coded sizes. This spec adds four tokens on `:root`: `--type-kicker`, `--type-title`, `--type-heading`, `--type-body`. Each is `calc(var(--deck-unit) * N)` with the same N as the rule it replaces (28, 120, 84 and 44). The computed sizes do not change. This is a chrome change. The bridge contract and the `deck@1` meta do not change.
- **Theme override path.** The artifact CSP sets `style-src 'self' 'unsafe-inline'` in `isolated_content_security_policy` (`backend/src/artifact_origin.rs`, around line 286 to 312). `PublishContractValidator` has no `style` rule, so a `style=""` attribute passes publish validation. So a bot can set tokens with one `style` attribute on `<main class="deck-stage">` and keep `index.html` as its only edited file. The skill uses this path. It does not tell the bot to edit `deck.css`, and it does not add a `theme.css`.
- **Type on a stage.** A token set on `.deck-stage` inherits to the slides. In scaled mode `--deck-unit` is `1px` and the stage is scaled as a whole, so `calc(var(--deck-unit) * 48)` is the portable form and works without JavaScript too.

## Layout

- `skills/presentation/SKILL.md`: rewritten in place (the skill keeps its name, frontmatter name and "When Not to Use" PDF and PowerPoint lines).
- `skills/presentation/assets/presentation-template.html`: deleted.
- `skills/presentation/assets/deck-starter/`: unchanged in its three files, except `deck.css` gains the `--type-*` tokens.
- `skills/presentation/references/presentation-libraries.md`: lines that name the old template or Solarized are rewritten. The Reveal.js content stays.
- `docs/specs/31-deck-starter.md`: the two lines that name the old template file are reworded as "the old single-file template (removed by RUB-555)", so the repo-wide grep is clean.
- New test file: `tests/Feature/Skills/PresentationSkillTest.php`. `tests/Feature/Starters/DeckStarterTest.php` gets one new test for the `--type-*` tokens.

## Done-when to test map

| Done when (RUB-555) | Proof | Status |
|---|---|---|
| Workflow reads `assets/deck-starter/` (`index.html`, `deck.css`, `app.js`), fills only `index.html`, and deploys a `files` bundle with `entrypoint: index.html` and `artifact_type: slide_deck`. No "move the script body" step. | `PresentationSkillTest` · `the skill copies the three starter files and fills only index.html`; `the skill deploys a files bundle with entrypoint index.html and artifact_type slide_deck` | passed (PresentationSkillTest: `the skill copies the three starter files...`, `the skill deploys a files bundle...`) |
| `assets/presentation-template.html` deleted; nothing in the repo references it or `{{PLACEHOLDER}}` tokens | `PresentationSkillTest` · `the old template is gone and nothing names it`; recorded `git grep` | passed. `git grep` for the file name and the placeholder token: only this spec and PresentationSkillTest name them; no skill, template or code does |
| Outline first: slide titles with a one-line point, shown and confirmed before building, unless the person said to just build | `PresentationSkillTest` · `the skill requires a confirmed outline before building` | passed (`the skill requires a confirmed outline before building`) |
| Talk track in each `<p class="notes">`; 5 to 15 slides, one point each; search for an existing deck with the same title, update with `artifact_id` and `base_version`; one findability search after deploy | `PresentationSkillTest` · `the skill asks for a talk track in each notes element and 5 to 15 slides`, `the skill searches for an existing deck before creating one`, `the skill runs one findability search after deploying` | passed (`the skill asks for a talk track...`, `the skill searches for an existing deck...`, `the skill runs one findability search...`) |
| Slide craft: one title grammar; one idea per slide; no punchline titles; projection-scale type via `--type-*`; "36pt" means points (1pt = 1.333px); photo `cover`, screenshot and diagram `contain` on a contrasting background; parallel slides share markup | `PresentationSkillTest` · `the skill sets the title grammar and bans punchline titles`, `the skill sizes type with the --type tokens and converts points`, `the skill fits photos and diagrams`, `the skill keeps parallel slides identical` | passed (`the skill sets the title grammar...`, `the skill sizes type with the --type tokens...`, `the skill fits photos and diagrams`, `the skill keeps parallel slides identical`) |
| Literal static HTML: one leaf element per text, repeated structure written out, no script-generated DOM | `PresentationSkillTest` · `the skill requires literal static markup` | passed (`the skill requires literal static markup`) |
| No Solarized; points at `--deck-*` for theming | `PresentationSkillTest` · `the skill names none of the old template, its tokens or Solarized` | passed (`the skill names none of the old template...`); Solarized absent from skills/presentation |
| `PresentationSkillTest` asserts: names `deck-starter`, `slide_deck`, `search_artifacts`, `base_version`; every asset path it names exists; names none of `presentation-template.html`, `{{`, `Solarized` | the same file (the first three tests above) | passed (`the skill names the deck starter, slide_deck, search_artifacts and base_version`, `every asset and reference path the skill names exists`, `the skill names none of the old template...`) |
| Every `--deck-*` and `--type-*` name in SKILL.md is declared in `deck.css` | `PresentationSkillTest` · `every --deck and --type token the skill names is declared in deck.css`; `DeckStarterTest` · `deck.css declares the --type tokens on :root` | passed (`every --deck and --type token the skill names is declared in deck.css`; DeckStarterTest `deck.css declares the --type tokens on :root`) |
| `npm run test:skills` and `npm run test:plugin` pass | Commands | passed: test:skills 8/8 files; test:plugin 3/3 |
| Staging: one deck built by a bot renders; ArrowRight changes counter; zero CSP errors; `search_artifacts` returns it | After merge, staging | not verified here |
| CI green; reviewed and approved; no preview environments | Process | pending (orchestrator) |

## Negative tests and guard breaks

Each guard below is broken on purpose, the named test is run and must fail, then the guard is restored and the test passes again.

Run on this branch: all eight breaks made their named test fail (result `failed`, 0 passed). After restoring, PresentationSkillTest is 17/17 and DeckStarterTest is 17/17.

| Guard | Break | Test expected to fail |
|---|---|---|
| Old template and placeholder ban | Add `presentation-template.html` and `{{TITLE}}` to SKILL.md | `the skill names none of the old template...` |
| Solarized ban | Add the word Solarized | same test |
| Asset path existence | Name `assets/deck-starter/missing.js` in SKILL.md | `every asset and reference path the skill names exists` |
| Token declaration | Name `--type-missing` in SKILL.md | `every --deck and --type token...` |
| Starter file list | Remove `app.js` from the copy step | `the skill copies the three starter files...` |
| Outline gate | Remove the step-1 sentence "wait for their confirmation before building" | `the skill requires a confirmed outline...` (exact-sentence assertion; the earlier regex matched across the file and passed with the sentence removed) |
| Findability search | Swap workflow steps 4 (deploy) and 5 (findability search) | `the skill runs one findability search...` (ordering now anchored to the step labels; the old `deploy_artifact` anchor matched the frontmatter) |
| `--type` tokens in starter | Remove `--type-body` from `deck.css` | `deck.css declares the --type tokens on :root` |

## Open questions

None open for this spec. The `artifact_type` values (`slide_deck`) come from `app/Enums/ArtifactType.php`. `base_version` and `artifact_id` come from `DeployArtifactTool`.

## Unverified (recorded in the PR)

- The bot-built staging deck and its CSP console check (after merge).
- Browser rendering of the `style=""` token override. The skill's theming claim rests on the CSP header and the validator, not on a browser run in this branch, unless a browser test is added.
- Print pagination (carried over from spec 31).
