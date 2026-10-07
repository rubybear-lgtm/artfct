<?php

use App\Models\Team;
use App\Services\Auth\OrgJwtService;
use App\Services\Billing\FakeUsage;
use App\Services\Billing\UsageContract;
use Illuminate\Support\Facades\Http;

/*
 * RUB-438: an artifact's sharing is `private` (owner and team admins), `team`
 * (the team) or `public` (anyone with the link), and `edit_access` says whether
 * the people it is shared with may publish new versions. `tier` stays accepted
 * as a deprecated alias that maps onto sharing, and reading an artifact now
 * reports the sharing, the caller's permissions and whether they own it. The
 * Worker contract is `openapi/artfct.yaml`; these tests drive the tools through
 * the hosted MCP server and assert the requests they emit and the structured
 * output they return.
 */

/** The deploy payload a successful permanent create returns for `$sharing`. */
function sharingDeployResponse(string $sharing, string $tier): array
{
    $id = STABLE_ARTIFACT_ID;

    return [
        'id' => $id,
        'url' => "https://worker.test/p/{$id}/",
        'tier' => $tier,
        'sharing' => $sharing,
        'edit_access' => 'view',
        'version' => 1,
        'missing_files' => [],
    ];
}

test('deploy_artifact sends and returns the requested sharing level', function (string $sharing, string $tier) {
    configureArtifactLinks();

    $team = Team::factory()->create(['slug' => 'rub-438-'.$sharing]);
    $token = remoteMcpToken($team);
    config(['services.worker.base_url' => 'https://worker.test']);
    Http::fake([
        'worker.test/v1/artifacts' => Http::response(sharingDeployResponse($sharing, $tier), 201),
    ]);
    app()->bind(UsageContract::class, FakeUsage::class);

    $response = $this->withToken($token)->postJson('/mcp', [
        'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call',
        'params' => ['name' => 'deploy_artifact', 'arguments' => ['html' => '<h1>Shared</h1>', 'sharing' => $sharing]],
    ])->assertOk();

    expect($response->json('result.isError'))->toBeFalse()
        ->and($response->json('result.structuredContent.sharing'))->toBe($sharing)
        // `tier` is kept for one release, matching the Worker's alias.
        ->and($response->json('result.structuredContent.tier'))->toBe($tier);

    // `sharing` is what the caller asked for; `tier` is derived from it so an
    // older Worker that only knows the alias still accepts the request.
    Http::assertSent(fn ($request): bool => $request->method() === 'POST'
        && str_ends_with((string) $request->url(), '/v1/artifacts')
        && $request['sharing'] === $sharing
        && $request['tier'] === $tier
        && $request['edit_access'] === 'view'
        && $request['mode'] === 'permanent');
})->with([
    ['private', 'private'],
    ['team', 'secure'],
    ['public', 'public'],
]);

test('deploy_artifact defaults sharing to team when neither sharing nor tier is given', function () {
    configureArtifactLinks();

    $team = Team::factory()->create(['slug' => 'rub-438-default']);
    $token = remoteMcpToken($team);
    config(['services.worker.base_url' => 'https://worker.test']);
    Http::fake([
        'worker.test/v1/artifacts' => Http::response(sharingDeployResponse('team', 'secure'), 201),
    ]);
    app()->bind(UsageContract::class, FakeUsage::class);

    $this->withToken($token)->postJson('/mcp', [
        'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call',
        'params' => ['name' => 'deploy_artifact', 'arguments' => ['html' => '<h1>Default</h1>']],
    ])->assertOk()
        ->assertJsonPath('result.structuredContent.sharing', 'team');

    Http::assertSent(fn ($request): bool => $request->method() === 'POST'
        && str_ends_with((string) $request->url(), '/v1/artifacts')
        && $request['sharing'] === 'team'
        && $request['tier'] === 'secure'
        && $request['edit_access'] === 'view');
});

test('deploy_artifact keeps tier as a deprecated alias that maps onto sharing', function (string $tier, string $sharing) {
    configureArtifactLinks();

    $team = Team::factory()->create(['slug' => 'rub-438-alias-'.$tier]);
    $token = remoteMcpToken($team);
    config(['services.worker.base_url' => 'https://worker.test']);
    Http::fake([
        'worker.test/v1/artifacts' => Http::response(sharingDeployResponse($sharing, $tier), 201),
    ]);
    app()->bind(UsageContract::class, FakeUsage::class);

    $response = $this->withToken($token)->postJson('/mcp', [
        'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call',
        'params' => ['name' => 'deploy_artifact', 'arguments' => ['html' => '<h1>Aliased</h1>', 'tier' => $tier]],
    ])->assertOk();

    expect($response->json('result.structuredContent.sharing'))->toBe($sharing)
        ->and($response->json('result.structuredContent.tier'))->toBe($tier);

    Http::assertSent(fn ($request): bool => $request->method() === 'POST'
        && str_ends_with((string) $request->url(), '/v1/artifacts')
        && $request['sharing'] === $sharing
        && $request['tier'] === $tier);
})->with([
    ['secure', 'team'],
    ['public', 'public'],
]);

test('deploy_artifact lets sharing win over the tier alias', function () {
    configureArtifactLinks();

    $team = Team::factory()->create(['slug' => 'rub-438-both']);
    $token = remoteMcpToken($team);
    config(['services.worker.base_url' => 'https://worker.test']);
    Http::fake([
        'worker.test/v1/artifacts' => Http::response(sharingDeployResponse('private', 'private'), 201),
    ]);
    app()->bind(UsageContract::class, FakeUsage::class);

    $response = $this->withToken($token)->postJson('/mcp', [
        'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call',
        'params' => [
            'name' => 'deploy_artifact',
            'arguments' => ['html' => '<h1>Conflicting</h1>', 'sharing' => 'private', 'tier' => 'public'],
        ],
    ])->assertOk();

    expect($response->json('result.structuredContent.sharing'))->toBe('private')
        ->and($response->json('result.structuredContent.tier'))->toBe('private');

    Http::assertSent(fn ($request): bool => $request->method() === 'POST'
        && str_ends_with((string) $request->url(), '/v1/artifacts')
        && $request['sharing'] === 'private'
        && $request['tier'] === 'private');
});

test('deploy_artifact maps public_sharing_disabled to a non-retryable error', function () {
    configureArtifactLinks();

    $team = Team::factory()->create(['slug' => 'rub-438-disabled']);
    $token = remoteMcpToken($team);
    config(['services.worker.base_url' => 'https://worker.test']);
    Http::fake([
        'worker.test/v1/artifacts' => Http::response([
            'error' => ['code' => 'public_sharing_disabled', 'message' => 'Public sharing is off.', 'details' => []],
        ], 403),
    ]);
    app()->bind(UsageContract::class, FakeUsage::class);

    $response = $this->withToken($token)->postJson('/mcp', [
        'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call',
        'params' => ['name' => 'deploy_artifact', 'arguments' => ['html' => '<h1>x</h1>', 'sharing' => 'public']],
    ])->assertOk();

    $response->assertJsonPath('result.isError', true)
        ->assertJsonPath('result.content.0._meta.artfct.errorCode', 'public_sharing_disabled')
        ->assertJsonPath('result.content.0._meta.artfct.retryable', false);

    expect($response->json('result.content.0.text'))
        ->toContain('Your team has turned off public links')
        ->toContain('sharing');
});

test('deploy_artifact derives sharing from the tier when the Worker omits sharing', function () {
    configureArtifactLinks();

    $team = Team::factory()->create(['slug' => 'rub-438-derive']);
    $token = remoteMcpToken($team);
    config(['services.worker.base_url' => 'https://worker.test']);
    Http::fake([
        'worker.test/v1/artifacts' => Http::response([
            'id' => STABLE_ARTIFACT_ID,
            'url' => 'https://worker.test/p/'.STABLE_ARTIFACT_ID.'/',
            'tier' => 'secure',
            'version' => 1,
            'missing_files' => [],
        ], 201),
    ]);
    app()->bind(UsageContract::class, FakeUsage::class);

    $this->withToken($token)->postJson('/mcp', [
        'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call',
        'params' => ['name' => 'deploy_artifact', 'arguments' => ['html' => '<h1>Fallback</h1>']],
    ])->assertOk()
        // secure = team, so an older Worker's tier still yields a sharing name.
        ->assertJsonPath('result.structuredContent.sharing', 'team');
});

test('get_artifact returns the sharing, the callers permissions and that they own it', function () {
    configureArtifactLinks();

    $team = Team::factory()->create(['slug' => 'rub-438-access']);
    $token = remoteMcpToken($team);
    $actor = (string) OrgJwtService::default()->verify($token)['user_id'];
    $id = STABLE_ARTIFACT_ID;
    config(['services.worker.base_url' => 'https://worker.test']);
    Http::fake([
        'worker.test/v1/artifacts/'.$id => Http::response([
            'id' => $id, 'tier' => 'secure', 'sharing' => 'team', 'edit_access' => 'edit',
            'owner_user_id' => $actor, 'can_edit' => true, 'can_change_sharing' => true,
            'entrypoint' => 'index.html', 'created_at' => '2026-09-20T00:00:00Z', 'expires_at' => null,
            'title' => 'Owned', 'description' => 'Owned summary',
            'version' => 1, 'version_count' => 1, 'updated_at' => '2026-10-01T00:00:00Z',
        ], 200),
    ]);

    $response = $this->withToken($token)->postJson('/mcp', [
        'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call',
        'params' => ['name' => 'get_artifact', 'arguments' => ['id' => $id]],
    ])->assertOk();

    expect($response->json('result.isError'))->toBeFalse()
        ->and($response->json('result.structuredContent.sharing'))->toBe('team')
        ->and($response->json('result.structuredContent.edit_access'))->toBe('edit')
        ->and($response->json('result.structuredContent.can_edit'))->toBeTrue()
        ->and($response->json('result.structuredContent.can_change_sharing'))->toBeTrue()
        ->and($response->json('result.structuredContent.owner_is_you'))->toBeTrue();

    // The owner's raw user id is never handed to the agent.
    expect($response->json('result.structuredContent.owner_user_id'))->toBeNull();
});

test('get_artifact reports owner_is_you false for an artifact owned by someone else', function () {
    configureArtifactLinks();

    $team = Team::factory()->create(['slug' => 'rub-438-not-owner']);
    $token = remoteMcpToken($team);
    $id = STABLE_ARTIFACT_ID;
    config(['services.worker.base_url' => 'https://worker.test']);
    Http::fake([
        'worker.test/v1/artifacts/'.$id => Http::response([
            'id' => $id, 'tier' => 'secure', 'sharing' => 'private', 'edit_access' => 'view',
            'owner_user_id' => 'user_someone_else', 'can_edit' => false, 'can_change_sharing' => false,
            'entrypoint' => 'index.html', 'created_at' => '2026-09-20T00:00:00Z', 'expires_at' => null,
            'title' => 'Theirs', 'description' => 'Their summary',
            'version' => 1, 'version_count' => 1, 'updated_at' => '2026-10-01T00:00:00Z',
        ], 200),
    ]);

    $this->withToken($token)->postJson('/mcp', [
        'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call',
        'params' => ['name' => 'get_artifact', 'arguments' => ['id' => $id]],
    ])->assertOk()
        ->assertJsonPath('result.structuredContent.owner_is_you', false)
        ->assertJsonPath('result.structuredContent.can_edit', false)
        ->assertJsonPath('result.structuredContent.sharing', 'private');
});

test('get_artifact derives sharing from the tier when the Worker omits sharing', function () {
    configureArtifactLinks();

    $team = Team::factory()->create(['slug' => 'rub-438-read-derive']);
    $token = remoteMcpToken($team);
    $id = STABLE_ARTIFACT_ID;
    config(['services.worker.base_url' => 'https://worker.test']);
    Http::fake([
        'worker.test/v1/artifacts/'.$id => Http::response([
            'id' => $id, 'tier' => 'public', 'entrypoint' => 'index.html',
            'created_at' => '2026-09-20T00:00:00Z', 'expires_at' => null,
            'title' => 'Public', 'description' => 'Public summary',
            'version' => 1, 'version_count' => 1, 'updated_at' => '2026-10-01T00:00:00Z',
        ], 200),
    ]);

    $this->withToken($token)->postJson('/mcp', [
        'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call',
        'params' => ['name' => 'get_artifact', 'arguments' => ['id' => $id]],
    ])->assertOk()
        ->assertJsonPath('result.structuredContent.sharing', 'public')
        ->assertJsonPath('result.structuredContent.tier', 'public');
});
