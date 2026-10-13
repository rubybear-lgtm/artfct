<?php

use App\Models\ArtifactChunk;
use App\Models\Team;
use App\Services\Indexing\PgVectorIndex;
use App\Services\Indexing\VectorChunk;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    if (DB::getDriverName() !== 'pgsql') {
        test()->markTestSkipped('Run with phpunit.pgsql.xml against pgvector Postgres.');
    }
});

function pgChunk(string $org, string $artifact = 'shared-id', string $text = 'shared content', int $dimension = 0): VectorChunk
{
    $vector = array_fill(0, 1024, 0.0);
    $vector[$dimension] = 1.0;

    return new VectorChunk($text, $vector, $artifact, $org, now()->toIso8601String(), 'cursor', 'https://github.com/acme/exact-repo', 'abcdef123456');
}

test('real_vector_index_addresses_only_the_calling_org', function () {
    Team::factory()->create(['slug' => 'org-a']);
    Team::factory()->create(['slug' => 'org-b']);
    $index = app(PgVectorIndex::class);
    $index->upsertChunks('org-a', 'shared-id', [pgChunk('org-a')]);
    $index->upsertChunks('org-b', 'shared-id', [pgChunk('org-b')]);
    foreach (['org-a', 'org-b'] as $org) {
        $matches = $index->query($org, pgChunk($org)->vector, 10);
        expect($matches)->toHaveCount(1);
        expect($matches[0]->chunk->orgId)->toBe($org);
        expect($matches[0]->similarity)->toBe(1.0);
        expect($index->queryText($org, 'shared', 10))->toHaveCount(1);
        expect($index->allVectorsForOrg($org))->toHaveCount(1);
    }
    expect(fn () => $index->upsertChunks('org-a', 'shared-id', [pgChunk('org-b')]))->toThrow(InvalidArgumentException::class);
    expect($index->query('unknown-org', pgChunk('org-a')->vector, 10))->toBe([]);
    expect(ArtifactChunk::query()->count())->toBe(2);
})->group('pgsql');

test('artifact candidate budgets deduplicate before limiting and scope both retrieval lists', function () {
    Team::factory()->create(['slug' => 'org-a']);
    Team::factory()->create(['slug' => 'org-b']);
    $index = app(PgVectorIndex::class);
    $index->upsertChunks('org-a', 'large', array_fill(0, 60, pgChunk('org-a', 'large', 'shared shared content')));
    $index->upsertChunks('org-a', 'answer', [pgChunk('org-a', 'answer', dimension: 1)]);
    $index->upsertChunks('org-b', 'answer', [pgChunk('org-b', 'answer')]);
    foreach (['queryArtifacts', 'queryTextArtifacts'] as $method) {
        $query = $method === 'queryArtifacts' ? pgChunk('org-a')->vector : 'shared';
        $matches = $index->$method('org-a', $query, 2, ['large', 'answer']);
        expect(array_map(fn ($match) => $match->chunk->artifactId, $matches))->toBe(['large', 'answer'])
            ->and(array_unique(array_map(fn ($match) => $match->chunk->orgId, $matches)))->toBe(['org-a']);
        expect($index->$method('org-a', $query, 1, ['answer'])[0]->chunk->artifactId)->toBe('answer');
        expect($index->$method('org-a', $query, 2, []))->toBe([]);
    }
})->group('pgsql');

test('artifact candidate queries accept more authorized ids than a statement can bind', function () {
    Team::factory()->create(['slug' => 'org-a']);
    $index = app(PgVectorIndex::class);
    $index->upsertChunks('org-a', 'answer', [pgChunk('org-a', 'answer', 'shared content')]);
    $ids = [...array_map(fn (int $number): string => 'other-'.$number, range(1, 70000)), 'answer'];
    foreach (['queryArtifacts' => pgChunk('org-a')->vector, 'queryTextArtifacts' => 'shared'] as $method => $query) {
        $matches = $index->$method('org-a', $query, 5, $ids);
        expect(array_map(fn ($match) => $match->chunk->artifactId, $matches))->toBe(['answer']);
    }
})->group('pgsql');

test('real_vector_index_delete_removes_all_chunks_of_artifact', function () {
    Team::factory()->create(['slug' => 'org-a']);
    Team::factory()->create(['slug' => 'org-b']);
    $index = app(PgVectorIndex::class);
    $index->upsertChunks('org-a', 'shared-id', [pgChunk('org-a'), pgChunk('org-a', text: 'second chunk')]);
    $index->upsertChunks('org-a', 'retained', [pgChunk('org-a', 'retained')]);
    $index->upsertChunks('org-b', 'shared-id', [pgChunk('org-b')]);
    $index->deleteArtifactVectors('org-a', 'shared-id');
    expect(array_map(fn ($chunk) => $chunk->artifactId, $index->allVectorsForOrg('org-a')))->toBe(['retained']);
    expect($index->allVectorsForOrg('org-b'))->toHaveCount(1);
    expect($index->queryText('org-a', 'second', 10))->toBe([]);
})->group('pgsql');

test('pgvector_replacement_rolls_back_on_invalid_vector', function () {
    Team::factory()->create(['slug' => 'org-a']);
    $index = app(PgVectorIndex::class);
    $original = pgChunk('org-a');
    $index->upsertChunks('org-a', 'shared-id', [$original]);
    $invalid = new VectorChunk('invalid', array_fill(0, 1024, 'invalid'), 'shared-id', 'org-a', now()->toIso8601String(), null, null, null);
    expect(fn () => $index->upsertChunks('org-a', 'shared-id', [$original, $invalid]))->toThrow(QueryException::class);
    expect($index->allVectorsForOrg('org-a'))->toHaveCount(1);
    expect($index->allVectorsForOrg('org-a')[0]->text)->toBe('shared content');
})->group('pgsql');

test('postgres_lexical_search_matches_identifiers_and_ranks_relevance', function () {
    Team::factory()->create(['slug' => 'org-a']);
    $index = app(PgVectorIndex::class);
    $index->upsertChunks('org-a', 'first', [pgChunk('org-a', 'first', 'revenue report', 0)]);
    $index->upsertChunks('org-a', 'second', [pgChunk('org-a', 'second', 'revenue revenue revenue report', 1)]);
    expect($index->query('org-a', pgChunk('org-a')->vector, 2)[0]->chunk->artifactId)->toBe('first');
    expect($index->queryText('org-a', 'revenue', 2)[0]->chunk->artifactId)->toBe('second');
    expect($index->queryText('org-a', 'abcdef123456', 10))->toHaveCount(2);
    expect($index->queryText('org-a', 'exact-repo', 10))->toHaveCount(2);
})->group('pgsql');
