<?php

use App\Contracts\ArtifactContentSource;
use App\Enums\AuditEventType;
use App\Enums\TeamRole;
use App\Models\AuditEvent;
use App\Models\Team;
use App\Services\Artifacts\ArtifactAccessLink;
use App\Services\Artifacts\ArtifactViewLink;
use App\Services\Billing\FakeUsage;
use App\Services\Billing\UsageContract;
use Illuminate\Support\Facades\Http;

/*
 * RUB-437: a permanent artifact is now versioned under a stable id, so every
 * Laravel id check, link builder and MCP tool that names an artifact has to
 * understand the new shape (13-character lowercase base36) without losing the
 * old one (32-character lowercase hex). The Worker contract is
 * `openapi/artfct.yaml`; these tests drive the tools through the hosted MCP
 * server and assert the requests they emit and the structured output they
 * return.
 */

/** A 13-character lowercase base36 id: the shape every new permanent artifact gets. */
const STABLE_ARTIFACT_ID = 'abc123def4567';

test('deploy_artifact with artifact_id publishes a new version through the versions endpoint', function () {
    configureArtifactLinks();

    $team = Team::factory()->create(['slug' => 'rub-437-org']);
    $token = remoteMcpToken($team);
    $id = STABLE_ARTIFACT_ID;
    $html = '<!doctype html><title>Q3 report v2</title><p>Updated</p>';
    $sha = hash('sha256', $html);
    config(['services.worker.base_url' => 'https://worker.test']);
    Http::fake([
        'worker.test/v1/artifacts/'.$id.'/versions' => Http::response([
            'id' => $id, 'version' => 2, 'url' => "https://worker.test/p/{$id}/", 'created' => true, 'missing_files' => [$sha],
            'tier' => 'secure', 'sharing' => 'team',
        ], 201),
        'worker.test/v1/artifacts/'.$id.'/files/*' => Http::response('', 204),
    ]);
    app()->bind(UsageContract::class, FakeUsage::class);

    $response = $this->withToken($token)->postJson('/mcp', [
        'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call',
        'params' => ['name' => 'deploy_artifact', 'arguments' => ['html' => $html, 'artifact_id' => $id]],
    ])->assertOk();

    expect($response->json('result.isError'))->toBeFalse()
        ->and($response->json('result.structuredContent.id'))->toBe($id)
        ->and($response->json('result.structuredContent.version'))->toBe(2)
        ->and($response->json('result.structuredContent.created'))->toBeTrue()
        // The tier comes from the version response; a new version never
        // changes sharing, so the response's sharing is what is reported.
        ->and($response->json('result.structuredContent.tier'))->toBe('secure')
        ->and($response->json('result.structuredContent.sharing'))->toBe('team');

    // A new version keeps the artifact's existing mode and tier, so none of
    // the sharing fields is sent to the versions endpoint.
    Http::assertSent(fn ($request): bool => $request->method() === 'POST'
        && str_ends_with((string) $request->url(), '/v1/artifacts/'.$id.'/versions')
        && ! isset($request['mode'])
        && ! isset($request['tier'])
        && ! isset($request['sharing'])
        && ! isset($request['edit_access'])
        && $request['title'] === 'Q3 report v2'
        && $request['manifest']['files'][0]['sha256'] === $sha);
    Http::assertSent(fn ($request): bool => $request->method() === 'PUT'
        && str_ends_with((string) $request->url(), '/v1/artifacts/'.$id."/files/{$sha}")
        && $request->body() === $html);

    // The audit names the version that was published, not only the artifact.
    expect(AuditEvent::query()
        ->where('team_id', $team->id)
        ->where('event_type', AuditEventType::ArtifactDeployed)
        ->where('target', "artifact:{$id} version:2")
        ->where('outcome', 'success')
        ->exists())->toBeTrue();
});

test('deploy_artifact reports created false when the bytes match the current version', function () {
    configureArtifactLinks();

    $team = Team::factory()->create(['slug' => 'rub-437-noop']);
    $token = remoteMcpToken($team);
    $id = STABLE_ARTIFACT_ID;
    config(['services.worker.base_url' => 'https://worker.test']);
    Http::fake([
        'worker.test/v1/artifacts/'.$id.'/versions' => Http::response([
            'id' => $id, 'version' => 2, 'url' => "https://worker.test/p/{$id}/", 'created' => false, 'missing_files' => [],
        ], 200),
    ]);
    app()->bind(UsageContract::class, FakeUsage::class);

    $response = $this->withToken($token)->postJson('/mcp', [
        'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call',
        'params' => ['name' => 'deploy_artifact', 'arguments' => ['html' => '<p>same</p>', 'artifact_id' => $id]],
    ])->assertOk();

    expect($response->json('result.structuredContent.created'))->toBeFalse()
        ->and($response->json('result.structuredContent.version'))->toBe(2);

    Http::assertNotSent(fn ($request): bool => $request->method() === 'PUT');
});

test('deploy_artifact maps a forbidden version publish to edit_forbidden', function () {
    configureArtifactLinks();

    $team = Team::factory()->create(['slug' => 'rub-437-forbidden']);
    $token = remoteMcpToken($team);
    $id = STABLE_ARTIFACT_ID;
    config(['services.worker.base_url' => 'https://worker.test']);
    Http::fake([
        'worker.test/v1/artifacts/'.$id.'/versions' => Http::response([
            'error' => ['code' => 'forbidden', 'message' => 'Not the owner.', 'details' => []],
        ], 403),
    ]);
    app()->bind(UsageContract::class, FakeUsage::class);

    $response = $this->withToken($token)->postJson('/mcp', [
        'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call',
        'params' => ['name' => 'deploy_artifact', 'arguments' => ['html' => '<p>x</p>', 'artifact_id' => $id]],
    ])->assertOk();

    $response->assertJsonPath('result.isError', true)
        ->assertJsonPath('result.content.0._meta.artfct.errorCode', 'edit_forbidden')
        ->assertJsonPath('result.content.0._meta.artfct.retryable', false);

    expect($response->json('result.content.0.text'))
        ->toContain('You can only publish new versions of artifacts you own')
        ->toContain('without artifact_id');
});

test('deploy_artifact maps a version conflict to a retryable error', function () {
    configureArtifactLinks();

    $team = Team::factory()->create(['slug' => 'rub-437-conflict']);
    $token = remoteMcpToken($team);
    $id = STABLE_ARTIFACT_ID;
    config(['services.worker.base_url' => 'https://worker.test']);
    Http::fake([
        'worker.test/v1/artifacts/'.$id.'/versions' => Http::response([
            'error' => ['code' => 'version_conflict', 'message' => 'Concurrent publish.', 'details' => []],
        ], 409),
    ]);
    app()->bind(UsageContract::class, FakeUsage::class);

    $this->withToken($token)->postJson('/mcp', [
        'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call',
        'params' => ['name' => 'deploy_artifact', 'arguments' => ['html' => '<p>x</p>', 'artifact_id' => $id]],
    ])->assertOk()
        ->assertJsonPath('result.isError', true)
        ->assertJsonPath('result.content.0._meta.artfct.errorCode', 'version_conflict')
        ->assertJsonPath('result.content.0._meta.artfct.retryable', true);
});

test('deploy_artifact rejects a malformed artifact_id before contacting the worker', function () {
    $team = Team::factory()->create();
    $token = remoteMcpToken($team);
    config(['services.worker.base_url' => 'https://worker.test']);
    Http::fake();
    app()->bind(UsageContract::class, FakeUsage::class);

    $this->withToken($token)->postJson('/mcp', [
        'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call',
        'params' => [
            'name' => 'deploy_artifact',
            'arguments' => ['html' => '<p>x</p>', 'artifact_id' => 'not-a-valid-id'],
        ],
    ])->assertOk()
        ->assertJsonPath('result.isError', true)
        ->assertJsonPath('result.content.0._meta.artfct.errorCode', 'invalid_request');

    Http::assertNothingSent();
});

test('get_artifact returns the current version metadata', function () {
    configureArtifactLinks();

    $team = Team::factory()->create(['slug' => 'rub-437-current']);
    $token = remoteMcpToken($team);
    $id = STABLE_ARTIFACT_ID;
    config(['services.worker.base_url' => 'https://worker.test']);
    Http::fake([
        'worker.test/v1/artifacts/'.$id => Http::response([
            'id' => $id, 'tier' => 'secure', 'entrypoint' => 'index.html',
            'created_at' => '2026-09-20T00:00:00Z', 'expires_at' => null,
            'title' => 'Current', 'description' => 'Current summary',
            'version' => 3, 'version_count' => 3, 'updated_at' => '2026-10-01T00:00:00Z',
        ], 200),
    ]);

    $response = $this->withToken($token)->postJson('/mcp', [
        'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call',
        'params' => ['name' => 'get_artifact', 'arguments' => ['id' => $id]],
    ])->assertOk();

    expect($response->json('result.isError'))->toBeFalse()
        ->and($response->json('result.structuredContent.version'))->toBe(3)
        ->and($response->json('result.structuredContent.version_count'))->toBe(3)
        ->and($response->json('result.structuredContent.updated_at'))->toBe('2026-10-01T00:00:00Z');

    Http::assertNotSent(fn ($request): bool => str_contains((string) $request->url(), '/versions/'));
});

test('get_artifact returns the named version with a version-aware view_url', function () {
    configureArtifactLinks();

    $team = Team::factory()->create(['slug' => 'rub-437-fetch-version']);
    $token = remoteMcpToken($team);
    $id = STABLE_ARTIFACT_ID;
    config(['services.worker.base_url' => 'https://worker.test']);
    Http::fake([
        'worker.test/v1/artifacts/'.$id => Http::response([
            'id' => $id, 'tier' => 'secure', 'entrypoint' => 'index.html',
            'created_at' => '2026-09-20T00:00:00Z', 'expires_at' => null,
            'title' => 'Current', 'description' => 'Current summary',
            'version' => 3, 'version_count' => 3, 'updated_at' => '2026-10-01T00:00:00Z',
        ], 200),
        'worker.test/v1/artifacts/'.$id.'/versions/2' => Http::response([
            'version' => 2,
            'created_at' => '2026-09-25T00:00:00Z',
            'created_by' => 'user_123',
            'agent' => 'claude-code',
            'title' => 'Version two',
            'description' => 'The second version',
            'content_hash' => str_repeat('a', 64),
            'current' => false,
            'restored_from' => null,
        ], 200),
    ]);

    $response = $this->withToken($token)->postJson('/mcp', [
        'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call',
        'params' => ['name' => 'get_artifact', 'arguments' => ['id' => $id, 'version' => 2]],
    ])->assertOk();

    expect($response->json('result.isError'))->toBeFalse()
        ->and($response->json('result.structuredContent.version'))->toBe(2)
        ->and($response->json('result.structuredContent.version_count'))->toBe(3)
        ->and($response->json('result.structuredContent.title'))->toBe('Version two')
        ->and($response->json('result.structuredContent.description'))->toBe('The second version')
        ->and($response->json('result.structuredContent.created_at'))->toBe('2026-09-25T00:00:00Z')
        ->and($response->json('result.structuredContent.created_by'))->toBe('user_123')
        ->and($response->json('result.structuredContent.agent'))->toBe('claude-code')
        ->and($response->json('result.structuredContent.current'))->toBeFalse();

    Http::assertSent(fn ($request): bool => $request->method() === 'GET'
        && str_ends_with((string) $request->url(), '/v1/artifacts/'.$id.'/versions/2'));

    // The link names the version, so following it opens that version rather
    // than the current one.
    expect((string) $response->json('result.structuredContent.view_url'))->toContain('version=2');
});

test('get_artifact maps a missing version to version_not_found', function () {
    $team = Team::factory()->create(['slug' => 'rub-437-missing-version']);
    $token = remoteMcpToken($team);
    $id = STABLE_ARTIFACT_ID;
    config(['services.worker.base_url' => 'https://worker.test']);
    Http::fake([
        'worker.test/v1/artifacts/'.$id => Http::response([
            'id' => $id, 'tier' => 'secure', 'entrypoint' => 'index.html',
            'created_at' => '2026-09-20T00:00:00Z', 'expires_at' => null,
            'title' => 'Current', 'description' => 'Current summary',
            'version' => 3, 'version_count' => 3, 'updated_at' => '2026-10-01T00:00:00Z',
        ], 200),
        'worker.test/v1/artifacts/'.$id.'/versions/9' => Http::response([
            'error' => ['code' => 'version_not_found', 'message' => 'No such version.', 'details' => []],
        ], 404),
    ]);

    $this->withToken($token)->postJson('/mcp', [
        'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call',
        'params' => ['name' => 'get_artifact', 'arguments' => ['id' => $id, 'version' => 9]],
    ])->assertOk()
        ->assertJsonPath('result.isError', true)
        ->assertJsonPath('result.content.0._meta.artfct.errorCode', 'version_not_found');
});

test('a 13-character stable id is linkable and opens on its isolated origin', function () {
    configureArtifactLinks();

    $team = Team::factory()->create(['slug' => 'rub-437-stable']);
    $token = remoteMcpToken($team);
    $member = memberOfTeam($team, TeamRole::Member);
    $id = STABLE_ARTIFACT_ID;
    config(['services.worker.base_url' => 'https://worker.test']);
    Http::fake([
        'worker.test/v1/artifacts/'.$id => Http::response([
            'id' => $id, 'tier' => 'secure', 'entrypoint' => 'index.html',
            'created_at' => '2026-09-20T00:00:00Z', 'expires_at' => null,
            'title' => 'Stable', 'description' => 'Stable summary',
            'version' => 1, 'version_count' => 1, 'updated_at' => '2026-09-20T00:00:00Z',
        ], 200),
    ]);

    $response = $this->withToken($token)->postJson('/mcp', [
        'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call',
        'params' => ['name' => 'get_artifact', 'arguments' => ['id' => $id]],
    ])->assertOk();

    // The old 32-hex-only hostname check refused this shape with
    // signed_link_unavailable; a stable id now links.
    expect($response->json('result.isError'))->toBeFalse()
        ->and($response->json('result.content.0._meta.artfct.errorCode'))->toBeNull();

    $viewUrl = (string) $response->json('result.structuredContent.view_url');

    /** @var FakeArtifactContentSource $content */
    $content = app(ArtifactContentSource::class);
    $content->seed($team->slug, $id, '<h1>Stable</h1>');

    $path = (string) parse_url($viewUrl, PHP_URL_PATH);
    $query = (string) parse_url($viewUrl, PHP_URL_QUERY);
    $open = test()->actingAs($member)->get($path.($query === '' ? '' : '?'.$query));
    $open->assertRedirect();
    $location = (string) $open->headers->get('Location');

    expect($location)->toStartWith('https://rub-437-stable--'.$id.'.artfct.dev/p/'.$id.'/?token=');

    parse_str((string) parse_url($location, PHP_URL_QUERY), $params);
    expect(artifactTokenVerifies((string) ($params['token'] ?? ''), $id, ARTIFACT_LINK_SECRET, now()->timestamp))->toBeTrue();
});

test('a legacy 32-character id keeps its unversioned isolated link', function () {
    configureArtifactLinks();

    $team = Team::factory()->create(['slug' => 'rub-437-legacy']);
    $member = memberOfTeam($team, TeamRole::Member);

    /** @var FakeArtifactContentSource $content */
    $content = app(ArtifactContentSource::class);
    $content->seed($team->slug, ARTIFACT_LINK_ID, '<h1>Legacy</h1>');

    $response = test()->actingAs($member)->get("/settings/teams/{$team->slug}/console/artifacts/".ARTIFACT_LINK_ID.'/open');
    $response->assertRedirect();
    $location = (string) $response->headers->get('Location');

    expect($location)->toStartWith('https://rub-437-legacy--'.ARTIFACT_LINK_ID.'.artfct.dev/p/'.ARTIFACT_LINK_ID.'/?token=')
        ->and($location)->not->toContain('/v:');
});

test('the console open route redirects to the requested version', function () {
    configureArtifactLinks();

    $team = Team::factory()->create(['slug' => 'rub-437-versioned']);
    $member = memberOfTeam($team, TeamRole::Member);

    /** @var FakeArtifactContentSource $content */
    $content = app(ArtifactContentSource::class);
    $content->seed($team->slug, ARTIFACT_LINK_ID, '<h1>Versioned</h1>');

    $response = test()->actingAs($member)
        ->get("/settings/teams/{$team->slug}/console/artifacts/".ARTIFACT_LINK_ID.'/open?version=2');
    $response->assertRedirect();
    $location = (string) $response->headers->get('Location');

    expect($location)->toStartWith('https://rub-437-versioned--'.ARTIFACT_LINK_ID.'.artfct.dev/p/'.ARTIFACT_LINK_ID.'/v:2/?token=');

    // The token is still per artifact id, not per version: the same wire form
    // verifies, and the version travels in the path.
    parse_str((string) parse_url($location, PHP_URL_QUERY), $params);
    expect(artifactTokenVerifies((string) ($params['token'] ?? ''), ARTIFACT_LINK_ID, ARTIFACT_LINK_SECRET, now()->timestamp))->toBeTrue();
});

test('the console open route refuses a malformed version with 422', function () {
    configureArtifactLinks();

    $team = Team::factory()->create(['slug' => 'rub-437-badversion']);
    $member = memberOfTeam($team, TeamRole::Member);

    /** @var FakeArtifactContentSource $content */
    $content = app(ArtifactContentSource::class);
    $content->seed($team->slug, ARTIFACT_LINK_ID, '<h1>Versioned</h1>');

    test()->actingAs($member)
        ->getJson("/settings/teams/{$team->slug}/console/artifacts/".ARTIFACT_LINK_ID.'/open?version=0')
        ->assertStatus(422);
});

test('version-aware link builders keep the /p/{id}/v:{version}/ path', function () {
    config(['app.public_base_url' => 'https://artfct.dev']);

    $id = STABLE_ARTIFACT_ID;

    expect(ArtifactViewLink::publicPermanentUrl($id))
        ->toBe('https://artfct.dev/p/'.$id.'/')
        ->and(ArtifactViewLink::publicPermanentUrl($id, 2))
        ->toBe('https://artfct.dev/p/'.$id.'/v:2/')

        // The open route carries the version as a query parameter; without one
        // it is byte-for-byte what it was before versioning.
        ->and(ArtifactViewLink::appOpenUrl('acme', $id, 2))
        ->toBe(route('console.open', ['team' => 'acme', 'artifactId' => $id, 'version' => 2]))
        ->and(ArtifactViewLink::appOpenUrl('acme', $id))
        ->toBe(route('console.open', ['team' => 'acme', 'artifactId' => $id]));

    $link = (new ArtifactAccessLink('fixed-secret', '.artfct.dev', 60))
        ->forArtifact('test-org', $id, now()->setTimestamp(1_700_000_000), 2);

    expect($link)->toBe(
        'https://test-org--'.$id.'.artfct.dev/p/'.$id.'/v:2/?token='.$id.'.1700000000.'
        .hash_hmac('sha256', $id.'.1700000000', 'fixed-secret')
    );
});
