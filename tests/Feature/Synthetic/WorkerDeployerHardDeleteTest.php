<?php

use App\Services\Synthetic\WorkerDeployer;
use App\Services\Synthetic\WorkerTarget;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;

function hardDeleteDeployer(): WorkerDeployer
{
    return new WorkerDeployer(new WorkerTarget(
        orgSlug: 'zz-northwind',
        url: 'https://worker.example',
        token: 'worker-token',
        governanceSecret: 'gov-secret',
        limitsSecret: null,
        persistTo: null,
        remote: true,
    ));
}

test('hard_delete_treats_an_already_deleted_artifact_as_success', function () {
    Http::fake(['worker.example/*' => Http::response(['error' => ['code' => 'artifact_not_found']], 404)]);

    hardDeleteDeployer()->hardDelete('gone');

    Http::assertSentCount(1);
});

test('hard_delete_still_fails_on_other_errors', function () {
    Http::fake(['worker.example/*' => Http::response(['error' => 'boom'], 500)]);

    expect(fn () => hardDeleteDeployer()->hardDelete('broken'))->toThrow(RequestException::class);
});
