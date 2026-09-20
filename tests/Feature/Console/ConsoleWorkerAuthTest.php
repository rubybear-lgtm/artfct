<?php

use App\Services\Artifacts\HttpArtifactDirectory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

test('directory_falls_back_to_the_org_token_when_the_browser_has_no_bearer', function () {
    config(['services.worker.org_token' => 'org-token']);
    Http::fake(['worker.test/*' => Http::response(['artifacts' => [['id' => 'a1']], 'next_cursor' => null])]);

    $result = (new HttpArtifactDirectory('https://worker.test'))->listArtifacts('zz-northwind');

    expect($result['artifacts'])->toHaveCount(1);
    Http::assertSent(fn (Request $request) => $request->hasHeader('Authorization', 'Bearer org-token'));
});

test('an_explicit_bearer_token_wins_over_the_fallback', function () {
    config(['services.worker.org_token' => 'org-token']);
    Http::fake(['worker.test/*' => Http::response(['artifacts' => [], 'next_cursor' => null])]);
    request()->headers->set('Authorization', 'Bearer caller-token');

    (new HttpArtifactDirectory('https://worker.test'))->listArtifacts('zz-northwind');

    Http::assertSent(fn (Request $request) => $request->hasHeader('Authorization', 'Bearer caller-token'));
});

test('no_token_at_all_sends_an_empty_bearer_not_a_fallback_leak', function () {
    config(['services.worker.org_token' => null]);
    Http::fake(['worker.test/*' => Http::response([], 401)]);

    try {
        (new HttpArtifactDirectory('https://worker.test'))->listArtifacts('acme');
    } catch (Throwable) {
    }

    Http::assertSent(fn (Request $request) => ! str_contains((string) $request->header('Authorization')[0], 'org-token'));
});
