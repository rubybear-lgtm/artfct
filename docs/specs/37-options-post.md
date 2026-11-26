# Spec 37: Options post with labeled alternatives and stable anchors (RUB-577)

**Track:** Official artifact types (RUB-551 epic) · **Depends on:** 29 (post data schema, `kind` set, RUB-559), 32 (context-post skill, RUB-553), the `post` case from RUB-575 · **Blocks:** nothing · **Related:** RUB-573 edits the same guidance later (merge order in RUB-551, decision 8: 575, 553, 556, 554, 568, 577, 573)

This page is both the spec and the plan.

## What and why

A team lead asks the bot for a choice, not a conclusion: two or three labeled alternatives the team can comment on and link to. Each alternative has its own anchor, so a comment or a link can say "option B". When the team picks one, the bot publishes a decision post and closes the options post as superseded.

This issue is guidance only. It adds an "Options" section to the `context-post` guidance and its skill mirror. It adds no topic, no tool, no code path, no migration and no new data field.

## Decisions

Grounded in the epic: post state lives in artifact data (decision 2); options posts are `post` artifacts with `kind` = `options` (decision 3); guidance is edited and `ArtifactGuidance::VERSION` is bumped (decision 8).

Open questions from the draft spec, decided here:

1. **Anchor ids across rounds: unique across the whole post.** Letters keep counting across rounds: round 1 is `option-a`, `option-b`, `option-c`; round 2 starts at `option-d`. An id is never reused, so `#option-b` always names the same alternative, and a page never has two elements with one id. Rejected: round-scoped ids (`round-2-option-a`), which make a deep link ambiguous across rounds and add a second scheme to explain.
2. **Count: two or three alternatives per round.** The guidance says so. If the person asks for more, the bot asks them to cut the list. No code cap, since the rule is about what the team can compare, not about page integrity.
3. **Letter in the anchor and the heading.** Each section is `<section class="option" id="option-a">` with `<h2>A. Title</h2>`. The letter in the heading matches the anchor, so people can say "B" in a comment and find it.
4. **Editing a superseded options post: refused in guidance.** A superseded options post gets only the status and snapshot republish from context-post step 6. Its alternatives are not edited. A new choice is a new round on a new options post, or a decision. No server refusal: the `post` schema already refuses bad `status` values (RUB-559), and a per-section check is a follow-up if needed.
5. **Deep-link format: the options post's `view_url` plus the fragment.** For example `<view_url>#option-b`. The `view_url` comes from `deploy_artifact` or `get_artifact`, as in context-post step 5. The decision post links to the anchor of the alternative it chose.
6. **Blocker on RUB-559.** The base carries RUB-559 up to `ef0c87e`, which includes the `kind` set with `options`, the `post/details` validation and the context-post starter. Three later review-fix commits on the RUB-559 branch (`180e48f` to `a84e097`) are not in this base. This issue does not depend on them: it changes guidance text only. They are not cherry-picked.
7. **Validation: guidance only.** Duplicate-anchor and missing-label refusals are rules the bot follows, stated in the guidance. There is no Done-when for a server validator, so none is added. A validator in `PublishContractValidator` is a follow-up if the team sees bots break these rules.
8. **VERSION: `1.9.0`.** The base serves `1.8.0` (RUB-568). One minor above it. The final number is confirmed at merge against develop.

## Options section (served text)

The `context-post` guidance gets a section headed `## Options`, placed after the Flow and before Rules. It states:

1. When to use it: the person asks for a choice between alternatives, and the post's `kind` is `options`.
2. Structure: `<dl class="post-details">` as usual with Kind `Options`, then one `<section class="option" id="option-x">` per alternative, with `<h2>X. Title</h2>`, a "what it is" paragraph, `<h3>Trade-offs</h3>` and `<h3>What it costs</h3>`.
3. Two or three alternatives per round. Every alternative has a title, what it is, trade-offs and cost. Ask for a missing label or detail; never invent one.
4. Anchors: `option-a`, `option-b` and so on, one per alternative, unique across the whole post. A new round goes on top of the page, older rounds stay below, and new alternatives continue the letters. Ids and letters are never renumbered on update.
5. Deep links: `<view_url>#option-b`.
6. Close the loop, in order:
   a. Publish the decision post (`artifact_type: post`, kind `decision`), naming the chosen option and linking `<options view_url>#option-x`.
   b. `db_update` the options post's `post/details` with `status: "superseded"` and `replaced_by` set to the decision's artifact id.
   c. Republish the options post with `artifact_id` and `base_version`. The snapshot shows Status `Superseded` and a Replaced by link.
7. Refusals (served as text): a superseded options post's alternatives are never edited; a decision that names an option id not on the post stops and asks which option was chosen; images per option and voting in the post are out of scope.

## Test map (Done-when to proof)

| # | Done-when (Linear RUB-577) | Proof |
|---|---|---|
| 1 | `context-post` guidance and skill mirror gain an "Options" section: class `option`, ids `option-a`, newest round on top, ids never renumbered | `McpGuidanceTest`: "the options section names the option markup, anchors and round order" (asserts inside the `## Options` section) |
| 2 | Section tells the bot to publish the decision, set the options post `superseded` with `replaced_by` via `db_update`, and republish the snapshot | `McpGuidanceTest`: "the options section closes the loop in order" (ordered markers inside the section) |
| 3 | `VERSION` bumped one minor; `McpGuidanceTest`, `npm run test:skills`, `npm run test:plugin` pass | `AgentInstructionsTest` "the guidance version moved with the text" pins `1.9.0`; `McpGuidanceTest` "every tool that names a guidance version"; `npm run test:skills`; `npm run test:plugin` |
| 4 | Assertions contain `class="option"`, `option-a`, `superseded`, `replaced_by` | Covered by tests 1 and 2 (asserted inside the section, not the whole page) |
| 5 | Staging: bot publishes a three-option post; `#option-b` deep-links; comment added; bot closes it | Manual. Not verifiable in this session. |
| 6 | `vendor/bin/pint --dirty --format agent` clean | Run command |
| 7 | CI green; PR reviewed; no PR preview environments | Outside this session |

### Refusal tests (guidance text)

Each refusal in the section has one negative test: the guidance must contain the refusal sentence. Break-the-guard: remove the sentence, confirm the test fails, restore.

| Refusal | Test name |
|---|---|
| Ids and letters never renumbered | "the options section refuses to renumber an option" |
| Superseded options post is not edited | "the options section refuses to edit a superseded options post" |
| Missing label or detail is asked for, never invented | "the options section asks for a missing label and never invents one" |
| Unknown option id in a decision stops and asks | "the options section stops when the decision names an unknown option" |
| Two or three alternatives per round | "the options section limits a round to two or three alternatives" |

## Plan (TDD order)

1. Commit this spec.
2. Red: add the McpGuidanceTest tests above. They look inside the `## Options` section, which does not exist yet, so they fail with "section not found". Keep the failing output.
3. Green: add the Options section to `resources/guidance/context-post.md`. Mirror it verbatim into `skills/artfct-context-post/SKILL.md` (frontmatter unchanged). Confirm the mirror test passes.
4. Bump `ArtifactGuidance::VERSION` to `1.9.0`, every `guidanceVersion` in `app/Mcp/Tools/*.php`, and the `AgentInstructionsTest` pin. Add a CHANGELOG line.
5. Run `php artisan test --compact tests/Feature/McpGuidanceTest.php tests/Feature/AgentInstructionsTest.php`, `npm run test:skills`, `npm run test:plugin`.
6. Refusal guard checks: for each refusal, remove its sentence, confirm its test fails for that reason, restore.
7. Pint: `vendor/bin/pint --dirty --format agent`.
8. Push. Staging check is not run in this session.
