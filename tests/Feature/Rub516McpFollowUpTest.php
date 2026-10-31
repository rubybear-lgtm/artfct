<?php

/**
 * RUB-516: production still failed several RUB-515 DoD items because earlier
 * tests asserted `_meta` / stubbed Worker shapes instead of the wire contract
 * Hoku's client reads (`structuredContent`) and the product meaning of
 * `created` on version publishes.
 *
 * These cases drive the real `/mcp` JSON-RPC transport. Worker HTTP is faked
 * only where Laravel never reaches the Worker for the assertion (stale_base
 * pre-check) or to relay the fixed Worker response shape for `created`.
 * Worker `created: false` on content-changing version publishes is locked in
 * `backend/tests/storage_integration.rs`.
 */

use App\Services\Billing\FakeUsage;
use App\Services\Billing\UsageContract;
use App\Models\Team;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/** @return array<string, mixed> */
function rub516DeployArgs(array $overrides = []): array
{
    return array_merge([
        'html' => '<!doctype html><title>rub516</title><p>body</p>',
    ], $overrides);
}

function rub516CallDeploy(string $token, array $arguments): Illuminate\Testing\TestResponse
{
    return test()->withToken($token)->postJson('/mcp', [
        'jsonrpc' => '2.0',
        'id' => 1,
        'method' => 'tools/call',
        'params' => ['name' => 'deploy_artifact', 'arguments' => $arguments],
    ]);
}

test('rub516_item1_stale_base_error_exposes_structuredContent_code_and_versions', function () {
    configureArtifactLinks();

    $team = Team::factory()->create(['slug' => 'rub-516-stale-base']);
    $token = remoteMcpToken($team);
    $id = STABLE_ARTIFACT_ID;
    config(['services.worker.base_url' => 'https://worker.test']);
    Http::fake([
        'worker.test/v1/artifacts/'.$id => Http::response([
            'id' => $id,
            'version' => 2,
            'updated_at' => '2026-11-01T00:00:00Z',
        ], 200),
        'worker.test/v1/artifacts/'.$id.'/versions' => Http::response(['id' => $id, 'version' => 3], 201),
    ]);
    app()->bind(UsageContract::class, FakeUsage::class);

    $response = rub516CallDeploy($token, rub516DeployArgs([
        'artifact_id' => $id,
        'base_version' => 1,
    ]))->assertOk();

    expect($response->json('result.isError'))->toBeTrue()
        ->and($response->json('result.structuredContent'))->not->toBeNull()
        ->and($response->json('result.structuredContent.code'))->toBe('stale_base')
        ->and($response->json('result.structuredContent.live_version'))->toBe(2)
        ->and($response->json('result.structuredContent.base_version'))->toBe(1)
        // Preserve the prior meta path so older clients keep working.
        ->and($response->json('result.content.0._meta.artfct.errorCode'))->toBe('stale_base');

    Http::assertNotSent(fn (Request $request): bool => str_ends_with($request->url(), '/versions'));
});

test('rub516_item1_base_version_ahead_of_live_is_not_stale_base', function () {
    configureArtifactLinks();

    $team = Team::factory()->create(['slug' => 'rub-516-future-base']);
    $token = remoteMcpToken($team);
    $id = STABLE_ARTIFACT_ID;
    config(['services.worker.base_url' => 'https://worker.test']);
    Http::fake([
        'worker.test/v1/artifacts/'.$id => Http::response([
            'id' => $id,
            'version' => 2,
            'updated_at' => '2026-11-01T00:00:00Z',
        ], 200),
        'worker.test/v1/artifacts/'.$id.'/versions' => Http::response(['id' => $id, 'version' => 3], 201),
    ]);
    app()->bind(UsageContract::class, FakeUsage::class);

    $response = rub516CallDeploy($token, rub516DeployArgs([
        'artifact_id' => $id,
        'base_version' => 99,
    ]))->assertOk();

    expect($response->json('result.isError'))->toBeTrue()
        ->and($response->json('result.structuredContent.code'))->not->toBe('stale_base')
        ->and($response->json('result.structuredContent.code'))->toBe('invalid_request')
        ->and($response->json('result.structuredContent.live_version'))->toBe(2)
        ->and($response->json('result.structuredContent.base_version'))->toBe(99);

    Http::assertNotSent(fn (Request $request): bool => str_ends_with($request->url(), '/versions'));
});

test('rub516_item2_new_artifact_created_true_version_publish_created_false', function () {
    configureArtifactLinks();

    $team = Team::factory()->create(['slug' => 'rub-516-created-flag']);
    $token = remoteMcpToken($team);
    $id = STABLE_ARTIFACT_ID;
    config(['services.worker.base_url' => 'https://worker.test']);

    // First publish: Worker create responses omit `created`; MCP must report true.
    Http::fake([
        'worker.test/v1/artifacts' => Http::response([
            'id' => $id,
            'version' => 1,
            'url' => "https://worker.test/p/{$id}/",
            'missing_files' => [],
            'tier' => 'secure',
            'sharing' => 'team',
        ], 201),
    ]);
    app()->bind(UsageContract::class, FakeUsage::class);

    $created = rub516CallDeploy($token, rub516DeployArgs(['html' => '<p>v1</p>']))->assertOk();
    expect($created->json('result.isError'))->toBeFalse()
        ->and($created->json('result.structuredContent.created'))->toBeTrue()
        ->and($created->json('result.structuredContent.version'))->toBe(1);

    Http::fake([
        'worker.test/v1/artifacts/'.$id.'/versions' => Http::response([
            'id' => $id,
            'version' => 2,
            'url' => "https://worker.test/p/{$id}/",
            // Fixed Worker semantics: a content-changing version publish is not
            // a newly created artifact (RUB-516 item 2 / storage_integration).
            'created' => false,
            'missing_files' => [],
            'tier' => 'secure',
            'sharing' => 'team',
        ], 201),
    ]);

    $updated = rub516CallDeploy($token, rub516DeployArgs([
        'html' => '<p>v2</p>',
        'artifact_id' => $id,
        'base_version' => 1,
    ]))->assertOk();

    expect($updated->json('result.isError'))->toBeFalse()
        ->and($updated->json('result.structuredContent.created'))->toBeFalse()
        ->and($updated->json('result.structuredContent.version'))->toBe(2);
});

test('rub516_item2_version_publish_reports_created_false_even_if_worker_sends_legacy_true', function () {
    configureArtifactLinks();

    $team = Team::factory()->create(['slug' => 'rub-516-created-legacy']);
    $token = remoteMcpToken($team);
    $id = STABLE_ARTIFACT_ID;
    config(['services.worker.base_url' => 'https://worker.test']);
    // Production on 3b3c392 still returned created:true from the Worker for v2.
    // MCP product semantics must still report created:false for an update.
    Http::fake([
        'worker.test/v1/artifacts/'.$id.'/versions' => Http::response([
            'id' => $id,
            'version' => 2,
            'url' => "https://worker.test/p/{$id}/",
            'created' => true,
            'missing_files' => [],
            'tier' => 'secure',
            'sharing' => 'team',
        ], 201),
    ]);
    app()->bind(UsageContract::class, FakeUsage::class);

    $response = rub516CallDeploy($token, rub516DeployArgs([
        'html' => '<p>v2 legacy worker</p>',
        'artifact_id' => $id,
    ]))->assertOk();

    expect($response->json('result.structuredContent.created'))->toBeFalse()
        ->and($response->json('result.structuredContent.version'))->toBe(2);
});
