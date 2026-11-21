<?php

use App\Enums\FeatureReleaseState;
use App\Enums\TeamRole;
use App\Models\FeatureRelease;
use App\Models\Team;
use App\Services\Auth\OrgJwtService;
use App\Services\FeatureFlags;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;

/*
 * RUB-559 / spec 29: the `post/details` document (kind, owner, status,
 * replaced_by) is validated by the db_* MCP tools before any Worker call.
 * The Worker's own rules (role, scope, edit access, versions) are pinned
 * by McpDatabaseToolsTest; these tests pin the post field rules and the
 * Laravel side of the Worker's answers for this collection.
 */

const POST_ARTIFACT_ID = 'abc123def456x';

function postReleaseArtifactDb(Team ...$teams): void
{
    FeatureRelease::factory()->create([
        'key' => 'artifact-db',
        'state' => FeatureReleaseState::SelectedTeams,
        'selected_team_ids' => array_map(fn (Team $team): int => $team->id, $teams),
    ]);
    app(FeatureFlags::class)->flush();
}

function postCallMcpTool(string $token, string $name, array $arguments): TestResponse
{
    return test()->withToken($token)->postJson('/mcp', [
        'jsonrpc' => '2.0',
        'id' => 1,
        'method' => 'tools/call',
        'params' => ['name' => $name, 'arguments' => $arguments],
    ]);
}

function postValidDetails(array $overrides = []): array
{
    return array_merge([
        'kind' => 'decision',
        'owner' => 'Ruby',
        'status' => 'draft',
        'replaced_by' => null,
    ], $overrides);
}

beforeEach(function () {
    config(['services.worker.base_url' => 'https://worker.test']);
});

test('post details can be created with all four fields', function () {
    $team = Team::factory()->create(['slug' => 'post-create']);
    postReleaseArtifactDb($team);
    Http::fake(['worker.test/*' => Http::response(['id' => 'details', 'version' => 1])]);

    postCallMcpTool(remoteMcpToken($team), 'db_set', [
        'id' => POST_ARTIFACT_ID, 'collection' => 'post', 'doc_id' => 'details',
        'data' => postValidDetails(),
    ])->assertOk()->assertJsonPath('result.isError', false);

    Http::assertSent(fn (Request $request): bool => $request->method() === 'PUT'
        && str_ends_with($request->url(), '/db/post/details')
        && $request->data() === postValidDetails());
});

test('post details can move to superseded with a replaced_by id', function () {
    $team = Team::factory()->create(['slug' => 'post-supersede']);
    postReleaseArtifactDb($team);
    Http::fake(['worker.test/*' => Http::response(['id' => 'details', 'version' => 2])]);

    postCallMcpTool(remoteMcpToken($team), 'db_update', [
        'id' => POST_ARTIFACT_ID, 'collection' => 'post', 'doc_id' => 'details',
        'data' => ['status' => 'superseded', 'replaced_by' => 'zzz999yyy888x'],
    ])->assertOk()->assertJsonPath('result.isError', false);

    Http::assertSent(fn (Request $request): bool => $request->method() === 'PATCH'
        && str_ends_with($request->url(), '/db/post/details')
        && $request->data() === ['status' => 'superseded', 'replaced_by' => 'zzz999yyy888x']);
});

test('post details refuses an unknown kind before anything is sent', function () {
    $team = Team::factory()->create(['slug' => 'post-kind']);
    postReleaseArtifactDb($team);
    Http::fake();

    postCallMcpTool(remoteMcpToken($team), 'db_update', [
        'id' => POST_ARTIFACT_ID, 'collection' => 'post', 'doc_id' => 'details',
        'data' => ['kind' => 'memo'],
    ])->assertOk()
        ->assertJsonPath('result.isError', true)
        ->assertJsonPath('result.structuredContent.code', 'invalid_request');

    Http::assertNothingSent();
});

test('post details refuses an unknown status before anything is sent', function () {
    $team = Team::factory()->create(['slug' => 'post-status']);
    postReleaseArtifactDb($team);
    Http::fake();

    postCallMcpTool(remoteMcpToken($team), 'db_update', [
        'id' => POST_ARTIFACT_ID, 'collection' => 'post', 'doc_id' => 'details',
        'data' => ['status' => 'archived'],
    ])->assertOk()
        ->assertJsonPath('result.isError', true)
        ->assertJsonPath('result.structuredContent.code', 'invalid_request');

    Http::assertNothingSent();
});

test('post details refuses a replaced_by that is not null or a permanent artifact id', function () {
    $team = Team::factory()->create(['slug' => 'post-replaced']);
    postReleaseArtifactDb($team);
    Http::fake();

    postCallMcpTool(remoteMcpToken($team), 'db_update', [
        'id' => POST_ARTIFACT_ID, 'collection' => 'post', 'doc_id' => 'details',
        'data' => ['replaced_by' => ['id' => 'zzz999yyy888x']],
    ])->assertOk()
        ->assertJsonPath('result.isError', true)
        ->assertJsonPath('result.structuredContent.code', 'invalid_request');

    Http::assertNothingSent();
});

test('db_set on post details must carry all four fields', function () {
    $team = Team::factory()->create(['slug' => 'post-partial']);
    postReleaseArtifactDb($team);
    Http::fake();

    postCallMcpTool(remoteMcpToken($team), 'db_set', [
        'id' => POST_ARTIFACT_ID, 'collection' => 'post', 'doc_id' => 'details',
        'data' => ['kind' => 'decision'],
    ])->assertOk()
        ->assertJsonPath('result.isError', true)
        ->assertJsonPath('result.structuredContent.code', 'invalid_request');

    Http::assertNothingSent();
});

test('db_batch refuses an invalid post details write and applies none of the batch', function () {
    $team = Team::factory()->create(['slug' => 'post-batch']);
    postReleaseArtifactDb($team);
    Http::fake();

    postCallMcpTool(remoteMcpToken($team), 'db_batch', [
        'id' => POST_ARTIFACT_ID,
        'writes' => [
            ['op' => 'set', 'collection' => 'cards', 'doc_id' => 'a', 'data' => ['title' => 'ok']],
            ['op' => 'set', 'collection' => 'post', 'doc_id' => 'details', 'data' => postValidDetails(['status' => 'live'])],
        ],
    ])->assertOk()
        ->assertJsonPath('result.isError', true)
        ->assertJsonPath('result.structuredContent.code', 'invalid_request');

    Http::assertNothingSent();
});

test('a viewer write is refused with forbidden', function () {
    $team = Team::factory()->create(['slug' => 'post-viewer']);
    postReleaseArtifactDb($team);
    Http::fake(['worker.test/*' => Http::response(['error' => ['code' => 'forbidden', 'message' => 'This viewer cannot write.']], 403)]);

    configureSigning(testSigningKey());
    $viewerToken = OrgJwtService::default()->mint($team, memberOfTeam($team, TeamRole::Viewer), TeamRole::Viewer, 3600, ['artifacts:read', 'data:read', 'data:write'])['token'];

    postCallMcpTool($viewerToken, 'db_update', [
        'id' => POST_ARTIFACT_ID, 'collection' => 'post', 'doc_id' => 'details',
        'data' => ['status' => 'current'],
    ])->assertOk()
        ->assertJsonPath('result.isError', true)
        ->assertJsonPath('result.structuredContent.code', 'forbidden');

    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/v1/artifacts/'.POST_ARTIFACT_ID.'/db/')
        && $request->header('Authorization') === ['Bearer '.$viewerToken]);
});

test('a token without data:write gets insufficient_scope on post details', function () {
    $team = Team::factory()->create(['slug' => 'post-scope']);
    postReleaseArtifactDb($team);
    Http::fake();
    configureSigning(testSigningKey());
    $token = OrgJwtService::default()->mint($team, memberOfTeam($team, TeamRole::Admin), TeamRole::Admin, 3600, ['artifacts:read', 'data:read'])['token'];

    $response = postCallMcpTool($token, 'db_update', [
        'id' => POST_ARTIFACT_ID, 'collection' => 'post', 'doc_id' => 'details',
        'data' => ['status' => 'current'],
    ]);

    expect((string) $response->json('result.content.0.text'))->toStartWith('insufficient_scope:')->toContain('data:write');
    Http::assertNothingSent();
});

test('a token from another team gets not_found on post details (Worker faked, not isolation)', function () {
    $team = Team::factory()->create(['slug' => 'post-other-team']);
    $otherTeam = Team::factory()->create(['slug' => 'post-other-owner']);
    postReleaseArtifactDb($team, $otherTeam);
    Http::fake(['worker.test/*' => Http::response(['error' => ['code' => 'not_found', 'message' => 'Not found.']], 404)]);

    $tokenForOtherTeam = remoteMcpToken($otherTeam);

    postCallMcpTool($tokenForOtherTeam, 'db_update', [
        'id' => POST_ARTIFACT_ID, 'collection' => 'post', 'doc_id' => 'details',
        'data' => ['status' => 'current'],
    ])->assertOk()
        ->assertJsonPath('result.isError', true)
        ->assertJsonPath('result.structuredContent.code', 'not_found');

    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/v1/artifacts/'.POST_ARTIFACT_ID.'/db/')
        && $request->header('Authorization') === ['Bearer '.$tokenForOtherTeam]);
});

test('post details refuses a replaced_by that is not a permanent artifact id', function () {
    $team = Team::factory()->create(['slug' => 'post-replaced-id']);
    postReleaseArtifactDb($team);
    Http::fake();

    postCallMcpTool(remoteMcpToken($team), 'db_update', [
        'id' => POST_ARTIFACT_ID, 'collection' => 'post', 'doc_id' => 'details',
        'data' => ['replaced_by' => 'hello'],
    ])->assertOk()
        ->assertJsonPath('result.isError', true)
        ->assertJsonPath('result.structuredContent.code', 'invalid_request');

    Http::assertNothingSent();
});

test('post details refuses a field it does not define before anything is sent', function () {
    $team = Team::factory()->create(['slug' => 'post-unknown-field']);
    postReleaseArtifactDb($team);
    Http::fake();

    postCallMcpTool(remoteMcpToken($team), 'db_update', [
        'id' => POST_ARTIFACT_ID, 'collection' => 'post', 'doc_id' => 'details',
        'data' => ['memo' => 'not a field'],
    ])->assertOk()
        ->assertJsonPath('result.isError', true)
        ->assertJsonPath('result.structuredContent.code', 'invalid_request');

    Http::assertNothingSent();
});

test('post details refuses a non-string owner before anything is sent', function () {
    $team = Team::factory()->create(['slug' => 'post-owner-type']);
    postReleaseArtifactDb($team);
    Http::fake();

    postCallMcpTool(remoteMcpToken($team), 'db_update', [
        'id' => POST_ARTIFACT_ID, 'collection' => 'post', 'doc_id' => 'details',
        'data' => ['owner' => ['name' => 'Ana']],
    ])->assertOk()
        ->assertJsonPath('result.isError', true)
        ->assertJsonPath('result.structuredContent.code', 'invalid_request');

    Http::assertNothingSent();
});

test('db_batch refuses a post details set with an unknown field and applies none of it', function () {
    $team = Team::factory()->create(['slug' => 'post-batch-unknown']);
    postReleaseArtifactDb($team);
    Http::fake();

    postCallMcpTool(remoteMcpToken($team), 'db_batch', [
        'id' => POST_ARTIFACT_ID,
        'writes' => [
            ['op' => 'set', 'collection' => 'post', 'doc_id' => 'details', 'data' => ['memo' => 'x']],
        ],
    ])->assertOk()
        ->assertJsonPath('result.isError', true)
        ->assertJsonPath('result.structuredContent.code', 'invalid_request');

    Http::assertNothingSent();
});

test('a valid post details write passes through to the Worker', function () {
    $team = Team::factory()->create(['slug' => 'post-valid-write']);
    postReleaseArtifactDb($team);
    Http::fake(['worker.test/*' => Http::response(['data' => ['status' => 'current']], 200)]);

    postCallMcpTool(remoteMcpToken($team), 'db_update', [
        'id' => POST_ARTIFACT_ID, 'collection' => 'post', 'doc_id' => 'details',
        'data' => ['status' => 'current', 'replaced_by' => null],
    ])->assertOk()
        ->assertJsonPath('result.isError', false);

    Http::assertSentCount(1);
});

test('a db_batch with a valid post details set and a delete passes through', function () {
    $team = Team::factory()->create(['slug' => 'post-valid-batch']);
    postReleaseArtifactDb($team);
    Http::fake(['worker.test/*' => Http::response(['applied' => 2], 200)]);

    postCallMcpTool(remoteMcpToken($team), 'db_batch', [
        'id' => POST_ARTIFACT_ID,
        'writes' => [
            ['op' => 'set', 'collection' => 'post', 'doc_id' => 'details', 'data' => ['kind' => 'decision', 'owner' => 'Ana', 'status' => 'draft', 'replaced_by' => null]],
            ['op' => 'delete', 'collection' => 'post', 'doc_id' => 'old'],
        ],
    ])->assertOk()
        ->assertJsonPath('result.isError', false);

    Http::assertSentCount(1);
});

test('db_set on post details refuses a field it does not define before anything is sent', function () {
    $team = Team::factory()->create(['slug' => 'post-set-unknown']);
    postReleaseArtifactDb($team);
    Http::fake();

    postCallMcpTool(remoteMcpToken($team), 'db_set', [
        'id' => POST_ARTIFACT_ID, 'collection' => 'post', 'doc_id' => 'details',
        'data' => postValidDetails(['memo' => 'not a field']),
    ])->assertOk()
        ->assertJsonPath('result.isError', true)
        ->assertJsonPath('result.structuredContent.code', 'invalid_request');

    Http::assertNothingSent();
});

test('db_batch refuses a post details set missing a field and applies none of the batch', function () {
    $team = Team::factory()->create(['slug' => 'post-batch-missing']);
    postReleaseArtifactDb($team);
    Http::fake();

    $details = postValidDetails();
    unset($details['replaced_by']);

    postCallMcpTool(remoteMcpToken($team), 'db_batch', [
        'id' => POST_ARTIFACT_ID,
        'writes' => [
            ['op' => 'set', 'collection' => 'cards', 'doc_id' => 'a', 'data' => ['title' => 'ok']],
            ['op' => 'set', 'collection' => 'post', 'doc_id' => 'details', 'data' => $details],
        ],
    ])->assertOk()
        ->assertJsonPath('result.isError', true)
        ->assertJsonPath('result.structuredContent.code', 'invalid_request');

    Http::assertNothingSent();
});

test('a name ending in a newline is refused before anything is sent', function () {
    $team = Team::factory()->create(['slug' => 'post-newline-name']);
    postReleaseArtifactDb($team);
    Http::fake();

    postCallMcpTool(remoteMcpToken($team), 'db_update', [
        'id' => POST_ARTIFACT_ID, 'collection' => 'post', 'doc_id' => "details\n",
        'data' => ['status' => 'current'],
    ])->assertOk()
        ->assertJsonPath('result.isError', true)
        ->assertJsonPath('result.content.0.text', 'The doc id field format is invalid.');

    Http::assertNothingSent();
});
