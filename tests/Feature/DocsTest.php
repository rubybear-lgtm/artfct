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

test('the hand-written MCP client config on the docs page is valid JSON that starts the server', function () {
    $page = file_get_contents(resource_path('js/pages/docs.tsx'));

    expect(preg_match('/code=\{`(\{\s*"mcpServers".*?\})`\}/s', $page, $matches))->toBe(1);

    $config = json_decode($matches[1], true, flags: JSON_THROW_ON_ERROR);

    expect($config['mcpServers']['artfct']['command'])->toBe('artfct')
        ->and($config['mcpServers']['artfct']['args'])->toContain('mcp', 'serve', '--host');
});

test('the docs page states the hosted rate limit and activity retention the app enforces', function () {
    $page = file_get_contents(resource_path('js/pages/docs.tsx'));

    expect(config('auth.mcp_throttle_per_minute'))->toBe(120)
        ->and(config('auth.mcp_activity_retention_days'))->toBe(90)
        ->and($page)->toContain('120 per minute')
        ->and($page)->toContain('90 days by default');
});
