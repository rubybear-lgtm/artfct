<?php

use App\Enums\Plan;
use App\Enums\TeamRole;
use App\Models\Team;
use App\Services\Auth\OrgJwtService;
use App\Services\Billing\DailyPublishLimiter;
use App\Services\Billing\FakeUsage;
use App\Services\Billing\QuotaExceededException;
use App\Services\Billing\UsageContract;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    Cache::flush();
});

test('daily publish allows under the per-user cap and refuses at the limit', function () {
    config(['billing.plans.free.daily_publish' => ['per_user' => 2, 'per_workspace' => 100]]);
    $team = Team::factory()->create(['plan' => Plan::Free]);
    $limiter = app(DailyPublishLimiter::class);

    $limiter->assertCanPublish($team, '1');
    $limiter->recordPublish($team, '1', 'hash-a');
    $limiter->assertCanPublish($team, '1');
    $limiter->recordPublish($team, '1', 'hash-b');

    expect(fn () => $limiter->assertCanPublish($team, '1'))
        ->toThrow(QuotaExceededException::class);

    try {
        $limiter->assertCanPublish($team, '1');
    } catch (QuotaExceededException $exception) {
        expect($exception->errorCode)->toBe('quota_exceeded')
            ->and($exception->details)->toHaveKeys(['reset_at', 'limit', 'scope'])
            ->and($exception->details['scope'])->toBe('user')
            ->and($exception->details['limit'])->toBe(2);
    }
});

test('daily publish refuses when the workspace cap is hit even if the user has budget', function () {
    config(['billing.plans.free.daily_publish' => ['per_user' => 100, 'per_workspace' => 2]]);
    $team = Team::factory()->create(['plan' => Plan::Free]);
    $limiter = app(DailyPublishLimiter::class);

    $limiter->recordPublish($team, '1', 'hash-a');
    $limiter->recordPublish($team, '2', 'hash-b');

    expect(fn () => $limiter->assertCanPublish($team, '3'))
        ->toThrow(QuotaExceededException::class);

    try {
        $limiter->assertCanPublish($team, '3');
    } catch (QuotaExceededException $exception) {
        expect($exception->details['scope'])->toBe('workspace');
    }
});

test('identical content fingerprint does not consume a second daily publish slot', function () {
    config(['billing.plans.free.daily_publish' => ['per_user' => 2, 'per_workspace' => 100]]);
    $team = Team::factory()->create(['plan' => Plan::Free]);
    $limiter = app(DailyPublishLimiter::class);

    $limiter->recordPublish($team, '1', 'same-bytes');
    $limiter->recordPublish($team, '1', 'same-bytes');

    // Only one slot used despite two record calls with the same fingerprint.
    $limiter->assertCanPublish($team, '1');
    $limiter->recordPublish($team, '1', 'other-bytes');

    expect(fn () => $limiter->assertCanPublish($team, '1'))
        ->toThrow(QuotaExceededException::class);
});

test('deploy_artifact returns quota_exceeded with reset_at when the daily user cap is reached', function () {
    configureArtifactLinks();
    configureSigning(testSigningKey());
    config(['billing.plans.free.daily_publish' => ['per_user' => 1, 'per_workspace' => 100]]);

    $team = Team::factory()->create(['plan' => Plan::Free]);
    $user = memberOfTeam($team, TeamRole::Admin);
    $token = OrgJwtService::default()->mint($team, $user, TeamRole::Admin)['token'];
    $html = '<!doctype html><title>Daily</title><p>one</p>';
    $sha = hash('sha256', $html);
    config(['services.worker.base_url' => 'https://worker.test']);
    Http::fake([
        'worker.test/v1/orgs/*/usage' => Http::response(['storage_bytes' => 0, 'artifacts_this_period' => 0], 200),
        'worker.test/v1/artifacts' => Http::response(['id' => ARTIFACT_LINK_ID, 'url' => 'https://worker.test/p/'.ARTIFACT_LINK_ID, 'tier' => 'secure', 'missing_files' => [$sha]], 201),
        'worker.test/v1/artifacts/'.ARTIFACT_LINK_ID.'/files/*' => Http::response('', 204),
    ]);
    app()->bind(UsageContract::class, FakeUsage::class);

    $this->withToken($token)->postJson('/mcp', [
        'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call',
        'params' => ['name' => 'deploy_artifact', 'arguments' => ['html' => $html]],
    ])->assertOk()->assertJsonPath('result.structuredContent.id', ARTIFACT_LINK_ID);

    $this->withToken($token)->postJson('/mcp', [
        'jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/call',
        'params' => ['name' => 'deploy_artifact', 'arguments' => ['html' => '<!doctype html><title>Daily</title><p>two</p>']],
    ])->assertOk()
        ->assertJsonPath('result.isError', true)
        ->assertJsonPath('result.content.0._meta.artfct.errorCode', 'quota_exceeded')
        ->assertJsonPath('result.content.0._meta.artfct.details.scope', 'user')
        ->assertJsonPath('result.content.0._meta.artfct.details.limit', 1)
        ->assertJsonPath('result.content.0._meta.artfct.details.reset_at', app(DailyPublishLimiter::class)->resetAt()->toIso8601String());

    // FakeUsage skips the Worker usage probe; only create + file upload fire.
    Http::assertSentCount(2);
});

test('version publish with worker 200 identical-content no-op does not burn a daily slot', function () {
    // RUB-516: after created became always-false on versions, quota must key
    // off HTTP 201 (new version written), not the created flag.
    configureArtifactLinks();
    configureSigning(testSigningKey());
    config(['billing.plans.free.daily_publish' => ['per_user' => 2, 'per_workspace' => 100]]);

    $team = Team::factory()->create(['plan' => Plan::Free]);
    $user = memberOfTeam($team, TeamRole::Admin);
    $token = OrgJwtService::default()->mint($team, $user, TeamRole::Admin)['token'];
    $id = ARTIFACT_LINK_ID;
    $htmlV1 = '<!doctype html><title>Daily</title><p>v1</p>';
    $htmlNoop = '<!doctype html><title>Daily</title><p>noop</p>';
    $shaV1 = hash('sha256', $htmlV1);
    config(['services.worker.base_url' => 'https://worker.test']);
    app()->bind(UsageContract::class, FakeUsage::class);
    $limiter = app(DailyPublishLimiter::class);

    Http::fake([
        'worker.test/v1/orgs/*/usage' => Http::response(['storage_bytes' => 0, 'artifacts_this_period' => 0], 200),
        'worker.test/v1/artifacts' => Http::response([
            'id' => $id,
            'version' => 1,
            'url' => 'https://worker.test/p/'.$id,
            'tier' => 'secure',
            'missing_files' => [$shaV1],
        ], 201),
        'worker.test/v1/artifacts/'.$id.'/files/*' => Http::response('', 204),
    ]);

    $this->withToken($token)->postJson('/mcp', [
        'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call',
        'params' => ['name' => 'deploy_artifact', 'arguments' => ['html' => $htmlV1]],
    ])->assertOk()->assertJsonPath('result.structuredContent.id', $id);

    Http::fake([
        'worker.test/v1/artifacts/'.$id => Http::response([
            'id' => $id,
            'version' => 1,
            'updated_at' => '2026-11-01T00:00:00Z',
        ], 200),
        'worker.test/v1/artifacts/'.$id.'/versions' => Http::response([
            'id' => $id,
            'version' => 1,
            'url' => 'https://worker.test/p/'.$id,
            'created' => false,
            'missing_files' => [],
            'tier' => 'secure',
            'sharing' => 'team',
        ], 200),
    ]);

    $this->withToken($token)->postJson('/mcp', [
        'jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/call',
        'params' => [
            'name' => 'deploy_artifact',
            'arguments' => [
                'html' => $htmlNoop,
                'artifact_id' => $id,
                'base_version' => 1,
            ],
        ],
    ])->assertOk()
        ->assertJsonPath('result.isError', false)
        ->assertJsonPath('result.structuredContent.created', false);

    // Count must still be 1 (create only). Cap 1 refuses; cap 2 still allows.
    // If the 200 no-op had counted, cap 2 would already be exhausted.
    config(['billing.plans.free.daily_publish' => ['per_user' => 1, 'per_workspace' => 100]]);
    expect(fn () => $limiter->assertCanPublish($team, (string) $user->id))
        ->toThrow(QuotaExceededException::class);

    config(['billing.plans.free.daily_publish' => ['per_user' => 2, 'per_workspace' => 100]]);
    $limiter->assertCanPublish($team, (string) $user->id);
});

test('daily publish counters reset at the next UTC midnight', function () {
    config(['billing.plans.free.daily_publish' => ['per_user' => 1, 'per_workspace' => 100]]);
    $team = Team::factory()->create(['plan' => Plan::Free]);
    $limiter = app(DailyPublishLimiter::class);

    $this->travelTo(Carbon::parse('2026-10-22 23:00:00', 'UTC'));
    $limiter->recordPublish($team, '1', 'hash-day-one');
    expect(fn () => $limiter->assertCanPublish($team, '1'))
        ->toThrow(QuotaExceededException::class);

    $this->travelTo(Carbon::parse('2026-10-23 00:00:01', 'UTC'));
    $limiter->assertCanPublish($team, '1');
});
