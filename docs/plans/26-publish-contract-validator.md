# Implementation plan — Spec 26: Shared publish-contract validator (RUB-574)

Spec: `docs/specs/26-publish-contract-validator.md`. Baseline: `php artisan test --compact tests/Feature/Templates` = 62 passed.

## Steps (red → green → refactor)

1. **Red: validator tests.** `php artisan make:test --pest Artifacts/PublishContractValidatorTest` (feature test in `tests/Feature/Artifacts/`). Write a valid bundle test (`[]`) and one fixture test per rule in the spec table. Run; expect failure "class not found" for the right reason.
2. **Green: validator class.** Create `app/Services/Artifacts/PublishContractValidator.php` by moving the file-level methods from `TemplateCatalog` verbatim: `validateFiles` body minus manifest, `validateReferences`, `externalOrigins`, `resolveRelative`, `htmlHasInlineScript`, `hasDataUri`, `isValidPath`. Add `$where` and `$entrypoint` parameters. Run the new test file; all green.
3. **Red→green: delegation.** `TemplateCatalog::validateFiles` calls `(new PublishContractValidator)->validate($files, "Template [{$key}]", $manifest['entrypoint'])`. Delete the moved private methods. Run `tests/Feature/Templates`; expect 62 passed.
4. **Negative checks.** For each rule, temporarily neuter its branch in the validator, confirm its fixture test fails, restore. Record the output.
5. **Quality.** `vendor/bin/pint --dirty --format agent`; larastan on the changed files. Add one CHANGELOG line.
6. **Commit.** Spec and plan as the first commit; code as the second.

## Test mapping

| Rule | Test name |
|---|---|
| valid bundle | `returns no errors for a valid bundle` |
| inline script | `flags an inline script in html` |
| SVG script | `flags a script element in svg` |
| SVG foreignObject | `flags a foreignObject element in svg` |
| on*= handler | `flags an on*= handler` |
| data URI | `flags a data URI` |
| external origin | `flags an external origin in css` (and html, js) |
| unresolved ref | `flags a reference that is not in the bundle` |

## Coverage not required

No browser or Worker E2E: no runtime behaviour or access control changes.
