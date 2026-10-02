# Spec 08 partition

| Unit | Owns these files | DoD items | Depends on |
|---|---|---|---|
| Backend list/filter/revoke/export API | `backend/src/lib.rs`, `backend/src/store.rs`, `openapi/artfct.yaml`, `DOCUMENTATION.md` (backend section) | Correctness-shaped items: 1 (structural, see below), 2, 3, 4, 5, 6, 10, 11 (export byte-identity/provenance) | Spec 07 commit `0d7b373` |
| Console UI (Laravel/Inertia) | `app/**` (new), `routes/**`, `resources/js/**` (new), `tests/Feature/**` (new), `tests/Browser/**`, `README.md`, `CHANGELOG.md` | Authorization/UX-shaped items: 7, 8, 9 (extends existing), plus browser tests | Backend unit's documented API contract (openapi), not its code — can run in parallel |

Two units, genuinely disjoint files, run in parallel — the console consumes
the backend's HTTP contract (documented in openapi/artfct.yaml, agreed
before either starts), not its Rust code.

## Architecture, decided up front

The artifact table lives in the Worker's D1 (spec 3/4/7), not Laravel's own
database. Laravel's Pest tests cannot seed 10,000 rows into D1, so:

- **Filter/pagination/export correctness** (does `repo_url` filtering
  actually filter, does cursor pagination skip/duplicate rows, are exported
  blobs byte-identical) is proven by the **backend unit's Rust tests**
  against seeded local D1 — same `#[ignore]`-when-infra-dependent pattern as
  every prior spec's integration tests.
- **Authorization and UX** (viewer sees no revoke button, 403 from the API,
  cross-org 404, token shown once, export admin-gated) is proven by the
  **console unit's Pest tests** against a **faked Worker HTTP client**
  (`Http::fake()`, same pattern as spec 07's `RevocationWriter` tests) —
  the console never talks to a real D1 row in its own test suite.

This splits the spec's own "Pest — feature" test list across the two units
(the spec files them all under one heading; that heading is not a partition
boundary):

- Backend-owned: `list_filters_by_repo`, `list_filters_by_agent`,
  `list_filters_by_date_range`, `cursor_pagination_has_no_gaps_or_duplicates`,
  `revoke_soft_deletes_and_stops_serving`, `revoked_artifact_retains_provenance`,
  `export_blobs_are_byte_identical`, `export_includes_provenance_sources`
- Console-owned: `viewer_cannot_revoke`, `member_cannot_read_other_org_list`,
  `cross_org_list_returns_404_not_403`, `token_value_shown_once_only`
  (already exists as `token_creation_returns_value_once_only` from spec 07 —
  extend, don't duplicate), `export_requires_admin`, `export_is_rate_limited`,
  both browser tests (`console_pages_have_no_js_errors` already exists from
  spec 06 — extend it to cover the new console pages, don't recreate)

**Performance item #1** (`list_of_10k_artifacts_renders_under_500ms`) is
closed structurally by the backend unit: cursor pagination on
`(created_at, id)` with spec-3's indexed columns, bounded page size, no
N+1 query. No wall-clock measurement is taken in this environment — say so
plainly rather than inventing a `microtime()` assertion on a dev machine,
which would be a flaky, meaningless number anyway.

## Model trial

Backend unit: `sonnet` (list/filter/cursor-pagination/export correctness is
subtle enough to warrant it). Console unit: `haiku`, per the user's request
to trial a smaller model on a mechanical CRUD/Inertia-shaped unit — judged
on what the zero-context reviewer finds, not merely on whether its tests
pass.

## Known tension, not a DoD gap

Spec 06's frontend shipped deliberately unstyled (flagged at the time).
Spec 08's own design section calls this screen "the demo that sells" — a
real tension, but not in this spec's DoD. Build functional, flag the
styling gap at close-out; do not let the console implementer take on an
unprompted design pass.
