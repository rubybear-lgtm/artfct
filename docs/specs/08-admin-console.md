# Spec 8 — Admin console and export

**Track:** C (control plane) · **Depends on:** 3, 6, 7 · **Blocks:** nothing; it is the first thing a buyer sees

## Scope

**In.** The org-facing console: list, filter and search artifacts; revoke them; manage API tokens; see usage. A working data export of artifacts, metadata and provenance.

**Out.** Audit log and SIEM export (spec 11) — this spec shows *what exists*, spec 11 shows *what happened*. Billing views (spec 14).

## Decisions implemented

- **09** — the console lives in Laravel/Inertia.
- **11** — a working export path, as a product requirement rather than an architectural hedge. A governance buyer asks "can we get our data out?" at procurement.

## Design

### The demo that sells

The admin-facing pitch is one screen: *here is every artifact your engineers' agents produced this month, here is who saw them, here is the kill switch.* Build that screen first and let the rest follow from it.

### Listing and filtering

Backed by the promoted, indexed columns from spec 3 — `org_id`, `user_id`, `created_at`, `content_hash` — plus provenance's `agent`, `repo_url`, `commit_sha`.

Filters that matter, in the order a buyer reaches for them: by person, by repo, by agent, by date range, by size. Free-text search over title and description is SQL `LIKE` here; semantic search is spec 12 and must not be conflated with it.

Cursor pagination on `(created_at, id)`. Offset pagination over an unbounded, permanently growing corpus degrades exactly when the product is succeeding.

### Revocation

Revoking is a **soft delete** — `revoked_at` set, artifact stops serving, row and blob retained. Retention and legal hold in spec 11 depend on this. Hard deletion is a separate, audited action, and reference counting from spec 3 governs whether the blob goes.

### Export

`GET /v1/orgs/{org}/export` produces a stream containing every blob in its original bytes plus a metadata JSON per artifact including full provenance. Open formats, no proprietary envelope — spec 3 stored them that way for this reason.

Export is admin-only, audited, and rate-limited: it is by definition the largest read an org can perform, and it is also what an exfiltrating insider would reach for.

## Definition of done

- [ ] The artifact list renders for an org with 10,000 artifacts in under 500 ms.
- [ ] Filtering by `repo_url` returns only artifacts from that repo.
- [ ] Filtering by `agent = cursor` returns only Cursor-produced artifacts.
- [ ] Paginating to the end of a 10,000-row list produces no duplicated or skipped rows.
- [ ] Clicking revoke stops the artifact serving; its URL returns 404 within the propagation window.
- [ ] A revoked artifact still appears in the list, marked revoked, with its provenance intact.
- [ ] A `viewer` sees the list but has no revoke control, and the API rejects the action with 403.
- [ ] A member of org A cannot load org B's list — 404, not 403.
- [ ] Creating an API token shows its value exactly once; reloading never shows it again.
- [ ] Export produces byte-identical blobs and metadata that includes `sources` for every provenance field.
- [ ] Export by a non-admin is rejected; export by an admin appears in the audit trail once spec 11 lands.

## Tests

**Pest — feature**
- `list_filters_by_repo`
- `list_filters_by_agent`
- `list_filters_by_date_range`
- `cursor_pagination_has_no_gaps_or_duplicates`
- `revoke_soft_deletes_and_stops_serving`
- `revoked_artifact_retains_provenance`
- `viewer_cannot_revoke` *(negative)*
- `member_cannot_read_other_org_list` *(negative)*
- `cross_org_list_returns_404_not_403` *(negative)*
- `token_value_shown_once_only`
- `export_requires_admin` *(negative)*
- `export_is_rate_limited` *(negative)*
- `export_blobs_are_byte_identical`
- `export_includes_provenance_sources`

**Pest — browser**
- `admin_finds_artifact_by_repo_and_revokes_it` — the demo flow, end to end
- `console_pages_have_no_js_errors`

**Performance**
- `list_of_10k_artifacts_renders_under_500ms`

## Rollback

Reversible. Read paths and soft deletes only; nothing is destroyed.

## Deferred

- Bulk actions. Wanted, but a bulk revoke with no audit log behind it is a support incident; sequence it after spec 11.
- Saved filters and scheduled exports.
