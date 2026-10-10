<?php

use App\Services\Artifacts\HttpArtifactContentSource;
use App\Services\Auth\OrgJwtService;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/**
 * The content read mints a `system` token. Since the Worker now refuses a
 * private artifact to a credential that does not ask for
 * `artifacts:read_private`, this scope is the whole reason indexing can still
 * see every artifact; if it regresses, private artifacts silently disappear
 * from the index.
 */
test('the_system_content_read_asks_for_the_read_private_scope', function () {
    configureSigning(testSigningKey());
    config(['services.worker.base_url' => 'https://worker.test']);
    Http::fake(['worker.test/*' => Http::response([
        'id' => 'abc',
        'content' => '<h1>Hello</h1>',
        'version' => 3,
        'tier' => 'secure',
        'sharing' => 'private',
        'owner_user_id' => '42',
        'provenance' => ['agent' => 'claude-code', 'repo_url' => null, 'commit_sha' => null],
    ])]);

    $artifact = HttpArtifactContentSource::default()->fetch('acme', 'abc');

    expect($artifact['sharing'])->toBe('private')
        ->and($artifact['owner_user_id'])->toBe('42')
        ->and($artifact['html'])->toBe('<h1>Hello</h1>');

    $token = null;
    Http::assertSent(function (Request $request) use (&$token): bool {
        $token = str_replace('Bearer ', '', $request->header('Authorization')[0]);

        return true;
    });

    expect($token)->toBeString()
        ->and(OrgJwtService::default()->verify($token)['scope'])
        ->toBe('artifacts:read artifacts:read_private');
});

test('a_missing_sharing_or_owner_is_returned_as_null_not_a_guess', function () {
    configureSigning(testSigningKey());
    config(['services.worker.base_url' => 'https://worker.test']);
    Http::fake(['worker.test/*' => Http::response([
        'content' => '<h1>Hello</h1>',
        'version' => 1,
        'tier' => 'secure',
    ])]);

    $artifact = HttpArtifactContentSource::default()->fetch('acme', 'abc');

    expect($artifact['sharing'])->toBeNull()
        ->and($artifact['owner_user_id'])->toBeNull();
});
