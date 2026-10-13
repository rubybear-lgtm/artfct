<?php

use App\Mcp\Tools\ReadArtifactTool;
use App\Models\ArtifactIndexEntry;
use App\Models\Team;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;

/*
 * RUB-443 item 1: `read_artifact` returns the indexed, extracted text of one
 * artifact in bounded pages, marked untrusted. Authorization is the Worker's
 * (called with the caller's own token), so an artifact the caller cannot see
 * reads as not found before any text is touched.
 */

const READ_ARTIFACT_ID = 'abc123def4567';

/** Call `read_artifact` through the hosted MCP server. */
function callReadArtifact(string $token, array $arguments): TestResponse
{
    return test()->withToken($token)->postJson('/mcp', [
        'jsonrpc' => '2.0',
        'id' => 1,
        'method' => 'tools/call',
        'params' => ['name' => 'read_artifact', 'arguments' => $arguments],
    ]);
}

/** Index text for an artifact in a team and fake the Worker's metadata answer. */
function readArtifactFixture(Team $team, string $text, array $entry = [], int $workerStatus = 200): void
{
    config(['services.worker.base_url' => 'https://worker.test']);
    ArtifactIndexEntry::factory()->create(array_merge([
        'team_id' => $team->id,
        'artifact_id' => READ_ARTIFACT_ID,
        'extracted_text' => $text,
        'title' => 'Migration plan',
        'headings' => ['Overview'],
        'version' => 3,
    ], $entry));
    Http::fake(['worker.test/v1/artifacts/*' => Http::response([
        'id' => READ_ARTIFACT_ID,
        'title' => 'Migration plan',
        'version' => 3,
        'provenance' => ['agent' => 'claude-code', 'repo_url' => null, 'commit_sha' => null],
    ], $workerStatus)]);
}

test('read_artifact returns untrusted-marked text with a locator', function () {
    $team = Team::factory()->create(['slug' => 'rub-443-read']);
    $token = remoteMcpToken($team);
    readArtifactFixture($team, 'The cutover happens on Friday.');

    $response = callReadArtifact($token, ['id' => READ_ARTIFACT_ID])
        ->assertOk()
        ->assertJsonPath('result.isError', false);

    $content = $response->json('result.structuredContent');
    expect($content['content']['untrusted'])->toBeTrue()
        ->and($content['content']['text'])->toBe('The cutover happens on Friday.')
        ->and($content['content']['notice'])->not->toBeEmpty()
        ->and($content['version'])->toBe(3)
        ->and($content['title'])->toBe('Migration plan')
        ->and($content['locator'])->toBe(['offset' => 0, 'end_offset' => 30, 'total_characters' => 30])
        ->and($content['next_offset'])->toBeNull();
});

test('read_artifact pages with a next offset and never exceeds the per-call cap', function () {
    $team = Team::factory()->create(['slug' => 'rub-443-paging']);
    $token = remoteMcpToken($team);
    readArtifactFixture($team, str_repeat('a', 20000));

    $first = callReadArtifact($token, ['id' => READ_ARTIFACT_ID, 'limit' => 8000])
        ->assertOk()->json('result.structuredContent');

    expect(mb_strlen($first['content']['text']))->toBe(ReadArtifactTool::MAX_LIMIT)
        ->and($first['next_offset'])->toBe(8000);

    $last = callReadArtifact($token, ['id' => READ_ARTIFACT_ID, 'offset' => 16000, 'limit' => 8000])
        ->assertOk()->json('result.structuredContent');

    expect(mb_strlen($last['content']['text']))->toBe(4000)
        ->and($last['next_offset'])->toBeNull();

    callReadArtifact($token, ['id' => READ_ARTIFACT_ID, 'limit' => 8001])
        ->assertOk()
        ->assertJsonPath('result.isError', true);
});

test('read_artifact defaults to a bounded page', function () {
    $team = Team::factory()->create(['slug' => 'rub-443-default']);
    $token = remoteMcpToken($team);
    readArtifactFixture($team, str_repeat('b', 10000));

    $page = callReadArtifact($token, ['id' => READ_ARTIFACT_ID])->assertOk()->json('result.structuredContent');

    expect(mb_strlen($page['content']['text']))->toBe(ReadArtifactTool::DEFAULT_LIMIT)
        ->and($page['next_offset'])->toBe(ReadArtifactTool::DEFAULT_LIMIT);
});

test('read_artifact reads an artifact the Worker hides as not found without touching text', function () {
    $team = Team::factory()->create(['slug' => 'rub-443-hidden']);
    $token = remoteMcpToken($team);
    readArtifactFixture($team, 'secret text', workerStatus: 404);

    $response = callReadArtifact($token, ['id' => READ_ARTIFACT_ID])
        ->assertOk()
        ->assertJsonPath('result.isError', true);

    expect($response->json('result.content.0._meta.artfct.errorCode'))->toBe('artifact_not_found')
        ->and($response->getContent())->not->toContain('secret text');
});

test('read_artifact never serves another workspace\'s indexed text', function () {
    $team = Team::factory()->create(['slug' => 'rub-443-own']);
    $other = Team::factory()->create(['slug' => 'rub-443-other']);
    $token = remoteMcpToken($team);
    readArtifactFixture($other, 'other workspace text');

    $response = callReadArtifact($token, ['id' => READ_ARTIFACT_ID])->assertOk();

    expect($response->json('result.isError'))->toBeTrue()
        ->and($response->json('result.content.0._meta.artfct.errorCode'))->toBe('content_not_indexed')
        ->and($response->getContent())->not->toContain('other workspace text');
});

test('read_artifact reports a version that was not indexed', function () {
    $team = Team::factory()->create(['slug' => 'rub-443-version']);
    $token = remoteMcpToken($team);
    readArtifactFixture($team, 'text');

    $response = callReadArtifact($token, ['id' => READ_ARTIFACT_ID, 'version' => 2])->assertOk();

    expect($response->json('result.content.0._meta.artfct.errorCode'))->toBe('version_not_indexed');
});

test('read_artifact forwards the caller token to the Worker', function () {
    $team = Team::factory()->create(['slug' => 'rub-443-token']);
    $token = remoteMcpToken($team);
    readArtifactFixture($team, 'text');

    callReadArtifact($token, ['id' => READ_ARTIFACT_ID])->assertOk();

    Http::assertSent(fn (Request $request): bool => $request->hasHeader('Authorization', 'Bearer '.$token)
        && str_ends_with($request->url(), '/v1/artifacts/'.READ_ARTIFACT_ID));
});
