<?php

use App\Mcp\Servers\ArtfctServer;
use Laravel\Mcp\Server\Transport\FakeTransporter;

test('the hosted MCP catalog exposes the stable cross-transport contract', function () {
    $server = app(ArtfctServer::class, ['transport' => new FakeTransporter]);
    $server->start();

    $tools = $server->createContext()->tools()->mapWithKeys(
        fn ($tool): array => [$tool->name() => $tool->toArray()]
    );

    $expectedContracts = [
        'deploy_to_canvas' => [
            'scopes' => ['artifacts:deploy'],
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
    ];

    expect($tools->keys()->all())->toBe(array_keys($expectedContracts));

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
                'compatibility' => 'stable',
            ])
            ->and($metadata['examples'])->not->toBeEmpty();

        if (in_array($name, ['get_connection', 'get_usage'], true)) {
            expect($metadata['examples'][0]['arguments'])->toBeObject();
        }
    }
});
