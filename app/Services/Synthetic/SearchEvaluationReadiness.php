<?php

namespace App\Services\Synthetic;

use App\Models\ArtifactChunk;
use App\Models\ArtifactIndexEntry;
use App\Models\ArtifactUsageEvent;
use App\Models\Collection;
use App\Models\Team;
use App\Services\Indexing\Chunker;
use App\Services\Indexing\RealEmbeddings;
use RuntimeException;

/** Server-side diagnostic, invoked through authorized staging console access. */
class SearchEvaluationReadiness
{
    /**
     * @return array<string, mixed>
     */
    public function inspect(SearchEvaluationDataset $dataset, SearchEvaluationManifest $manifest): array
    {
        if (! app()->environment('staging', 'testing')) {
            throw new RuntimeException('Readiness requires authorized staging console access.');
        }
        SyntheticSeeder::assertSafe($manifest->data['organization']);
        $team = Team::query()->where('slug', $manifest->data['organization'])->firstOrFail();
        $sources = [];
        foreach ($dataset->data['sources'] as $source) {
            $mapping = $manifest->data['sources'][$source['key']] ?? null;
            $entry = $mapping === null ? null : ArtifactIndexEntry::query()->where('team_id', $team->id)->where('artifact_id', $mapping['id'])->first();
            $reason = match (true) {
                $mapping === null => 'not_published',
                $entry === null => 'not_extracted',
                $entry->version !== $mapping['version'] => 'stale_extraction_version',
                $source['presentation'] === 'rendered' && ! $entry->rendered => 'render_not_completed',
                default => null,
            };
            $chunks = $mapping === null ? collect() : ArtifactChunk::query()->where('team_id', $team->id)->where('artifact_id', $mapping['id'])->orderBy('id')->get();
            if ($reason === null) {
                $expected = trim($entry->extracted_text);
                $title = trim((string) $entry->title);
                if ($title !== '' && ! str_contains($expected, $title)) {
                    $expected = $title."\n".$expected;
                }
                $expectedChunks = Chunker::chunk($expected);
                if ($chunks->pluck('text')->all() !== $expectedChunks || $chunks->isEmpty() || $chunks->contains(fn (ArtifactChunk $chunk): bool => count($chunk->embedding) !== RealEmbeddings::DIMENSIONS || ! $chunk->chunk_created_at->equalTo($entry->extracted_at))) {
                    $reason = 'chunk_embedding_upsert_incomplete_or_stale';
                }
                foreach ($dataset->data['cases'] as $case) {
                    foreach ($case['gold'] as $span) {
                        if ($span['source'] === $source['key'] && ! str_contains(SearchEvaluationDataset::normalize($entry->extracted_text), SearchEvaluationDataset::normalize($span['text']))) {
                            $reason = 'gold_text_missing_from_extraction';
                        }
                    }
                }
            }
            $sources[$source['key']] = ['ready' => $reason === null, 'reason' => $reason, 'version' => $entry?->version, 'chunks' => $chunks->count(), 'extraction_hash' => $entry === null ? null : hash('sha256', $entry->extracted_text)];
        }
        $usage = ArtifactUsageEvent::query()->where('team_id', $team->id)->orderBy('id')->get(['artifact_id', 'event_type', 'actor_user_id', 'viewer_key', 'occurred_at'])->toArray();
        $collections = Collection::query()->where('team_id', $team->id)->orderBy('id')->with('artifacts')->get()->map(fn (Collection $collection): array => ['name' => $collection->name, 'canonical' => $collection->canonical, 'ids' => $collection->artifactIds()])->all();

        return [
            'ready' => ! in_array(false, array_column($sources, 'ready'), true), 'checked_at' => now()->toIso8601String(),
            'dataset_hash' => $dataset->hash(), 'manifest_hash' => $manifest->hash(), 'organization' => $team->slug, 'sources' => $sources,
            'ranking_inputs_hash' => hash('sha256', json_encode([$usage, $collections], JSON_THROW_ON_ERROR)),
            'configuration' => ['embeddings_model' => config('services.cloudflare.embeddings_model'), 'reranker_model' => config('services.cloudflare.reranker_model'), 'indexing_enabled' => config('indexing.enabled'), 'chunk_size' => 800, 'chunk_overlap' => 100],
            'telemetry' => 'Search records served rows; usage boosts change only on subsequent views or shares. This runner never opens viewer links.',
        ];
    }
}
