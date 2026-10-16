# Spec 13 mutation evidence

Solo session (no subagent budget). Mutations run and restored directly,
2026-09-04.

| Guard removed | Exact command | Mutated result | Restored result |
|---|---|---|---|
| `$artifact === null \|\| $artifact['revoked_at'] !== null` in `SearchService::search`'s filter closure → `if (false)` | `php artisan test --compact --filter="revoked_artifact_absent_from_results\|inaccessible_artifact_indistinguishable_from_absent"` | both failed: the revoked-artifact test saw a non-empty result set; the inaccessible-artifact test crashed with "Undefined array key" — proving the null-check is what makes an inaccessible artifact silently vanish rather than fatal | both passed |
| Per-org key scoping in `FakeVectorIndex::query` → flattens `$this->byOrg` across all orgs before searching | `php artisan test --compact --filter=search_scoped_to_credential_org` | failed: org A's search returned org B's artifact | passed |
| Repo-match boost application in `SearchRanking::combinedScore` → `if (false)` | `php artisan test --compact --filter=exact_repo_match_outranks_semantic_match` | failed: the semantically-closer unrelated-repo artifact won instead of the billing-repo one | passed |

All three mutations independently confirmed genuine; `git diff` clean
after each restore (verified via a full `php artisan test --compact`
rerun — 115/115 passing).
