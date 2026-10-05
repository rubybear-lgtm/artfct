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
        ->toContain('connectionGuides(hostedMcpUrl, hostedMcpBaseUrl)')
        ->toContain('<CodeBlock code={hostedMcpUrl} />')
        ->toContain('url: mcpUrl')
        ->toContain('claude mcp add --transport http artfct ${mcpUrl}')
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
        ->and(preg_replace('/\s+/', ' ', $page))->toContain('120 requests per minute')
        ->and(preg_replace('/\s+/', ' ', $page))->toContain('90 days by default');
});

test('the MCP docs explain workspace terminology and first-time access', function () {
    $page = preg_replace('/\s+/', ' ', file_get_contents(resource_path('js/pages/docs.tsx')));

    expect($page)
        ->toContain('your organization is called a team')
        ->toContain('your AI tool may also call it a workspace')
        ->toContain('AI tool connections')
        ->toContain('Artfct asks you to create')
        ->toContain('ask its administrator to invite')
        ->toContain('Connecting an AI tool never needs an API token')
        ->toContain('sends one as a bearer token')
        ->toContain('This address belongs to the site you are reading now')
        ->toContain('login.url()')
        ->toContain('terms.url()')
        ->toContain('privacy.url()');
});

test('team wording replaces workspace everywhere outside AI tool terminology', function () {
    $page = preg_replace('/\s+/', ' ', file_get_contents(resource_path('js/pages/docs.tsx')));
    $landing = preg_replace('/\s+/', ' ', file_get_contents(resource_path('js/pages/landing.tsx')));
    $welcome = file_get_contents(resource_path('js/pages/welcome/welcome-agent-prompt.tsx'));
    $callout = file_get_contents(resource_path('js/pages/welcome/welcome-cli-callout.tsx'));

    expect($page)
        ->toContain('Save new artifacts to your team.')
        ->toContain('pick the team on the approval page')
        ->toContain('publish to your team and find what is already there')
        ->not->toContain('team’s workspace')
        ->and($page)->toContain('your AI tool may also call it a workspace')
        ->and($page)->toContain('Which Artfct workspace am I connected to?')
        ->and($landing)->not->toContain('shared workspace')
        ->and($landing)->toContain('A shared library that your team’s AI fills for you.')
        ->and($callout)->not->toContain('WelcomeInstallOptions')
        ->and($welcome)->not->toContain('npx skills add')
        ->and($welcome)->not->toContain('<pre')
        ->and($welcome)->toContain('mcpEndpoint');
});

test('the docs give step-by-step setup for every verified AI tool', function () {
    $page = preg_replace('/\s+/', ' ', file_get_contents(resource_path('js/pages/docs.tsx')));

    expect($page)
        ->toContain("id: 'connect-claude-code'")
        ->toContain('claude mcp add --transport http artfct ${mcpUrl}')
        ->toContain("id: 'connect-codex'")
        ->toContain('codex mcp add artfct --url ${mcpUrl}')
        ->toContain('codex mcp login artfct')
        ->toContain("id: 'connect-opencode'")
        ->toContain('opencode mcp add artfct --url ${mcpUrl}')
        ->toContain('opencode mcp auth artfct')
        ->toContain('Select <strong>Approve</strong> once')
        ->toContain("id: 'connect-antigravity'")
        ->toContain('agy mcp add artfct ${mcpUrl}')
        ->toContain('~/.gemini/config/mcp_config.json')
        ->toContain('oauth: {}')
        ->toContain("id: 'connect-other'")
        ->toContain('cursor-agent mcp login artfct')
        ->toContain('We have not tested these tools yet')
        ->toContain('Which Artfct workspace am I connected to?')
        ->toContain('claude mcp remove artfct');
});

test('the connections page links to the setup guide that exists on the docs page', function () {
    $connections = file_get_contents(resource_path('js/pages/teams/mcp-connections.tsx'));
    $page = file_get_contents(resource_path('js/pages/docs.tsx'));

    expect($connections)
        ->toContain('${docs.url()}#mcp')
        ->not->toContain('#cli')
        ->and($page)->toContain('id="mcp"');
});

test('the free tool page gives the prompt the endpoint of the environment serving it', function () {
    $this->get(route('free'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('welcome')
            ->where('mcpEndpoint', url('/mcp')));
});

/*
 * Guards the tab pattern the way the tests above guard the guide content:
 * the roving tabIndex and arrow keys are client-only, so a source assertion
 * is the only check that fails if someone drops them.
 */
test('the AI tool tabs keep roving focus and arrow-key navigation', function () {
    $page = preg_replace('/\s+/', ' ', file_get_contents(resource_path('js/pages/docs.tsx')));

    expect($page)
        ->toContain('role="tablist"')
        ->toContain('tabIndex={guide.id === current.id ? 0 : -1}')
        ->toContain("case 'ArrowLeft':")
        ->toContain("case 'ArrowRight':")
        ->toContain("case 'Home':")
        ->toContain("case 'End':")
        ->toContain("querySelectorAll<HTMLButtonElement>('[role=\"tab\"]')")
        ->toContain('.focus();');
});
