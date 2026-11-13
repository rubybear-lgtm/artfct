<?php

use App\Enums\ArtifactType;
use App\Mcp\Servers\ArtfctServer;
use App\Mcp\Support\ArtifactGuidance;
use App\Models\Team;
use App\Support\AiToolSetup;
use Laravel\Mcp\Server\Transport\FakeTransporter;

/*
 * RUB-443 item 7: the guidance is static, versioned text served as a normal
 * tool call, and the tool descriptions only point at it. These tests fail when
 * a description or the server instructions drift from the guidance version.
 */

test('get_guidance returns each topic with the live types and limits', function (string $topic) {
    $team = Team::factory()->create();
    $token = remoteMcpToken($team);

    $response = test()->withToken($token)->postJson('/mcp', [
        'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call',
        'params' => ['name' => 'get_guidance', 'arguments' => ['topic' => $topic]],
    ])->assertOk();

    $result = $response->json('result.structuredContent');

    expect($result['contract_version'])->toBe(ArtifactGuidance::VERSION)
        ->and($result['topic'])->toBe($topic)
        ->and($result['text'])->toContain('#')
        ->and($result['artifact_types'])->toBe(ArtifactType::values())
        ->and($result['limits']['max_html_bytes'])->toBe(1048576);
})->with(ArtifactGuidance::TOPICS);

test('an unknown guidance topic is refused', function () {
    $token = remoteMcpToken(Team::factory()->create());

    $response = test()->withToken($token)->postJson('/mcp', [
        'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call',
        'params' => ['name' => 'get_guidance', 'arguments' => ['topic' => '../../.env']],
    ])->assertOk()->assertJsonPath('result.isError', true);

    expect($response->json('result.content.0._meta.artfct.errorCode'))->toBe('invalid_request')
        ->and($response->json('result.content.0.text'))->toContain('Allowed topics:')
        ->and($response->json('result.content.0.text'))->toContain('publish')
        ->and($response->json('result.content.0.text'))->toContain('search')
        ->and($response->json('result.content.0.text'))->toContain('collaborate');
});

test('the guidance text names only tools and fields that exist', function () {
    $server = app(ArtfctServer::class, ['transport' => new FakeTransporter]);
    $server->start();
    $tools = $server->createContext()->tools()->map(fn ($tool): string => $tool->name())->all();

    foreach (ArtifactGuidance::TOPICS as $topic) {
        $text = ArtifactGuidance::topic($topic)['text'];
        preg_match_all('/`([a-z]+(?:_[a-z]+)+)`/', $text, $matches);
        $mentionedTools = array_filter($matches[1], fn (string $name): bool => str_ends_with($name, '_artifact')
            || str_ends_with($name, '_artifacts')
            || str_ends_with($name, '_artifact_comments')
            || str_ends_with($name, '_artifact_comment')
            || str_ends_with($name, '_collection')
            || str_ends_with($name, '_collections'));

        foreach ($mentionedTools as $name) {
            expect($tools)->toContain($name);
        }
    }
});

test('tool descriptions and the server instructions point at the guidance at its current version', function () {
    $server = app(ArtfctServer::class, ['transport' => new FakeTransporter]);
    $server->start();
    $tools = $server->createContext()->tools()->mapWithKeys(fn ($tool): array => [$tool->name() => $tool->toArray()]);

    foreach (['deploy_artifact', 'search_artifacts', 'read_artifact'] as $name) {
        expect($tools[$name]['description'])->toContain('get_guidance')
            ->and($tools[$name]['_meta']['artfct']['guidanceVersion'])->toBe(ArtifactGuidance::VERSION);
    }

    expect(ArtifactGuidance::INSTRUCTIONS)->toContain('get_guidance')
        ->toContain('untrusted')
        ->toContain('base_version');
});

test('every tool that names a guidance version names the current one', function () {
    // Read the source, not the registered tools: the db_* tools are only
    // registered when the artifact database is on, but they carry the version.
    $versions = collect(glob(app_path('Mcp/Tools/*.php')))
        ->mapWithKeys(function (string $file): array {
            preg_match("/'guidanceVersion' => '([^']+)'/", (string) file_get_contents($file), $match);

            return [basename($file, '.php') => $match[1] ?? null];
        })
        ->filter();

    expect($versions->keys()->all())->toContain('GetGuidanceTool', 'DeployArtifactTool', 'DbSchemaTool')
        ->and($versions->unique()->values()->all())->toBe([ArtifactGuidance::VERSION]);
});

test('db_schema still points agents at the data guidance', function () {
    expect(file_get_contents(app_path('Mcp/Tools/DbSchemaTool.php')))->toContain('get_guidance')->toContain('topic data');
});

test('the grok-build playbook never sends an agent to artfct-init, which has no Grok Build setting', function () {
    $text = onboardingGuidance('grok-build')['text'];

    expect($text)->toContain('Do not run the `artfct-init` skill')->not->toContain('--host');
});

test('the Agent Skills files carry exactly the served guidance', function (string $topic) {
    $skill = file_get_contents(base_path("skills/artfct-{$topic}/SKILL.md"));
    $guidance = ArtifactGuidance::topic($topic)['text'];

    expect($skill)->toStartWith("---\nname: artfct-{$topic}\n")
        ->and(str_ends_with($skill, $guidance))->toBeTrue();
})->with(ArtifactGuidance::MIRRORED_TOPICS);

/*
 * RUB-530: onboarding is the one topic with no mirrored skill. It branches by
 * host, and it sends coding agents to the local artfct-init skill instead.
 */
test('every guidance topic is either mirrored as a skill or is onboarding', function () {
    expect(array_values(array_diff(ArtifactGuidance::TOPICS, ArtifactGuidance::MIRRORED_TOPICS)))->toBe(['onboarding']);
});

function onboardingGuidance(?string $host): array
{
    $arguments = ['topic' => 'onboarding'] + ($host === null ? [] : ['host' => $host]);

    return test()->withToken(remoteMcpToken(Team::factory()->create()))->postJson('/mcp', [
        'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call',
        'params' => ['name' => 'get_guidance', 'arguments' => $arguments],
    ])->assertOk()->json('result.structuredContent');
}

test('onboarding guidance is served for each host with its mode and steps', function (string $host, string $mode) {
    $result = onboardingGuidance($host);

    expect($result['topic'])->toBe('onboarding')
        ->and($result['host'])->toBe($host)
        ->and($result['mode'])->toBe($mode)
        ->and($result['contract_version'])->toBe(ArtifactGuidance::VERSION)
        ->and($result['text'])->toStartWith('# ')
        ->and(array_column($result['steps'], 'id'))->not->toBeEmpty();
})->with(collect(ArtifactGuidance::HOST_MODES)->map(fn (string $mode, string $host): array => [$host, $mode])->values()->all());

test('onboarding without a host asks the agent to find its host and call again', function () {
    $result = onboardingGuidance(null);

    expect($result['host'])->toBe('unknown')
        ->and($result['mode'])->toBe('hybrid')
        ->and($result['text'])->toContain('get_guidance')->toContain('host')
        ->and($result['text'])->not->toContain('npx');
});

test('connector-only hosts are never told to add anything to the computer', function (string $host) {
    $result = onboardingGuidance($host);
    $text = strtolower($result['text']);

    expect($result['mode'])->toBe('connector_only')
        ->and($text)->not->toContain('npx')
        ->and($text)->not->toContain('plugin')
        ->and($text)->not->toContain('marketplace')
        ->and($text)->not->toContain('artfct-init')
        ->and($text)->not->toContain('install')
        ->and(array_column($result['steps'], 'requires_consent'))->each->toBeFalse();
})->with(['claude-app', 'chatgpt', 'grok']);

test('coding agents ask before changing the project and never ask the person to run a command', function (string $host) {
    $result = onboardingGuidance($host);
    $consent = collect($result['steps'])->where('requires_consent', true)->pluck('id')->all();

    expect($result['mode'])->toBe('coding_agent')
        ->and($result['text'])->toContain('Never ask the person to run a command')->toContain('Ask first')
        ->and($result['text'])->toContain('get_connection')->toContain('get_guidance')
        ->and($consent)->toBe(['add_project_instructions']);
})->with(['claude-code', 'cursor', 'grok-build', 'coding-agent']);

/*
 * RUB-530: non-technical people install nothing from a terminal, so no
 * playbook may send an agent to npx. The plugin commands are left out until a
 * plugin exists, so an agent never runs one that fails.
 */
test('no onboarding playbook mentions npx or a plugin command that does not exist yet', function (string $host) {
    expect(strtolower(onboardingGuidance($host)['text']))
        ->not->toContain('npx')
        ->not->toContain('npm ')
        ->not->toContain('/plugin')
        ->not->toContain('marketplace add')
        ->not->toContain('/add-plugin');
})->with(array_keys(ArtifactGuidance::HOST_MODES));

test('the instruction line coding agents add is the one the connect pages show', function (string $host) {
    expect(onboardingGuidance($host)['text'])->toContain((new AiToolSetup('https://example.test'))->agentInstructionLine());
})->with(['claude-code', 'cursor', 'grok-build', 'coding-agent']);

test('an unknown onboarding host is refused and lists the allowed hosts', function () {
    $response = test()->withToken(remoteMcpToken(Team::factory()->create()))->postJson('/mcp', [
        'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call',
        'params' => ['name' => 'get_guidance', 'arguments' => ['topic' => 'onboarding', 'host' => '../../.env']],
    ])->assertOk()->assertJsonPath('result.isError', true);

    expect($response->json('result.content.0.text'))->toContain('Allowed hosts:')->toContain('claude-code')->toContain('chatgpt');
});

test('the host is ignored for topics that are not onboarding', function () {
    $result = test()->withToken(remoteMcpToken(Team::factory()->create()))->postJson('/mcp', [
        'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call',
        'params' => ['name' => 'get_guidance', 'arguments' => ['topic' => 'publish', 'host' => 'chatgpt']],
    ])->assertOk()->json('result.structuredContent');

    expect($result['topic'])->toBe('publish')->and($result)->not->toHaveKey('host');
});

test('the server instructions and tool description name the onboarding topic', function () {
    $server = app(ArtfctServer::class, ['transport' => new FakeTransporter]);
    $server->start();
    $tool = $server->createContext()->tools()->first(fn ($tool): bool => $tool->name() === 'get_guidance')->toArray();

    expect(ArtifactGuidance::INSTRUCTIONS)->toContain('onboarding')
        ->and($tool['description'])->toContain('onboarding')
        ->and(json_encode($tool['inputSchema']))->toContain('claude-code');
});

/*
 * RUB-444: a published page is served with `script-src 'self'`, so an inline
 * script never runs and nothing reports it. The rule has to be in front of the
 * agent that writes the page, in the guidance and in the tool description.
 */
test('the publish guidance says inline scripts do not run and where scripts must live', function () {
    $text = ArtifactGuidance::topic('publish')['text'];

    expect($text)->toContain('inline `<script>` block')
        ->toContain('`onclick=`')
        ->toContain('from another site')
        ->toContain('`data:`')
        ->toContain('app.js');
});

test('deploy_artifact tells agents inline scripts do not run', function () {
    $server = app(ArtfctServer::class, ['transport' => new FakeTransporter]);
    $server->start();
    $tool = $server->createContext()->tools()->first(fn ($tool): bool => $tool->name() === 'deploy_artifact')->toArray();

    expect($tool['description'])->toContain('Inline `<script>` blocks')
        ->and($tool['description'])->toContain('resources from other sites do not load')
        ->and($tool['description'])->toContain('README.md')
        ->and($tool['description'])->toContain('base_version_checked')
        ->and($tool['description'])->toContain('onclick=')
        ->and(json_encode($tool['inputSchema']))->toContain('without inline scripts');
});

test('get_guidance names its own guidance version like the guided tools', function () {
    $server = app(ArtfctServer::class, ['transport' => new FakeTransporter]);
    $server->start();
    $tool = $server->createContext()->tools()->first(fn ($tool): bool => $tool->name() === 'get_guidance')->toArray();

    expect($tool['_meta']['artfct']['guidanceVersion'])->toBe(ArtifactGuidance::VERSION);
});

/*
 * The guidance says inline scripts and outside resources are blocked. That is
 * only true while the Worker's isolated-origin policy builder keeps scripts to
 * 'self' and sets no img-src or font-src. If it changes, this fails so the
 * guidance, the skills and the tool text are revisited with it (RUB-444).
 */
test('the guidance rule matches the isolated origin policy the Worker builds', function () {
    $source = (string) file_get_contents(base_path('backend/src/artifact_origin.rs'));

    expect($source)->toContain('script-src {script_src};')
        ->toContain('let mut script_src = default_src.clone();')
        ->not->toContain('script_src.push_str(" \'unsafe-inline\'")')
        ->not->toContain('img-src')
        ->not->toContain('font-src');
});
