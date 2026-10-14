<?php

use App\Enums\TeamRole;
use App\Models\ArtifactComment;
use App\Models\Team;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;

/*
 * RUB-443 item 5: threaded comments on an artifact through MCP. Visibility is
 * the Worker's (called with the caller's token); comment text is returned
 * marked untrusted; writing needs its own scope.
 */

const COMMENTED_ARTIFACT_ID = 'cmt0000000a1b';

/** One call through the hosted MCP server. */
function callCommentTool(string $token, string $tool, array $arguments): TestResponse
{
    return test()->withToken($token)->postJson('/mcp', [
        'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call',
        'params' => ['name' => $tool, 'arguments' => $arguments],
    ]);
}

/** Fake the Worker's artifact read, which is how visibility is decided. */
function fakeCommentedArtifact(int $status = 200, array $body = []): void
{
    config(['services.worker.base_url' => 'https://worker.test']);
    Http::fake(['worker.test/v1/artifacts/*' => Http::response($body + ['id' => COMMENTED_ARTIFACT_ID, 'version' => 1], $status)]);
}

test('a member adds a comment and a reply, and lists them as one untrusted thread', function () {
    $team = Team::factory()->create(['slug' => 'rub-443-comments']);
    $token = remoteMcpToken($team);
    fakeCommentedArtifact();

    $root = callCommentTool($token, 'add_artifact_comment', ['id' => COMMENTED_ARTIFACT_ID, 'body' => 'Does this include refunds?'])
        ->assertOk()->json('result.structuredContent');
    $reply = callCommentTool($token, 'add_artifact_comment', ['id' => COMMENTED_ARTIFACT_ID, 'body' => 'Yes.', 'parent_id' => $root['id']])
        ->assertOk()->json('result.structuredContent');

    expect($reply['parent_id'])->toBe($root['id']);

    $list = callCommentTool($token, 'list_artifact_comments', ['id' => COMMENTED_ARTIFACT_ID])->assertOk()->json('result.structuredContent');

    expect($list['untrusted'])->toBeTrue()
        ->and($list['notice'])->not->toBeEmpty()
        ->and(array_column($list['comments'], 'body'))->toBe(['Does this include refunds?', 'Yes.']);
});

test('a reply to a reply joins the root thread', function () {
    $team = Team::factory()->create(['slug' => 'rub-443-flat']);
    $token = remoteMcpToken($team);
    fakeCommentedArtifact();
    $root = ArtifactComment::factory()->create(['team_id' => $team->id, 'artifact_id' => COMMENTED_ARTIFACT_ID]);
    $child = ArtifactComment::factory()->create(['team_id' => $team->id, 'artifact_id' => COMMENTED_ARTIFACT_ID, 'parent_id' => $root->id]);

    $reply = callCommentTool($token, 'add_artifact_comment', ['id' => COMMENTED_ARTIFACT_ID, 'body' => 'later', 'parent_id' => $child->id])
        ->assertOk()->json('result.structuredContent');

    expect($reply['parent_id'])->toBe($root->id);
});

test('resolving a thread hides it by default and reopening brings it back', function () {
    $team = Team::factory()->create(['slug' => 'rub-443-resolve']);
    $token = remoteMcpToken($team);
    fakeCommentedArtifact();
    $root = ArtifactComment::factory()->create(['team_id' => $team->id, 'artifact_id' => COMMENTED_ARTIFACT_ID]);

    callCommentTool($token, 'resolve_artifact_comment', ['id' => COMMENTED_ARTIFACT_ID, 'comment_id' => $root->id])->assertOk();

    $open = callCommentTool($token, 'list_artifact_comments', ['id' => COMMENTED_ARTIFACT_ID])->json('result.structuredContent.comments');
    $all = callCommentTool($token, 'list_artifact_comments', ['id' => COMMENTED_ARTIFACT_ID, 'include_resolved' => true])->json('result.structuredContent.comments');
    expect($open)->toBe([])
        ->and($all[0]['resolved'])->toBeTrue();

    callCommentTool($token, 'resolve_artifact_comment', ['id' => COMMENTED_ARTIFACT_ID, 'comment_id' => $root->id, 'resolved' => false])->assertOk();
    expect(callCommentTool($token, 'list_artifact_comments', ['id' => COMMENTED_ARTIFACT_ID])->json('result.structuredContent.comments'))->toHaveCount(1);
});

test('an artifact the caller cannot see reads as not found for every comment tool', function (string $tool, array $arguments) {
    $team = Team::factory()->create(['slug' => 'rub-443-comments-hidden-'.$tool]);
    $token = remoteMcpToken($team);
    fakeCommentedArtifact(404);
    ArtifactComment::factory()->create(['team_id' => $team->id, 'artifact_id' => COMMENTED_ARTIFACT_ID, 'body' => 'secret thread']);

    $response = callCommentTool($token, $tool, ['id' => COMMENTED_ARTIFACT_ID] + $arguments)->assertOk();

    expect($response->json('result.isError'))->toBeTrue()
        ->and($response->json('result.content.0._meta.artfct.errorCode'))->toBe('artifact_not_found')
        ->and($response->getContent())->not->toContain('secret thread');
})->with([
    'list' => ['list_artifact_comments', []],
    'add' => ['add_artifact_comment', ['body' => 'hi']],
    'resolve' => ['resolve_artifact_comment', ['comment_id' => 1]],
]);

test('comments never cross workspaces', function () {
    $team = Team::factory()->create(['slug' => 'rub-443-comments-a']);
    $other = Team::factory()->create(['slug' => 'rub-443-comments-b']);
    $token = remoteMcpToken($team);
    fakeCommentedArtifact();
    ArtifactComment::factory()->create(['team_id' => $other->id, 'artifact_id' => COMMENTED_ARTIFACT_ID, 'body' => 'other workspace']);

    $list = callCommentTool($token, 'list_artifact_comments', ['id' => COMMENTED_ARTIFACT_ID])->assertOk()->json('result.structuredContent');

    expect($list['comments'])->toBe([]);
});

test('commenting needs the comments:write scope and a viewer role does not get it', function () {
    $team = Team::factory()->create(['slug' => 'rub-443-comments-viewer']);
    $token = remoteMcpToken($team, TeamRole::Viewer);
    fakeCommentedArtifact();

    $response = callCommentTool($token, 'add_artifact_comment', ['id' => COMMENTED_ARTIFACT_ID, 'body' => 'hi']);

    expect(json_encode($response->json()))->toContain('comments:write');
    expect(ArtifactComment::query()->count())->toBe(0);
});

test('comment length is bounded and the Worker is called with the caller token', function () {
    $team = Team::factory()->create(['slug' => 'rub-443-comments-bounds']);
    $token = remoteMcpToken($team);
    fakeCommentedArtifact();

    callCommentTool($token, 'add_artifact_comment', ['id' => COMMENTED_ARTIFACT_ID, 'body' => str_repeat('a', 4001)])
        ->assertOk()
        ->assertJsonPath('result.isError', true);

    callCommentTool($token, 'list_artifact_comments', ['id' => COMMENTED_ARTIFACT_ID])->assertOk();
    Http::assertSent(fn (Request $request): bool => $request->hasHeader('Authorization', 'Bearer '.$token));
});

test('a long thread lists the newest comments in order and says it was cut', function () {
    $team = Team::factory()->create(['slug' => 'rub-443-comments-long']);
    $token = remoteMcpToken($team);
    fakeCommentedArtifact();
    foreach (range(1, 201) as $n) {
        ArtifactComment::factory()->create(['team_id' => $team->id, 'artifact_id' => COMMENTED_ARTIFACT_ID, 'body' => "comment {$n}", 'created_at' => now()->subSeconds(300 - $n)]);
    }

    $list = callCommentTool($token, 'list_artifact_comments', ['id' => COMMENTED_ARTIFACT_ID])->assertOk()->json('result.structuredContent');

    expect($list['truncated'])->toBeTrue()
        ->and($list['comments'])->toHaveCount(200)
        ->and($list['comments'][0]['body'])->toBe('comment 2')
        ->and($list['comments'][199]['body'])->toBe('comment 201');
});
