# Spec 12 — Indexing pipeline

**Track:** F (thesis) · **Depends on:** 3, 9, 11 · **Blocks:** 13

## Scope

**In.** Headless render of stored artifacts, post-hydration text extraction, chunking, embeddings, and a per-tenant vector index. The job runner that drives it.

**Out.** Querying it — spec 13. This spec builds the corpus; spec 13 makes it useful.

**This is where the product stops being an artifact host.** Everything before it is substrate.

## Decisions implemented

- **01** — server-readable enterprise artifacts. Indexing is the reason that decision was forced, and this is where it pays.
- **07** — provenance, captured since spec 2, becomes queryable metadata here.

## Design

### Why rendering, not parsing

A React dashboard's source HTML contains a `<div id="root">` and a script tag. Parsing it yields nothing. Real content only exists after hydration, so extraction requires **Cloudflare Browser Rendering** driving a headless browser, on a queue, never in the request path.

Cost is bounded and known: 10 browser-hours included monthly, then $0.09/hour. At ~5 seconds per artifact and 20,000 artifacts a month, roughly 28 hours — about $2. Not a constraint at this scale.

### Pipeline

`artifact.created` → Queue → render → extract → chunk → embed → upsert.

Each stage is separately retryable. A render failure must not lose the artifact: it is retried with backoff, then parked in a dead-letter queue with the reason recorded and surfaced in the console. An artifact that cannot be indexed is still a valid artifact.

Indexing is **asynchronous and best-effort**. It never blocks a deploy, and a total indexing outage degrades search rather than breaking the product.

### Extraction

Rendered DOM → visible text, plus `title`, headings, table headers, and chart accessible labels where present. Scripts, styles and hidden elements dropped. Extraction output is stored so re-embedding with a different model does not require re-rendering — rendering is the expensive step.

### Chunking and metadata

Chunk with overlap. Every chunk carries `artifact_id`, `org_id`, `created_at`, `agent`, `repo_url`, `commit_sha` — the provenance captured back in spec 2, which is what makes spec 13's provenance-aware queries possible and is the reason those columns could not be backfilled.

### Embeddings and index

Workers AI for embeddings. **One Vectorize index per tenant** — the isolation boundary is the same as everywhere else, and a shared index with metadata filtering is one bug away from cross-tenant leakage.

### The cost caveat that must be resolved before committing

Vectorize bills on "queried vector dimensions", documented as `(vectors in index + query vectors) × dimensions` per query. Applied naively to a multi-million-vector corpus that produces absurd totals, yet Cloudflare's own worked example lands at $1.94/month. **The two readings do not reconcile.** Model it against a realistic corpus before this ships; it is the only line item in the whole plan with order-of-magnitude uncertainty, and it sits underneath the feature that is supposed to be the product. If it does not reconcile, an alternative vector store is the fallback and the pipeline above is unchanged.

## Definition of done

- [ ] Deploying a React dashboard whose source HTML contains no body text results in an index entry containing text visible only after hydration.
- [ ] A static HTML artifact indexes without a render pass where the text is already present.
- [ ] Indexing never blocks a deploy — deploy latency is unchanged with the queue backed up.
- [ ] A render timeout retries with backoff, then dead-letters with a reason visible in the console.
- [ ] A dead-lettered artifact still serves normally.
- [ ] Extracted text is stored, and re-embedding runs without re-rendering.
- [ ] Every chunk carries `org_id`, `agent`, `repo_url`, `commit_sha` from provenance.
- [ ] Tenant A's index contains no vector belonging to tenant B — asserted by direct inspection.
- [ ] Deleting an artifact removes its vectors within one processing cycle.
- [ ] A cost model for Vectorize against a 1M-vector corpus is written down and reconciles with observed billing over a week.

## Tests

**Pest / `backend`**
- `js_heavy_artifact_indexes_post_hydration_text`
- `static_artifact_indexes_without_render`
- `indexing_does_not_block_deploy`
- `render_timeout_retries_then_dead_letters`
- `dead_lettered_artifact_still_serves`
- `extracted_text_persisted_for_reembedding`
- `chunks_carry_provenance_metadata`
- `tenant_index_contains_no_foreign_vectors` *(negative)*
- `artifact_deletion_removes_vectors`
- `indexing_outage_degrades_search_not_serving`

## Rollback

Reversible. The index is derived data; dropping and rebuilding it from stored artifacts and extracted text costs compute, not information.

## Deferred

- Image and chart content extraction beyond accessible labels.
- Incremental re-index on artifact update. Full re-index is fine at this volume.
