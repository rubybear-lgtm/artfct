# Spec 27 — First-class artifact types: post and design_system (RUB-575)

Part of epic RUB-551. Combined spec and plan page (size M, one page).

## What and why

Teams filter by kind. A bot asked "what did we decide on pricing?" should see decision posts, not every memo. A design system should be a distinct, filterable thing. Add two declared types: `post` (team context posts, including the "Brand and voice" post) and `design_system` (RUB-568).

## Interfaces

- `App\Enums\ArtifactType` gains `Post = 'post'` and `DesignSystem = 'design_system'`. `values()` includes both.
- Every tool that takes or filters by type accepts both: `deploy_artifact`, `search_artifacts`, `list_artifacts`. Their description strings name them.
- Template manifests accept both through `ArtifactType::values()` (already true, no change).
- Guidance `publish.md` and its mirror `skills/artfct-publish/SKILL.md` list both. `ArtifactGuidance::VERSION` goes 1.4.1 → 1.5.0. Every guided tool's hard-coded `guidanceVersion` moves with it.

## Rules

- **Declared only.** `ArtifactTypeResolver::infer()` never returns `post` or `design_system`. A declared type is kept on later versions.
- Refusal: an unknown type is still refused (`in:` validation).
- `post` is named "team context post" in the enum docblock, so it is not confused with the marketing blog's `BlogPost`.
- Out of scope: post state in data (RUB-559), the kind set (RUB-559 owns it), the post skill (RUB-553).

## Done-when → proof

| Done when (RUB-575) | Proof |
|---|---|
| Both cases exist with docblocks; `values()` returns them | enum; exercised by all type tests |
| `deploy_artifact` accepts and keeps `post` and `design_system`; list and search filter by them | `McpArtifactTypeTest`: "post and design_system are declared types…" (2 datasets), "search and list filter by post and design_system" |
| Enum lists and descriptions name both | `DeployArtifactTool`, `SearchArtifactsTool`, `ListArtifactsTool` descriptions |
| Resolver infers neither | "the resolver never infers post or design_system" |
| Declared type kept on a second version | "a declared post is kept on a later version without a type" |
| Console type filter lists "Post" and "Design system" | **Not met.** `resources/js/pages/console/index.tsx` has no artifact-type filter, only an AI-tool select. Needs a decision: build a type filter (new UI) or drop this item. Raised on RUB-575. |
| Templates accept both | `TemplateCatalog` uses `ArtifactType::values()`; existing `Templates` suite passes |
| `publish.md` and mirror list both; VERSION bumped; `McpGuidanceTest` passes | `McpGuidanceTest` (live-types test) passes |
| Pint clean; CI green; reviewed | pint passed; CI and review pending |

## Plan (as executed)

Red: type tests added and failing (undefined `ArtifactType::Post`). Green: enum cases, tool descriptions, guidance text, version bump across the ten guided tools, the test-slug fix (team slugs are capped at 24 characters). Then guidance, skills, plugin suites.

Note: this spec was written after the code, which breaks the spec-first step for this issue. Recorded here rather than hidden.
