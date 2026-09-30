<?php

namespace App\Services\Indexing;

use App\Models\ArtifactIndexEntry;
use App\Models\Team;
use Illuminate\Support\Carbon;

/**
 * Orchestrates one artifact's indexing (spec 12): render only if needed,
 * extract, persist the extracted text, chunk with provenance, embed,
 * upsert. Called from `IndexArtifactJob` (the retryable/dead-letterable
 * queue path) and directly from `reembed()` (which never renders again —
 * DoD: "re-embedding runs without re-rendering").
 */
final class IndexingService
{
    public function __construct(
        private readonly RendererContract $renderer,
        private readonly EmbeddingsContract $embeddings,
        private readonly VectorIndexContract $vectorIndex,
    ) {}

    /**
     * @param  array{agent: ?string, repo_url: ?string, commit_sha: ?string}  $provenance
     *
     * @throws RenderTimeoutException bubbles up so the queued job's retry/
     *                                backoff/dead-letter handling applies.
     */
    public function indexArtifact(Team $team, string $artifactId, string $html, array $provenance): ArtifactIndexEntry
    {
        $rendered = ExtractionHeuristics::needsRender($html);

        if ($rendered) {
            $result = $this->renderer->render($team->slug, $artifactId, $html);
            $text = $result->text;
            $title = $result->title;
            $headings = $result->headings;
        } else {
            $text = ExtractionHeuristics::visibleText($html);
            $title = null;
            $headings = [];
        }

        $entry = ArtifactIndexEntry::query()->updateOrCreate(
            ['team_id' => $team->id, 'artifact_id' => $artifactId],
            [
                'rendered' => $rendered,
                'extracted_text' => $text,
                'title' => $title,
                'headings' => $headings,
                'extracted_at' => Carbon::now(),
            ],
        );

        $this->embedAndUpsert($team, $entry, $provenance);

        return $entry;
    }

    /**
     * Re-embeds an already-extracted artifact using its stored text — no
     * renderer call at all.
     *
     * @param  array{agent: ?string, repo_url: ?string, commit_sha: ?string}  $provenance
     */
    public function reembed(Team $team, string $artifactId, array $provenance): void
    {
        $entry = ArtifactIndexEntry::query()
            ->where('team_id', $team->id)
            ->where('artifact_id', $artifactId)
            ->firstOrFail();

        $this->embedAndUpsert($team, $entry, $provenance);
    }

    public function removeFromIndex(Team $team, string $artifactId): void
    {
        $this->vectorIndex->deleteArtifactVectors($team->slug, $artifactId);
    }

    /**
     * The text that gets chunked and embedded. Spec 12 extracts the title and
     * headings along with the visible text, so a rendered page whose body is
     * empty is still findable by its title and headings, and a title the body
     * does not repeat is embedded ahead of it. The stored extraction itself is
     * left as the renderer returned it.
     */
    private function embeddableText(ArtifactIndexEntry $entry): string
    {
        $text = trim((string) $entry->extracted_text);
        $title = trim((string) $entry->title);

        if ($text === '') {
            $headings = implode(' ', array_filter(array_map('trim', (array) $entry->headings)));

            return trim(implode("\n", array_filter([$title, $headings])));
        }

        return $title !== '' && ! str_contains($text, $title) ? $title."\n".$text : $text;
    }

    /**
     * @param  array{agent: ?string, repo_url: ?string, commit_sha: ?string}  $provenance
     */
    private function embedAndUpsert(Team $team, ArtifactIndexEntry $entry, array $provenance): void
    {
        $texts = Chunker::chunk($this->embeddableText($entry));
        if ($texts === []) {
            $this->vectorIndex->deleteArtifactVectors($team->slug, $entry->artifact_id);

            return;
        }

        $vectors = $this->embeddings->embed($texts);

        $chunks = [];
        foreach ($texts as $index => $text) {
            $chunks[] = new VectorChunk(
                text: $text,
                vector: $vectors[$index],
                artifactId: $entry->artifact_id,
                orgId: $team->slug,
                createdAt: $entry->extracted_at->toIso8601String(),
                agent: $provenance['agent'] ?? null,
                repoUrl: $provenance['repo_url'] ?? null,
                commitSha: $provenance['commit_sha'] ?? null,
            );
        }

        $this->vectorIndex->upsertChunks($team->slug, $entry->artifact_id, $chunks);
    }
}
