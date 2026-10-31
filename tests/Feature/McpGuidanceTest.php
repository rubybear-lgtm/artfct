<?php

use App\Enums\ArtifactType;
use App\Mcp\Servers\ArtfctServer;
use App\Mcp\Support\ArtifactGuidance;
use App\Models\Team;
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

test('the Agent Skills files carry exactly the served guidance', function (string $topic) {
    $skill = file_get_contents(base_path("skills/artfct-{$topic}/SKILL.md"));
    $guidance = ArtifactGuidance::topic($topic)['text'];

    expect($skill)->toStartWith("---\nname: artfct-{$topic}\n")
        ->and(str_ends_with($skill, $guidance))->toBeTrue();
})->with(ArtifactGuidance::TOPICS);

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
