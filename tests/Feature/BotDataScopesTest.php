<?php

use App\Enums\FeatureReleaseState;
use App\Enums\McpScope;
use App\Enums\OrgTokenKind;
use App\Enums\Plan;
use App\Enums\TeamRole;
use App\Models\FeatureRelease;
use App\Models\OrgToken;
use App\Models\Team;
use App\Models\User;
use App\Services\Auth\OrgJwtService;
use App\Services\FeatureFlags;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;

/*
 * RUB-572: bot agent tokens carry `data:read` / `data:write`, and the `db_*`
 * MCP tools enforce them. Every token here is minted the way the app mints
 * one, through the team's "add a bot teammate" endpoint.
 *
 * Laravel checks the scope and the plan; the Worker decides workspace and
 * edit access (see backend/src/artifact_db_routes.rs and artifact_db.rs). The
 * fake Worker below applies those same rules to the claims inside the bot's
 * real bearer token, so these tests prove the token reaching the Worker
 * carries the bot's own workspace, role and scopes, and that Laravel hands the
 * Worker's refusals back unchanged.
 */

const BOT_DATA_ARTIFACT_ID = 'abc123def456x';
const BOT_DATA_OTHER_ARTIFACT_ID = 'zzz999yyy888x';

/** @param  list<Team>  $teams */
function botDataReleaseArtifactDb(array $teams): void
{
    FeatureRelease::factory()->create([
        'key' => 'artifact-db',
        'state' => FeatureReleaseState::SelectedTeams,
        'selected_team_ids' => array_map(fn (Team $team): int => $team->id, $teams),
    ]);
    app(FeatureFlags::class)->flush();
}

/**
 * Adds a bot through the endpoint a human uses and returns its bearer token.
 *
 * @param  list<string>|null  $scopes  null lets the app pick its default scopes
 */
function botDataMintToken(Team $team, ?array $scopes = null, string $name = 'Hoku'): string
{
    $admin = memberOfTeam($team, TeamRole::Admin);
    $payload = ['name' => $name] + ($scopes === null ? [] : ['scopes' => $scopes]);

    return test()->actingAs($admin)
        ->postJson(route('teams.bots.store', $team), $payload)
        ->assertCreated()
        ->json('token');
}

/**
 * A fake Worker agent API for the artifact database. It reads the bot's own
 * token and applies the Worker's rules: the matching data scope, the token's
 * workspace owns the artifact, and a writer who is not an admin or the owner
 * writes only where the artifact is open to edit. Reads that succeed are recorded in $served.
 *
 * @param  array<string, array{org: string, edit_access: string, owner: string}>  $artifacts
 * @param  list<string>  $served
 */
function botDataFakeWorker(array $artifacts, array &$served): void
{
    Http::fake(['worker.test/*' => function (Request $request) use ($artifacts, &$served) {
        $token = substr((string) ($request->header('Authorization')[0] ?? ''), 7);
        $claims = OrgJwtService::default()->verify($token);
        $isWrite = $request->method() !== 'GET';
        $scopes = preg_split('/\s+/', trim($claims['scope'] ?? '')) ?: [];

        if (! in_array($isWrite ? 'data:write' : 'data:read', $scopes, true)) {
            return Http::response(['error' => ['code' => 'insufficient_scope', 'message' => 'Missing scope.']], 403);
        }

        preg_match('#/v1/artifacts/([^/]+)/db/(.*)$#', (string) parse_url($request->url(), PHP_URL_PATH), $match);
        $artifact = $artifacts[$match[1] ?? ''] ?? null;

        if ($artifact === null || $artifact['org'] !== $claims['org_id']) {
            return Http::response(['error' => ['code' => 'not_found', 'message' => 'Not found.']], 404);
        }

        $canEdit = $claims['role'] === 'admin' || $artifact['owner'] === $claims['user_id'] || $artifact['edit_access'] === 'edit';
        if ($isWrite && ! $canEdit) {
            return Http::response(['error' => ['code' => 'forbidden', 'message' => 'This artifact is read-only for you.']], 403);
        }

        if (! $isWrite) {
            $served[] = $artifact['org'].':'.$match[2];
        }

        return match (true) {
            $isWrite => Http::response(['id' => 'c1', 'collection' => 'cards', 'version' => 1, 'updated_at' => 'now']),
            $match[2] === 'schema' => Http::response(['collections' => ['cards' => ['index' => 'column']]]),
            default => Http::response(['docs' => [['id' => 'c1', 'version' => 1, 'data' => ['title' => $artifact['org'].' card']]]]),
        };
    }]);
}

function botDataCall(string $token, string $tool, array $arguments): TestResponse
{
    return test()->withToken($token)->postJson('/mcp', [
        'jsonrpc' => '2.0',
        'id' => 1,
        'method' => 'tools/call',
        'params' => ['name' => $tool, 'arguments' => $arguments],
    ])->assertOk();
}

function botDataText(TestResponse $response): string
{
    return (string) $response->json('result.content.0.text');
}

/** @return list<Request> */
function botDataWorkerRequests(?string $method = null): array
{
    return Http::recorded()
        ->map(fn (array $pair): Request => $pair[0])
        ->filter(fn (Request $request): bool => $request->method() !== 'POST' || ! str_contains($request->url(), '/internal/'))
        ->filter(fn (Request $request): bool => $method === null || $request->method() === $method)
        ->values()
        ->all();
}

beforeEach(function () {
    configureOrgJwt();
    config(['services.worker.base_url' => 'https://worker.test']);
});

test('a bot on an eligible plan reads the schema and rows of a team artifact', function (Plan $plan) {
    $team = Team::factory()->create(['plan' => $plan, 'slug' => 'bot-data-grant']);
    botDataReleaseArtifactDb([$team]);
    $served = [];
    botDataFakeWorker([BOT_DATA_ARTIFACT_ID => ['org' => 'bot-data-grant', 'edit_access' => 'view', 'owner' => 'someone-else']], $served);
    $token = botDataMintToken($team);

    $schema = botDataCall($token, 'db_schema', ['id' => BOT_DATA_ARTIFACT_ID]);
    $rows = botDataCall($token, 'db_list', ['id' => BOT_DATA_ARTIFACT_ID, 'collection' => 'cards']);

    expect($schema->json('result.isError'))->toBeFalse()
        ->and($schema->json('result.structuredContent.collections.cards.index'))->toBe('column')
        ->and($rows->json('result.isError'))->toBeFalse()
        ->and($rows->json('result.structuredContent.docs.0.data.title'))->toBe('bot-data-grant card')
        ->and(botDataText($schema).botDataText($rows))->not->toContain('insufficient_scope');

    // The Worker was called with the bot's own token, minted for this team.
    $claims = OrgJwtService::default()->verify($token);
    expect($claims['org_id'])->toBe('bot-data-grant')
        ->and($claims['scope'])->toContain('data:read')
        ->and(User::query()->whereKey((int) $claims['user_id'])->value('is_bot'))->toBeTrue();
    Http::assertSent(fn (Request $request): bool => $request->hasHeader('Authorization', 'Bearer '.$token));
})->with([
    'Pro' => [Plan::Pro],
    'Team' => [Plan::Team],
    'Business' => [Plan::Enterprise],
]);

test('a bot token minted without data:read is refused db_schema and db_list', function () {
    $team = Team::factory()->create(['plan' => Plan::Team, 'slug' => 'bot-data-no-read']);
    botDataReleaseArtifactDb([$team]);
    $served = [];
    botDataFakeWorker([BOT_DATA_ARTIFACT_ID => ['org' => 'bot-data-no-read', 'edit_access' => 'edit', 'owner' => 'someone-else']], $served);
    $token = botDataMintToken($team, [McpScope::ArtifactsRead->value, McpScope::DataWrite->value]);

    $schema = botDataCall($token, 'db_schema', ['id' => BOT_DATA_ARTIFACT_ID]);
    $rows = botDataCall($token, 'db_list', ['id' => BOT_DATA_ARTIFACT_ID, 'collection' => 'cards']);

    expect(botDataText($schema))->toStartWith('insufficient_scope:')->toContain('data:read')
        ->and(botDataText($rows))->toStartWith('insufficient_scope:')->toContain('data:read')
        ->and($served)->toBe([]);
    expect(botDataWorkerRequests())->toBe([]);
});

test('a bot token minted without data:write is refused db_set and nothing is written', function () {
    $team = Team::factory()->create(['plan' => Plan::Team, 'slug' => 'bot-data-no-write']);
    botDataReleaseArtifactDb([$team]);
    $served = [];
    botDataFakeWorker([BOT_DATA_ARTIFACT_ID => ['org' => 'bot-data-no-write', 'edit_access' => 'edit', 'owner' => 'someone-else']], $served);
    $token = botDataMintToken($team, [McpScope::ArtifactsRead->value, McpScope::DataRead->value]);

    $response = botDataCall($token, 'db_set', [
        'id' => BOT_DATA_ARTIFACT_ID, 'collection' => 'cards', 'doc_id' => 'c1', 'data' => ['column' => 'done'],
    ]);

    expect(botDataText($response))->toStartWith('insufficient_scope:')->toContain('data:write');
    expect(botDataWorkerRequests())->toBe([]);

    // The read scope it does hold still works, so the refusal is about the write scope alone.
    expect(botDataCall($token, 'db_schema', ['id' => BOT_DATA_ARTIFACT_ID])->json('result.isError'))->toBeFalse();
});

test('a bot with data:write is refused on an artifact it cannot edit, and writes where it can', function () {
    $team = Team::factory()->create(['plan' => Plan::Team, 'slug' => 'bot-data-edit']);
    botDataReleaseArtifactDb([$team]);
    $served = [];
    botDataFakeWorker([
        BOT_DATA_ARTIFACT_ID => ['org' => 'bot-data-edit', 'edit_access' => 'view', 'owner' => 'someone-else'],
        BOT_DATA_OTHER_ARTIFACT_ID => ['org' => 'bot-data-edit', 'edit_access' => 'edit', 'owner' => 'someone-else'],
    ], $served);
    $token = botDataMintToken($team);
    $write = fn (string $artifactId): TestResponse => botDataCall($token, 'db_set', [
        'id' => $artifactId, 'collection' => 'cards', 'doc_id' => 'c1', 'data' => ['column' => 'done'],
    ]);

    $refused = $write(BOT_DATA_ARTIFACT_ID);

    expect($refused->json('result.isError'))->toBeTrue()
        ->and($refused->json('result.structuredContent.code'))->toBe('forbidden');
    // The Worker answered that one write and recorded nothing: no success came back.
    expect($refused->json('result.structuredContent.version'))->toBeNull();

    $allowed = $write(BOT_DATA_OTHER_ARTIFACT_ID);
    expect($allowed->json('result.isError'))->toBeFalse()
        ->and($allowed->json('result.structuredContent.version'))->toBe(1);
});

test('a bot token from one team cannot read another team\'s artifact data', function () {
    $teamA = Team::factory()->create(['plan' => Plan::Team, 'slug' => 'bot-data-team-a']);
    $teamB = Team::factory()->create(['plan' => Plan::Team, 'slug' => 'bot-data-team-b']);
    botDataReleaseArtifactDb([$teamA, $teamB]);
    $served = [];
    botDataFakeWorker([BOT_DATA_ARTIFACT_ID => ['org' => 'bot-data-team-b', 'edit_access' => 'edit', 'owner' => 'someone-else']], $served);
    $tokenA = botDataMintToken($teamA);

    $schema = botDataCall($tokenA, 'db_schema', ['id' => BOT_DATA_ARTIFACT_ID]);
    $rows = botDataCall($tokenA, 'db_list', ['id' => BOT_DATA_ARTIFACT_ID, 'collection' => 'cards']);
    $write = botDataCall($tokenA, 'db_set', [
        'id' => BOT_DATA_ARTIFACT_ID, 'collection' => 'cards', 'doc_id' => 'c1', 'data' => ['column' => 'done'],
    ]);

    foreach ([$schema, $rows, $write] as $response) {
        expect($response->json('result.isError'))->toBeTrue()
            ->and($response->json('result.structuredContent.code'))->toBe('not_found')
            ->and(botDataText($response))->not->toContain('bot-data-team-b card');
    }
    expect($served)->toBe([]);

    // Every call the Worker saw carried team A's workspace, never team B's.
    expect(OrgJwtService::default()->verify($tokenA)['org_id'])->toBe('bot-data-team-a');
    expect(collect(botDataWorkerRequests())->every(
        fn (Request $request): bool => $request->hasHeader('Authorization', 'Bearer '.$tokenA),
    ))->toBeTrue();
});

test('a bot cannot reach the db tools of a team the artifact-db feature is not released to', function () {
    $released = Team::factory()->create(['plan' => Plan::Team, 'slug' => 'bot-data-released']);
    $unreleased = Team::factory()->create(['plan' => Plan::Team, 'slug' => 'bot-data-unreleased']);
    botDataReleaseArtifactDb([$released]);
    $served = [];
    botDataFakeWorker([BOT_DATA_ARTIFACT_ID => ['org' => 'bot-data-unreleased', 'edit_access' => 'edit', 'owner' => 'someone-else']], $served);
    $token = botDataMintToken($unreleased);

    $response = botDataCall($token, 'db_schema', ['id' => BOT_DATA_ARTIFACT_ID]);

    expect($response->json('error.code'))->not->toBeNull()
        ->and($served)->toBe([]);
    expect(botDataWorkerRequests())->toBe([]);
});

test('a team on the Free plan cannot add a bot teammate or mint a bot token', function () {
    $team = Team::factory()->create(['plan' => Plan::Free, 'slug' => 'bot-data-free']);
    $admin = memberOfTeam($team, TeamRole::Admin);

    test()->actingAs($admin)
        ->postJson(route('teams.bots.store', $team), [
            'name' => 'Hoku',
            'scopes' => [McpScope::ArtifactsRead->value, McpScope::DataRead->value, McpScope::DataWrite->value],
        ])
        ->assertForbidden()
        ->assertJsonPath('errorCode', 'plan_required');

    expect(User::query()->where('is_bot', true)->exists())->toBeFalse()
        ->and(OrgToken::query()->where('kind', OrgTokenKind::Agent)->exists())->toBeFalse();
});

test('a bot token loses db access when its team drops to the Free plan', function () {
    $team = Team::factory()->create(['plan' => Plan::Team, 'slug' => 'bot-data-downgrade']);
    botDataReleaseArtifactDb([$team]);
    $served = [];
    botDataFakeWorker([BOT_DATA_ARTIFACT_ID => ['org' => 'bot-data-downgrade', 'edit_access' => 'edit', 'owner' => 'someone-else']], $served);
    $token = botDataMintToken($team);

    expect(botDataCall($token, 'db_schema', ['id' => BOT_DATA_ARTIFACT_ID])->json('result.isError'))->toBeFalse();

    $team->forceFill(['plan' => Plan::Free])->save();
    $before = count(botDataWorkerRequests());

    test()->withToken($token)->postJson('/mcp', [
        'jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/call',
        'params' => ['name' => 'db_schema', 'arguments' => ['id' => BOT_DATA_ARTIFACT_ID]],
    ])
        ->assertForbidden()
        ->assertJsonPath('error.data.artfct.errorCode', 'plan_required');

    expect(count(botDataWorkerRequests()))->toBe($before);
});
