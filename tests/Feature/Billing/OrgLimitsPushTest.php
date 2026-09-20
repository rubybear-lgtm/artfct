<?php

use App\Enums\PaymentStatus;
use App\Models\Team;
use App\Services\Billing\BillingService;
use App\Services\Billing\RealUsage;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config([
        'services.worker.base_url' => 'https://worker.test',
        'services.worker.org_token' => 'org-token',
        'services.worker.limits_write_secret' => 'limits-secret',
    ]);
});

function fakeWorker(Response|PromiseInterface|null $response = null): void
{
    Http::fake(['worker.test/*' => $response ?? Http::response(['org' => 'acme'])]);
}

test('payment_failed_pushes_read_only_to_worker', function () {
    fakeWorker();
    $team = Team::factory()->create(['slug' => 'acme']);

    app(BillingService::class)->applyPaymentFailed($team);

    Http::assertSent(fn (Request $request) => $request->url() === 'https://worker.test/v1/internal/org-limits'
        && $request->hasHeader('Authorization', 'Bearer limits-secret')
        && $request['org'] === 'acme'
        && $request['read_only'] === true);
});

test('payment_succeeded_pushes_restore', function () {
    fakeWorker();
    $team = Team::factory()->create(['slug' => 'acme', 'payment_status' => PaymentStatus::PastDue]);

    app(BillingService::class)->applyPaymentSucceeded($team);

    Http::assertSent(fn (Request $request) => $request->url() === 'https://worker.test/v1/internal/org-limits'
        && $request['read_only'] === false);
});

test('billing_sync_limits_pushes_current_config', function () {
    fakeWorker();
    config(['billing.plans.free' => [
        'storage_bytes' => 1234,
        'artifacts_per_month' => 5,
        'bundle_size_ceiling_bytes' => 99,
        'render_minutes_per_month' => 1,
    ]]);
    Team::factory()->create(['slug' => 'acme']);

    test()->artisan('billing:sync-limits', ['org' => 'acme'])->assertSuccessful();

    Http::assertSent(fn (Request $request) => $request['org'] === 'acme'
        && $request['storage_bytes'] === 1234
        && $request['artifacts_per_month'] === 5
        && $request['bundle_ceiling_bytes'] === 99);
});

test('billing_sync_limits_fails_when_push_is_dropped', function () {
    fakeWorker(Http::response('nope', 401));
    Team::factory()->create(['slug' => 'acme']);

    test()->artisan('billing:sync-limits', ['org' => 'acme'])->assertFailed();
});

test('real_usage_reads_worker_usage_endpoint', function () {
    configureSigning(testSigningKey());
    fakeWorker(Http::response(['storage_bytes' => 2048, 'artifacts_this_period' => 7]));

    expect((new RealUsage)->currentUsage('acme'))->toBe([
        'storage_bytes' => 2048,
        'artifacts_this_period' => 7,
        'render_minutes_this_period' => 0,
    ]);
    Http::assertSent(fn (Request $request) => str_starts_with($request->header('Authorization')[0], 'Bearer eyJ'));
});
