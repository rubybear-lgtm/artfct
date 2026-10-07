<?php

dataset('blog demo posts', [
    'team library' => ['share-ai-agent-knowledge-team', 'team', 5],
    'agent handoff' => ['share-context-claude-code-codex-mcp', 'handoff', 7],
    'semantic search' => ['semantic-search-ai-generated-reports', 'search', 5],
]);

test('workflow articles render their matching demos without autoplay', function (string $slug, string $kind) {
    visit(route('blog.show', ['slug' => $slug]))
        ->assertVisible('[data-testid="blog-demo"]')
        ->assertScript('document.querySelector("[data-testid=blog-demo]").dataset.kind', $kind)
        ->assertScript('Number(document.querySelector("[data-testid=blog-demo]").dataset.step)', 0)
        ->assertSee('Illustrative demo')
        ->assertNoJavaScriptErrors();
})->with('blog demo posts');

test('workflow demos pause and resume and can replay after completion', function (string $slug, string $kind, int $finalStep) {
    $page = visit(route('blog.show', ['slug' => $slug]))->click('Play');
    $page->assertScript('Number(document.querySelector("[data-testid=blog-demo]").dataset.step) > 0', true);
    $page->click('Pause');
    $pausedStep = $page->script('Number(document.querySelector("[data-testid=blog-demo]").dataset.step)');

    $page->wait(2)
        ->assertScript('Number(document.querySelector("[data-testid=blog-demo]").dataset.step)', $pausedStep)
        ->click('Play')
        ->assertScript('Number(document.querySelector("[data-testid=blog-demo]").dataset.step) > '.$pausedStep, true);

    $page->click('Pause')->click('Replay')
        ->assertScript('Number(document.querySelector("[data-testid=blog-demo]").dataset.step)', 0)
        ->wait(13)
        ->assertScript('Number(document.querySelector("[data-testid=blog-demo]").dataset.step)', $finalStep)
        ->assertScript('document.querySelector("[data-testid=blog-demo]").textContent.includes("undefined")', false)
        ->click('Replay')
        ->assertScript('Number(document.querySelector("[data-testid=blog-demo]").dataset.step)', 0)
        ->assertNoJavaScriptErrors();
})->with('blog demo posts');

test('workflow demos show a completed static view with reduced motion', function (string $slug, string $kind, int $finalStep) {
    visit(route('blog.show', ['slug' => $slug]), ['reducedMotion' => 'reduce'])
        ->assertScript('Number(document.querySelector("[data-testid=blog-demo]").dataset.step)', $finalStep)
        ->assertSee('Reduced motion: the completed workflow is shown without animation.')
        ->assertScript('document.querySelector("[data-testid=blog-demo]").querySelectorAll("button").length', 0)
        ->assertNoJavaScriptErrors();
})->with('blog demo posts');

test('workflow demos fit narrow screens in both themes', function (string $slug) {
    foreach (['light', 'dark'] as $scheme) {
        visit(route('blog.show', ['slug' => $slug]), ['colorScheme' => $scheme])
            ->resize(360, 800)
            ->assertScript('document.documentElement.scrollWidth <= window.innerWidth', true)
            ->assertNoJavaScriptErrors();
    }
})->with('blog demo posts');

test('contradicting tools article renders without javascript errors', function () {
    visit(route('blog.show', ['slug' => 'stop-ai-tools-contradicting-each-other']))
        ->assertSee('How we stopped our AI tools from contradicting each other')
        ->assertSee('The Thursday our AI tools disagreed')
        ->assertSee('Why AI tools contradict each other')
        ->assertSee('Frequently asked questions')
        ->assertNoJavaScriptErrors();
});
