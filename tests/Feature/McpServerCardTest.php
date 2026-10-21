<?php

use App\Mcp\Servers\ArtfctServer;
use App\Support\AiToolSetup;
use Laravel\Mcp\Server\Attributes\Version;

test('the MCP server card names the server, its endpoint and its tools', function () {
    config(['app.url' => 'https://artfct.dev']);

    $response = $this->get('/.well-known/mcp/server-card.json')
        ->assertOk()
        ->assertHeader('Content-Type', 'application/json')
        ->assertHeader('Access-Control-Allow-Origin', '*');

    $version = (new ReflectionClass(ArtfctServer::class))->getAttributes(Version::class)[0]->getArguments()[0];
    $listing = json_decode(file_get_contents(base_path('server.json')), true);

    $response->assertJsonPath('serverInfo.name', 'artfct')
        ->assertJsonPath('serverInfo.version', $version)
        ->assertJsonPath('transport.type', 'streamable-http')
        ->assertJsonPath('transport.endpoint', 'https://artfct.dev/mcp')
        ->assertJsonPath('name', $listing['name'])
        ->assertJsonPath('capabilities.tools.listChanged', false)
        ->assertJsonPath('authentication.protectedResourceMetadata', 'https://artfct.dev/.well-known/oauth-protected-resource/mcp');

    expect(collect($response->json('tools'))->pluck('name')->all())
        ->toBe(collect(AiToolSetup::forCurrentEnvironment()->tools())->pluck('name')->all());
});

test('the server card and the registry listing report the same version', function () {
    $version = (new ReflectionClass(ArtfctServer::class))->getAttributes(Version::class)[0]->getArguments()[0];

    expect(json_decode(file_get_contents(base_path('server.json')), true)['version'])->toBe($version);
});
