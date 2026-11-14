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
 * Spec 23 Unit B: the team-wiki template loads clean, pulls seed-only
 * copies with provenance, and its skill flow drives the db tools (doctest).
 */

beforeEach(function () {
    configureArtifactLinks();
    app()->bind(TemplateCatalog::class, fn () => new TemplateCatalog(resource_path('templates')));
});

function wikiPullToken(Team $team, array $scopes): string
{
    configureSigning(testSigningKey());

    $user = memberOfTeam($team, TeamRole::Admin);

    return OrgJwtService::default()->mint($team, $user, TeamRole::Admin, 300, $scopes)['token'];
}

function wikiRelease(Team $team): void
{
    $gates = app(TeamFeatureGates::class);
    $gates->enable($team, TeamFeatureGates::ARTIFACT_TEMPLATES);
    $gates->enable($team, TeamFeatureGates::ARTIFACT_DB);
}

function wikiFakeCreate(string $id = STABLE_ARTIFACT_ID, int $version = 1, array $missing = []): void
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

test('team_wiki_template_loads_with_valid_manifest_and_seed', function () {
    $catalog = app(TemplateCatalog::class);

    expect($catalog->keys())->toContain('team-wiki');

    $template = $catalog->find('team-wiki');
    expect($template)->not->toBeNull()
        ->and($template->key)->toBe('team-wiki')
        ->and($template->entrypoint)->toBe('index.html')
        ->and($template->sharing)->toBe('team')
        ->and($template->editAccess)->toBe('edit')
        ->and($template->requires)->toBe(['artifact-db'])
        ->and(array_keys($template->db['collections'] ?? []))->toEqualCanonicalizing(['pages', 'activity'])
        ->and($template->db['seed'])->toHaveCount(4)
        ->and($template->skill)->toContain('ingest')
        ->and($catalog->validate('team-wiki'))->toBe([]);
});

test('team_wiki_pull_creates_owned_wiki_with_seed_and_provenance', function () {
    $team = Team::factory()->create(['plan' => Plan::Team, 'slug' => 'rub-537-org']);
    wikiRelease($team);
    $token = wikiPullToken($team, ['artifacts:deploy', 'artifacts:read', 'data:read', 'data:write']);
    wikiFakeCreate();
    $id = STABLE_ARTIFACT_ID;

    $content = $this->withToken($token)->postJson('/mcp', [
        'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call',
        'params' => ['name' => 'pull_template', 'arguments' => [
            'template' => 'team-wiki', 'request_id' => 'wiki-owned-1',
        ]],
    ])->assertOk()->json('result.structuredContent');

    expect($content['id'])->toBe($id)
        ->and($content['template'])->toBe('team-wiki')
        ->and($content['created'])->toBeTrue()
        ->and($content['sharing'])->toBe('team');

    Http::assertSent(function ($request): bool {
        if ($request->method() !== 'POST' || ! str_ends_with((string) $request->url(), '/v1/artifacts')) {
            return false;
        }
        $collections = array_keys($request['manifest']['db']['collections'] ?? []);
        sort($collections);

        return $collections === ['activity', 'pages']
            && count($request['manifest']['db']['seed'] ?? []) === 4
            && str_starts_with((string) ($request['provenance']['source_path'] ?? ''), 'template:team-wiki@');
    });

    expect(TemplatePullRecord::query()
        ->where('team_id', $team->id)
        ->where('template_key', 'team-wiki')
        ->where('request_id', 'wiki-owned-1')
        ->exists())->toBeTrue();
});

test('team_wiki_pull_is_idempotent_per_request_id', function () {
    $team = Team::factory()->create(['plan' => Plan::Team, 'slug' => 'rub-537-org']);
    wikiRelease($team);
    $token = wikiPullToken($team, ['artifacts:deploy', 'artifacts:read', 'data:read', 'data:write']);
    wikiFakeCreate();

    $call = fn () => $this->withToken($token)->postJson('/mcp', [
        'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call',
        'params' => ['name' => 'pull_template', 'arguments' => [
            'template' => 'team-wiki', 'request_id' => 'wiki-same',
        ]],
    ])->assertOk()->json('result.structuredContent');

    expect($call()['id'])->toBe($call()['id'])
        ->and($call()['created'])->toBeFalse();
});

test('team_wiki_private_pull_gives_personal_wiki', function () {
    $team = Team::factory()->create(['plan' => Plan::Team, 'slug' => 'rub-537-org']);
    wikiRelease($team);
    $token = wikiPullToken($team, ['artifacts:deploy', 'artifacts:read', 'data:read', 'data:write']);
    wikiFakeCreate();

    $this->withToken($token)->postJson('/mcp', [
        'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call',
        'params' => ['name' => 'pull_template', 'arguments' => [
            'template' => 'team-wiki', 'request_id' => 'wiki-private', 'sharing' => 'private',
        ]],
    ])->assertOk()->assertJsonPath('result.isError', false);

    Http::assertSent(fn ($request): bool => $request->method() === 'POST'
        && str_ends_with((string) $request->url(), '/v1/artifacts')
        && ($request['sharing'] ?? $request['tier'] ?? null) === 'private');
});

test('team_wiki_skill_doctest_ingest_query_and_lint_shapes', function () {
    $team = Team::factory()->create(['slug' => 'rub-537-org']);
    wikiRelease($team);
    $token = wikiPullToken($team, ['artifacts:read', 'data:read', 'data:write']);
    config(['services.worker.base_url' => 'https://worker.test']);
    $id = STABLE_ARTIFACT_ID;
    $host = "rub-537-org--{$id}.artfct.dev";

    Http::fake([
        "worker.test/v1/artifacts/{$id}" => Http::response([
            'id' => $id, 'tier' => 'secure', 'sharing' => 'team',
            'database' => ['collections' => [
                'pages' => ['index' => 'title'],
                'activity' => ['index' => 'ts'],
            ]],
        ]),
        "{$host}/__artfct/db/pages*" => Http::sequence()
            ->push(['docs' => [
                ['collection' => 'pages', 'doc_id' => 'overview',
                    'body' => ['title' => 'Wiki overview', 'body_md' => 'Start here.',
                        'sources' => [], 'visibility' => 'shared'],
                    'version' => 1, 'updated_at' => 't', 'updated_by' => 'system'],
            ], 'cursor' => null])
            ->push(['collection' => 'pages', 'doc_id' => 'decisions',
                'body' => ['title' => 'Decisions', 'body_md' => 'We compile.',
                    'sources' => [['label' => 'chat', 'ref' => '2026-11-14']], 'visibility' => 'shared'],
                'version' => 1, 'updated_at' => 't', 'updated_by' => 'system']),
    ]);

    $call = fn (string $tool, array $args) => $this->withToken($token)->postJson('/mcp', [
        'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call',
        'params' => ['name' => $tool, 'arguments' => $args],
    ])->assertOk();

    // Ingest: index-first, then file the new page back.
    $call('get_artifact', ['id' => $id])
        ->assertJsonPath('result.structuredContent.database.collections.pages.index', 'title');
    $call('db_list', ['artifact' => $id, 'collection' => 'pages'])
        ->assertJsonPath('result.structuredContent.documents.0.data.title', 'Wiki overview');
    // Query: the index points at `overview`; file a good answer back as a page.
    $call('db_set', ['artifact' => $id, 'collection' => 'pages', 'doc_id' => 'decisions',
        'body' => ['title' => 'Decisions', 'body_md' => 'We compile.',
            'sources' => [['label' => 'chat', 'ref' => '2026-11-14']], 'visibility' => 'shared']])
        ->assertJsonPath('result.structuredContent.data.sources.0.label', 'chat');

    // The filed page is a raw document; if_version rides the query string.
    Http::assertSent(fn ($request): bool => $request->method() === 'PUT'
        && str_contains((string) $request->toPsrRequest()->getUri(), '/__artfct/db/pages/decisions')
        && ($request->data()['sources'][0]['label'] ?? null) === 'chat');
});
