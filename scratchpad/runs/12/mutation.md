# Spec 12 mutation evidence

Solo session (no subagent budget). Mutation run and restored directly,
2026-09-04.

| Guard removed | Exact command | Mutated result | Restored result |
|---|---|---|---|
| Per-org key scoping in `FakeVectorIndex::allVectorsForOrg` → merges every org's vectors together regardless of `$orgId` | `php artisan test --compact --filter=tenant_index_contains_no_foreign_vectors` | failed: `expect($chunk->orgId)->toBe('org-a')` saw `'org-b'` — confirms the test genuinely detects cross-tenant leakage | passed |

Confirmed genuine; `git diff` clean after restore (verified via a full
`php artisan test --compact` rerun — 106/106 passing).

Not independently mutation-checked: the DoD marks only
`tenant_index_contains_no_foreign_vectors` as negative for this spec, and
it is the one mutated above. `render_timeout_retries_then_dead_letters`
exercises `IndexArtifactJob::failed()` directly (not a guard being removed
so much as a hook contract being called) — given time constraints this was
verified by direct assertion only.
