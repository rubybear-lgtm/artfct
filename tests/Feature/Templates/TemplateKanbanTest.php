<?php

use App\Contracts\UsageContract;
use App\Enums\Plan;
use App\Enums\TeamRole;
use App\Models\Team;
use App\Models\TemplatePullRecord;
use App\Services\Auth\OrgJwtService;
use App\Services\Billing\FakeUsage;
use App\Services\Templates\TeamFeatureGates;
use App\Services\Templates\TemplateCatalog;
use Illuminate\Support\Facades\Http;

/*
 * Spec 22 Unit B: the kanban template loads clean, pulls seed-only copies,
 * and its skill flow drives the db tools end to end (doctest).
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
    $gates = app(TeamFeatureGates::class);
    $gates->enable($team, TeamFeatureGates::ARTIFACT_TEMPLATES);
    $gates->enable($team, TeamFeatureGates::ARTIFACT_DB);
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

function kanbanOrigin(string $teamSlug, string $id): string
{
    return "{$teamSlug}--{$id}.artfct.dev";
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

    // The Worker echoes sharing in production; the fake echoes `team`, so
    // assert the request asked for private.
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
    $host = kanbanOrigin('rub-537-org', $id);

    // The skill flow, literally: get_artifact (collections), list, add,
    // move, delete — asserting the transport contract at each step.
    Http::fake([
        "worker.test/v1/artifacts/{$id}" => Http::response([
            'id' => $id, 'tier' => 'secure', 'sharing' => 'team',
            'database' => ['collections' => ['cards' => ['index' => 'column']]],
        ]),
        "{$host}/__artfct/db/cards*" => Http::sequence()
            ->push(['documents' => [], 'cursor' => null])
            ->push(['collection' => 'cards', 'doc_id' => 'c1',
                'body' => ['column' => 'todo', 'title' => 'Ship it'],
                'version' => 1, 'updated_at' => '2026-11-14T00:00:00Z', 'updated_by' => 'system'])
            ->push(['collection' => 'cards', 'doc_id' => 'c1',
                'body' => ['column' => 'doing', 'title' => 'Ship it'],
                'version' => 2, 'updated_at' => '2026-11-14T00:00:01Z', 'updated_by' => 'system'])
            ->push(['collection' => 'cards', 'doc_id' => 'c1', 'deleted' => true]),
    ]);

    $call = fn (string $tool, array $args) => $this->withToken($token)->postJson('/mcp', [
        'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call',
        'params' => ['name' => $tool, 'arguments' => $args],
    ])->assertOk();

    $call('get_artifact', ['id' => $id])
        ->assertJsonPath('result.structuredContent.database.collections.cards.index', 'column');
    $call('db_list', ['artifact' => $id, 'collection' => 'cards'])
        ->assertJsonPath('result.structuredContent.documents', []);
    $call('db_set', ['artifact' => $id, 'collection' => 'cards', 'doc_id' => 'c1',
        'body' => ['column' => 'todo', 'title' => 'Ship it']])
        ->assertJsonPath('result.structuredContent.data.column', 'todo');
    $call('db_update', ['artifact' => $id, 'collection' => 'cards', 'doc_id' => 'c1',
        'body' => ['column' => 'doing'], 'if_version' => 1])
        ->assertJsonPath('result.structuredContent.data.column', 'doing');
    $call('db_delete', ['artifact' => $id, 'collection' => 'cards', 'doc_id' => 'c1', 'if_version' => 2])
        ->assertJsonPath('result.structuredContent.deleted', true);

    Http::assertSent(fn ($request): bool => $request->method() === 'PUT'
        && str_contains((string) $request->toPsrRequest()->getUri(), '/__artfct/db/cards/c1')
        && $request->data() === ['column' => 'todo', 'title' => 'Ship it']);
    Http::assertSent(fn ($request): bool => $request->method() === 'PATCH'
        && str_contains((string) $request->toPsrRequest()->getUri(), 'if_version=1')
        && $request->data() === ['column' => 'doing']);
    Http::assertSent(fn ($request): bool => $request->method() === 'DELETE'
        && str_contains((string) $request->toPsrRequest()->getUri(), 'if_version=2'));
});
