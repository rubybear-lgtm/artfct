# Spec 39: One-pager tweak fields backed by artifact data (RUB-560)

**Track:** Official artifact types (RUB-551 epic) · **Depends on:** 20 (artifact database), 29 (post data schema, the data-contract pattern), 30 (doc starter, RUB-557), 35 (team-doc skill, RUB-554), 26 (publish contract validator, RUB-574) · **Blocks:** none in the epic · **Related:** RUB-572 (bot `data:read`/`data:write`)

This page is both the spec and the plan. It is one combined page because the issue is size M.

## What and why

A seller opens the standard one-pager, sets the customer name, tier and logo, and shares a link. Nobody has to ask a bot to republish the whole HTML. The values live in artifact data (spec 20); the page reads them and an edit panel writes them.

The tweak fields are declared, not hard-coded. A bundled `tweaks.json` lists the fields. `tweaks.js` loads that file, renders the edit panel from it, fills the elements marked `data-tweak`, and saves the values. A different team-doc can declare different fields and reuse the same `tweaks.js`.

## Decisions

1. **Declaration is a bundled file, not a `<script>` tag.** `tweaks.json` is an ordered array of `{name, label, type, default, max?, choices?, min?, step?}`. A JSON-in-`<script>` form would be an inline script, which `PublishContractValidator` refuses (review finding 2, 2026-11-19).
2. **Storage.** Collection `tweaks`, document `fields`, body is an object with one key per declared field. The page reads it with `GET /__artfct/db/tweaks/fields` (same origin, cookie) and writes it with `PUT /__artfct/db/tweaks/fields` after a session token is obtained from `GET /__artfct/db/session`. A missing document (404) means "use the declared defaults". No seed row is needed.
3. **The publisher declares the database.** `deploy_artifact` takes the `db` argument, which the team-doc guidance gives as `{"collections": {"tweaks": {"index": "customer_name"}}}`. The `db` argument is passed by the publisher, not read from `tweaks.json`.
4. **Roles.** The session response reports `role` as `writer` or `reader` (`backend/src/artifact_db.rs`). The issue's "member and owner" means `writer`, and "viewer" means `reader`. The panel is shown only for `writer`.
5. **Validation is client-side and guidance-side, not server-side.** The db tools cannot see a bundle's `tweaks.json`, and a server rule would need a bundle-file reader in the write path. `tweaks.js` refuses a bad value before saving and shows the error beside the control. The server enforces only what spec 20 and spec 29 already enforce. The team-doc guidance says so, so a bot writing `db_update` knows the server will not refuse an out-of-constraint value.
6. **Control types.** `text` (`max`, characters), `choice` (`choices`), `color` (hex `#rrggbb`), `toggle` (boolean), `range` (`min`, `max`, `step`, numeric) and `image` (a path to a file in the bundle; empty string means no image). Values are written as text with `textContent`, never as HTML.
7. **Image check.** `tweaks.js` checks the path shape first (relative, no leading `/`, no `..`, no `:` or `\`, extension `png`, `jpg`, `jpeg`, `gif`, `webp` or `svg`) and then confirms the file exists with a same-origin `HEAD` request. Both checks run before the save.
8. **Test seam.** `tweaks.js` exposes `window.artfctTweaks.check(field, value)`, which returns an error string or `null`. Browser tests drive each refusal through it. The seam does not write anything.
9. **Starter location.** `skills/artfct-team-doc/assets/one-pager-starter/` (decision 6 of RUB-551): `index.html` (content only), `doc.css` (chrome, including the panel), `tweaks.js` (behaviour) and `tweaks.json` (declaration). No `export.js`: the one-pager has no export bridge in this issue.
10. **Declared fields (the shipped one-pager):**

| name | label | type | constraints | default |
|---|---|---|---|---|
| `customer_name` | Customer name | `text` | `max` 80 | `Customer name` |
| `tier` | Tier | `choice` | `choices` `["Tier one", "Tier two", "Tier three"]` (placeholders, see open question 1) | `Tier one` |
| `logo_path` | Logo | `image` | bundle file, or empty | `""` |

The index is `customer_name`, so a bot can find a one-pager by customer with `db_list` and `index_eq`.

11. **Panel error.** If `tweaks.json` cannot be read or is not a valid declaration, `tweaks.js` shows a visible `role="alert"` message in the page for every viewer. A broken bundle must not fail silently.
12. **No-JavaScript reading.** Each `data-tweak` element in `index.html` holds a neutral placeholder, so the page reads correctly with JavaScript off.
13. **Guidance version.** `ArtifactGuidance::VERSION` is bumped one minor above `develop` at merge time (`develop` is `1.4.1`, the chain base is `1.10.0`), per the issue. This PR does not bump it.

## Limits

- An `image` value is a bundle path only. The panel does not upload a file. Choosing an image from elsewhere is out of scope.
- A value a viewer cannot see is not hidden: a `reader` sees the stored values, as with any artifact data.
- The panel does not enforce cross-field rules.
- Write tokens last 5 minutes (spec 20). The panel refreshes the session before each save.

## Plan (TDD order)

1. Commit this spec.
2. Red: `tests/Feature/Starters/OnePagerStarterTest.php` (static file, contract and declaration checks), `tests/Feature/McpGuidanceTest.php` additions (team-doc names the one-pager starter, the tweak fields, the constraints and the `db` argument). Commit the red tests. Show each fails for the right reason.
3. Green: the four starter files and the guidance text (in `resources/guidance/team-doc.md` and `skills/artfct-team-doc/SKILL.md`, kept identical). Commit.
4. Red: `tests/Browser/OnePagerTweaksBrowserTest.php` with a fixture server in `tests/Fixtures/one-pager/`. Green: `tweaks.js` behaviour. Commit.
5. Break each refusal guard once, confirm its test fails, restore.

## Done-when to test map

Status values: **passed** (test ran and passed), **guard broken** (test failed when the guard was removed), **not run** (could not run here, with reason).

| Done when (RUB-560) | Proof | Status |
|---|---|---|
| Starter files exist and pass `PublishContractValidator`; four violating-copy checks | `OnePagerStarterTest` · `the one-pager starter has its four files`, `the one-pager starter passes the publish contract`, `the publish contract refuses violating copies of the one-pager starter` (dataset: inline script, onclick handler, data uri image, external font url) | pending |
| `tweaks.json` ships in the bundle; a missing file fails loudly on the page | `OnePagerStarterTest` · `tweaks.json is in the bundle as an ordered declaration`; `OnePagerTweaksBrowserTest` · `a bundle without tweaks.json shows a visible panel error` | pending |
| Placeholders readable with JavaScript off | `OnePagerStarterTest` · `every data-tweak element holds a placeholder in index.html`. A browser run with JavaScript disabled is not in the test suite. | partly: static only |
| Client-side refusals (max, choice, color, range, toggle, image path), with the error beside the control | `OnePagerTweaksBrowserTest` · `tweaks.js refuses text over max`, `... a choice outside choices`, `... a color that is not hex`, `... a range outside min and max`, `... a toggle that is not boolean`, `... an image path that is not a file in the bundle` | pending |
| Refused values are never sent | `OnePagerTweaksBrowserTest` · `a refused value sends no save request` | pending |
| Guidance states the constraints and that the server will not refuse | `McpGuidanceTest` · `the one-pager guidance states the tweak constraints and that the server does not refuse them` | pending |
| Edit panel visible for writer, absent for reader | `OnePagerTweaksBrowserTest` · `the edit panel is shown to a writer`, `the edit panel is absent for a reader` | pending |
| Saving writes the declared fields; reload shows them | `OnePagerTweaksBrowserTest` · `saving the tweaks writes the declared fields and a reload shows them` | pending |
| Declaration-driven: a fourth field renders and saves with no change to `tweaks.js` | `OnePagerTweaksBrowserTest` · `a fourth declared field renders and saves with no change to tweaks.js` (fixture: `tests/Fixtures/one-pager-four-fields/tweaks.json`) | pending |
| Guidance names the one-pager starter, the three fields, the `tweaks` collection and `fields` document | `McpGuidanceTest` · `the team doc guidance names the one-pager starter and its three fields`, `the team doc guidance names the tweaks collection and the db argument`; `OnePagerStarterTest` · `the db argument in the guidance is a valid manifest` | pending |
| `ArtifactGuidance::VERSION` bumped at merge | Not in this PR (merge-time step) | deferred by the issue |
| `McpGuidanceTest`, `npm run test:skills`, `npm run test:plugin` pass | Run before push | pending |
| Staging: change fields as a member, see them as a viewer with no panel, zero CSP console errors | Not run: needs merged code and staging | not run |
| PR screenshots (desktop and mobile) | Not run: the PR is opened by the orchestrator; the fixture can produce them | not run |
| `vendor/bin/pint --dirty --format agent` clean; CI green; reviewed | Pint before each PHP commit; CI and review happen after the push | pending |

## Open questions (for the owner)

1. **Tier names.** The shipped `tier` choices are neutral placeholders (`Tier one`, `Tier two`, `Tier three`). The real tier names and prices are out of scope for this issue (price tables), so the owner needs to supply the names before the one-pager goes to a customer.
2. **Index field.** The `tweaks` collection is indexed on `customer_name`. Confirm that is the field the sales team searches by, or name another.
3. **Image uploads.** The image control takes a bundle path. If sellers need to choose a logo from their own files, that needs an upload path, which is a separate decision.

## Out of scope

Tier price tables, cross-artifact theming, a settings panel beyond what the declaration renders, server-side enforcement of the declaration, an export bridge, and the `ArtifactGuidance::VERSION` bump (merge-time).
