<?php

use App\Mcp\Servers\ArtfctServer;
use Laravel\Mcp\Server\Transport\FakeTransporter;

test('every tool the hosted MCP server registers is listed in the README', function () {
    $server = app(ArtfctServer::class, ['transport' => new FakeTransporter]);
    $server->start();

    $toolNames = $server->createContext()->tools()->map(fn ($tool): string => $tool->name())->all();
    $readme = file_get_contents(base_path('README.md'));

    expect($toolNames)->not->toBeEmpty();

    foreach ($toolNames as $toolName) {
        expect($readme)->toContain("`{$toolName}`");
    }
});

test('the README does not advertise the removed deploy_to_canvas tool', function () {
    $readme = file_get_contents(base_path('README.md'));

    expect($readme)->not->toContain('deploy_to_canvas');
});
