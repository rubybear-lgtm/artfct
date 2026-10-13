<?php

namespace App\Services\Search;

use App\Services\Indexing\VectorChunk;
use App\Services\Indexing\VectorMatch;

/** Keep artifact candidates, but let the reranker compare up to three distinct passages each. */
final class SearchPassageSelection
{
    /**
     * @param  list<VectorMatch>  $artifacts
     * @param  list<VectorChunk>  $introductions
     * @param  list<VectorMatch>  $retrieved
     * @param  array<string, string|null>  $titles
     * @return list<VectorMatch>
     */
    public static function expand(array $artifacts, array $introductions, array $retrieved, string $query = '', array $titles = []): array
    {
        $passages = [];
        foreach ($artifacts as $artifact) {
            $seen = [];
            $count = 0;
            $intro = array_values(array_filter($introductions, fn (VectorChunk $chunk): bool => $chunk->orgId === $artifact->chunk->orgId && $chunk->artifactId === $artifact->chunk->artifactId));
            $joined = self::joinIntroductions($intro);
            foreach ([$artifact->chunk, ...$joined, ...$intro, ...array_map(fn (VectorMatch $match): VectorChunk => $match->chunk, $retrieved)] as $chunk) {
                if ($chunk->orgId !== $artifact->chunk->orgId || $chunk->artifactId !== $artifact->chunk->artifactId) {
                    continue;
                }
                $title = $titles[$chunk->artifactId] ?? null;
                // The retrieved representative chunk is always compared unmodified, so focusing can only add evidence.
                $context = $query === '' || $chunk === $artifact->chunk
                    ? $chunk->text
                    : trim($title."\n".SearchSnippet::make($chunk->text, $query, $title, 400));
                $hash = hash('sha256', $context);
                $source = hash('sha256', $chunk->text);
                if (isset($seen[$hash]) || isset($seen[$source])) {
                    continue;
                }
                $seen[$hash] = true;
                $seen[$source] = true;
                $passages[] = new VectorMatch(new VectorChunk(
                    $context, $chunk->vector, $chunk->artifactId, $chunk->orgId, $chunk->createdAt, $chunk->agent, $chunk->repoUrl, $chunk->commitSha,
                ), $artifact->similarity);
                if (++$count === 3) {
                    break;
                }
            }
        }

        return $passages;
    }

    /**
     * Preserve sentences crossing the existing chunk boundary without changing indexed chunks.
     *
     * @param  list<VectorChunk>  $chunks
     * @return list<VectorChunk>
     */
    private static function joinIntroductions(array $chunks): array
    {
        if (count($chunks) < 2) {
            return [];
        }
        [$first, $second] = $chunks;
        for ($overlap = min(mb_strlen($first->text), mb_strlen($second->text), 200); $overlap >= 20; $overlap--) {
            if (mb_substr($first->text, -$overlap) === mb_substr($second->text, 0, $overlap)) {
                return [new VectorChunk($first->text.mb_substr($second->text, $overlap), $first->vector, $first->artifactId, $first->orgId, $first->createdAt, $first->agent, $first->repoUrl, $first->commitSha)];
            }
        }

        return [];
    }

    /**
     * @param  list<VectorMatch>  $passages
     * @return list<VectorMatch>
     */
    public static function bestArtifacts(array $passages, float $minimumRelevance, string $query = ''): array
    {
        usort($passages, fn (VectorMatch $a, VectorMatch $b): int => $b->similarity <=> $a->similarity);
        $seen = [];
        $best = [];
        $queryTerms = SearchSnippet::terms($query);
        foreach ($passages as $passage) {
            $id = $passage->chunk->orgId.':'.$passage->chunk->artifactId;
            // Weak model scores need multiple corroborating terms, not one ambiguous topic word.
            $matchedTerms = array_intersect($queryTerms, SearchSnippet::terms($passage->chunk->text));
            $lexicalEvidence = count($matchedTerms) >= 2 && count($matchedTerms) / max(1, count($queryTerms)) >= 0.5;
            $relevant = $passage->similarity >= $minimumRelevance || ($passage->similarity >= 0.001 && $lexicalEvidence);
            if (! $relevant || isset($seen[$id])) {
                continue;
            }
            $seen[$id] = true;
            $best[] = $passage;
        }

        return $best;
    }
}
