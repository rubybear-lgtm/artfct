# Spec 26 — Shared publish-contract validator (RUB-574)

Part of epic RUB-551. Size S. Combined spec and plan page (small issue); the plan lives in `docs/plans/26-publish-contract-validator.md`.

## What and why

The publish contract's file checks live as private methods of `TemplateCatalog`. Starters, packagers and sales templates need to prove "this bundle obeys the contract" in tests. Extract the checks once into a public service so every child reuses the same rules.

## Interface

`App\Services\Artifacts\PublishContractValidator` (final, no constructor state).

```php
/**
 * @param  array<string, string>  $files  relative path => file bytes
 * @return list<string>  error strings; empty means the bundle is valid
 */
public function validate(array $files, string $where = 'Bundle', ?string $entrypoint = null): array
```

- `$where` prefixes every error string. `TemplateCatalog` passes `Template [{key}]`, so its messages do not change.
- `$entrypoint`, when given, must name a file in `$files`. It is optional so the validator stays usable without a manifest.

## Rules checked

Moved unchanged from `TemplateCatalog` (same error strings, same order):

| Rule | Error prefix |
|---|---|
| file count over `MAX_BUNDLE_FILES` | `{where} has N files; the bundle limit is …` |
| total bytes outside 1..`MAX_BUNDLE_BYTES` | `{where} totals N bytes …` |
| invalid relative path | `{where} file [p] is not a valid relative bundle path.` |
| `__artfct/` prefix | `{where} file [p] lives under the reserved …` |
| entrypoint missing (when given) | `{where} entrypoint [e] is not one of …` |
| inline `<script>` in HTML | `… has an inline <script> …` |
| `<script>` in SVG | `… has an SVG <script> element …` |
| `<foreignObject>` in SVG | `… has an SVG <foreignObject> element …` |
| `on*=` handler in HTML or SVG | `… has an on*= handler …` |
| `data:` URI | `… has a data: URI …` |
| external origin in html, svg, css, js | `… loads an external origin […] …` |
| absolute path reference | `… references absolute path […] …` |
| unresolved relative reference | `… references […], which is not in files/.` |

`TemplateCatalog` keeps its manifest-level checks (`validateManifest`, `validateDb`, `validateSkill`) and calls the validator from `validateFiles`.

## Edge cases and refusals

- Invalid or empty paths, `..` escapes, and absolute references are refused, not normalised.
- Unknown extensions are skipped by design (the contract checks only html, htm, css, js, mjs, svg). This is unchanged.
- Out of scope: changing the contract, Worker CSP, wiring into `deploy_artifact`. `DeployArtifactTool` keeps its own private checks (its `isValidPath` and `htmlHasInlineScript` are not touched).

## Done-when → proof

| Done when (RUB-574) | Proof |
|---|---|
| Public `validate()` returns errors, empty when valid | `PublishContractValidatorTest`: valid bundle → `[]` |
| Checks inline script, SVG script/foreignObject, `on*=`, data URIs, external origins, unresolved refs | one violating fixture per rule, each asserting the matching error substring |
| `TemplateCatalog` delegates; `tests/Feature/Templates` passes, no edits to existing tests | `php artisan test --compact tests/Feature/Templates` (baseline 62 passed), with the existing test files unchanged |
| Pint and larastan clean | `vendor/bin/pint --dirty --format agent`; larastan run |
| CI green, reviewed | PR checks plus independent Sonnet review |

Negative tests: for each violating fixture, break the matching rule in the validator and confirm that test fails, then restore.

## Non-goals and notes

- No runtime behaviour change, so no staging step. No preview environment.
- Docs: no behaviour change to document beyond the internal class. `CHANGELOG.md` gets one line under Unreleased.
