<?php

use App\Contracts\UsageContract;
use App\Enums\FeatureReleaseState;
use App\Enums\PaymentStatus;
use App\Enums\Plan;
use App\Enums\TeamRole;
use App\Models\FeatureRelease;
use App\Models\Team;
use App\Models\TemplatePullRecord;
use App\Services\Auth\OrgJwtService;
use App\Services\Billing\FakeUsage;
use App\Services\FeatureFlags;
use App\Services\Templates\PullFailedException;
use App\Services\Templates\TemplateFeatures;
use App\Services\Templates\TemplatePull;
use App\Services\Templates\TemplatePuller;
use Illuminate\Support\Facades\Http;

function pullToken(Team $team, array $scopes): string
{
    configureSigning(testSigningKey());

    $user = memberOfTeam($team, TeamRole::Admin);

    return OrgJwtService::default()->mint($team, $user, TeamRole::Admin, 300, $scopes)['token'];
}

function enableFeatureForTeam(Team $team, string $key): void
{
    FeatureRelease::query()->updateOrCreate(
        ['key' => $key, 'environment' => app()->environment()],
        ['state' => FeatureReleaseState::SelectedTeams, 'selected_team_ids' => [$team->id]],
    );
    app(FeatureFlags::class)->flush();
}

function releaseTemplates(Team $team, bool $db = true): void
{
    enableFeatureForTeam($team, TemplateFeatures::ARTIFACT_TEMPLATES);

    if ($db) {
        enableFeatureForTeam($team, 'artifact-db');
    }
}

function fakeWorkerCreate(string $id = ARTIFACT_LINK_ID, int $version = 1, array $missing = []): void
{
    config(['services.worker.base_url' => 'https://worker.test']);
    Http::fake([
        'worker.test/v1/orgs/*/usage' => Http::response(['storage_bytes' => 0, 'artifacts_this_period' => 0], 200),
        'worker.test/v1/artifacts' => Http::response([
            'id' => $id, 'version' => $version, 'url' => 'https://worker.test/p/'.$id,
            'tier' => 'secure', 'sharing' => 'team', 'missing_files' => $missing,
        ], 201),
        'worker.test/v1/artifacts/*/files/*' => Http::response('', 204),
    ]);
    app()->bind(UsageContract::class, FakeUsage::class);
}

beforeEach(function () {
    configureArtifactLinks();
    fixtureCatalog();
});

test('pull_creates_an_owned_artifact_with_seed_and_provenance', function () {
    $team = Team::factory()->create(['plan' => Plan::Team]);
    releaseTemplates($team);
    $token = pullToken($team, ['artifacts:deploy', 'artifacts:read', 'data:read', 'data:write']);
    fakeWorkerCreate();

    $response = test()->withToken($token)->postJson('/mcp', [
        'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call',
        'params' => ['name' => 'pull_template', 'arguments' => [
            'template' => 'fixture', 'request_id' => 'req-owned-1',
        ]],
    ])->assertOk();

    $content = $response->json('result.structuredContent');
    expect($content['id'])->toBe(ARTIFACT_LINK_ID)
        ->and($content['template'])->toBe('fixture')
        ->and($content['created'])->toBeTrue()
        ->and($content['sharing'])->toBe('team');

    Http::assertSent(function ($request): bool {
        if ($request->method() !== 'POST' || ! str_ends_with((string) $request->url(), '/v1/artifacts')) {
            return false;
        }

        $provenance = $request['provenance'] ?? null;
        $db = $request['manifest']['db'] ?? null;

        return is_array($provenance)
            && ($provenance['tool'] ?? null) === 'pull_template'
            && ($provenance['source_path'] ?? null) === 'template:fixture@3'
            && is_array($db)
            && ($db['collections'] ?? null) === ['cards' => ['index' => 'column']]
            && ($db['seed'] ?? null) === [['collection' => 'cards', 'doc_id' => 'welcome', 'body' => ['column' => 'todo', 'title' => 'Welcome']]];
    });

    expect(TemplatePullRecord::query()
        ->where('team_id', $team->id)
        ->where('template_key', 'fixture')
        ->where('request_id', 'req-owned-1')
        ->where('artifact_id', ARTIFACT_LINK_ID)
        ->exists())->toBeTrue();
});

test('pull_is_idempotent_per_request_id', function () {
    $team = Team::factory()->create(['plan' => Plan::Team]);
    releaseTemplates($team);
    $token = pullToken($team, ['artifacts:deploy', 'artifacts:read', 'data:read', 'data:write']);
    fakeWorkerCreate();

    $call = fn () => test()->withToken($token)->postJson('/mcp', [
        'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call',
        'params' => ['name' => 'pull_template', 'arguments' => [
            'template' => 'fixture', 'request_id' => 'req-same',
        ]],
    ])->assertOk()->json('result.structuredContent');

    $first = $call();
    $second = $call();

    expect($first['id'])->toBe($second['id'])
        ->and($second['created'])->toBeFalse();

    Http::assertSentCount(1, fn ($request): bool => $request->method() === 'POST'
        && str_ends_with((string) $request->url(), '/v1/artifacts'));
});

test('pull_without_request_id_twice_makes_two_artifacts', function () {
    $team = Team::factory()->create(['plan' => Plan::Team]);
    releaseTemplates($team);
    $token = pullToken($team, ['artifacts:deploy', 'artifacts:read', 'data:read', 'data:write']);

    $ids = ['aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa', 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb'];
    config(['services.worker.base_url' => 'https://worker.test']);
    Http::fake([
        'worker.test/v1/orgs/*/usage' => Http::response(['storage_bytes' => 0, 'artifacts_this_period' => 0], 200),
        'worker.test/v1/artifacts' => Http::response([
            'id' => $ids[0], 'version' => 1, 'url' => 'https://worker.test/p/'.$ids[0],
            'tier' => 'secure', 'sharing' => 'team', 'missing_files' => [],
        ], 201),
        'worker.test/v1/artifacts/*/files/*' => Http::response('', 204),
    ]);
    app()->bind(UsageContract::class, FakeUsage::class);

    $call = fn () => test()->withToken($token)->postJson('/mcp', [
        'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call',
        'params' => ['name' => 'pull_template', 'arguments' => ['template' => 'fixture']],
    ])->assertOk()->json('result.structuredContent.id');

    expect($call())->toBe($ids[0])->and($call())->toBe($ids[0]);

    Http::assertSentCount(2, fn ($request): bool => $request->method() === 'POST'
        && str_ends_with((string) $request->url(), '/v1/artifacts'));

    expect(TemplatePullRecord::query()->where('team_id', $team->id)->count())->toBe(0);
});

test('pull_refused_when_artifact_db_or_templates_not_released', function () {
    $team = Team::factory()->create(['plan' => Plan::Team]);
    $token = pullToken($team, ['artifacts:deploy', 'artifacts:read', 'data:read', 'data:write']);
    fakeWorkerCreate();

    $names = fn () => test()->withToken($token)->postJson('/mcp', [
        'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list', 'params' => [],
    ])->assertOk()->json('result.tools.*.name');

    expect($names())->not->toContain('pull_template', 'list_templates');

    enableFeatureForTeam($team, TemplateFeatures::ARTIFACT_TEMPLATES);

    $response = test()->withToken($token)->postJson('/mcp', [
        'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call',
        'params' => ['name' => 'pull_template', 'arguments' => ['template' => 'fixture']],
    ])->assertOk();

    expect($response->json('result.content.0._meta.artfct.errorCode'))->toBe('feature_not_enabled')
        ->and($response->json('result.content.0.text'))->toContain('artifact-db');
});

test('pull_obeys_quota_daily_limit_and_scope', function () {
    $team = Team::factory()->create(['plan' => Plan::Team, 'payment_status' => PaymentStatus::PastDue]);
    releaseTemplates($team);
    $token = pullToken($team, ['artifacts:deploy', 'artifacts:read', 'data:read', 'data:write']);
    fakeWorkerCreate();

    $response = test()->withToken($token)->postJson('/mcp', [
        'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call',
        'params' => ['name' => 'pull_template', 'arguments' => ['template' => 'fixture']],
    ])->assertOk();

    expect($response->json('result.content.0._meta.artfct.errorCode'))->toBe('quota_exceeded');

    $team->update(['payment_status' => PaymentStatus::Active]);
    $narrow = pullToken($team, ['artifacts:deploy', 'artifacts:read']);

    $response = test()->withToken($narrow)->postJson('/mcp', [
        'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call',
        'params' => ['name' => 'pull_template', 'arguments' => ['template' => 'fixture']],
    ])->assertOk();

    expect($response->json('result.content.0._meta.artfct.errorCode'))->toBe('insufficient_scope')
        ->and($response->json('result.content.0.text'))->toContain('data:read')
        ->and($response->json('result.structuredContent.next_action'))->toBe('reconnect');
});

test('template_tools_hidden_until_released', function () {
    $team = Team::factory()->create(['plan' => Plan::Team]);
    $token = pullToken($team, ['artifacts:deploy', 'artifacts:read']);

    $tools = fn () => test()->withToken($token)->postJson('/mcp', [
        'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list', 'params' => [],
    ])->assertOk()->json('result.tools.*.name');

    expect($tools())->not->toContain('pull_template', 'list_templates');

    releaseTemplates($team, db: false);
    enableFeatureForTeam($team, 'artifact-db');

    expect($tools())->toContain('pull_template', 'list_templates');
});

test('list_templates_marks_unavailable_templates', function () {
    $team = Team::factory()->create(['plan' => Plan::Team]);
    enableFeatureForTeam($team, TemplateFeatures::ARTIFACT_TEMPLATES);
    $token = pullToken($team, ['artifacts:read']);
    fakeWorkerCreate();

    $response = test()->withToken($token)->postJson('/mcp', [
        'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call',
        'params' => ['name' => 'list_templates', 'arguments' => []],
    ])->assertOk();

    $templates = $response->json('result.structuredContent.templates');
    expect($templates)->toHaveCount(1)
        ->and($templates[0]['key'])->toBe('fixture')
        ->and($templates[0]['available'])->toBeFalse()
        ->and($templates[0]['unavailable_reason'])->toContain('artifact-db');
});

test('library_pull_runs_without_an_mcp_connection_and_cleans_up_on_failure', function () {
    $team = Team::factory()->create(['plan' => Plan::Team]);
    releaseTemplates($team);

    $puller = app(TemplatePuller::class);
    $pull = fn (string $token, ?string $requestId = null) => new TemplatePull(
        team: $team,
        actor: 'user-1',
        credentialToken: $token,
        templateKey: 'fixture',
        startedAt: hrtime(true),
        scopes: ['artifacts:deploy', 'artifacts:read', 'data:read', 'data:write'],
        requestId: $requestId,
        source: 'library',
        recordTelemetry: false,
    );

    config(['services.worker.base_url' => 'https://worker.test']);
    $indexSha = hash('sha256', (string) file_get_contents(base_path('tests/Fixtures/templates/fixture/files/index.html')));
    $creates = 0;
    Http::fake([
        'worker.test/v1/orgs/*/usage' => Http::response(['storage_bytes' => 0, 'artifacts_this_period' => 0], 200),
        'worker.test/v1/artifacts' => function () use (&$creates, $indexSha) {
            $creates++;

            return $creates === 1
                ? Http::response([
                    'id' => ARTIFACT_LINK_ID, 'version' => 1, 'url' => 'https://worker.test/p/'.ARTIFACT_LINK_ID,
                    'tier' => 'secure', 'sharing' => 'team', 'missing_files' => [],
                ], 201)
                : Http::response([
                    'id' => 'cccccccccccccccccccccccccccccccc', 'version' => 1,
                    'url' => 'https://worker.test/p/cccccccccccccccccccccccccccccccc',
                    'tier' => 'secure', 'sharing' => 'team',
                    'missing_files' => [$indexSha],
                ], 201);
        },
        'worker.test/v1/artifacts/*/files/*' => Http::response('no', 500),
    ]);
    app()->bind(UsageContract::class, FakeUsage::class);

    $result = $puller->pull($pull('library-token', 'req-lib-1'));

    expect($result['id'])->toBe(ARTIFACT_LINK_ID)
        ->and($result['created'])->toBeTrue()
        ->and(TemplatePullRecord::query()->where('request_id', 'req-lib-1')->exists())->toBeTrue();

    Http::assertSent(fn ($request): bool => ($request->header('Authorization')[0] ?? null) === 'Bearer library-token');

    try {
        $puller->pull($pull('library-token', 'req-lib-2'));
        expect(false)->toBeTrue('expected the pull to fail');
    } catch (PullFailedException $exception) {
        expect($exception->errorCode)->toBe('deployment_rejected');
    }

    Http::assertSent(fn ($request): bool => $request->method() === 'DELETE'
        && str_ends_with((string) $request->url(), '/v1/artifacts/cccccccccccccccccccccccccccccccc'));

    expect(TemplatePullRecord::query()->where('request_id', 'req-lib-2')->exists())->toBeFalse();
});

test('pull_records_the_mcp_client_as_the_publishing_agent', function () {
    $team = Team::factory()->create(['plan' => Plan::Team]);
    releaseTemplates($team);
    $token = pullToken($team, ['artifacts:deploy', 'artifacts:read', 'data:read', 'data:write']);
    fakeWorkerCreate();

    test()->withToken($token)->withHeaders(['MCP-Client-Name' => 'Grok Bot'])->postJson('/mcp', [
        'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call',
        'params' => ['name' => 'pull_template', 'arguments' => ['template' => 'fixture']],
    ])->assertOk();

    Http::assertSent(function ($request): bool {
        if ($request->method() !== 'POST' || ! str_ends_with((string) $request->url(), '/v1/artifacts')) {
            return false;
        }

        $provenance = $request['provenance'] ?? null;

        return is_array($provenance)
            && ($provenance['agent'] ?? null) === 'Grok Bot'
            && ($provenance['sources']['agent'] ?? null) === 'client_info';
    });
});
