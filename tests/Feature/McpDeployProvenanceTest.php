<?php

use App\Contracts\UsageContract;
use App\Models\Team;
use App\Services\Billing\FakeUsage;
use Illuminate\Support\Facades\Http;

test('deploy_artifact records the MCP client as the publishing agent and stores the model', function () {
    configureArtifactLinks();

    $team = Team::factory()->create();
    $token = remoteMcpToken($team);
    $html = '<!doctype html><title>Launch plan</title><p>Notes</p>';
    config(['services.worker.base_url' => 'https://worker.test']);
    Http::fake([
        'worker.test/v1/orgs/*/usage' => Http::response(['storage_bytes' => 0, 'artifacts_this_period' => 0], 200),
        'worker.test/v1/artifacts' => Http::response([
            'id' => ARTIFACT_LINK_ID,
            'url' => 'https://worker.test/p/'.ARTIFACT_LINK_ID,
            'tier' => 'secure',
            'missing_files' => [],
        ], 201),
    ]);
    app()->bind(UsageContract::class, FakeUsage::class);

    $this->withToken($token)
        ->withHeaders([
            'MCP-Client-Name' => 'Grok Bot',
            'MCP-Client-Version' => '1.2.3',
        ])
        ->postJson('/mcp', [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/call',
            'params' => [
                'name' => 'deploy_artifact',
                'arguments' => [
                    'html' => $html,
                    'model' => 'Hoku (Grok Bot)',
                ],
            ],
        ])
        ->assertOk()
        ->assertJsonPath('result.structuredContent.id', ARTIFACT_LINK_ID);

    Http::assertSent(function ($request): bool {
        if ($request->method() !== 'POST' || ! str_ends_with((string) $request->url(), '/v1/artifacts')) {
            return false;
        }

        $provenance = $request['provenance'] ?? null;

        return is_array($provenance)
            && ($provenance['agent'] ?? null) === 'Grok Bot'
            && ($provenance['agent_raw'] ?? null) === 'Grok Bot'
            && ($provenance['agent_version'] ?? null) === '1.2.3'
            && ($provenance['model'] ?? null) === 'Hoku (Grok Bot)'
            && ($provenance['sources']['agent'] ?? null) === 'client_info'
            && ($provenance['sources']['model'] ?? null) === 'self_reported';
    });
});

test('get_artifact returns agent and model from Worker provenance', function () {
    configureArtifactLinks();

    $team = Team::factory()->create();
    $token = remoteMcpToken($team);
    config(['services.worker.base_url' => 'https://worker.test']);
    Http::fake([
        'worker.test/v1/artifacts/'.ARTIFACT_LINK_ID => Http::response([
            'id' => ARTIFACT_LINK_ID,
            'tier' => 'secure',
            'sharing' => 'team',
            'edit_access' => 'view',
            'owner_user_id' => '1',
            'can_edit' => true,
            'can_change_sharing' => true,
            'entrypoint' => 'index.html',
            'created_at' => '2026-10-21T00:00:00Z',
            'expires_at' => null,
            'title' => 'Launch plan',
            'description' => null,
            'version' => 1,
            'version_count' => 1,
            'updated_at' => '2026-10-21T00:00:00Z',
            'provenance' => [
                'agent' => 'Grok Bot',
                'repo_url' => null,
                'commit_sha' => null,
                'model' => 'Hoku (Grok Bot)',
            ],
        ], 200),
    ]);

    $this->withToken($token)->postJson('/mcp', [
        'jsonrpc' => '2.0',
        'id' => 1,
        'method' => 'tools/call',
        'params' => [
            'name' => 'get_artifact',
            'arguments' => ['id' => ARTIFACT_LINK_ID],
        ],
    ])->assertOk()
        ->assertJsonPath('result.structuredContent.agent', 'Grok Bot')
        ->assertJsonPath('result.structuredContent.model', 'Hoku (Grok Bot)')
        ->assertJsonPath('result.structuredContent.provenance.agent', 'Grok Bot')
        ->assertJsonPath('result.structuredContent.provenance.model', 'Hoku (Grok Bot)');
});

test('list_artifacts agent filter matches a hosted deploy provenance agent', function () {
    configureArtifactLinks();

    $team = Team::factory()->create();
    $token = remoteMcpToken($team);
    config(['services.worker.base_url' => 'https://worker.test']);
    Http::fake([
        'worker.test/v1/orgs/*/artifacts*' => Http::response([
            'artifacts' => [[
                'id' => ARTIFACT_LINK_ID,
                'title' => 'Launch plan',
                'description' => null,
                'sharing' => 'team',
                'owner_user_id' => '1',
                'can_edit' => true,
                'version' => 1,
                'kind' => 'html',
                'created_at' => '2026-10-21T00:00:00Z',
                'updated_at' => '2026-10-21T00:00:00Z',
                'provenance' => [
                    'agent' => 'Grok Bot',
                    'repo_url' => null,
                    'commit_sha' => null,
                    'model' => 'Hoku (Grok Bot)',
                ],
            ]],
            'next_cursor' => null,
        ], 200),
    ]);

    $this->withToken($token)->postJson('/mcp', [
        'jsonrpc' => '2.0',
        'id' => 1,
        'method' => 'tools/call',
        'params' => [
            'name' => 'list_artifacts',
            'arguments' => ['agent' => 'Grok Bot'],
        ],
    ])->assertOk()
        ->assertJsonPath('result.structuredContent.artifacts.0.id', ARTIFACT_LINK_ID)
        ->assertJsonPath('result.structuredContent.artifacts.0.agent', 'Grok Bot')
        ->assertJsonPath('result.structuredContent.artifacts.0.model', 'Hoku (Grok Bot)');

    Http::assertSent(fn ($request): bool => str_contains((string) $request->url(), 'agent=Grok')
        || str_contains((string) $request->url(), 'agent=Grok%20Bot'));
});
