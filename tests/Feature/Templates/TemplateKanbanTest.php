<?php

use App\Contracts\UsageContract;
use App\Enums\FeatureReleaseState;
use App\Enums\Plan;
use App\Enums\TeamRole;
use App\Models\FeatureRelease;
use App\Models\Team;
use App\Models\TemplatePullRecord;
use App\Services\Auth\OrgJwtService;
use App\Services\Billing\FakeUsage;
use App\Services\FeatureFlags;
use App\Services\Templates\TemplateCatalog;
use App\Services\Templates\TemplateFeatures;
use Illuminate\Support\Facades\Http;

/*
 * Spec 23 (was 22) Unit B: the kanban template loads clean, pulls seed-only
 * copies, and its skill flow drives the db tools end to end (doctest).
 */

beforeEach(function () {
    configureArtifactLinks();
    app()->bind(TemplateCatalog::class, fn () => new TemplateCatalog(resource_path('templates')));
});

function kanbanPullToken(Team $team, array $scopes): string
{
    configureSigning(testSigningKey());

    $user = memberOfTeam($team, TeamRole::Admin);

    return OrgJwtService::default()->mint($team, $user, TeamRole::Admin, 300, $scopes)['token'];
}

function kanbanRelease(Team $team): void
{
    foreach ([TemplateFeatures::ARTIFACT_TEMPLATES, 'artifact-db'] as $key) {
        FeatureRelease::query()->updateOrCreate(
            ['key' => $key, 'environment' => app()->environment()],
            ['state' => FeatureReleaseState::SelectedTeams, 'selected_team_ids' => [$team->id]],
        );
    }
    app(FeatureFlags::class)->flush();
}

function kanbanFakeCreate(string $id = STABLE_ARTIFACT_ID): void
{
    config(['services.worker.base_url' => 'https://worker.test']);
    Http::fake([
        'worker.test/v1/orgs/*/usage' => Http::response(['storage_bytes' => 0, 'artifacts_this_period' => 0], 200),
        'worker.test/v1/artifacts' => Http::response([
            'id' => $id, 'version' => 1, 'url' => 'https://worker.test/p/'.$id,
            'tier' => 'secure', 'sharing' => 'team', 'missing_files' => [],
        ], 201),
        'worker.test/v1/artifacts/*/files/*' => Http::response('', 204),
    ]);
    app()->bind(UsageContract::class, FakeUsage::class);
}

test('kanban_template_loads_with_valid_manifest_and_seed', function () {
    $catalog = app(TemplateCatalog::class);

    expect($catalog->keys())->toContain('kanban');

    $template = $catalog->find('kanban');

    expect($template)->not->toBeNull()
        ->and($template->key)->toBe('kanban')
        ->and($template->entrypoint)->toBe('index.html')
        ->and($template->sharing)->toBe('team')
        ->and($template->editAccess)->toBe('edit')
        ->and($template->requires)->toBe(['artifact-db'])
        ->and($template->files)->toHaveKey('index.html')
        ->and($template->files)->toHaveKey('app.js')
        ->and($template->files)->toHaveKey('SKILL.md')
        ->and($template->db['collections'])->toBe(['cards' => ['index' => 'column']])
        ->and($template->db['seed'])->toHaveCount(3)
        ->and($template->skill)->toContain('Keep the board updated')
        ->and($catalog->validate('kanban'))->toBe([]);
});

test('kanban_pull_creates_owned_board_with_seed_and_provenance', function () {
    $team = Team::factory()->create(['plan' => Plan::Team, 'slug' => 'rub-537-org']);
    kanbanRelease($team);
    $token = kanbanPullToken($team, ['artifacts:deploy', 'artifacts:read', 'data:read', 'data:write']);
    kanbanFakeCreate();
    $id = STABLE_ARTIFACT_ID;

    $content = $this->withToken($token)->postJson('/mcp', [
        'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call',
        'params' => ['name' => 'pull_template', 'arguments' => [
            'template' => 'kanban', 'request_id' => 'kanban-owned-1',
        ]],
    ])->assertOk()->json('result.structuredContent');

    expect($content['id'])->toBe($id)
        ->and($content['template'])->toBe('kanban')
        ->and($content['created'])->toBeTrue()
        ->and($content['sharing'])->toBe('team');

    Http::assertSent(fn ($request): bool => $request->method() === 'POST'
        && str_ends_with((string) $request->url(), '/v1/artifacts')
        && ($request['manifest']['db']['collections'] ?? null) === ['cards' => ['index' => 'column']]
        && count($request['manifest']['db']['seed'] ?? []) === 3
        && str_starts_with((string) ($request['provenance']['source_path'] ?? ''), 'template:kanban@'));

    expect(TemplatePullRecord::query()
        ->where('team_id', $team->id)
        ->where('template_key', 'kanban')
        ->where('request_id', 'kanban-owned-1')
        ->exists())->toBeTrue();
});

test('kanban_pull_is_idempotent_per_request_id', function () {
    $team = Team::factory()->create(['plan' => Plan::Team, 'slug' => 'rub-537-org']);
    kanbanRelease($team);
    $token = kanbanPullToken($team, ['artifacts:deploy', 'artifacts:read', 'data:read', 'data:write']);
    kanbanFakeCreate();

    $call = fn () => $this->withToken($token)->postJson('/mcp', [
        'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call',
        'params' => ['name' => 'pull_template', 'arguments' => [
            'template' => 'kanban', 'request_id' => 'kanban-same',
        ]],
    ])->assertOk()->json('result.structuredContent');

    expect($call()['id'])->toBe($call()['id'])
        ->and($call()['created'])->toBeFalse();
});

test('kanban_private_pull_marks_personal_mode', function () {
    $team = Team::factory()->create(['plan' => Plan::Team, 'slug' => 'rub-537-org']);
    kanbanRelease($team);
    $token = kanbanPullToken($team, ['artifacts:deploy', 'artifacts:read', 'data:read', 'data:write']);
    kanbanFakeCreate();

    $this->withToken($token)->postJson('/mcp', [
        'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call',
        'params' => ['name' => 'pull_template', 'arguments' => [
            'template' => 'kanban', 'request_id' => 'kanban-private', 'sharing' => 'private',
        ]],
    ])->assertOk()->assertJsonPath('result.isError', false);

    Http::assertSent(fn ($request): bool => $request->method() === 'POST'
        && str_ends_with((string) $request->url(), '/v1/artifacts')
        && ($request['sharing'] ?? $request['tier'] ?? null) === 'private');
});

test('kanban_skill_doctest_add_move_and_delete', function () {
    $team = Team::factory()->create(['slug' => 'rub-537-org']);
    kanbanRelease($team);
    $token = kanbanPullToken($team, ['artifacts:read', 'data:read', 'data:write']);
    config(['services.worker.base_url' => 'https://worker.test']);
    $id = STABLE_ARTIFACT_ID;

    Http::fake([
        "worker.test/v1/artifacts/{$id}/db/schema" => Http::response([
            'collections' => ['cards' => ['index' => 'column']],
        ]),
        "worker.test/v1/artifacts/{$id}/db/cards*" => Http::sequence()
            ->push(['documents' => [[
                'id' => 'seed-1', 'collection' => 'cards',
                'data' => ['column' => 'todo', 'title' => 'Welcome', 'body' => '', 'owner' => 'artfct', 'position' => 1],
                'version' => 1, 'updated_at' => 't', 'updated_by' => 'artfct',
            ]], 'next_cursor' => null])
            ->push(['id' => 'c1', 'collection' => 'cards',
                'data' => ['column' => 'todo', 'title' => 'Ship it'],
                'version' => 1, 'updated_at' => '2026-11-14T00:00:00Z', 'updated_by' => '1'])
            ->push(['id' => 'c1', 'collection' => 'cards',
                'data' => ['column' => 'doing', 'title' => 'Ship it'],
                'version' => 2, 'updated_at' => '2026-11-14T00:00:01Z', 'updated_by' => '1'])
            ->push(['id' => 'c1', 'collection' => 'cards', 'deleted' => true]),
    ]);

    $call = fn (string $tool, array $args) => $this->withToken($token)->postJson('/mcp', [
        'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call',
        'params' => ['name' => $tool, 'arguments' => $args],
    ])->assertOk();

    $call('db_schema', ['id' => $id])
        ->assertJsonPath('result.structuredContent.collections.cards.index', 'column');
    $call('db_list', ['id' => $id, 'collection' => 'cards'])
        ->assertJsonPath('result.structuredContent.documents.0.data.title', 'Welcome')
        ->assertJsonPath('result.structuredContent.documents.0.id', 'seed-1');
    $call('db_set', ['id' => $id, 'collection' => 'cards', 'doc_id' => 'c1',
        'data' => ['column' => 'todo', 'title' => 'Ship it']])
        ->assertJsonPath('result.structuredContent.data.column', 'todo');
    $call('db_update', ['id' => $id, 'collection' => 'cards', 'doc_id' => 'c1',
        'data' => ['column' => 'doing'], 'if_version' => 1])
        ->assertJsonPath('result.structuredContent.data.column', 'doing');
    $call('db_delete', ['id' => $id, 'collection' => 'cards', 'doc_id' => 'c1', 'if_version' => 2])
        ->assertJsonPath('result.structuredContent.deleted', true);

    Http::assertSent(fn ($request): bool => $request->method() === 'PUT'
        && str_contains($request->url(), "/v1/artifacts/{$id}/db/cards/c1")
        && $request->body() === '{"column":"todo","title":"Ship it"}');
    Http::assertSent(fn ($request): bool => $request->method() === 'PATCH'
        && str_contains($request->url(), 'if_version=1')
        && $request->body() === '{"column":"doing"}');
    Http::assertSent(fn ($request): bool => $request->method() === 'DELETE'
        && str_contains($request->url(), 'if_version=2'));
});
