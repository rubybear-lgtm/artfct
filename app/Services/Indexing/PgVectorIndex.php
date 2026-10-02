<?php

namespace App\Services\Indexing;

use App\Models\ArtifactChunk;
use App\Models\Team;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * pgvector-backed vector index (RUB-316, 2026-09-29 architecture decision —
 * replaces the Cloudflare Vectorize design in favor of the Postgres
 * already operated for everything else). One `artifact_chunks` table,
 * scoped to `team_id` on every query — the isolation boundary is the
 * `WHERE team_id = ?` clause every method here applies before touching a
 * row, same posture `VectorIndexContract` requires.
 *
 * Deliberately no ANN index (HNSW): at the design corpus size (10k
 * vectors/org), exact brute-force cosine search via `orderByVectorDistance`
 * is fast enough and strictly more accurate than an approximate index —
 * HNSW only pays for itself at much larger per-org corpora.
 */
final class PgVectorIndex implements VectorIndexContract
{
    public function upsertChunks(string $orgId, string $artifactId, array $chunks): void
    {
        $team = Team::query()->where('slug', $orgId)->firstOrFail();
        foreach ($chunks as $chunk) {
            if ($chunk->orgId !== $orgId || $chunk->artifactId !== $artifactId || count($chunk->vector) !== RealEmbeddings::DIMENSIONS) {
                throw new InvalidArgumentException('Chunk tenant, artifact or embedding dimensions do not match the index.');
            }
        }

        DB::transaction(function () use ($team, $artifactId, $chunks): void {
            Team::query()->whereKey($team->id)->lockForUpdate()->firstOrFail();
            ArtifactChunk::query()->where('team_id', $team->id)->where('artifact_id', $artifactId)->delete();
            foreach ($chunks as $chunk) {
                ArtifactChunk::query()->create([
                    'team_id' => $team->id,
                    'artifact_id' => $artifactId,
                    'text' => $chunk->text,
                    'search_text' => implode(' ', [$chunk->text, $chunk->agent, $chunk->repoUrl, basename($chunk->repoUrl ?? ''), $chunk->commitSha]),
                    'embedding' => $chunk->vector,
                    'agent' => $chunk->agent,
                    'repo_url' => $chunk->repoUrl,
                    'commit_sha' => $chunk->commitSha,
                    'chunk_created_at' => $chunk->createdAt,
                ]);
            }
        });
    }

    public function deleteArtifactVectors(string $orgId, string $artifactId): void
    {
        $team = $this->team($orgId);
        if ($team === null) {
            return;
        }

        ArtifactChunk::query()
            ->where('team_id', $team->id)
            ->where('artifact_id', $artifactId)
            ->delete();
    }

    public function query(string $orgId, array $queryVector, int $limit): array
    {
        $team = $this->team($orgId);
        if ($team === null || $limit <= 0) {
            return [];
        }

        return ArtifactChunk::query()
            ->where('team_id', $team->id)
            ->select('*')
            ->selectVectorDistance('embedding', $queryVector, as: 'distance')
            ->orderByVectorDistance('embedding', $queryVector)
            ->limit($limit)
            ->get()
            ->map(fn (ArtifactChunk $row): VectorMatch => new VectorMatch(
                $this->toChunk($row, $orgId),
                // pgvector's `<=>` cosine operator returns distance
                // (0 = identical); VectorMatch wants similarity.
                1.0 - (float) $row->getAttribute('distance'),
            ))
            ->all();
    }

    public function queryText(string $orgId, string $query, int $limit): array
    {
        $team = $this->team($orgId);
        if ($team === null || trim($query) === '' || $limit <= 0) {
            return [];
        }

        return ArtifactChunk::query()
            ->where('team_id', $team->id)
            ->select('*')
            ->selectRaw("ts_rank(to_tsvector('simple', search_text), plainto_tsquery('simple', ?)) as lexical_score", [$query])
            ->whereFullText('search_text', $query, ['language' => 'simple'])
            ->orderByDesc('lexical_score')->orderBy('id')
            ->limit($limit)->get()
            ->map(fn (ArtifactChunk $row): VectorMatch => new VectorMatch($this->toChunk($row, $orgId), (float) $row->getAttribute('lexical_score')))
            ->all();
    }

    public function allVectorsForOrg(string $orgId): array
    {
        $team = $this->team($orgId);
        if ($team === null) {
            return [];
        }

        return ArtifactChunk::query()
            ->where('team_id', $team->id)
            ->get()
            ->map(fn (ArtifactChunk $row): VectorChunk => $this->toChunk($row, $orgId))
            ->all();
    }

    private function team(string $orgId): ?Team
    {
        return Team::query()->where('slug', $orgId)->first();
    }

    private function toChunk(ArtifactChunk $row, string $orgId): VectorChunk
    {
        return new VectorChunk(
            text: $row->text,
            vector: $row->embedding,
            artifactId: $row->artifact_id,
            orgId: $orgId,
            createdAt: $row->chunk_created_at->toIso8601String(),
            agent: $row->agent,
            repoUrl: $row->repo_url,
            commitSha: $row->commit_sha,
        );
    }
}
