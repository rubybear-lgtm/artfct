<?php

use App\Contracts\ArtifactDirectory;
use App\Enums\UsageEventType;
use App\Models\ArtifactChunk;
use App\Models\ArtifactUsageEvent;
use App\Models\Collection;
use App\Models\Team;
use App\Services\Collections\CollectionService;
use App\Services\Indexing\Chunker;
use App\Services\Indexing\PgVectorIndex;
use App\Services\Indexing\RerankerContract;
use App\Services\Indexing\VectorChunk;
use App\Services\Indexing\VectorIndexContract;
use App\Services\Indexing\VectorMatch;
use App\Services\Search\SearchPassageSelection;
use App\Services\Search\SearchRanking;
use App\Services\Search\SearchService;
use App\Services\Search\SearchSnippet;

function qualityMatch(string $id, string $text, float $score, string $org = 'zz-quality'): VectorMatch
{
    return new VectorMatch(new VectorChunk($text, [1.0], $id, $org, now()->toIso8601String(), null, null, null), $score);
}

test('large documents cannot exhaust the distinct artifact retrieval budget or a collection scope', function () {
    $index = app(VectorIndexContract::class);
    $index->upsertChunks('zz-quality', 'large', array_fill(0, 60, qualityMatch('large', 'shared content', 1.0)->chunk));
    $index->upsertChunks('zz-quality', 'answer', [qualityMatch('answer', 'shared answer', 0.5)->chunk]);
    $index->upsertChunks('other-org', 'answer', [qualityMatch('answer', 'shared private', 1.0, 'other-org')->chunk]);
    foreach (['queryArtifacts', 'queryTextArtifacts'] as $method) {
        $query = $method === 'queryArtifacts' ? [1.0] : 'shared';
        $matches = $index->$method('zz-quality', $query, 2, ['large', 'answer']);
        expect(array_map(fn ($match) => $match->chunk->artifactId, $matches))->toBe(['large', 'answer']);
        expect($index->$method('zz-quality', $query, 1, ['answer'])[0]->chunk->artifactId)->toBe('answer');
        expect($index->$method('zz-quality', $query, 5, []))->toBe([]);
        expect(array_map(fn ($match) => $match->chunk->artifactId, $index->$method('zz-quality', $query, 2, null)))->toBe(['large', 'answer']);
    }
});

test('passage selection recovers the introduction without admitting foreign chunks and caps contexts', function () {
    $tail = qualityMatch('a', 'Revoked credential fallback.', 0.9);
    $intro = qualityMatch('a', 'Readiness must exercise an authorized operation.', 0.2);
    $other = qualityMatch('a', 'Rotation order.', 0.1);
    $foreign = qualityMatch('a', 'Private foreign evidence.', 1.0, 'other-org');
    $passages = SearchPassageSelection::expand([$tail], [$intro->chunk, $other->chunk, $foreign->chunk], [$tail, qualityMatch('a', 'Fourth passage.', 0.1)]);
    expect($passages)->toHaveCount(3)->and(array_map(fn ($match) => $match->chunk->text, $passages))->toBe([$tail->chunk->text, $intro->chunk->text, $other->chunk->text]);
    $reranked = [new VectorMatch($intro->chunk, 0.85), new VectorMatch($tail->chunk, 0.0001), new VectorMatch($other->chunk, 0.2)];
    $best = SearchPassageSelection::bestArtifacts($reranked, 0.01);
    expect($best)->toHaveCount(1)->and($best[0]->chunk->text)->toBe($intro->chunk->text)->and($best[0]->similarity)->toBe(0.85);
});

test('no match gating removes low relevance even when the artifact is canonical and heavily used', function () {
    $team = Team::factory()->create(['slug' => 'zz-quality']);
    app(ArtifactDirectory::class)->seedArtifact(['id' => 'greenhouse', 'org_id' => $team->slug, 'user_id' => 1, 'title' => 'Recruiting connector', 'created_at' => now()->toIso8601String(), 'revoked_at' => null, 'provenance' => []]);
    $collection = Collection::factory()->create(['team_id' => $team->id, 'canonical' => true]);
    app(CollectionService::class)->addArtifact($collection, 'greenhouse');
    ArtifactUsageEvent::factory()->count(50)->create(['team_id' => $team->id, 'artifact_id' => 'greenhouse', 'event_type' => UsageEventType::SlackShared]);
    $index = app(VectorIndexContract::class);
    $index->upsertChunks($team->slug, 'greenhouse', [qualityMatch('greenhouse', 'Greenhouse imports applicants.', 1.0)->chunk]);
    $reranker = Mockery::mock(RerankerContract::class);
    $reranker->shouldReceive('rerank')->once()->andReturn([qualityMatch('greenhouse', 'Greenhouse imports applicants.', 0.00835)]);
    app()->instance(RerankerContract::class, $reranker);
    config(['indexing.minimum_search_relevance' => 0.01]);
    expect(app(SearchService::class)->search($team, 'How to grow tomatoes in a greenhouse', [], 5, 'tester'))->toBe([]);
});

test('passage selection joins verified overlapping introductions so a boundary cannot sever the answer', function () {
    $answer = 'Queue visibility timeouts exceed the maximum job duration to prevent duplicate work.';
    $text = str_repeat('Earlier operational record. ', 26).$answer;
    $chunks = Chunker::chunk($text, 750, 100);
    $intro = array_map(fn (string $text) => qualityMatch('a', $text, 0.1)->chunk, $chunks);
    $contexts = SearchPassageSelection::expand([qualityMatch('a', $chunks[0], 0.1)], $intro, [], 'Why must queue visibility outlast job execution?');
    expect(array_any($contexts, fn ($match) => str_contains($match->chunk->text, $answer)))->toBeTrue();
});

test('passage selection always reranks the unmodified representative chunk and does not spend slots on its duplicate', function () {
    $answer = 'Queue visibility timeouts exceed the maximum job duration to prevent duplicate work.';
    $chunks = Chunker::chunk(str_repeat('Earlier operational record. ', 26).$answer, 750, 100);
    $intro = array_map(fn (string $text) => qualityMatch('a', $text, 0.1)->chunk, $chunks);
    $contexts = SearchPassageSelection::expand([qualityMatch('a', $chunks[0], 0.1)], $intro, [], 'Why must queue visibility outlast job execution?', ['a' => 'Queue notes']);
    $texts = array_map(fn ($match) => $match->chunk->text, $contexts);
    expect($texts[0])->toBe($chunks[0])
        ->and($texts)->toHaveCount(3)
        ->and(count(array_filter($texts, fn (string $text) => str_contains($text, $answer))))->toBeGreaterThanOrEqual(1);
});

test('display excerpts open on the answer sentence and title queries open on the introduction', function () {
    $title = 'Vendor onboarding control matrix';
    $text = 'Vendor onboarding requires a signed agreement before records move. '.str_repeat('Unrelated operating record text. ', 8)
        .'A missing stage identifier pauses that opening rather than assigning a default. '.str_repeat('More unrelated closing text. ', 8);
    $answer = SearchSnippet::make($text, 'What happens when a stage identifier is missing?', $title);
    $intro = SearchSnippet::make($text, $title, $title);
    expect($answer)->toStartWith('… A missing stage identifier pauses')
        ->and($intro)->toStartWith('Vendor onboarding requires a signed agreement')
        ->and(SearchSnippet::make($text, 'What happens when a stage identifier is missing?', $title, 200, displayExcerpt: false))->not->toBe('');
});

test('relevance bands prevent popularity and canonical boosts displacing direct matches', function () {
    $exact = qualityMatch('exact', 'One design language.', 0.8735);
    $noise = qualityMatch('noise', 'Unrelated software.', 0.00657);
    $ranked = SearchRanking::rank([$noise, $exact], 'design', ['noise' => 1000], ['noise' => true]);
    expect($ranked[0]->chunk->artifactId)->toBe('exact')->and($ranked[0]->similarity)->toBe(0.8735);
    $close = qualityMatch('close', 'Approved design.', 0.879);
    expect(SearchRanking::rank([$exact, $close], 'design', [], ['exact' => true])[0]->chunk->artifactId)->toBe('exact');
});

test('snippets show the relevant body and preserve complete words with escaped whitespace normalized', function () {
    $title = 'How we stopped our AI tools from contradicting each other';
    $text = $title.' Home Reports Contact Subscribe '.$title.' Decision and rationale Conflicting answers came from stale local copies of the launch brief. The team published one approved brief with a visible revision history. '.str_repeat('Unrelated footer wording. ', 20);
    $snippet = SearchSnippet::make($text, 'What caused the launch deadline answers to conflict?', $title);
    expect($snippet)->toContain('Conflicting answers came from stale local copies of the launch brief.')
        ->not->toContain('Home Reports Contact Subscribe', $title)
        ->and(mb_strlen($snippet))->toBeLessThanOrEqual(201);
    expect(SearchSnippet::make('Hello\\nworld\\tready', 'ready', null))->toBe('Hello world ready');
    expect(SearchSnippet::make(str_repeat('completeword ', 25), 'completeword', null))->not->toMatch('/completewor…$/');
});

test('first chunk lookup is bounded by artifact and tenant on the real database implementation', function () {
    $team = Team::factory()->create(['slug' => 'zz-quality']);
    $foreign = Team::factory()->create(['slug' => 'other-org']);
    foreach ([$team, $foreign] as $owner) {
        foreach (['a', 'b'] as $id) {
            foreach (range(1, 4) as $position) {
                ArtifactChunk::factory()->create(['team_id' => $owner->id, 'artifact_id' => $id, 'text' => 'Passage '.$position, 'embedding' => array_fill(0, 1024, 0.5)]);
            }
        }
    }
    $chunks = app(PgVectorIndex::class)->firstChunks($team->slug, ['a'], 2);
    expect(array_map(fn ($chunk) => $chunk->text, $chunks))->toBe(['Passage 1', 'Passage 2'])
        ->and(array_unique(array_map(fn ($chunk) => $chunk->orgId, $chunks)))->toBe([$team->slug]);
    expect(app(PgVectorIndex::class)->firstChunks('missing-org', ['a'], 2))->toBe([]);
});

test('search returns the selected passage raw relevance rather than the combined ranking score', function () {
    $team = Team::factory()->create(['slug' => 'zz-quality']);
    app(ArtifactDirectory::class)->seedArtifact(['id' => 'a', 'org_id' => $team->slug, 'user_id' => 1, 'title' => 'Credential readiness', 'created_at' => now()->toIso8601String(), 'revoked_at' => null, 'provenance' => []]);
    $intro = qualityMatch('a', 'Readiness must exercise an authorized operation.', 0.85);
    app(VectorIndexContract::class)->upsertChunks($team->slug, 'a', [$intro->chunk]);
    $reranker = Mockery::mock(RerankerContract::class);
    $reranker->shouldReceive('rerank')->once()->andReturn([$intro]);
    app()->instance(RerankerContract::class, $reranker);
    $result = app(SearchService::class)->search($team, 'readiness', [], 5, 'tester')[0];
    expect($result->score)->toBe(0.85)->and($result->snippet)->toContain('authorized operation');
});

test('model weak confidence requires corroborating query terms rather than one ambiguous noun', function () {
    $gardening = qualityMatch('recruiting', 'Greenhouse recruiting connector rollout. The connector imports applicant stages.', 0.00835);
    $navigation = qualityMatch('navigation', 'Navigation review. Active link state has a text cue in addition to color.', 0.0022);
    $migration = qualityMatch('migration', 'Customer migration plan. Compare the first and last account in the range.', 0.028);
    expect(SearchPassageSelection::bestArtifacts([$gardening], 0.05, 'How to grow tomatoes in a greenhouse'))->toBe([]);
    expect(SearchPassageSelection::bestArtifacts([$migration], 0.05, 'How can I identify migrating cranes?'))->toBe([]);
    expect(SearchPassageSelection::bestArtifacts([$navigation], 0.05, 'How is active navigation conveyed beyond its color?')[0]->chunk->artifactId)->toBe('navigation');
});
