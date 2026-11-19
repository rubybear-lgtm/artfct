# Spec 41: Engineering handoff pack, artifact plus structured README (RUB-564)

**Track:** Official artifact types (RUB-551 epic, Next) · **Depends on:** 40 (standalone packager: content `files` listing, `StandaloneBundlePacker`), 26 (publish contract validator, RUB-574), 36 (design system, for the fidelity language) · **Blocks:** none in the epic · **Related:** RUB-551 decisions 1, 8 and 9

This page is both the spec and the plan. It is one combined page because the issue is size M.

## What and why

An engineer or coding agent downloads a zip that holds a posted mockup and a short, structured `README.md`. They implement from that README and the files, not from a message to the author. The bot that makes the mockup writes `HANDOFF.md` into the bundle as part of the publish, and the download route promotes it to `README.md`.

## Decisions

1. **Guidance topic, same shape as the other type skills (RUB-551 decision 1).** `handoff` joins `ArtifactGuidance::TOPICS` and `MIRRORED_TOPICS`. The text lives in `resources/guidance/handoff.md`. The mirror `skills/artfct-handoff/SKILL.md` is the frontmatter plus that text verbatim. The skill is also listed in the plugin pack (`scripts/plugin/sync-skills.mjs`). The guidance version goes from `1.10.0` to `1.11.0`, one minor above the base, and every guided tool's `guidanceVersion` and the `AgentInstructionsTest` pin move with it. Develop may already carry a later bump at merge time; see open questions.
2. **The README is built on download, not stored.** The bot publishes `HANDOFF.md` as an ordinary file of the mockup bundle. The route reads it at download time and writes it into the zip as `README.md`. The route does not store a second copy and does not create a second artifact.
3. **Route.** `artifacts.download.handoff` at `a/{artifactId}/download-handoff.zip`, inside the `auth` group next to `artifacts.download.html`. It reuses the viewer's `resolveArtifact`, `ArtifactContentSource::fetch` and `ArtifactVisibility::canView` path, so access follows the same rules as the HTML download: any refusal of visibility is `404`, the same as a missing artifact.
4. **Type gate.** The route only packs artifacts whose recorded type is `mockup`, read with `ArtifactTypeResolver::typesFor`. A viewable artifact of another type gets `422` with code `handoff_not_mockup` (proposed; see open questions).
5. **Checks before anything is packed.**
   - The Worker did not list the files: `503` (the same refusal as spec 40).
   - No `HANDOFF.md` in the listed files: `422`, code `handoff_missing`.
   - The bundle already has a `README.md` (the zip would hold two entries of that name): `422`, code `handoff_readme_conflict` (proposed).
   - Each blob is read through `ArtifactDirectory::fetchBlob` and must match its listed size. A missing or mis-sized blob is a `422` with the path named, and nothing is written.
6. **Zip contents.** Every listed file, at its own path, except `HANDOFF.md`, which is written as `README.md`. `README.md`'s first line is a link to the artifact's version view, `artifacts.version` (or `artifacts.show` when the version is unknown), followed by the version number. Then comes the rest of `HANDOFF.md` unchanged. The zip is built in a temp file with `ZipArchive`, as `OrganizationExportArchive` does, and the file is deleted after it is sent. No new dependency.
7. **Fidelity and "design reference" language live in the guidance, not the code.** The packer does not judge the README. The guidance tells the bot which nine sections to write, in order: Overview, About these files, Fidelity, Screens or views, Interactions and behavior, State management, Tokens, Assets, Notes. "About these files" says the HTML files are design references to recreate in the target codebase's own framework and patterns, not production code to ship. "Fidelity" says high or low, with exact measurements for high fidelity.
8. **Out of scope.** Opening a pull request, writing production code, a handoff for any type other than `mockup`, and a README that the server writes or edits.
9. **Decision 9 (export never runs artifact script in the app origin)** does not change anything here: the route sends bytes that are never executed. The HTML inside the bundle is not rendered by the route.

## Done-when to test map

| Done when (RUB-564) | Where it is checked |
|---|---|
| `resources/guidance/handoff.md`, `skills/artfct-handoff/SKILL.md` mirror, `handoff` in `TOPICS` and `MIRRORED_TOPICS`, `VERSION` bumped | `McpGuidanceTest`: "handoff is a registered and mirrored guidance topic"; "handoff skill mirrors the guidance verbatim" |
| Guidance lists nine README sections by name, has the design-reference instruction, tells the bot to publish `HANDOFF.md` with `artifact_type: mockup` | `McpGuidanceTest`: "the handoff guidance names the nine README sections in order"; "the handoff guidance says design references, not production code"; "the handoff guidance tells the bot to publish HANDOFF.md as a mockup" |
| Route returns a zip for a `mockup` the caller can view; no access gives `404`; no `HANDOFF.md` gives `422` `handoff_missing` | `tests/Feature/Artifacts/HandoffPackDownloadTest.php` |
| Zip contents: all artifact files, `README.md` present, `HANDOFF.md` not duplicated | `HandoffPackDownloadTest`: "the zip holds every bundle file and README.md, and no HANDOFF.md" |
| `README.md` first line has the view URL and `version` | `HandoffPackDownloadTest`: "the README first line links the artifact version" |
| `handoff_missing` | `HandoffPackDownloadTest`: "a bundle without HANDOFF.md is refused with handoff_missing" |
| `McpGuidanceTest`, `npm run test:skills`, `npm run test:plugin` pass | run output recorded in the commit report |
| Staging: publish a sample mockup with `HANDOFF.md`, download, attach listing and README head to the PR | not run here (no staging access from this session); see the report |
| Pint clean, CI green, reviewed | `vendor/bin/pint --dirty --format agent`; CI and review are the orchestrator's |

## Open questions (owner decision, not guessed)

1. **Non-mockup artifacts.** This plan returns `422 handoff_not_mockup` for a viewable artifact of another type. The alternative is `404`, which hides the type. Confirm.
2. **README conflict.** A bundle that already has `README.md` is refused with `handoff_readme_conflict`. The alternative is to rename the bundle file. Confirm.
3. **Guidance version at merge.** The base is `1.10.0`, so this change sets `1.11.0`. If develop has moved since, the bump must be one minor above develop at merge time (the Done-when says so), which means renumbering the pins again.
4. **Route path.** `download-handoff.zip` is chosen to keep the `artifacts.download.handoff` name and a clear file extension. Confirm if the owner prefers another path.
