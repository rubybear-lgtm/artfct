# Spec 14 mutation evidence

Solo session (no subagent budget). Mutations run and restored directly,
2026-09-04.

## A real bug caught along the way, not a mutation

`QuotaExceededException`/`BundleTooLargeException` originally declared
`public string $code = '...'`. PHP fatally errors on this — `Exception`'s
built-in `$code` is untyped, and a subclass may not redeclare an inherited
property with an incompatible type ("Type of ...::$code must be omitted
to match the parent definition in class Exception"). The failure mode was
unusually hostile to diagnose: `php artisan test --filter=...` exited 1
with **zero bytes on both stdout and stderr** — the fatal happens during
class declaration, before Pest's output buffering starts, so nothing is
printed at all. Individually-filtered tests that never touched these two
classes ran fine, which is what isolated it. Fixed by renaming the
property to `$errorCode` in both classes (see the docblock added there).
Verified via `php -r` reproducing the exact fatal in isolation before
attributing it.

## Mutations

| Guard removed | Exact command | Mutated result | Restored result |
|---|---|---|---|
| `if ($team->plan !== Plan::Enterprise)` in `PlanGate::requireEnterprise` → `if (false)` | `php artisan test --compact --filter="team_plan_refused_audit_export\|team_plan_refused_retention_config\|team_plan_refused_sso"` | all three failed: no exception/session error where one was expected | all three passed |
| `if ($bundleSizeBytes > $limits->bundleSizeCeilingBytes)` in `QuotaService::assertCanCreateArtifact` → `if (false)` | `php artisan test --compact --filter=bundle_over_tenant_ceiling_refused` | failed: no exception thrown for an oversized bundle | passed |
| `if ($status->anyExceeded())` in `QuotaService::assertCanCreateArtifact` → `if (false)` | `php artisan test --compact --filter=quota_exceeded_refuses_creation` | failed: no exception thrown at 100% usage | passed |
| `'unique:teams,custom_hostname,...'` validation rule in `BillingController::updateHostname` → removed | `php artisan test --compact --filter=duplicate_custom_hostname_rejected` | failed: a raw `PDOException` (SQLite UNIQUE constraint violation) surfaced instead of a friendly session error — proving the validation rule, not just the DB constraint, is what the DoD's "not a 500" half depends on | passed |

| `if ($team->payment_status === PaymentStatus::PastDue)` in `QuotaService::assertCanCreateArtifact` → `if (false)` | `php artisan test --compact --filter=payment_failure_degrades_to_read_only` | failed: no exception thrown for a past-due team | passed |

All five mutations independently confirmed genuine; `git diff` clean
after each restore (verified via a full `php artisan test --compact`
rerun — 130/130 passing).
