# Spec 3 — Storage split

**Track:** B (data plane) · **Depends on:** 0, 2 · **Blocks:** 4, 5, 7, 8, 9

## Scope

**In.** An artifact-level storage abstraction with two implementations. D1 schema for metadata. R2 for content, content-addressed. Content-addressed IDs replacing the current random scheme. Provenance columns consuming what spec 2 already sends. Bundles stored in open formats so export is possible.

**Out.** Multi-file bundles (spec 4) and per-artifact origins (spec 5). This spec can store a single file in permanent mode; spec 4 makes it a bundle.

## Decisions implemented

- **01** — enterprise artifacts are server-readable; the free tier stays E2EE.
- **05** — one codebase, two storage modes.
- **07** — provenance columns.
- **11** — open formats in R2 and a working export path.

## Design

### Abstraction level

The trait sits at the **artifact** level, not the storage-primitive level:

```rust
trait ArtifactStore {
    async fn put(&self, artifact: NewArtifact) -> Result<StoredRef>;
    async fn get(&self, id: &ArtifactId) -> Result<Option<Artifact>>;
    async fn delete(&self, id: &ArtifactId) -> Result<()>;
    async fn list(&self, filter: ListFilter) -> Result<Page<ArtifactSummary>>;
}
```

A trait unifying KV-blob and D1+R2 at the primitive level degrades into a lowest-common-denominator interface that fits neither. `list` is unimplemented for the KV backend and says so — anonymous artifacts are not listable by design.

Mode-specific behaviour stays in explicit handler branches rather than hidden behind a leaky abstraction. The free path is higher-traffic and lower-value: it must not get slower or wider in attack surface because of enterprise complexity.

### Content-addressed IDs

`sha256(bundle)` truncated to **32 lowercase hex characters**. This replaces the 10-char random scheme and its read-back collision check, which does not survive an unbounded corpus.

**The DNS-label constraints are decided here, and spec 5 inherits them** — the hostname format is spec 5's concern but the ID format is fixed now:

- A DNS label caps at 63 characters. 24-char tenant slug + `--` + 32-char id = 58, with headroom.
- `--` is **reserved as the separator and forbidden inside tenant slugs**, enforced at tenant creation, or `acme--corp--abc123` is ambiguous.
- Labels are `[a-z0-9-]`, no leading or trailing hyphen. Lowercase hex satisfies this; slugs are validated.
- Non-ASCII rejected outright in slugs — homograph defence.

Dedupe falls out for free: identical content produces an identical hash. Reference counting is required before deletion, because two artifacts may point at one blob.

### Schema (per-tenant D1)

`orgs`, `users`, `memberships`, `api_tokens`, `artifacts`, `artifact_versions`, `files`, `blobs`, `shares`, `audit_events`, `provenance`.

Promoted, indexed columns on `artifacts`: `org_id`, `user_id`, `content_hash`, `created_at`, `superseded_by`, `retention_class`, `legal_hold`.
Promoted on `provenance`: `agent`, `repo_url`, `commit_sha`. The long tail — including `sources` — lives in a JSON column; D1 is SQLite, so JSON1 lets clients send new fields without a migration each time.

### R2 layout

`blobs/<sha256>` — raw bytes, no wrapper, original content type in metadata. Open format, no proprietary envelope, so export is a copy rather than a conversion. That is decision 11's requirement discharged at the storage layer.

### What the free tier keeps

KV, E2EE, fragment key, TTL, anonymous. Its record shape is redesigned to mirror the permanent one where they overlap — no users exist, so this is a choice rather than an inheritance.

## Definition of done

- [ ] `artfct deploy ./x.html` (no auth) stores in KV, returns a fragment URL, and `/p/{id}` renders exactly as before.
- [ ] `artfct deploy ./x.html --tier permanent` with an org token stores metadata in D1 and bytes in R2, and returns a URL that renders.
- [ ] The same file deployed twice in permanent mode produces one row in `blobs` and two in `artifacts`.
- [ ] Deleting one of those two artifacts leaves the blob intact; deleting both removes it.
- [ ] A 6 MB HTML file deploys successfully in permanent mode — the 1 MB cap is gone.
- [ ] `SELECT agent, repo_url, commit_sha FROM provenance` returns populated rows from a spec-2 client, with `sources` intact in the JSON column.
- [ ] A tenant slug containing `--` is rejected at creation with a clear error.
- [ ] A tenant slug containing non-ASCII is rejected at creation.
- [ ] `<slug>--<id>` is ≤ 63 characters for the maximum permitted slug — asserted in a test, not by inspection.
- [ ] An export command writes every blob plus a metadata JSON for one org to a local directory, and the blobs are byte-identical to what was uploaded.

## Tests

**`backend`**
- `ephemeral_roundtrip_unchanged`
- `permanent_roundtrip_stores_d1_and_r2`
- `identical_content_dedupes_to_one_blob`
- `delete_decrements_refcount_not_blob`
- `delete_last_reference_removes_blob`
- `content_hash_is_32_lowercase_hex`
- `hostname_label_fits_63_chars_at_max_slug`
- `slug_with_double_hyphen_rejected`
- `slug_with_non_ascii_rejected`
- `provenance_columns_populated_from_request`
- `provenance_json_tail_survives_unknown_fields`
- `list_unimplemented_for_kv_backend`
- `permanent_mode_requires_auth`  *(negative)*
- `ephemeral_mode_rejects_manifest_field`  *(negative)*

**Export**
- `export_writes_byte_identical_blobs`
- `export_metadata_round_trips`

## Rollback

**Partially irreversible.** New IDs are content-addressed and any URL issued under them is permanent. The KV path is untouched, so free-tier links survive regardless. Reverting after permanent artifacts exist requires exporting and re-importing — which is why the export path is in this spec rather than later.

## Deferred

- Multi-file bundles — spec 4.
- R2 lifecycle tiering (hot → infrequent → archive). Needed before the first large tenant, not before the first tenant.
- Cross-tenant dedupe. Deliberately not done: sharing blobs between tenants is a data-isolation problem, not an optimisation.
