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

test('one-click install links carry the hosted MCP URL', function () {
    $setup = new AiToolSetup('https://staging.artfct.dev');

    $cursorConfig = json_decode((string) base64_decode(substr($setup->cursorInstallLink(), strpos($setup->cursorInstallLink(), 'config=') + 7)), true);
    $vscodeConfig = json_decode(urldecode(substr($setup->vscodeInstallLink(), strpos($setup->vscodeInstallLink(), '?') + 1)), true);

    expect($setup->cursorInstallLink())->toStartWith('cursor://anysphere.cursor-deeplink/mcp/install?name=artfct&config=')
        ->and($cursorConfig)->toBe(['url' => 'https://staging.artfct.dev/mcp'])
        ->and($setup->cursorWebInstallLink())->toStartWith('https://cursor.com/install-mcp?')
        ->and($setup->vscodeInstallLink())->toStartWith('vscode:mcp/install?')
        ->and($vscodeConfig)->toBe(['name' => 'artfct', 'type' => 'http', 'url' => 'https://staging.artfct.dev/mcp'])
        ->and($setup->vscodeWebInstallLink())->toStartWith('https://vscode.dev/redirect/mcp/install?');

    $guides = collect($setup->guides())->keyBy('id');

    expect($guides['connect-cursor']['installLink'])->toBe($setup->cursorInstallLink())
        ->and($guides['connect-vscode']['installLink'])->toBe($setup->vscodeInstallLink());

    config(['app.url' => 'https://staging.artfct.dev']);

    $this->get(route('docs'))
        ->assertOk()
        ->assertInertia(fn (Assert $page): Assert => $page
            ->where('setup.cursorInstallLink', $setup->cursorInstallLink())
            ->where('setup.vscodeInstallLink', $setup->vscodeInstallLink()));

    expect($this->get('/llms-full.txt')->getContent())
        ->toContain($setup->cursorInstallLink())
        ->toContain($setup->vscodeWebInstallLink());
});

test('the agent setup prompt connects, verifies and teaches the project, leaving sign-in to the person', function () {
    $setup = new AiToolSetup('https://staging.artfct.dev');

    expect($setup->agentSetupPrompt())
        ->toContain("\n\n{\n    \"mcpServers\": {\n        \"artfct\": {\n            \"type\": \"http\",\n            \"url\": \"https://staging.artfct.dev/mcp\"\n        }\n    }\n}\n\n")
        ->toContain('Work out which app you are running in')
        ->toContain('npx skills add rubybear-lgtm/artfct@artfct-init')
        ->toContain('all my projects, just me in this project, or the whole team in this repo')
        ->toContain('offer to help connect them to the same workspace')
        ->not->toContain('not yet tested')
        ->toContain('https://staging.artfct.dev/mcp')
        ->toContain('get_connection')
        ->toContain('CLAUDE.md')
        ->toContain('AGENTS.md')
        ->toContain('restart')
        ->toContain('Never ask me for a password or a token')
        ->toContain('Connected to the wrong team')
        ->toContain($setup->projectInstructions())
        ->toContain('https://staging.artfct.dev/llms-full.txt')
        ->and($setup->projectInstructions())
        ->toContain('base_version')
        ->and($setup->agentInstructionLine())
        ->toContain('search_artifacts')
        ->toContain('deploy_artifact');

    config(['app.url' => 'https://staging.artfct.dev']);

    $this->get(route('docs'))
        ->assertOk()
        ->assertInertia(fn (Assert $page): Assert => $page
            ->where('setup.agentSetupPrompt', $setup->agentSetupPrompt())
            ->where('setup.agentInstructionLine', $setup->agentInstructionLine()));

    expect($this->get('/llms-full.txt')->getContent())
        ->toContain($setup->agentSetupPrompt());
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
        ->toContain('agent token')
        ->toContain('Agent &amp; API tokens')
        ->toContain('connect-agent-token')
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
        ->and($landing)->toContain('The team knowledge base for every AI tool')
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

test('setup guides for tools we have not verified end to end carry their steps without a public untested label', function () {
    $guides = collect((new AiToolSetup('https://artfct.dev'))->guides())->keyBy('id');

    expect($guides->where('verified', false)->keys()->all())
        ->toBe(['connect-omp', 'connect-claude', 'connect-chatgpt', 'connect-cursor', 'connect-vscode', 'connect-windsurf', 'connect-zed', 'connect-other'])
        ->and(json_decode(collect($guides['connect-omp']['steps'])->pluck('code')->filter()->values()->all()[0], true))->toBe(['mcpServers' => ['artfct' => ['type' => 'http', 'url' => 'https://artfct.dev/mcp']]])
        ->and($guides['connect-omp']['steps'][0]['text'])->toContain('~/.omp/agent/mcp.json')
        ->and(collect($guides['connect-cursor']['steps'])->pluck('code')->filter()->all())->toContain('cursor-agent mcp login artfct')
        ->and(json_decode($guides['connect-vscode']['steps'][0]['code'], true))->toBe(['servers' => ['artfct' => ['type' => 'http', 'url' => 'https://artfct.dev/mcp']]])
        ->and(json_decode($guides['connect-windsurf']['steps'][0]['code'], true))->toBe(['mcpServers' => ['artfct' => ['serverUrl' => 'https://artfct.dev/mcp']]]);

    expect(file_get_contents(resource_path('js/pages/docs.tsx')))
        ->not->toContain('{!guide.verified && (')
        ->not->toContain('Not yet tested by us');

    expect(file_get_contents(resource_path('js/pages/connect-show.tsx')))
        ->not->toContain('verified end to end');
});

test('the documented actions match the tools the server registers', function () {
    $registered = collect((new ReflectionClass(ArtfctServer::class))->getProperty('tools')->getDefaultValue())
        ->map(fn (string $tool): string => (new ReflectionClass($tool))->getAttributes(Name::class)[0]->getArguments()[0])
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
        ->toContain("### ChatGPT\n")
        ->not->toContain('not yet tested')
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

    $agentToken = $setup->agentTokenSetup();
    expect($body)->toContain("### {$agentToken['title']}")
        ->toContain($agentToken['intro']);
    foreach ($agentToken['steps'] as $step) {
        expect($body)->toContain($step['text']);
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

test('every JSON snippet in the setup guides is pretty-printed', function () {
    $snippets = collect((new AiToolSetup('https://artfct.dev'))->guides())
        ->flatMap(fn (array $guide): array => collect($guide['steps'])->pluck('code')->filter()->all())
        ->filter(fn (string $code): bool => str_starts_with(ltrim($code), '{'));

    expect($snippets)->not->toBeEmpty();

    foreach ($snippets as $code) {
        $pretty = json_encode(json_decode($code, false, flags: JSON_THROW_ON_ERROR), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        expect($code)->toBe($pretty)->toContain("\n    ");
    }
});
