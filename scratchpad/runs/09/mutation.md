# Spec 09 mutation evidence

Solo session (no subagent budget — account spend limit). All mutations run
and restored directly, 2026-09-04.

| Guard removed | Exact command | Mutated result | Restored result |
|---|---|---|---|
| Resume-from-failed-step skip logic in `TenantProvisioningService::provision` (loop over all steps unconditionally) | `php artisan test --compact --filter=provision_failure_records_step_and_is_resumable` | failed: `createDatabase`/`createStoragePrefix` called again on resume, instead of skipping to `uploadScript` | passed |
| Already-at-target-version skip in `TenantFleetMigrator::migrateAll` (`schema_version >= $targetSchemaVersion` check removed) | `php artisan test --compact --filter=migrate_all_is_resumable_after_fault_cleared` | failed: all 9 already-migrated tenants were re-migrated on the second run instead of skipped | passed |
| `unresolved_hostname_status()` (forced to `500`) | `cargo test -p artfct-backend unknown_hostname_returns_404_not_500` | failed: `500 != 404` | passed |

All three mutations independently confirmed genuine; `git diff`/file content
matched pre/post in every case (no residue left in the tree).

Not independently mutation-checked (would need the same D1-integration
infrastructure this spec's own DoD split marks as unavailable): none of
`provision_creates_all_resources`, `provision_is_idempotent`, or the fleet
tests' D1-backed equivalents — all are proven against `FakeTenantProvisioner`
only, per the partition's stated scope.
