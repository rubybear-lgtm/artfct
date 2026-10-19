# Spec 08 mutation evidence

Backend and console units both hit the account monthly spend limit mid-task
(see partition.md addendum below) and left incomplete/broken work. The
coordinator finished the backend wiring, wrote all 8 named backend tests,
fixed the console unit's fatal duplicate-function break and several test
bugs, and ran the mutation checks below directly (no separate reviewer
agent — spawning was blocked by the same spend limit).

## Backend (independently executed, 2026-09-04)

| Guard removed | Exact command | Mutated result | Restored result |
|---|---|---|---|
| Cursor-position skip in `paginate_sorted` (`start` forced to `0`) | `cargo test -p artfct-backend cursor_pagination_has_no_gaps_or_duplicates` | failed: artifact `0000000000` returned on more than one page | passed |
| Revoked-set membership check in `MemoryArtifactStore::is_servable` (forced `true`) | `cargo test -p artfct-backend revoke_soft_deletes_and_stops_serving` | failed: artifact still reported servable after revoke | passed |

Both mutations independently confirmed genuine; `git diff --stat` matched
pre/post in both cases (no residue left in the tree).

While strengthening the pagination test to the DoD's literal 10,000-row
scale (originally written smaller, at 2,500), the test's own fixture
generator was found to produce a non-monotonic `created_at` sequence (a
minute/second formula that cycled every 3,600 items) — `paginate_sorted`
requires pre-sorted input, so the fixture violated its own precondition.
This was a bug in the test's synthetic data, not in `paginate_sorted`;
fixed by generating monotonically increasing RFC 3339 timestamps.

## Not independently mutation-checked

`list_org_artifacts`/`revoke_org_artifact` (the D1-backed SQL paths) and
the revoked-artifact 404 gate added to `resolve_permanent_artifact`'s SQL
— none are closable without a live Wrangler Worker, consistent with every
prior spec's `#[ignore]`d-integration-test pattern. No such integration
test was added for this spec's D1 paths due to the time lost to the spend
limit interruption; this is a real gap, flagged rather than silently
carried.
