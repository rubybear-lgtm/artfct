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
 * Spec 24 Unit B: the announcements template loads clean, pulls seed-only
 * copies with provenance, and its skill flow drives the db tools (doctest).
 */

beforeEach(function () {
    configureArtifactLinks();
    app()->bind(TemplateCatalog::class, fn () => new TemplateCatalog(resource_path('templates')));
});

function announcePullToken(Team $team, array $scopes): string
{
    configureSigning(testSigningKey());

    $user = memberOfTeam($team, TeamRole::Admin);

    return OrgJwtService::default()->mint($team, $user, TeamRole::Admin, 300, $scopes)['token'];
}

function announceRelease(Team $team): void
{
    $gates = app(TeamFeatureGates::class);
    $gates->enable($team, TeamFeatureGates::ARTIFACT_TEMPLATES);
    $gates->enable($team, TeamFeatureGates::ARTIFACT_DB);
}

function announceFakeCreate(string $id = STABLE_ARTIFACT_ID, int $version = 1, array $missing = []): void
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

test('announcement_template_loads_with_valid_manifest_and_seed', function () {
    $catalog = app(TemplateCatalog::class);

    expect($catalog->keys())->toContain('announcements');

    $template = $catalog->find('announcements');

    expect($template)->not->toBeNull()
        ->and($template->key)->toBe('announcements')
        ->and($template->entrypoint)->toBe('index.html')
        ->and($template->sharing)->toBe('team')
        ->and($template->editAccess)->toBe('edit')
        ->and($template->requires)->toBe(['artifact-db'])
        ->and($template->db['collections'])->toBe(['posts' => ['index' => 'published_at']])
        ->and($template->db['seed'])->toHaveCount(1)
        ->and($template->db['seed'][0]['doc_id'])->toStartWith('p-')
        ->and($template->skill)->toContain('publish-worthy')
        ->and($catalog->validate('announcements'))->toBe([]);
});

test('announcement_pull_creates_owned_board_with_seed_and_provenance', function () {
    $team = Team::factory()->create(['plan' => Plan::Team, 'slug' => 'rub-537-org']);
    announceRelease($team);
    $token = announcePullToken($team, ['artifacts:deploy', 'artifacts:read', 'data:read', 'data:write']);
    announceFakeCreate();
    $id = STABLE_ARTIFACT_ID;

    $content = $this->withToken($token)->postJson('/mcp', [
        'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call',
        'params' => ['name' => 'pull_template', 'arguments' => [
            'template' => 'announcements', 'request_id' => 'announce-owned-1',
        ]],
    ])->assertOk()->json('result.structuredContent');

    expect($content['id'])->toBe($id)
        ->and($content['template'])->toBe('announcements')
        ->and($content['created'])->toBeTrue()
        ->and($content['sharing'])->toBe('team');

    Http::assertSent(fn ($request): bool => $request->method() === 'POST'
        && str_ends_with((string) $request->url(), '/v1/artifacts')
        && ($request['manifest']['db']['collections'] ?? null) === ['posts' => ['index' => 'published_at']]
        && count($request['manifest']['db']['seed'] ?? []) === 1
        && str_starts_with((string) ($request['provenance']['source_path'] ?? ''), 'template:announcements@'));

    expect(TemplatePullRecord::query()
        ->where('team_id', $team->id)
        ->where('template_key', 'announcements')
        ->where('request_id', 'announce-owned-1')
        ->exists())->toBeTrue();
});

test('announcement_pull_is_idempotent_per_request_id', function () {
    $team = Team::factory()->create(['plan' => Plan::Team, 'slug' => 'rub-537-org']);
    announceRelease($team);
    $token = announcePullToken($team, ['artifacts:deploy', 'artifacts:read', 'data:read', 'data:write']);
    announceFakeCreate();

    $call = fn () => $this->withToken($token)->postJson('/mcp', [
        'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call',
        'params' => ['name' => 'pull_template', 'arguments' => [
            'template' => 'announcements', 'request_id' => 'announce-same',
        ]],
    ])->assertOk()->json('result.structuredContent');

    expect($call()['id'])->toBe($call()['id'])
        ->and($call()['created'])->toBeFalse();
});

test('announcement_private_pull_gives_personal_log', function () {
    $team = Team::factory()->create(['plan' => Plan::Team, 'slug' => 'rub-537-org']);
    announceRelease($team);
    $token = announcePullToken($team, ['artifacts:deploy', 'artifacts:read', 'data:read', 'data:write']);
    announceFakeCreate();

    $this->withToken($token)->postJson('/mcp', [
        'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call',
        'params' => ['name' => 'pull_template', 'arguments' => [
            'template' => 'announcements', 'request_id' => 'announce-private', 'sharing' => 'private',
        ]],
    ])->assertOk()->assertJsonPath('result.isError', false);

    Http::assertSent(fn ($request): bool => $request->method() === 'POST'
        && str_ends_with((string) $request->url(), '/v1/artifacts')
        && ($request['sharing'] ?? $request['tier'] ?? null) === 'private');
});

test('announcement_skill_doctest_check_then_publish', function () {
    $team = Team::factory()->create(['slug' => 'rub-537-org']);
    announceRelease($team);
    $token = announcePullToken($team, ['artifacts:read', 'data:read', 'data:write']);
    config(['services.worker.base_url' => 'https://worker.test']);
    $id = STABLE_ARTIFACT_ID;
    $host = "rub-537-org--{$id}.artfct.dev";

    Http::fake([
        "worker.test/v1/artifacts/{$id}" => Http::response([
            'id' => $id, 'tier' => 'secure', 'sharing' => 'team',
            'database' => ['collections' => ['posts' => ['index' => 'published_at']]],
        ]),
        "{$host}/__artfct/db/posts*" => Http::sequence()
            ->push(['docs' => [
                ['collection' => 'posts', 'doc_id' => 'p-1',
                    'body' => ['title' => 'Welcome', 'body' => 'Post the news.',
                        'tag' => 'note', 'published_at' => '2026-01-05T09:00:00Z'],
                    'version' => 1, 'updated_at' => 't', 'updated_by' => 'artfct'],
            ], 'cursor' => null])
            ->push(['collection' => 'posts', 'doc_id' => 'p-2',
                'body' => ['title' => 'Shipped v2', 'body' => 'It is live.',
                    'tag' => 'launch', 'published_at' => '2026-11-14T00:00:00Z'],
                'version' => 1, 'updated_at' => 't', 'updated_by' => 'system'])
            ->push(['collection' => 'posts', 'doc_id' => 'p-2', 'deleted' => true]),
    ]);

    $call = fn (string $tool, array $args) => $this->withToken($token)->postJson('/mcp', [
        'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call',
        'params' => ['name' => $tool, 'arguments' => $args],
    ])->assertOk();

    // Check before work: latest posts first.
    $call('get_artifact', ['id' => $id])
        ->assertJsonPath('result.structuredContent.database.collections.posts.index', 'published_at');
    $call('db_list', ['artifact' => $id, 'collection' => 'posts'])
        ->assertJsonPath('result.structuredContent.documents.0.data.tag', 'note');
    // Publish-worthy: a launch is news; file it, then retract it.
    $call('db_set', ['artifact' => $id, 'collection' => 'posts', 'doc_id' => 'p-2',
        'body' => ['title' => 'Shipped v2', 'body' => 'It is live.',
            'tag' => 'launch', 'published_at' => '2026-11-14T00:00:00Z']])
        ->assertJsonPath('result.structuredContent.data.tag', 'launch');
    $call('db_delete', ['artifact' => $id, 'collection' => 'posts', 'doc_id' => 'p-2', 'if_version' => 1])
        ->assertJsonPath('result.structuredContent.deleted', true);

    Http::assertSent(fn ($request): bool => $request->method() === 'PUT'
        && str_contains((string) $request->toPsrRequest()->getUri(), '/__artfct/db/posts/p-2')
        && ($request->data()['tag'] ?? null) === 'launch'
        && ($request->header('X-Artfct-Db')[0] ?? '') === '1');
    Http::assertSent(fn ($request): bool => $request->method() === 'DELETE'
        && str_contains((string) $request->toPsrRequest()->getUri(), 'if_version=1'));
});
