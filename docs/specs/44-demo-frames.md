# Spec 44: Demo frames, CSS-only browser, phone and desktop (RUB-578)

**Track:** Official artifact types (RUB-551 epic, Next-polish) · **Depends on:** RUB-574 (publish contract validator, on base as `App\Services\Artifacts\PublishContractValidator`), RUB-558 (deck starter, on base as `skills/presentation/assets/deck-starter/`, whose `<figure>` and `<img>` slot is the image slot this issue reuses) · **Blocks:** nothing · **Related:** RUB-551 (Next-polish: never blocks anything), RUB-555 (presentation skill)

This page is both the spec and the plan. The issue is size S.

## What and why

A person demoing a product or reviewing a customer mock drops a screenshot into a browser, phone or desktop frame so it looks like a real device, then publishes it as a mockup the team can comment on. This issue adds three original, CSS-only frames and a sample page that shows each one. It adds no script, no `data:` image and no external asset, so every frame passes the publish contract unchanged.

## Decisions

1. **Location.** The frames live in `skills/presentation/assets/frames/`: `browser.css`, `phone.css`, `desktop.css`, `sample-shot.svg` (a static bundled screenshot) and `index.html` (the sample). A skill folder holds them, so the plugin pack ships them. No new top-level folder.
2. **Image slot.** The base has no element named `image-slot`. The deck starter's slot is a `<figure>` holding an `<img>`. Each frame wraps a `<figure class="frame-slot">` with an `<img>` inside. This is the reading of the Linear phrase "image slot (from the starters)" that this spec uses. Open question 1.
3. **Chrome is decorative.** Every frame chrome element (the browser bar, the phone notch and home bar, the desktop stand) carries `aria-hidden="true"`. The screenshot carries the `alt` text, and every `<img>` in the sample has a non-empty `alt`.
4. **No script, no `data:` image, no external origin.** The sample loads only its three CSS files and the bundled SVG by relative path. Interactivity is out of scope.
5. **Out of scope.** Animated frames, device-accurate bezels per vendor, a frame-picker, and a starter `artfct-starter` meta tag (frames are not starters).
6. **Skill text.** The presentation skill's `## Additional Resources` section gets one line per frame. Its existing tests in `tests/Feature/Skills/PresentationSkillTest.php` must still pass.

## Done-when to test map

Linear RUB-578 Done-when, mapped to the Pest file `tests/Feature/Starters/FramesTest.php` unless noted.

| Done-when (Linear RUB-578) | Test name | Status |
|---|---|---|
| The three CSS files and `index.html` exist; the sample shows each frame around an image slot with `alt` text | `the frame files exist and the sample shows each frame around an image slot` | pending |
| `PublishContractValidator` accepts the sample bundle | `the sample bundle passes the publish contract` | pending |
| Four violating copies are rejected: inline script | `a sample with an inline script fails the publish contract` | pending |
| Violating copy: `onclick=` handler | `a sample with an on-handler fails the publish contract` | pending |
| Violating copy: `data:` image | `a sample with a data image fails the publish contract` | pending |
| Violating copy: external origin | `a sample with an external origin fails the publish contract` | pending |
| Every frame chrome element has `aria-hidden="true"` | `every frame chrome element is aria-hidden` | pending |
| Every slide image has a non-empty `alt` | `every slide image has a non-empty alt` | pending |
| The presentation skill lists the frames in its resources section, one line each | `the presentation skill lists the three frames in its resources section` in `tests/Feature/Skills/PresentationSkillTest.php` | pending |
| The RUB-555 presentation skill test still passes | `tests/Feature/Skills/PresentationSkillTest.php` as a whole | pending |
| Staging render check: publish the sample as a `mockup`; all three frames render; zero CSP console errors | none in CI | not verifiable here, see Results |
| PR has a screenshot; Pint clean; CI green; reviewed | none | owned by the orchestrator |

Negative tests (guards to break and restore): the four violating-copy tests, the `aria-hidden` test and the `alt` test. Each is broken on purpose in a copy of the sample, shown to fail, then restored.

## Plan

1. Commit this spec (first commit).
2. Red: write `tests/Feature/Starters/FramesTest.php` with the test names above. Run it and show each fails for its stated reason (files missing).
3. Green, one commit per step: add the frame CSS and the sample SVG; add `index.html`; add the presentation skill resource lines.
4. Run `php artisan test --compact tests/Feature/Starters/FramesTest.php` and `php artisan test --compact tests/Feature/Skills/PresentationSkillTest.php`.
5. Break each negative guard, confirm the matching test fails, restore, confirm green.
6. Run `vendor/bin/pint --dirty --format agent`, then push. The pre-push hook runs the full suite.

## Open questions for the owner

1. **Image slot.** RUB-578 says "image-slot (from the starters)", which the base does not contain. This spec uses the deck starter's `<figure>` and `<img>` slot. Confirm.
2. **Staging render.** The Done-when needs a staging publish and a zero-CSP-error browser check. That is not run in this session.

## Results

Recorded during the run on branch `rub-578-demo-frames`.

**Red.** `FramesTest.php` on the red commit: all eight tests fail because the frame files are missing (`browser.css` does not exist; the sample `index.html` cannot be read). `PresentationSkillTest` new test `the skill lists the three demo frames in its resources section, one line each` fails: "the resources section must list assets/frames/browser.css on one line; actual size 0".

**Green.** `php artisan test --compact tests/Feature/Starters/FramesTest.php` passes 8 of 8 (27 assertions). `tests/Feature/Skills/PresentationSkillTest.php` passes 18 of 18, including the new test and the RUB-555 tests. Affected set (`FramesTest`, `PresentationSkillTest`, `DeckStarterTest`, `PublishContractValidatorTest`): 63 passed, 0 failed.

**Guard breaks (each broken, shown to fail, restored).**
- Sample: `aria-hidden` removed from one `frame-chrome` element. `every frame chrome element is aria-hidden` fails (expected `'true'`, got `''`). Restored, green.
- Sample: one `alt` emptied. `every slide image has a non-empty alt` fails. Restored, green.
- Validator `PublishContractValidator`: inline-script check disabled, fails `a sample with an inline script fails the publish contract`. On-handler check disabled, fails `a sample with an on-handler fails the publish contract`. Data-URI check disabled, fails `a sample with a data image fails the publish contract`. External-origin check disabled, fails `a sample with an external origin fails the publish contract`. Each restored with `git checkout`, then green.

**Not verified.** Staging render check (publish as `mockup`, zero CSP console errors): not run in this session. Screenshots for the PR: not captured in this session.
