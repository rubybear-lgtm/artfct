<?php

use App\Services\Blog\BlogContent;
use App\Support\AiToolSetup;

test('agents asking for markdown get the homepage as markdown with a token count', function () {
    $response = $this->get('/', ['Accept' => 'text/markdown'])
        ->assertOk()
        ->assertHeader('Content-Type', 'text/markdown; charset=UTF-8')
        ->assertHeader('Vary', 'Accept');

    expect($response->getContent())
        ->toStartWith('# Artfct: shared context and AI artifacts for every agent')
        ->toContain(AiToolSetup::forCurrentEnvironment()->agentSetupPrompt())
        ->not->toContain('<html')
        ->and((int) $response->headers->get('x-markdown-tokens'))->toBeGreaterThan(100);
});

test('browsers still get HTML, and the HTML says it varies by Accept', function () {
    $response = $this->get('/', ['Accept' => 'text/html,application/xhtml+xml,*/*;q=0.8'])->assertOk();

    expect($response->headers->get('Content-Type'))->toContain('text/html')
        ->and(implode(',', $response->headers->all('vary')))->toContain('Accept')
        ->and($response->headers->has('x-markdown-tokens'))->toBeFalse();

    expect($this->get('/', ['Accept' => '*/*'])->headers->get('Content-Type'))->toContain('text/html');
});

test('markdown wins only when the client prefers it over HTML', function () {
    expect($this->get('/connect', ['Accept' => 'text/markdown, text/html;q=0.5'])->headers->get('Content-Type'))
        ->toContain('text/markdown')
        ->and($this->get('/connect', ['Accept' => 'text/html, text/markdown;q=0.5'])->headers->get('Content-Type'))
        ->toContain('text/html');
});

test('every setup guide, the hub, the blog and the docs have a markdown version', function () {
    $markdown = fn (string $path): string => $this->get($path, ['Accept' => 'text/markdown'])->assertOk()
        ->assertHeader('Content-Type', 'text/markdown; charset=UTF-8')->getContent();

    foreach (AiToolSetup::forCurrentEnvironment()->guides() as $guide) {
        expect($markdown("/connect/{$guide['id']}"))->toStartWith("# Connect {$guide['label']} to Artfct");
    }

    $post = app(BlogContent::class)->all()[0];

    expect($markdown('/connect'))->toContain('/connect/connect-claude-code')
        ->and($markdown('/blog'))->toContain("/blog/{$post->slug}")
        ->and($markdown("/blog/{$post->slug}"))->toStartWith("# {$post->title}")
        ->and($markdown('/docs'))->toStartWith('# Artfct documentation');
});

test('pages without a markdown version and unknown guides fall through to the normal response', function () {
    $this->get('/terms', ['Accept' => 'text/markdown'])->assertHeaderMissing('x-markdown-tokens');
    $this->get('/connect/not-a-tool', ['Accept' => 'text/markdown'])->assertNotFound();
});
