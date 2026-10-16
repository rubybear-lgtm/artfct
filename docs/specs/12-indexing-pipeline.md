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

Workers AI's `@cf/qwen/qwen3-embedding-0.6b` produces 1024-dimensional embeddings. `PgVectorIndex` stores them in Railway Postgres with a `team_id` on every chunk and scopes every read and write to the resolved team. Exact cosine search is the default at the 10k-vectors-per-org design size. PostgreSQL full-text search supplies the lexical signal for hybrid retrieval in spec 13; Workers AI's `@cf/baai/bge-reranker-base` reranks visible candidates.

### Vector-store decision and remaining cost check

The 2026-09-29 decision on RUB-316 chose pgvector on Railway Postgres instead of Cloudflare Vectorize. The proposed Vectorize 1k/10k/100k probe, seven-day billing observation, and probe-index deletion do not apply to the chosen store.

For the current [Browser Run pricing](https://developers.cloudflare.com/browser-run/pricing/), the `/scrape` Quick Action consumes browser hours only: Workers Paid includes 10 hours per month, then charges $0.09 per hour. Record `X-Browser-Ms-Used` or the dashboard's browser duration for successful staging renders, including retries that incurred usage. For [Workers AI pricing](https://developers.cloudflare.com/workers-ai/platform/pricing/), Qwen3 embedding uses 1,075 neurons per million input tokens and BGE reranking uses 283; the account receives 10,000 free neurons per day, then pays $0.011 per thousand. Estimate gross neurons from the batch's document, query, and rerank input tokens, then compare them with observed model usage. Compare resource units before free allowances, because a zero-dollar bill does not validate the model. The representative batch should be within a factor of two for both browser duration and Workers AI neurons. Measure hybrid search latency against the real providers and database as well.

## Definition of done

- [ ] Deploying a React dashboard whose source HTML contains no body text results in an index entry containing text visible only after hydration.
- [ ] A static HTML artifact indexes without a render pass where the text is already present.
- [ ] Indexing never blocks a deploy — deploy latency is unchanged with the queue backed up.
- [ ] A render timeout retries with backoff, then dead-letters with a reason visible in the console.
- [ ] A dead-lettered artifact still serves normally.
- [ ] Extracted text is stored, and re-embedding runs without re-rendering.
- [ ] Every chunk carries `org_id`, `agent`, `repo_url`, `commit_sha` from provenance.
- [ ] Tenant A's pgvector rows contain no chunk belonging to tenant B, and an org A query cannot return org B's chunks — asserted by direct inspection with two real orgs.
- [ ] Deleting an artifact removes its vectors within one processing cycle.
- [ ] A representative staging batch shows Browser Rendering and Workers AI usage within a factor of two of the provider cost model; real-provider search latency is recorded.

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
