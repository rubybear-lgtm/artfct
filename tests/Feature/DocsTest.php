<?php

use App\Mcp\Servers\ArtfctServer;
use App\Support\AiToolSetup;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Mcp\Server\Attributes\Name;

/**
 * The docs page copy plus the setup data it renders, whitespace-collapsed, so
 * copy assertions hold whether a sentence lives in the page or in AiToolSetup.
 */
function docsCopy(): string
{
    $setup = json_encode(
        AiToolSetup::forCurrentEnvironment()->toArray(),
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
    );

    return preg_replace('/\s+/', ' ', file_get_contents(resource_path('js/pages/docs.tsx')).$setup);
}

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
    config(['app.url' => 'https://staging.artfct.dev']);

    $this->get(route('docs'))
        ->assertOk()
        ->assertInertia(fn (Assert $page): Assert => $page
            ->where('setup.mcpUrl', 'https://staging.artfct.dev/mcp')
            ->where('setup.llmsFullUrl', 'https://staging.artfct.dev/llms-full.txt')
            ->where('setup.guides.0.id', 'connect-claude-code')
            ->where('setup.guides.0.steps.0.code', 'claude mcp add --transport http artfct https://staging.artfct.dev/mcp')
            ->where('setup.quickInstall', 'npx add-mcp@latest https://staging.artfct.dev/mcp --name artfct --global'));

    expect(file_get_contents(resource_path('js/pages/docs.tsx')))
        ->toContain('<CodeBlock code={setup.mcpUrl} />')
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
        ->and(docsCopy())->toContain('120 requests per minute')
        ->and(docsCopy())->toContain('90 days by default');
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
    $page = docsCopy();
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
    $setup = new AiToolSetup('https://staging.artfct.dev');
    $guides = collect($setup->guides())->keyBy('id');
    $codes = fn (string $id): array => collect($guides[$id]['steps'])->pluck('code')->filter()->values()->all();

    expect($guides->where('verified', true)->keys()->all())
        ->toBe(['connect-claude-code', 'connect-codex', 'connect-opencode', 'connect-antigravity'])
        ->and($codes('connect-claude-code'))->toContain('claude mcp add --transport http artfct https://staging.artfct.dev/mcp')
        ->and($codes('connect-codex'))->toBe(['codex mcp add artfct --url https://staging.artfct.dev/mcp', 'codex mcp login artfct'])
        ->and($codes('connect-opencode'))->toBe(['opencode mcp add artfct --url https://staging.artfct.dev/mcp', 'opencode mcp auth artfct'])
        ->and($guides['connect-opencode']['steps'][1]['text'])->toContain('select Approve once')
        ->and($codes('connect-antigravity')[0])->toBe('agy mcp add artfct https://staging.artfct.dev/mcp')
        ->and(json_decode($codes('connect-antigravity')[1], true))->toBe(['artfct' => ['serverUrl' => 'https://staging.artfct.dev/mcp', 'oauth' => []]])
        ->and($guides['connect-antigravity']['steps'][1]['text'])->toContain('~/.gemini/config/mcp_config.json');

    expect(docsCopy())
        ->toContain('Which Artfct workspace am I connected to?')
        ->toContain('claude mcp remove artfct');
});

test('setup guides for tools we have not verified are labelled as untested', function () {
    $guides = collect((new AiToolSetup('https://artfct.dev'))->guides())->keyBy('id');

    expect($guides->where('verified', false)->keys()->all())
        ->toBe(['connect-claude', 'connect-chatgpt', 'connect-cursor', 'connect-vscode', 'connect-windsurf', 'connect-zed', 'connect-other'])
        ->and(collect($guides['connect-cursor']['steps'])->pluck('code')->filter()->all())->toContain('cursor-agent mcp login artfct')
        ->and(json_decode($guides['connect-vscode']['steps'][0]['code'], true))->toBe(['servers' => ['artfct' => ['type' => 'http', 'url' => 'https://artfct.dev/mcp']]])
        ->and(json_decode($guides['connect-windsurf']['steps'][0]['code'], true))->toBe(['mcpServers' => ['artfct' => ['serverUrl' => 'https://artfct.dev/mcp']]]);

    expect(file_get_contents(resource_path('js/pages/docs.tsx')))
        ->toContain('{!guide.verified && (')
        ->toContain('Not yet tested by us');
});

test('the documented actions match the tools the server registers, minus the deprecated one', function () {
    $registered = collect((new ReflectionClass(ArtfctServer::class))->getProperty('tools')->getDefaultValue())
        ->map(fn (string $tool): string => (new ReflectionClass($tool))->getAttributes(Name::class)[0]->getArguments()[0])
        ->reject(fn (string $name): bool => $name === 'deploy_to_canvas')
        ->sort()
        ->values()
        ->all();

    $documented = collect((new AiToolSetup('https://artfct.dev'))->tools())->pluck('name')->sort()->values()->all();

    expect($documented)->toBe($registered);
});

test('every documented action needs a permission the docs explain', function () {
    $setup = new AiToolSetup('https://artfct.dev');
    $permissions = collect($setup->permissions())->pluck('name')->push('Any');

    foreach ($setup->tools() as $tool) {
        expect($permissions)->toContain($tool['permission']);
    }
});

test('llms.txt indexes the docs for AI tools', function () {
    config(['app.url' => 'https://staging.artfct.dev']);

    $response = $this->get('/llms.txt');

    $response->assertOk()->assertHeader('Content-Type', 'text/plain; charset=UTF-8');

    expect($response->getContent())
        ->toStartWith("# Artfct\n\n> ")
        ->toContain('https://staging.artfct.dev/mcp')
        ->toContain('(https://staging.artfct.dev/llms-full.txt)')
        ->toContain('(https://staging.artfct.dev/docs#mcp)')
        ->toContain('(https://staging.artfct.dev/docs#rest-api)');
});

test('llms-full.txt carries every setup guide, action and endpoint the docs page shows', function () {
    config(['app.url' => 'https://staging.artfct.dev']);
    $setup = AiToolSetup::forCurrentEnvironment();

    $response = $this->get('/llms-full.txt');

    $response->assertOk()->assertHeader('Content-Type', 'text/plain; charset=UTF-8');
    $body = $response->getContent();

    expect($body)
        ->toContain($setup->quickInstall())
        ->toContain($setup->projectInstructions())
        ->toContain('### ChatGPT (not yet tested by Artfct)')
        ->not->toContain('### Codex (not yet tested')
        ->not->toContain('deploy_to_canvas');

    foreach ($setup->guides() as $guide) {
        expect($body)->toContain("### {$guide['label']}");

        foreach ($guide['steps'] as $step) {
            expect($body)->toContain($step['text']);

            foreach (explode("\n", $step['code'] ?? '') as $line) {
                expect($body)->toContain($line);
            }
        }
    }

    foreach ([...$setup->tools(), ...$setup->permissions(), ...$setup->troubleshooting(), ...$setup->skills()] as $row) {
        expect($body)->toContain($row['name']);
    }

    foreach (['GET /v1/artifacts', 'POST /v1/artifacts', 'POST /v1/search'] as $endpoint) {
        expect($body)->toContain("`{$endpoint}");
    }
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
