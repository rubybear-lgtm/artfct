<?php

use App\Contracts\AnalyticsContract;
use App\Contracts\ArtifactDirectory;
use App\Enums\TeamRole;
use App\Models\Team;
use App\Models\User;
use App\Services\Analytics\FakeAnalytics;
use App\Services\Analytics\ProductAnalytics;
use App\Services\Artifacts\FakeArtifactDirectory;
use App\Services\Billing\FakeUsage;
use App\Services\Billing\UsageContract;
use App\Services\Search\SearchService;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config([
        'analytics.enabled' => true,
        'analytics.api_key' => 'phc_test',
        'analytics.environment' => 'local',
        'indexing.enabled' => true,
    ]);

    /** @var FakeAnalytics $analytics */
    $analytics = app(AnalyticsContract::class);
    $analytics->reset();
});

test('B2 artifact_shared fires on web sharing update with source=web', function () {
    configureArtifactLinks();

    $team = Team::factory()->create(['slug' => 'share-analytics']);
    $owner = memberOfTeam($team, TeamRole::Admin);
    $team->forceFill(['owner_user_id' => $owner->id])->save();

    /** @var FakeArtifactDirectory $directory */
    $directory = app(ArtifactDirectory::class);
    $directory->seedArtifact([
        'id' => ARTIFACT_LINK_ID,
        'org_id' => $team->slug,
        'user_id' => (string) $owner->id,
        'owner_user_id' => (string) $owner->id,
        'title' => 'Share analytics',
        'sharing' => 'team',
        'can_change_sharing' => true,
        'content_hash' => md5(ARTIFACT_LINK_ID),
        'created_at' => now()->toIso8601String(),
        'revoked_at' => null,
        'provenance' => ['agent' => null, 'repo_url' => null, 'commit_sha' => null],
    ]);

    test()->actingAs($owner)
        ->patch('/a/'.ARTIFACT_LINK_ID.'/sharing', ['sharing' => 'public'])
        ->assertRedirect('/a/'.ARTIFACT_LINK_ID);

    /** @var FakeAnalytics $analytics */
    $analytics = app(AnalyticsContract::class);
    $events = $analytics->eventsNamed(ProductAnalytics::ARTIFACT_SHARED);

    expect($events)->toHaveCount(1)
        ->and($events[0]['properties']['source'])->toBe('web')
        ->and($events[0]['properties']['team_slug'])->toBe('share-analytics')
        ->and($events[0]['properties']['actor_is_bot'])->toBeFalse()
        ->and($events[0]['properties'])->not->toHaveKey('html')
        ->and($events[0]['properties'])->not->toHaveKey('content')
        ->and($events[0]['distinct_id'])->toBe((string) $owner->id);
});

test('B3 artifact_searched fires from SearchService with query_length and source', function () {
    $team = Team::factory()->create(['slug' => 'search-analytics']);
    $user = memberOfTeam($team, TeamRole::Member);
    $bot = User::factory()->create(['is_bot' => true]);
    $team->memberships()->create(['user_id' => $bot->id, 'role' => TeamRole::Member]);

    app(SearchService::class)->search(
        $team,
        'design partner',
        [],
        10,
        actor: (string) $bot->id,
        source: 'mcp',
    );

    app(SearchService::class)->search(
        $team,
        'web query',
        [],
        10,
        actor: (string) $user->id,
        source: 'web',
    );

    /** @var FakeAnalytics $analytics */
    $analytics = app(AnalyticsContract::class);
    $events = $analytics->eventsNamed(ProductAnalytics::ARTIFACT_SEARCHED);

    expect($events)->toHaveCount(2);

    $mcp = collect($events)->first(fn (array $e): bool => $e['properties']['source'] === 'mcp');
    $web = collect($events)->first(fn (array $e): bool => $e['properties']['source'] === 'web');

    expect($mcp['properties']['query_length'])->toBe(mb_strlen('design partner'))
        ->and($mcp['properties']['result_count'])->toBe(0)
        ->and($mcp['properties']['actor_is_bot'])->toBeTrue()
        ->and($mcp['properties']['team_id'])->toBe($team->id)
        ->and($mcp['properties'])->not->toHaveKey('query')
        ->and($web['properties']['actor_is_bot'])->toBeFalse()
        ->and($web['properties']['source'])->toBe('web');
});

test('B1 artifact_shared fires on real deploy_artifact MCP success path', function () {
    configureArtifactLinks();

    $team = Team::factory()->create(['slug' => 'mcp-share']);
    $token = remoteMcpToken($team);
    $html = '<!doctype html><title>Analytics share</title><p>Hi</p>';
    $sha = hash('sha256', $html);
    config(['services.worker.base_url' => 'https://worker.test']);
    Http::fake([
        'worker.test/v1/orgs/*/usage' => Http::response(['storage_bytes' => 0, 'artifacts_this_period' => 0], 200),
        'worker.test/v1/artifacts' => Http::response([
            'id' => ARTIFACT_LINK_ID,
            'url' => 'https://worker.test/p/'.ARTIFACT_LINK_ID,
            'tier' => 'secure',
            'version' => 1,
            'missing_files' => [$sha],
            'sharing' => 'team',
        ], 201),
        'worker.test/v1/artifacts/'.ARTIFACT_LINK_ID.'/files/*' => Http::response('', 204),
    ]);
    app()->bind(UsageContract::class, FakeUsage::class);

    test()->withToken($token)->postJson('/mcp', [
        'jsonrpc' => '2.0',
        'id' => 1,
        'method' => 'tools/call',
        'params' => ['name' => 'deploy_artifact', 'arguments' => ['html' => $html]],
    ])->assertOk()->assertJsonPath('result.structuredContent.id', ARTIFACT_LINK_ID);

    /** @var FakeAnalytics $analytics */
    $analytics = app(AnalyticsContract::class);
    $events = $analytics->eventsNamed(ProductAnalytics::ARTIFACT_SHARED);

    expect($events)->toHaveCount(1)
        ->and($events[0]['properties']['source'])->toBe('mcp')
        ->and($events[0]['properties']['team_slug'])->toBe('mcp-share')
        ->and($events[0]['properties']['artifact_id'])->toBe(ARTIFACT_LINK_ID)
        ->and($events[0]['properties'])->not->toHaveKey('html')
        ->and($events[0]['properties'])->not->toHaveKey('content');
});
