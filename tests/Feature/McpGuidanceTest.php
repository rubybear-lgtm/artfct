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

    test()->withToken($token)->postJson('/mcp', [
        'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call',
        'params' => ['name' => 'get_guidance', 'arguments' => ['topic' => '../../.env']],
    ])->assertOk()->assertJsonPath('result.isError', true);
});

test('the guidance text names only tools and fields that exist', function () {
    $server = app(ArtfctServer::class, ['transport' => new FakeTransporter]);
    $server->start();
    $tools = $server->createContext()->tools()->map(fn ($tool): string => $tool->name())->all();

    foreach (ArtifactGuidance::TOPICS as $topic) {
        $text = ArtifactGuidance::topic($topic)['text'];
        preg_match_all('/`([a-z]+(?:_[a-z]+)+)`/', $text, $matches);
        $mentionedTools = array_filter($matches[1], fn (string $name): bool => str_ends_with($name, '_artifact')
            || str_ends_with($name, '_artifacts') || str_ends_with($name, '_artifact_comments') || str_ends_with($name, '_artifact_comment'));

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
        ->toContain('`files`')
        ->toContain('app.js');
});

test('deploy_artifact tells agents inline scripts do not run', function () {
    $server = app(ArtfctServer::class, ['transport' => new FakeTransporter]);
    $server->start();
    $tool = $server->createContext()->tools()->first(fn ($tool): bool => $tool->name() === 'deploy_artifact')->toArray();

    expect($tool['description'])->toContain('Inline `<script>` blocks do not run')
        ->and(json_encode($tool['inputSchema']))->toContain('without inline scripts');
});
