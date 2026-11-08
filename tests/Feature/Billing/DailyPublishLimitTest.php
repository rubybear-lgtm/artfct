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
