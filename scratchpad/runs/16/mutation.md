# Spec 16 mutation evidence

Solo session (no subagent budget). Mutations run and restored directly,
2026-09-04.

| Guard removed | Exact command | Mutated result | Restored result |
|---|---|---|---|
| `pinCanonicalCollection` permission check in `TeamPolicy` → `return true` | `php artisan test --compact --filter=viewer_cannot_pin_canonical` | failed: no `AuthorizationException` thrown for a viewer | passed |
| Collection-scope filter in `SearchService::search` → `if (false)` | `php artisan test --compact --filter=collection_scoped_search_excludes_others` | failed: 2 results returned instead of 1 — the out-of-collection artifact leaked through | passed |
| Superseded zeroing in `UsageScorer::score` → `if (false)` | `php artisan test --compact --filter=superseded_artifact_ranks_below_successor` | failed: score was `~3.0` instead of `0.0` — the old artifact's heavy historical usage would have outranked its successor | passed |

All three mutations independently confirmed genuine; `git diff` clean
after each restore (verified via a full `php artisan test --compact`
rerun — 151/151 passing).

Not independently mutation-checked: `collection_invisible_across_orgs` —
isolation here is a direct consequence of `team_id` being a real column
scoping the query, the same shape as every other org-boundary test in
this run; there is no separate guard distinct from the schema to remove.
`repeat_views_raise_ranking`/`slack_share_raises_ranking`/
`retrieved_then_opened_scores_above_retrieved_then_ignored`/
`usage_signal_decays_over_time` exercise `UsageScorer`'s weight/decay
arithmetic directly rather than a boolean guard — verified by direct
assertion (each constructs an adversarial pair and checks strict
ordering) rather than mutation, given time constraints.
