<?php

use App\Contracts\ArtifactContentSource;
use App\Contracts\ArtifactDirectory;
use App\Enums\AuditEventType;
use App\Enums\TeamRole;
use App\Models\ArtifactTypeAssignment;
use App\Models\AuditEvent;
use App\Models\Team;
use App\Services\Artifacts\ArtifactAccessLink;
use App\Services\Artifacts\ArtifactViewLink;
use App\Services\Artifacts\FakeArtifactDirectory;
use App\Services\Billing\FakeUsage;
use App\Services\Billing\UsageContract;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;

/*
 * RUB-437: a permanent artifact is now versioned under a stable id, so every
 * Laravel id check, link builder and MCP tool that names an artifact has to
 * understand the new shape (13-character lowercase base36) without losing the
 * old one (32-character lowercase hex). The Worker contract is
 * `openapi/artfct.yaml`; these tests drive the tools through the hosted MCP
 * server and assert the requests they emit and the structured output they
 * return.
 */

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
        // A new version never changes sharing, so the response's sharing is
        // what is reported. `tier` is no longer returned.
        ->and($response->json('result.structuredContent.sharing'))->toBe('team')
        ->and($response->json('result.structuredContent'))->not->toHaveKey('tier');

    // A new version keeps sharing and omits title/description when the caller
    // did not send them, so previous metadata is preserved.
    Http::assertSent(fn ($request): bool => $request->method() === 'POST'
        && str_ends_with((string) $request->url(), '/v1/artifacts/'.$id.'/versions')
        && ! isset($request['mode'])
        && ! isset($request['tier'])
        && ! isset($request['sharing'])
        && ! isset($request['edit_access'])
        && ! array_key_exists('title', $request->data())
        && ! array_key_exists('description', $request->data())
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
        // Version N uses that version's own timestamp, not the live updated_at.
        ->and($response->json('result.structuredContent.updated_at'))->toBe('2026-09-25T00:00:00Z')
        ->and($response->json('result.structuredContent.created_by'))->toBe('user_123')
        ->and($response->json('result.structuredContent.agent'))->toBe('claude-code')
        ->and($response->json('result.structuredContent.current'))->toBeFalse()
        ->and($response->json('result.structuredContent'))->not->toHaveKey('tier');

    Http::assertSent(fn ($request): bool => $request->method() === 'GET'
        && str_ends_with((string) $request->url(), '/v1/artifacts/'.$id.'/versions/2'));

    // The link names the version, so following it opens that version rather
    // than the current one.
    expect((string) $response->json('result.structuredContent.view_url'))->toContain('/v/2');
});

test('delete_artifact rejects a malformed id with the same format error as get and read', function () {
    $team = Team::factory()->create(['slug' => 'rub-515-delete-id']);
    $token = remoteMcpToken($team);
    config(['services.worker.base_url' => 'https://worker.test']);
    Http::fake();

    $response = $this->withToken($token)->postJson('/mcp', [
        'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call',
        'params' => ['name' => 'delete_artifact', 'arguments' => ['id' => 'not-a-valid-id']],
    ])->assertOk();

    expect($response->json('result.isError'))->toBeTrue()
        ->and($response->json('result.content.0._meta.artfct.errorCode'))->toBe('invalid_request')
        ->and($response->json('result.content.0.text'))->toContain('permanent artifact id');

    Http::assertNothingSent();
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

    // The viewer resolves the artifact through the directory, so the artifact
    // has to exist there as well as in the content source. The directory is
    // read with the member's own token in production; the in-memory fake
    // serves globally, which is fine with a single workspace here.
    /** @var FakeArtifactDirectory $directory */
    $directory = app(ArtifactDirectory::class);
    $directory->seedArtifact([
        'id' => $id,
        'org_id' => $team->slug,
        'user_id' => $member->id,
        'title' => 'Stable',
        'description' => 'Stable summary',
        'content_hash' => md5($id),
        'created_at' => now()->toIso8601String(),
        'revoked_at' => null,
        'sharing' => 'team',
        'provenance' => ['agent' => 'cursor', 'repo_url' => null, 'commit_sha' => null],
    ]);

    /** @var FakeArtifactContentSource $content */
    $content = app(ArtifactContentSource::class);
    $content->seed($team->slug, $id, '<h1>Stable</h1>');

    $path = (string) parse_url($viewUrl, PHP_URL_PATH);
    $query = (string) parse_url($viewUrl, PHP_URL_QUERY);
    $open = test()->actingAs($member)->get($path.($query === '' ? '' : '?'.$query));
    $open->assertOk();

    // The viewer renders the artifact in a frame on its isolated origin, and
    // the frame's token is the thing that makes the link open.
    /** @var array<string, mixed> $props */
    $props = $open->viewData('page')['props'];
    $frameUrl = (string) ($props['frameUrl'] ?? '');

    expect($frameUrl)->toStartWith('https://rub-437-stable--'.$id.'.artfct.dev/p/'.$id.'/?token=');

    parse_str((string) parse_url($frameUrl, PHP_URL_QUERY), $params);
    expect(artifactTokenVerifies((string) ($params['token'] ?? ''), $id, ARTIFACT_LINK_SECRET, now()->timestamp))->toBeTrue();
});

test('a legacy 32-character id keeps its unversioned short link', function () {
    configureArtifactLinks();

    $team = Team::factory()->create(['slug' => 'rub-437-legacy']);
    $member = memberOfTeam($team, TeamRole::Member);

    /** @var FakeArtifactContentSource $content */
    $content = app(ArtifactContentSource::class);
    $content->seed($team->slug, ARTIFACT_LINK_ID, '<h1>Legacy</h1>');

    $response = test()->actingAs($member)->get("/settings/teams/{$team->slug}/console/artifacts/".ARTIFACT_LINK_ID.'/open');

    $response->assertRedirect(route('artifacts.show', ['artifactId' => ARTIFACT_LINK_ID]));

    $location = (string) $response->headers->get('Location');

    expect($location)->toBe(route('artifacts.show', ['artifactId' => ARTIFACT_LINK_ID]))
        ->and($location)->not->toContain('/v');
});

test('the console open route redirects to the requested version', function () {
    configureArtifactLinks();

    $team = Team::factory()->create(['slug' => 'rub-437-versioned']);
    $member = memberOfTeam($team, TeamRole::Member);

    /** @var FakeArtifactContentSource $content */
    $content = app(ArtifactContentSource::class);
    $content->seed($team->slug, ARTIFACT_LINK_ID, '<h1>Versioned</h1>');

    test()->actingAs($member)
        ->get("/settings/teams/{$team->slug}/console/artifacts/".ARTIFACT_LINK_ID.'/open?version=2')
        ->assertRedirect(route('artifacts.version', ['artifactId' => ARTIFACT_LINK_ID, 'version' => 2]));
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

        // The viewer route carries the version in the path; without one it is
        // byte-for-byte what it was before versioning.
        ->and(ArtifactViewLink::appOpenUrl('acme', $id, 2))
        ->toBe(route('artifacts.version', ['artifactId' => $id, 'version' => 2]))
        ->and(ArtifactViewLink::appOpenUrl('acme', $id))
        ->toBe(route('artifacts.show', ['artifactId' => $id]));

    $link = (new ArtifactAccessLink('fixed-secret', '.artfct.dev', 60))
        ->forArtifact('test-org', $id, now()->setTimestamp(1_700_000_000), 2);

    expect($link)->toBe(
        'https://test-org--'.$id.'.artfct.dev/p/'.$id.'/v:2/?token='.$id.'.1700000000.'
        .hash_hmac('sha256', $id.'.1700000000', 'fixed-secret')
    );
});

/** Call deploy_artifact for RUB-443's base-version tests. */
function deployWithBase(string $token, array $arguments): TestResponse
{
    return test()->withToken($token)->postJson('/mcp', [
        'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call',
        'params' => ['name' => 'deploy_artifact', 'arguments' => $arguments],
    ]);
}

test('deploy_artifact refuses a stale base_version and returns the live version without publishing', function () {
    configureArtifactLinks();

    $team = Team::factory()->create(['slug' => 'rub-443-stale-base']);
    $token = remoteMcpToken($team);
    $id = STABLE_ARTIFACT_ID;
    config(['services.worker.base_url' => 'https://worker.test']);
    Http::fake([
        'worker.test/v1/artifacts/'.$id => Http::response(['id' => $id, 'version' => 4, 'updated_at' => '2026-10-13T10:00:00Z'], 200),
        'worker.test/v1/artifacts/'.$id.'/versions' => Http::response(['id' => $id, 'version' => 5], 201),
    ]);
    app()->bind(UsageContract::class, FakeUsage::class);

    $response = deployWithBase($token, ['html' => '<p>mine</p>', 'artifact_id' => $id, 'base_version' => 3])->assertOk();

    expect($response->json('result.isError'))->toBeTrue()
        ->and($response->json('result.content.0._meta.artfct.errorCode'))->toBe('stale_base')
        ->and($response->json('result.content.0._meta.artfct.retryable'))->toBeFalse()
        ->and($response->json('result.content.0._meta.artfct.nextAction'))->toBe('read_artifact')
        ->and($response->json('result.content.0._meta.artfct.details.live_version'))->toBe(4)
        ->and($response->json('result.content.0._meta.artfct.details.current_version'))->toBe(4)
        ->and($response->json('result.content.0._meta.artfct.details.base_version'))->toBe(3);

    Http::assertNotSent(fn (Request $request): bool => str_ends_with($request->url(), '/versions'));
});

test('deploy_artifact rejects a base_version ahead of the live version', function () {
    configureArtifactLinks();

    $team = Team::factory()->create(['slug' => 'rub-515-future-base']);
    $token = remoteMcpToken($team);
    $id = STABLE_ARTIFACT_ID;
    config(['services.worker.base_url' => 'https://worker.test']);
    Http::fake([
        'worker.test/v1/artifacts/'.$id => Http::response(['id' => $id, 'version' => 4, 'updated_at' => '2026-10-13T10:00:00Z'], 200),
        'worker.test/v1/artifacts/'.$id.'/versions' => Http::response(['id' => $id, 'version' => 5], 201),
    ]);
    app()->bind(UsageContract::class, FakeUsage::class);

    $response = deployWithBase($token, ['html' => '<p>mine</p>', 'artifact_id' => $id, 'base_version' => 99])->assertOk();

    expect($response->json('result.isError'))->toBeTrue()
        ->and($response->json('result.content.0._meta.artfct.errorCode'))->toBe('invalid_request')
        ->and($response->json('result.content.0._meta.artfct.details.live_version'))->toBe(4)
        ->and($response->json('result.content.0.text'))->toContain('ahead of the live version');

    Http::assertNotSent(fn (Request $request): bool => str_ends_with($request->url(), '/versions'));
});

test('deploy_artifact version publish omits title description so previous values are kept', function () {
    configureArtifactLinks();

    $team = Team::factory()->create(['slug' => 'rub-515-keep-fields']);
    $token = remoteMcpToken($team);
    $id = STABLE_ARTIFACT_ID;
    $html = '<!doctype html><title>Ignored title</title><p>v2 body</p>';
    config(['services.worker.base_url' => 'https://worker.test']);
    Http::fake([
        'worker.test/v1/artifacts/'.$id.'/versions' => Http::response([
            'id' => $id, 'version' => 2, 'url' => "https://worker.test/p/{$id}/", 'created' => true, 'missing_files' => [],
            'tier' => 'secure', 'sharing' => 'team',
        ], 201),
    ]);
    app()->bind(UsageContract::class, FakeUsage::class);

    $response = deployWithBase($token, ['html' => $html, 'artifact_id' => $id])->assertOk();

    expect($response->json('result.isError'))->toBeFalse()
        ->and($response->json('result.structuredContent.created'))->toBeTrue()
        ->and($response->json('result.structuredContent.version'))->toBe(2);

    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
        && str_ends_with((string) $request->url(), '/v1/artifacts/'.$id.'/versions')
        && ! array_key_exists('title', $request->data())
        && ! array_key_exists('description', $request->data())
        && ! array_key_exists('sharing', $request->data())
        && ! array_key_exists('edit_access', $request->data())
        && ! array_key_exists('collection', $request->data()));
});

test('deploy_artifact version publish sends title and description only when provided', function () {
    configureArtifactLinks();

    $team = Team::factory()->create(['slug' => 'rub-515-explicit-fields']);
    $token = remoteMcpToken($team);
    $id = STABLE_ARTIFACT_ID;
    config(['services.worker.base_url' => 'https://worker.test']);
    Http::fake([
        'worker.test/v1/artifacts/'.$id.'/versions' => Http::response([
            'id' => $id, 'version' => 3, 'url' => "https://worker.test/p/{$id}/", 'created' => true, 'missing_files' => [],
            'tier' => 'secure', 'sharing' => 'team',
        ], 201),
    ]);
    app()->bind(UsageContract::class, FakeUsage::class);

    deployWithBase($token, [
        'html' => '<p>v3</p>',
        'artifact_id' => $id,
        'title' => 'Kept title',
        'description' => 'Kept description',
    ])->assertOk();

    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
        && str_ends_with((string) $request->url(), '/v1/artifacts/'.$id.'/versions')
        && $request['title'] === 'Kept title'
        && $request['description'] === 'Kept description');
});

test('deploy_artifact always returns created and version for a new artifact', function () {
    configureArtifactLinks();

    $team = Team::factory()->create(['slug' => 'rub-515-created-new']);
    $token = remoteMcpToken($team);
    $id = STABLE_ARTIFACT_ID;
    config(['services.worker.base_url' => 'https://worker.test']);
    Http::fake([
        'worker.test/v1/artifacts' => Http::response([
            'id' => $id, 'version' => 1, 'url' => "https://worker.test/p/{$id}/", 'missing_files' => [],
            'tier' => 'secure', 'sharing' => 'team',
        ], 201),
    ]);
    app()->bind(UsageContract::class, FakeUsage::class);

    $response = deployWithBase($token, ['html' => '<p>new</p>'])->assertOk();

    expect($response->json('result.structuredContent.created'))->toBeTrue()
        ->and($response->json('result.structuredContent.version'))->toBe(1)
        ->and($response->json('result.structuredContent'))->not->toHaveKey('tier');
});

test('deploy_artifact converts markdown entrypoint to HTML bytes with markdown path', function () {
    configureArtifactLinks();

    $team = Team::factory()->create(['slug' => 'rub-515-markdown']);
    $token = remoteMcpToken($team);
    $id = STABLE_ARTIFACT_ID;
    $markdown = "# Quarterly notes\n\n| Region | Revenue |\n| --- | --- |\n| West | 10 |\n";
    config(['services.worker.base_url' => 'https://worker.test']);
    Http::fake([
        'worker.test/v1/artifacts' => Http::response([
            'id' => $id, 'version' => 1, 'url' => "https://worker.test/p/{$id}/", 'missing_files' => [],
            'tier' => 'secure', 'sharing' => 'team',
        ], 201),
    ]);
    app()->bind(UsageContract::class, FakeUsage::class);

    $response = deployWithBase($token, [
        'files' => [['path' => 'README.md', 'content' => $markdown]],
        'entrypoint' => 'README.md',
    ])->assertOk();

    expect($response->json('result.isError'))->toBeFalse()
        ->and($response->json('result.structuredContent.created'))->toBeTrue()
        ->and($response->json('result.structuredContent.artifact_type'))->toBe('document');

    Http::assertSent(function (Request $request): bool {
        if ($request->method() !== 'POST' || ! str_ends_with((string) $request->url(), '/v1/artifacts')) {
            return false;
        }
        $file = $request['manifest']['files'][0];

        return $request['manifest']['entrypoint'] === 'README.md'
            && $file['path'] === 'README.md'
            && $file['content_type'] === 'text/html; charset=utf-8'
            && ! isset($request['missing_files']);
    });
});

test('deploy_artifact rejects markdown passed as html', function () {
    $team = Team::factory()->create(['slug' => 'rub-515-md-html']);
    $token = remoteMcpToken($team);
    config(['services.worker.base_url' => 'https://worker.test']);
    Http::fake();
    app()->bind(UsageContract::class, FakeUsage::class);

    $response = deployWithBase($token, ['html' => "# Notes\n\nHello team"])->assertOk();

    expect($response->json('result.isError'))->toBeTrue()
        ->and($response->json('result.content.0._meta.artfct.errorCode'))->toBe('invalid_request')
        ->and($response->json('result.content.0.text'))->toContain('README.md');

    Http::assertNothingSent();
});

test('deploy_artifact returns warnings for onclick and data image content', function () {
    configureArtifactLinks();

    $team = Team::factory()->create(['slug' => 'rub-515-warnings']);
    $token = remoteMcpToken($team);
    $id = STABLE_ARTIFACT_ID;
    $html = '<button onclick="alert(1)">Go</button><img src="data:image/png;base64,abc">';
    config(['services.worker.base_url' => 'https://worker.test']);
    Http::fake([
        'worker.test/v1/artifacts' => Http::response([
            'id' => $id, 'version' => 1, 'url' => "https://worker.test/p/{$id}/", 'missing_files' => [],
            'tier' => 'secure', 'sharing' => 'team',
        ], 201),
    ]);
    app()->bind(UsageContract::class, FakeUsage::class);

    $response = deployWithBase($token, ['html' => $html])->assertOk();

    expect($response->json('result.isError'))->toBeFalse()
        ->and($response->json('result.structuredContent.warnings'))->toHaveCount(2)
        ->and($response->json('result.structuredContent.warnings.0'))->toContain('onclick=')
        ->and($response->json('result.structuredContent.warnings.1'))->toContain('data:image');
});

test('deploy_artifact publishes when base_version matches the live version', function () {
    configureArtifactLinks();

    $team = Team::factory()->create(['slug' => 'rub-443-fresh-base']);
    $token = remoteMcpToken($team);
    $id = STABLE_ARTIFACT_ID;
    $html = '<!doctype html><title>Report</title><p>v5</p>';
    config(['services.worker.base_url' => 'https://worker.test']);
    Http::fake([
        'worker.test/v1/artifacts/'.$id => Http::response(['id' => $id, 'version' => 4], 200),
        'worker.test/v1/artifacts/'.$id.'/versions' => Http::response([
            'id' => $id, 'version' => 5, 'url' => "https://worker.test/p/{$id}/", 'created' => true, 'missing_files' => [],
            'tier' => 'secure', 'sharing' => 'team',
        ], 201),
    ]);
    app()->bind(UsageContract::class, FakeUsage::class);

    $response = deployWithBase($token, ['html' => $html, 'artifact_id' => $id, 'base_version' => 4])->assertOk();

    expect($response->json('result.isError'))->toBeFalse()
        ->and($response->json('result.structuredContent.version'))->toBe(5)
        ->and($response->json('result.structuredContent.base_version_checked'))->toBeTrue();
});

test('deploy_artifact without base_version still publishes and says the base was not checked', function () {
    configureArtifactLinks();

    $team = Team::factory()->create(['slug' => 'rub-443-no-base']);
    $token = remoteMcpToken($team);
    $id = STABLE_ARTIFACT_ID;
    config(['services.worker.base_url' => 'https://worker.test']);
    Http::fake([
        'worker.test/v1/artifacts/'.$id.'/versions' => Http::response([
            'id' => $id, 'version' => 2, 'url' => "https://worker.test/p/{$id}/", 'created' => true, 'missing_files' => [],
            'tier' => 'secure', 'sharing' => 'team',
        ], 201),
    ]);
    app()->bind(UsageContract::class, FakeUsage::class);

    $response = deployWithBase($token, ['html' => '<p>x</p>', 'artifact_id' => $id])->assertOk();

    expect($response->json('result.structuredContent.base_version_checked'))->toBeFalse();
    Http::assertNotSent(fn (Request $request): bool => $request->method() === 'GET');
});

test('deploy_artifact rejects base_version without artifact_id and hides a forbidden artifact as not found', function () {
    $team = Team::factory()->create(['slug' => 'rub-443-base-misuse']);
    $token = remoteMcpToken($team);
    $id = STABLE_ARTIFACT_ID;
    config(['services.worker.base_url' => 'https://worker.test']);
    Http::fake(['worker.test/v1/artifacts/'.$id => Http::response([], 403)]);
    app()->bind(UsageContract::class, FakeUsage::class);

    $misuse = deployWithBase($token, ['html' => '<p>x</p>', 'base_version' => 2])->assertOk();
    expect($misuse->json('result.content.0._meta.artfct.errorCode'))->toBe('invalid_request');

    $hidden = deployWithBase($token, ['html' => '<p>x</p>', 'artifact_id' => $id, 'base_version' => 2])->assertOk();
    expect($hidden->json('result.content.0._meta.artfct.errorCode'))->toBe('artifact_not_found');
});

test('a view-only caller cannot publish or change the type even with a fresh base_version', function () {
    configureArtifactLinks();

    $team = Team::factory()->create(['slug' => 'rub-443-view-only']);
    $token = remoteMcpToken($team);
    $id = STABLE_ARTIFACT_ID;
    config(['services.worker.base_url' => 'https://worker.test']);
    Http::fake([
        'worker.test/v1/artifacts/'.$id => Http::response(['id' => $id, 'version' => 2, 'can_edit' => false], 200),
        'worker.test/v1/artifacts/'.$id.'/versions' => Http::response(['error' => ['code' => 'forbidden', 'message' => 'no', 'details' => []]], 403),
    ]);
    app()->bind(UsageContract::class, FakeUsage::class);

    $response = deployWithBase($token, ['html' => '<p>x</p>', 'artifact_id' => $id, 'base_version' => 2, 'artifact_type' => 'dashboard'])->assertOk();

    expect($response->json('result.content.0._meta.artfct.errorCode'))->toBe('edit_forbidden')
        ->and(ArtifactTypeAssignment::query()->where('team_id', $team->id)->count())->toBe(0);
});
