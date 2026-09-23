<?php

use App\Mcp\Servers\ArtfctServer;
use App\Services\Artifacts\ArtifactViewLink;
use Laravel\Mcp\Server\Transport\FakeTransporter;

test('the hosted MCP catalog exposes the stable cross-transport contract', function () {
    $server = app(ArtfctServer::class, ['transport' => new FakeTransporter]);
    $server->start();

    $tools = $server->createContext()->tools()->mapWithKeys(
        fn ($tool): array => [$tool->name() => $tool->toArray()]
    );

    $expectedContracts = [
        'deploy_artifact' => [
            'scopes' => ['artifacts:deploy'],
            'annotations' => [
                'readOnlyHint' => false,
                'idempotentHint' => true,
                'destructiveHint' => false,
                'openWorldHint' => true,
            ],
        ],
        'deploy_to_canvas' => [
            'scopes' => ['artifacts:deploy'],
            'compatibility' => 'deprecated',
            'annotations' => [
                'readOnlyHint' => false,
                'idempotentHint' => false,
                'destructiveHint' => false,
                'openWorldHint' => true,
            ],
        ],
        'search_artifacts' => [
            'scopes' => ['artifacts:read'],
            'annotations' => [
                'readOnlyHint' => true,
                'idempotentHint' => true,
                'destructiveHint' => false,
                'openWorldHint' => false,
            ],
        ],
        'get_connection' => [
            'scopes' => [],
            'annotations' => [
                'readOnlyHint' => true,
                'idempotentHint' => true,
                'destructiveHint' => false,
                'openWorldHint' => false,
            ],
        ],
        'get_usage' => [
            'scopes' => ['usage:read'],
            'annotations' => [
                'readOnlyHint' => true,
                'idempotentHint' => true,
                'destructiveHint' => false,
                'openWorldHint' => false,
            ],
        ],
        'get_artifact' => [
            'scopes' => ['artifacts:read'],
            'annotations' => [
                'readOnlyHint' => true,
                'idempotentHint' => true,
                'destructiveHint' => false,
                'openWorldHint' => false,
            ],
        ],
        'list_collections' => [
            'scopes' => ['collections:read'],
            'annotations' => [
                'readOnlyHint' => true,
                'idempotentHint' => true,
                'destructiveHint' => false,
                'openWorldHint' => false,
            ],
        ],
        'create_collection' => [
            'scopes' => ['collections:write'],
            'annotations' => [
                'readOnlyHint' => false,
                'idempotentHint' => false,
                'destructiveHint' => false,
                'openWorldHint' => false,
            ],
        ],
        'add_collection_artifact' => [
            'scopes' => ['collections:write'],
            'annotations' => [
                'readOnlyHint' => false,
                'idempotentHint' => true,
                'destructiveHint' => false,
                'openWorldHint' => false,
            ],
        ],
        'delete_artifact' => [
            'scopes' => ['artifacts:delete'],
            'annotations' => [
                'readOnlyHint' => false,
                'idempotentHint' => true,
                'destructiveHint' => true,
                'openWorldHint' => true,
            ],
        ],
    ];

    expect($tools->keys()->all())->toBe(array_keys($expectedContracts));

    expect($tools->keys()->all())->toEqualCanonicalizing(array_keys($expectedContracts));

    foreach ($expectedContracts as $name => $contract) {
        $tool = $tools->get($name);
        $annotations = $tool['annotations'];
        $metadata = $tool['_meta']['artfct'];

        expect($tool['description'])->not->toBeEmpty()
            ->and($tool['inputSchema']['type'])->toBe('object')
            ->and($tool['inputSchema'])->toHaveKey('properties')
            ->and($annotations)->toBe($contract['annotations'])
            ->and($metadata)->toMatchArray([
                'contractVersion' => '1.0.0',
                'toolVersion' => '1.0.0',
                'owner' => 'artfct-mcp',
                'requiredScopes' => $contract['scopes'],
                'compatibility' => $contract['compatibility'] ?? 'stable',
            ])
            ->and($metadata['examples'])->not->toBeEmpty();

        if (in_array($name, ['get_connection', 'get_usage'], true)) {
            expect($metadata['examples'][0]['arguments'])->toBeObject();
        }
    }
});

/**
 * The artifact view-link contract, asserted on the hosted server against the
 * same fixture `mcp-server/src/mcp.rs` asserts the local stdio server against
 * (`view_url_follows_the_shared_cross_server_contract`). One file, two
 * servers: a change to the field name or to which link a tier gets fails both,
 * instead of letting one side drift while its own tests keep passing.
 */
test('the hosted server keeps the shared cross-server view_url contract', function () {
    $contract = json_decode(
        (string) file_get_contents(base_path('tests/Fixtures/artifact-view-link-contract.json')),
        true,
        flags: JSON_THROW_ON_ERROR,
    );

    config(['app.public_base_url' => 'https://artfct.dev']);

    expect($contract['field'])->toBe('view_url')
        ->and($contract['public_path_template'])->toBe('/p/{artifact}')
        ->and($contract['tiers']['public'])->toBe('worker_public_url')
        ->and($contract['tiers']['ephemeral'])->toBe('worker_public_url')
        ->and($contract['tiers']['secure'])->toBe('app_open_route')
        ->and($contract['tiers']['unreadable'])->toBe('app_open_route');

    // The template in the fixture is the template the route actually has, so
    // the local server cannot address a path this app does not serve.
    expect(route('console.open', ['team' => 'acme', 'artifactId' => 'abc'], absolute: false))
        ->toBe(str_replace(['{team}', '{artifactId}'], ['acme', 'abc'], $contract['app_open_path_template']));

    $appOpenUrl = route('console.open', ['team' => 'acme', 'artifactId' => 'abc']);

    // An artifact the Worker serves without a credential — public, or the
    // anonymous ephemeral preview deploy_to_canvas publishes — gets the raw URL.
    // Secure — and any tier a caller could not read — opens through the app's
    // own route, which is what makes a link from an agent work when a member
    // clicks it while signed in.
    expect(ArtifactViewLink::forArtifact('acme', 'abc', 'public'))->toBe('https://artfct.dev/p/abc')
        ->and(ArtifactViewLink::forArtifact('acme', 'abc', 'ephemeral'))->toBe('https://artfct.dev/p/abc')
        ->and(ArtifactViewLink::forArtifact('acme', 'abc', 'secure'))->toBe($appOpenUrl)
        ->and(ArtifactViewLink::forArtifact('acme', 'abc', null))->toBe($appOpenUrl)
        ->and(ArtifactViewLink::forArtifact('acme', 'abc', 'permanent'))->toBe($appOpenUrl);

    // A caller that cannot read a tier still never mints: the app route mints
    // per click, and only for a secure artifact.
    expect(ArtifactViewLink::forArtifact('acme', 'abc', 'public'))->not->toContain('token');
});
