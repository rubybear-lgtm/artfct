<?php

use Inertia\Testing\AssertableInertia as Assert;

test('docs page renders the generated OpenAPI contract', function () {
    $response = $this->get(route('docs'));

    $response
        ->assertOk()
        ->assertInertia(fn (Assert $page): Assert => $page
            ->component('docs')
            ->where('contract.openapi', '3.1.0')
            ->has('contract.paths'));

    $contract = $response->inertiaProps('contract');
    $source = json_decode(
        file_get_contents(base_path('openapi/artfct.yaml')),
        true,
        flags: JSON_THROW_ON_ERROR,
    );

    $source['servers'] = [[
        'url' => config('app.url'),
    ]];

    expect($contract)
        ->toBe($source)
        ->and($contract['paths'])
        ->toHaveKeys([
            '/v1/artifacts',
            '/v1/artifacts/{id}',
            '/p/{id}',
            '/v1/search',
        ]);
});

test('hosted MCP docs and API reference use the URL configured for the current environment', function (string $baseUrl) {
    config(['app.url' => $baseUrl]);

    $this->get(route('docs'))
        ->assertOk()
        ->assertInertia(fn (Assert $page): Assert => $page
            ->component('docs')
            ->where('contract.servers.0.url', $baseUrl));
})->with([
    'staging' => 'https://staging.artfct.dev',
    'production' => 'https://artfct.dev',
]);

test('the MCP config block on the docs page is the hosted URL only', function () {
    $page = file_get_contents(resource_path('js/pages/docs.tsx'));

    expect($page)
        ->toContain('contract.servers[0].url.replace')
        ->toContain('const hostedMcpUrl = `${hostedMcpBaseUrl}/mcp`;')
        ->toContain('url: hostedMcpUrl')
        ->toContain('claude mcp add --transport http artfct ${hostedMcpUrl}')
        ->not->toContain('"command": "artfct"');
});

test('the docs document only the hosted server', function () {
    $page = file_get_contents(resource_path('js/pages/docs.tsx'));

    expect($page)
        ->not->toContain('install.sh')
        ->not->toContain('artfct deploy')
        ->not->toContain('artfct setup')
        ->not->toContain('artfct mcp serve')
        ->not->toContain('artfct doctor')
        ->not->toContain('id="cli"')
        ->not->toContain('optional command line');
});

test('connection guidance is hosted only and states its limits', function () {
    $page = file_get_contents(resource_path('js/pages/docs.tsx'));
    $welcome = file_get_contents(resource_path('js/pages/welcome/welcome-agent-prompt.tsx'));
    $connections = file_get_contents(resource_path('js/pages/teams/mcp-connections.tsx'));

    expect($welcome)->not->toContain('artfct setup')
        ->and($welcome)->not->toContain('install.sh')
        ->and($welcome)->not->toContain('https://artfct.dev/mcp')
        ->and($welcome)->toContain('mcpEndpoint')
        ->and($connections)->not->toContain('Local CLI')
        ->and($connections)->not->toContain('install.sh')
        ->and($connections)->not->toContain('artfct setup')
        ->and($connections)->not->toContain('artfct login');

    expect(config('auth.mcp_throttle_per_minute'))->toBe(120)
        ->and(config('auth.mcp_activity_retention_days'))->toBe(90)
        ->and($page)->toContain('120 per minute')
        ->and($page)->toContain('90 days by default');
});

test('the MCP docs explain workspace terminology and first-time access', function () {
    $page = preg_replace('/\s+/', ' ', file_get_contents(resource_path('js/pages/docs.tsx')));

    expect($page)
        ->toContain('an organization is called a team')
        ->toContain('the OAuth protocol also calls it a workspace')
        ->toContain('Artfct asks you to create')
        ->toContain('ask its administrator to invite')
        ->toContain('Hosted MCP uses browser sign-in')
        ->toContain('send it as a bearer token')
        ->toContain('These URLs point to the environment serving this page')
        ->toContain('login.url()')
        ->toContain('terms.url()')
        ->toContain('privacy.url()')
        ->toContain('claude mcp get artfct')
        ->toContain('cursor-agent mcp login artfct');
});

test('the free tool page gives the prompt the endpoint of the environment serving it', function () {
    $this->get(route('free'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('welcome')
            ->where('mcpEndpoint', url('/mcp')));
});
