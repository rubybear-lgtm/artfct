# Spec 42: Sales templates, HTML email and single-page flier (RUB-565)

**Track:** Official artifact types (RUB-551 epic, Next) · **Depends on:** 30 (doc starter, RUB-557), 34 (brand and voice rule, RUB-556), 26 (publish contract validator, RUB-574), 35 (team-doc topic, RUB-554) · **Blocks:** none in the epic · **Related:** RUB-537 (board templates, complementary), RUB-551 decisions 5, 6, 7

This page is both the spec and the plan. It is one combined page because the issue is size M.

## What and why

A seller asks the bot for a customer email or a single-page flier. The bot starts from an official starter, follows the team "Brand and voice" post, and deploys the result as an Artfct artifact. The flier can later be exported to PDF. The email is authored for email clients, but this issue does not send email.

## Decisions

1. **Two starters in the team-doc skill folder (RUB-551 decision 6).** The flier lives at `skills/artfct-team-doc/assets/flier-starter/` and the email at `skills/artfct-team-doc/assets/email-template/`. Both are assets only; `skills/artfct-team-doc/SKILL.md` is the existing skill.
2. **Flier chrome is a byte-identical copy of the doc starter's `doc.css`.** A bundle is published on its own, so a `<link>` into `../doc-starter/` would not resolve in the publish contract. The copy is kept identical by a test that compares the two files. Upgrading means replacing both copies, which the guidance says.
3. **Flier is the fixed shape.** `index.html` uses `<main class="sheet sheet--fixed">` from the doc starter, so it fills exactly one Letter sheet and clips overflow instead of reflowing.
4. **Flier carries no export bridge file.** The issue scope lists `index.html` and `doc.css` only. PDF export ([RUB-561](https://linear.app/rubyapps/issue/RUB-561)) needs the bridge (`export.js`); see open questions.
5. **Email is table-based.** One outer `<table>` at `width="600"`, inline `style=` attributes, no `<script>`, no `<div>`. Its logo is a bundled file, `logo.svg`, referenced by relative path. The publish contract forbids external origins, so the email has no external links; the sender fills in any link target later.
6. **Guidance goes into the existing `team-doc` topic.** The flier and email sections are added to `resources/guidance/team-doc.md`, mirrored verbatim in `skills/artfct-team-doc/SKILL.md`. No new topic.
7. **Version.** The base is `1.11.0` (spec 41), so this change sets `1.12.0`. Every guided tool's `guidanceVersion` and the `AgentInstructionsTest` pin move with it.

## Done-when to test map

| Done when (RUB-565) | Where it is checked |
|---|---|
| Both templates exist at the paths above, carry `artfct-starter` meta `flier@1` and `email@1`, pass `PublishContractValidator`; four violating copies each | `tests/Feature/Starters/SalesTemplatesTest.php`: "the flier passes the publish contract", "the email passes the publish contract", "the publish contract refuses violating copies of the flier" (dataset of 4), "... of the email" (dataset of 4), "the flier and email carry their artfct-starter meta" |
| Email uses `<table` for layout, no `<div>`, 600px content width | `SalesTemplatesTest`: "the email lays out in tables at 600px with no div grid" |
| Flier fits one Letter sheet; `.sheet` markup is the doc starter's; reuses `doc.css` | `SalesTemplatesTest`: "the flier is one fixed sheet that reuses the doc starter's doc.css" |
| `team-doc` guidance flier section carries the print-craft rules | `tests/Feature/McpGuidanceTest.php`: "the team doc guidance gives flier print-craft rules" |
| `team-doc` guidance has flier and email sections naming both templates, says follow "Brand and voice", says email is not sent from Artfct | `McpGuidanceTest`: "the team doc guidance has flier and email sections naming both templates", "the team doc guidance says email is not sent from Artfct" |
| `VERSION` bumped to `1.12.0`; `AgentInstructionsTest` pin and every `guidanceVersion` move | `AgentInstructionsTest`: "the guidance version moved with the text"; `McpGuidanceTest`: "the Agent Skills files carry exactly the served guidance" |
| `McpGuidanceTest`, `npm run test:skills`, `npm run test:plugin` pass | Command output recorded in Results |
| Staging render check (both samples show, zero CSP console errors, flier prints on one page) | Not run here; see Results |
| Pint clean | `vendor/bin/pint --dirty --format agent` |

## Plan

1. Commit this spec.
2. Red: `SalesTemplatesTest` and the new guidance tests, plus the `1.12.0` pin. Confirm each fails for the right reason (missing files, missing text, old version).
3. Green templates: `flier-starter/` (index.html, doc.css copy), `email-template/` (index.html, logo.svg).
4. Green guidance and version: team-doc flier and email sections, SKILL.md mirror, VERSION and guidanceVersion bump.
5. Guard breaks: for each refusal dataset, break the guard (strip the violating copy's protection) and confirm the test fails, then restore.

## Open questions (owner decision, not guessed)

1. **Flier export.** The flier has no `export.js`, so RUB-561 PDF export cannot run on it until the bridge file is added. The user story says "can be exported to PDF". Confirm whether to add a copy of `export.js` to the flier now or in RUB-561.
2. **Logo.** `logo.svg` is a neutral placeholder mark, not a brand logo. The brand logo comes from the "Brand and voice" post or the sender.
3. **Guidance version at merge.** The base is `1.11.0`, so this sets `1.12.0`. If develop has moved since, the bump must be one minor above develop at merge time, which renumbers the pins again.

## Results (recorded at implementation)

Pending.
