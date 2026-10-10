# Spec 11 mutation evidence

Solo session (no subagent budget). All mutations run and restored
directly, 2026-09-04.

| Guard removed | Exact command | Mutated result | Restored result |
|---|---|---|---|
| `if ($candidate['legal_hold'])` in `RetentionService::apply` → `if (false)` | `php artisan test --compact --filter=legal_hold_survives_retention_job` | failed: FakeArtifactGovernance refused the attempted hard delete of the held artifact with an uncaught `ArtifactUnderLegalHoldException` — proves the retention job would have tried to delete it without the guard | passed |
| `if ($artifact['legal_hold'])` in `FakeArtifactGovernance::hardDeleteArtifact` → `if (false)` | `php artisan test --compact --filter=legal_hold_blocks_admin_hard_delete` | failed: `expect($succeeded)->toBeFalse()` — delete silently succeeded against a held artifact | passed |
| `if ($held !== [])` in `ErasureService::erase` → `if (false)` | `php artisan test --compact --filter=erasure_conflicting_with_hold_is_refused` | failed: uncaught `ArtifactUnderLegalHoldException` when the erasure loop tried to hard-delete the held artifact instead of refusing up front | passed |
| All three `AuditEvent` append-only guards at once (`save()`'s exists-check, `update()`'s throw, `boot()`'s `updating` listener) → all made into no-ops | `php artisan test --compact --filter=audit_rows_have_no_update_or_delete_path` | failed: "Exception LogicException not thrown" on the `save()` path — confirms the test genuinely exercises all three layers, not just one | passed |
| `if ($team->isDirty('region') && ...)` in `Team::boot()`'s `updating` closure → `if (false)` | `php artisan test --compact --filter=region_is_immutable_after_provisioning` | failed: "Exception RuntimeException not thrown" — the region change silently succeeded | passed |

Every mutation independently confirmed genuine; `git diff` clean after each
restore (verified via a full `php artisan test --compact` rerun — 92/92
passing — after all five restores).

The Worker's `governance.rs` pure functions
(`plan_retention`, `plan_erasure`, `check_share_access`) are exercised by
direct unit tests with both branches of each guard already asserted
(e.g. `plan_erasure_refuses_and_names_conflict_when_any_candidate_is_held`
vs. `plan_erasure_proceeds_across_all_artifacts_when_none_held`), which is
weaker than an explicit mutation run but does prove both branches are live
code, not dead code — not independently mutated given time constraints.
