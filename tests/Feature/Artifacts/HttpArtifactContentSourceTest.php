<?php

use App\Services\Artifacts\HttpArtifactContentSource;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

/**
 * The server-side content read (RUB-563). The Worker's content response now
 * lists the current bundle's files, so the packer can fetch each one by sha256.
 */
test('the content read returns the bundle file list the Worker sends', function () {
    configureOrgJwt();

    Http::fake([
        'worker.test/v1/orgs/acme/artifacts/abc/content' => Http::response([
            'id' => 'abc',
            'version' => 3,
            'content' => '<h1>Deck</h1>',
            'content_type' => 'text/html',
            'entrypoint' => 'index.html',
            'sharing' => 'team',
            'owner_user_id' => '7',
            'tier' => 'secure',
            'provenance' => ['agent' => null, 'repo_url' => null, 'commit_sha' => null],
            'files' => [
                ['path' => 'app.js', 'sha256' => str_repeat('b', 64), 'content_type' => 'text/javascript', 'size_bytes' => 12],
                ['path' => 'index.html', 'sha256' => str_repeat('a', 64), 'content_type' => 'text/html', 'size_bytes' => 16],
            ],
        ], 200),
    ]);

    $entry = (new HttpArtifactContentSource('https://worker.test'))->fetch('acme', 'abc');

    expect($entry['files'])->toBe([
        ['path' => 'app.js', 'sha256' => str_repeat('b', 64), 'content_type' => 'text/javascript', 'size_bytes' => 12],
        ['path' => 'index.html', 'sha256' => str_repeat('a', 64), 'content_type' => 'text/html', 'size_bytes' => 16],
    ]);
    expect($entry['content_type'])->toBe('text/html');
});

test('the content read reports the file list as unknown when an older Worker omits it', function () {
    configureOrgJwt();

    Http::fake([
        'worker.test/v1/orgs/acme/artifacts/abc/content' => Http::response([
            'id' => 'abc',
            'version' => 3,
            'content' => '<h1>Deck</h1>',
            'sharing' => 'team',
            'owner_user_id' => '7',
            'tier' => 'secure',
            'provenance' => ['agent' => null, 'repo_url' => null, 'commit_sha' => null],
        ], 200),
    ]);

    $entry = (new HttpArtifactContentSource('https://worker.test'))->fetch('acme', 'abc');

    expect($entry)->toHaveKey('files')
        ->and($entry['files'])->toBeNull()
        ->and($entry['content_type'])->toBeNull();
});

test('the content read accepts a shorter request-path timeout without changing the default', function () {
    configureOrgJwt();

    Http::fake([
        'worker.test/v1/orgs/acme/artifacts/abc/content' => Http::response([
            'id' => 'abc',
            'version' => 3,
            'content' => '<h1>Deck</h1>',
            'sharing' => 'team',
            'owner_user_id' => '7',
            'tier' => 'secure',
            'provenance' => ['agent' => null, 'repo_url' => null, 'commit_sha' => null],
        ], 200),
    ]);

    $entry = (new HttpArtifactContentSource('https://worker.test'))->fetch('acme', 'abc', 8);

    expect($entry['version'])->toBe(3);
});
