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

test('every MCP client config block on the docs page is valid JSON', function () {
    $page = file_get_contents(resource_path('js/pages/docs.tsx'));

    expect(preg_match_all('/code=\{`(\{\s*"mcpServers".*?\})`\}/s', $page, $matches))->toBe(1);

    $configs = array_map(fn (string $block): array => json_decode($block, true, flags: JSON_THROW_ON_ERROR), $matches[1]);
    $byKind = collect($configs)->keyBy(fn (array $config): string => isset($config['mcpServers']['artfct']['url']) ? 'hosted' : 'local');

    expect($byKind['local']['mcpServers']['artfct']['command'])->toBe('artfct')
        ->and($byKind['local']['mcpServers']['artfct']['args'])->toContain('mcp', 'serve', '--host')
        ->and($page)
        ->toContain('const hostedMcpBaseUrl = contract.servers[0].url;')
        ->toContain('const hostedMcpUrl = `${hostedMcpBaseUrl}/mcp`;')
        ->toContain('url: hostedMcpUrl')
        ->toContain('claude mcp add --transport http artfct ${hostedMcpUrl}');
});

test('the docs page states the hosted rate limit and activity retention the app enforces', function () {
    $page = file_get_contents(resource_path('js/pages/docs.tsx'));

    expect(config('auth.mcp_throttle_per_minute'))->toBe(120)
        ->and(config('auth.mcp_activity_retention_days'))->toBe(90)
        ->and($page)->toContain('120 per minute')
        ->and($page)->toContain('90 days by default');
});

test('the doctor output shown on the docs page matches what the CLI prints', function () {
    $page = file_get_contents(resource_path('js/pages/docs.tsx'));
    $doctor = file_get_contents(base_path('mcp-server/src/doctor.rs'));

    foreach ([
        'configured (value hidden)',
        'Selected: ',
        'Available organizations: ',
        'Connected: ',
        'MCP health check failed',
        'Run `artfct login --oauth` to refresh access',
    ] as $fragment) {
        expect($doctor)->toContain($fragment);
    }

    foreach (['configured (value hidden)', 'Selected: acme', 'Available organizations: acme', 'Connected: artfct', 'MCP health check failed'] as $shown) {
        expect($page)->toContain($shown);
    }
});

test('the MCP docs explain workspace terminology and first-time access', function () {
    $page = preg_replace('/\s+/', ' ', file_get_contents(resource_path('js/pages/docs.tsx')));

    expect($page)
        ->toContain('an organization is called a team')
        ->toContain('the CLI and OAuth protocol also call it a workspace')
        ->toContain('Artfct asks you to create')
        ->toContain('ask its administrator to invite')
        ->toContain('Hosted MCP uses browser sign-in')
        ->toContain('ARTFCT_ORG_TOKEN')
        ->toContain('These URLs point to the environment serving this page')
        ->toContain('login.url()')
        ->toContain('terms.url()')
        ->toContain('privacy.url()')
        ->toContain('claude mcp get artfct')
        ->toContain('cursor-agent mcp login artfct')
        ->toContain('Hosted-client sign-in is separate from the local CLI session');
});
