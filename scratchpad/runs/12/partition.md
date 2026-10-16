# Spec 12 partition

Solo session (no subagent budget since spec 08). One unit.

## Closable honestly in this environment

- Pipeline orchestration (`IndexingService`): needs-render heuristic
  (`ExtractionHeuristics::needsRender`, pure), extraction, persisted
  extracted-text storage (`ArtifactIndexEntry`), chunking with overlap
  (`Chunker`, pure), provenance attachment on every chunk, embed, upsert.
  Real Laravel `ShouldQueue` job (`IndexArtifactJob`) with `$tries`/
  `backoff()`/`failed()` dead-lettering to `ArtifactIndexingFailure` — this
  spec is the first one where the actual queue infrastructure the DoD asks
  for (retry, backoff, dead-letter) is something Laravel provides natively,
  unlike spec 11's Worker-side Queues gap.
- Tenant isolation: `VectorIndexContract` is `$orgId`-scoped at every
  method; `FakeVectorIndex` keys its storage by org at the top level, so a
  leak would require crossing array keys entirely — mutation-checked (see
  mutation.md).
- Vector deletion on hard delete: `IndexingService::removeFromIndex()`
  wired into spec 11's `RetentionService`/`ErasureService`/
  `LegalHoldService` wherever they call `hardDeleteArtifact`.
- Re-embedding without re-rendering: `IndexingService::reembed()` reads
  the persisted `ArtifactIndexEntry.extracted_text` and never touches
  `RendererContract`.

## Structural / documented, not measured

- `indexing_does_not_block_deploy`: proven the same way as spec 11's
  backpressure test — dispatching the job (`Queue::fake()`) never invokes
  the renderer; the deploy response path and the job execution are
  provably disjoint code paths. No real deploy latency was measured under
  a backed-up queue.
- "Console surfacing" of dead-lettered artifacts: `ConsoleController`
  passes a real, tested `indexingFailures` prop to the Inertia page; the
  React panel that displays it was not built this session (documented in
  DOCUMENTATION.md).

## Not closable here — named, not silently skipped

- `RealRenderer`/`RealEmbeddings`/`RealVectorIndex` all fail closed (same
  `RealTenantProvisioner` pattern) — no live Cloudflare Browser Rendering,
  Workers AI, or Vectorize account in this environment.
- The trigger wiring: `artifact.created` happens on the Worker, which has
  no live webhook calling into `IndexArtifactJob::dispatch()` in this
  environment (same cross-boundary gap as spec 11's Worker-side audit
  events). `app/Console/Commands/IndexArtifactCommand.php` is the manual/
  ops entry point that exercises the same job a webhook would dispatch.
- The Vectorize cost-model DoD item ("written down and reconciles with
  observed billing over a week") — the written cost model is in
  DOCUMENTATION.md; "reconciles with observed billing" cannot be produced
  without a live account and a week of traffic.
