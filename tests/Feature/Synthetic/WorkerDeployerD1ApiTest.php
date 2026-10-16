<?php

use App\Services\Synthetic\WorkerDeployer;
use App\Services\Synthetic\WorkerTarget;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

function d1ApiDeployer(): WorkerDeployer
{
    return new WorkerDeployer(new WorkerTarget(
        orgSlug: 'zz-northwind',
        url: 'https://worker.example',
        token: 'worker-token',
        governanceSecret: null,
        limitsSecret: null,
        persistTo: null,
        remote: true,
        wranglerConfig: 'wrangler.staging.jsonc',
    ));
}

function runD1ApiBackdate(array $statements): void
{
    $method = new ReflectionMethod(WorkerDeployer::class, 'backdateThroughD1Api');
    $method->invoke(d1ApiDeployer(), $statements);
}

test('backdating_without_wrangler_sends_one_d1_batch_with_the_configs_database_id', function () {
    config([
        'services.cloudflare.account_id' => 'acct123',
        'services.cloudflare.api_token' => 'cf-token',
        'synthetic.cloudflare_api_token' => null,
    ]);
    Http::fake(['api.cloudflare.com/*' => Http::response(['success' => true, 'result' => []])]);

    runD1ApiBackdate(["UPDATE artifacts SET created_at = 'x' WHERE id = 'a'", "UPDATE artifacts SET created_at = 'y' WHERE id = 'b'"]);

    Http::assertSent(fn (Request $request): bool => $request->hasHeader('Authorization', 'Bearer cf-token')
        && str_contains($request->url(), '/accounts/acct123/d1/database/9d483c7e-1709-4bfb-918d-e9570064c813/query')
        && count($request['batch']) === 2
        && $request['batch'][0]['sql'] === "UPDATE artifacts SET created_at = 'x' WHERE id = 'a'");
    Http::assertSentCount(1);
});

test('a_dedicated_seed_token_wins_over_the_app_token', function () {
    config([
        'services.cloudflare.account_id' => 'acct123',
        'services.cloudflare.api_token' => 'app-token',
        'synthetic.cloudflare_api_token' => 'seed-token',
    ]);
    Http::fake(['api.cloudflare.com/*' => Http::response(['success' => true, 'result' => []])]);

    runD1ApiBackdate(["UPDATE artifacts SET created_at = 'x' WHERE id = 'a'"]);

    Http::assertSent(fn (Request $request): bool => $request->hasHeader('Authorization', 'Bearer seed-token'));
});

test('a_failed_d1_call_raises_instead_of_leaving_the_corpus_half_backdated', function () {
    config(['services.cloudflare.account_id' => 'acct123', 'services.cloudflare.api_token' => 'cf-token', 'synthetic.cloudflare_api_token' => null]);
    Http::fake(['api.cloudflare.com/*' => Http::response(['success' => false, 'errors' => [['message' => 'nope']]], 403)]);

    expect(fn () => runD1ApiBackdate(["UPDATE artifacts SET created_at = 'x' WHERE id = 'a'"]))
        ->toThrow(RuntimeException::class, 'D1 API backdating failed: HTTP 403');
});

test('backdating_without_credentials_explains_what_is_missing', function () {
    config(['services.cloudflare.account_id' => null, 'services.cloudflare.api_token' => null, 'synthetic.cloudflare_api_token' => null]);
    Http::fake();

    expect(fn () => runD1ApiBackdate(["UPDATE artifacts SET created_at = 'x' WHERE id = 'a'"]))
        ->toThrow(RuntimeException::class, 'needs the wrangler config');
    Http::assertNothingSent();
});
