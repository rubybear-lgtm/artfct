# Spec 21 mechanism — mutation check (2026-11-14)

Every `*(negative)*` test owned by this unit: guard removed/muted → test
must FAIL → guard restored → test must PASS. No survivors. Full-file runs
only (`PAO_DISABLE=1 php artisan test <file> --compact`); filtered runs were
not used.

Baseline (post-`TemplateListing` refactor): `TemplateCatalogTest` 19/19,
`TemplatePullTest` 9/9, `TemplateLibraryTest` 9/9. The refactor had dropped
the `Illuminate\Contracts\JsonSchema\JsonSchema` import in
`ListTemplatesTool` (fatal in every MCP suite); restored as part of this
pass (separate commit).

## Catalog — `tests/Feature/Templates/TemplateCatalogTest.php` (19/19)

| # | Guard muted (`app/Services/Templates/TemplateCatalog.php` unless noted) | Target negative | Without guard | Restored |
|---|---|---|---|---|
| M1 | inline-`<script>` check (`validateFiles`) | `rejects malformed … "inline script"` | FAIL (1 failed, 18 passed) | 19 passed |
| M2 | ``on*=` `` handler check | `"on handler"` | FAIL | 19 passed |
| M3 | ``data:`` URI check | `"data uri"` | FAIL | 19 passed |
| M4 | `externalOrigins` loop → `[]` | `"external origin"` + `"external stylesheet import"` (both FAIL) | FAIL (2 failed, 17 passed) | 19 passed |
| M5 | db collection ``index`` check (`validateDbManifest`) | `"bad db manifest"` | FAIL | 19 passed |
| M6 | seed undeclared-collection check | `"undeclared seed collection"` | FAIL | 19 passed |
| M7a | entrypoint-in-files check | `"missing entrypoint"` | FAIL | 19 passed |
| M7b | reference-resolves check (`validateReferences`) | `"missing referenced asset"` | FAIL | 19 passed |
| M8 | reserved ``__artfct/`` prefix check | `"reserved prefix"` | FAIL | 19 passed |
| M9 | missing `skill/SKILL.md` check (`validateSkill`) | `"missing skill"` | FAIL | 19 passed |
| M10 | skill/files `SKILL.md` byte-identical check | `"skill mismatch"` | FAIL | 19 passed |
| M11 | manifest `key` == directory check | `"key mismatch"` | FAIL | 19 passed |
| M12 | `requires` known-feature check | `"unknown requires"` | FAIL | 19 passed |
| M13 | bundle-size ceiling (`MAX_BUNDLE_BYTES`) | `rejects an oversized bundle` | FAIL | 19 passed |
| M14 | seed cap (`MAX_SEED_BYTES`) | `rejects seed over the cap` | FAIL | 19 passed |
| M15 | reserved-prefix refusal in `app/Mcp/Tools/DeployArtifactTool.php` | `deploy_artifact refuses a bundle path under the reserved prefix` | FAIL | 19 passed |

## Pull — `tests/Feature/Templates/TemplatePullTest.php` (9/9)

| # | Guard muted | Target negative | Without guard | Restored |
|---|---|---|---|---|
| M16 | `FeatureFlaggedTool::shouldRegister` → always true | `pull_refused_when_…_not_released` + `template_tools_hidden_until_released` (both FAIL) | FAIL (2 failed, 7 passed) | 9 passed |
| M17 | `TemplatePuller::pull` requires loop → `[]` | `pull_refused_when_artifact_db_or_templates_not_released` | FAIL (1 failed, 8 passed) | 9 passed |
| M18 | past-due refusal in `app/Services/Billing/QuotaService.php` | `pull_obeys_quota_daily_limit_and_scope` | FAIL | 9 passed |
| M19 | `TemplatePuller::requireScope` → never throws | `pull_obeys_quota_daily_limit_and_scope` (scope half) | FAIL | 9 passed |
| M20 | `TemplateListing` `available` → always true | `list_templates_marks_unavailable_templates` | FAIL | 9 passed |

## Library — `tests/Feature/Console/TemplateLibraryTest.php` (9/9)

| # | Guard muted (`app/Http/Controllers/ConsoleController.php` unless noted) | Target negative | Without guard | Restored |
|---|---|---|---|---|
| L1 | index `templatesReleased`/`templates` → always on | `the_templates_section_is_hidden_until_released` | FAIL | 9 passed |
| L2 | `TemplateListing` `available` → always true (shared with M20) | `a_template_needing_an_unreleased_feature_lists_as_unavailable` | FAIL | 9 passed |
| L3 | index `canPullTemplates` → always true | `a_viewer_sees_templates_but_gets_no_use_control` | FAIL | 9 passed |
| L4 | route `abort_unless(templates)` **and** puller requires loop muted together | `pulling_before_release_is_a_404` | FAIL | 9 passed |
| L5 | `insufficient_scope` → 403 mapping changed to 404 | `a_viewer_cannot_pull` | FAIL | 9 passed |
| L6 | `template_not_found` dropped from the 404 mapping | `pulling_an_unknown_template_is_a_404` | FAIL | 9 passed |

Note on L4 layering: muting only the route guard leaves the test green —
the puller's own requires check still refuses with `feature_not_enabled`,
which the route maps to 404. That layer is itself mutation-verified (M17),
so the visible 404 is guard-backed twice over (cheap early refusal at the
route, authoritative refusal in the puller). No survivor hole.

## Result

26 mutations, 0 surviving negatives. Working tree after the pass contains
only the `ListTemplatesTool` import restoration; all mutations were
restored (`git diff --stat` clean apart from that fix).
