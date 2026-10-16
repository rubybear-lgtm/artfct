<?php

use App\Services\Blog\BlogRenderer;

beforeEach(function () {
    $this->renderer = new BlogRenderer;
});

test('the site fragment carries the class every block is contracted to carry', function () {
    $markdown = <<<'MD'
## A heading

A paragraph with `code`, **strong** and a [link](https://artfct.dev/docs).

### A subheading

- first
- second

1. one
2. two

> A quote.

```bash
npx artfct
```

| head | other |
| --- | --- |
| cell | cell |
MD;

    $html = $this->renderer->site($markdown);

    expect($html)
        ->toContain('<p class="mb-5 leading-[1.75] text-muted-foreground">')
        ->toContain('<h2 class="mt-12 mb-4 font-serif text-2xl leading-tight font-medium tracking-tight text-foreground">')
        ->toContain('<h3 class="mt-8 mb-2 font-serif text-lg leading-snug font-medium text-foreground">')
        ->toContain('<code class="rounded bg-muted px-1.5 py-0.5 font-mono text-[0.85em] text-foreground">')
        ->toContain('<strong class="text-foreground">')
        ->toContain('<a href="https://artfct.test/docs" class="font-semibold text-primary underline-offset-4 hover:underline">')
        ->toContain('<ul class="mb-5 list-disc space-y-2 pl-6 leading-[1.75] text-muted-foreground">')
        ->toContain('<ol class="mb-5 list-decimal space-y-2 pl-6 leading-[1.75] text-muted-foreground">')
        ->toContain('<blockquote class="mb-5 border-l-2 border-border pl-4 leading-[1.75] text-muted-foreground italic">')
        ->toContain('A quote.')
        ->toContain('<pre class="mb-5 overflow-x-auto rounded border border-border bg-muted p-4 font-mono text-sm leading-relaxed text-foreground">')
        ->toContain('<div class="mb-5 overflow-x-auto"><table class="w-full border-collapse text-left text-sm">')
        ->toContain('<th class="border-b border-border pr-4 pb-2 font-mono text-xs tracking-wide text-foreground uppercase">')
        ->toContain('<td class="border-b border-border py-3 pr-4 align-top leading-[1.6] text-muted-foreground">');
});

test('code inside a pre carries no class of its own', function () {
    $html = $this->renderer->site("```bash\nnpx artfct\n```");

    expect($html)->toContain('<code>npx artfct')
        ->not->toContain('<code class=');
});

test('a link to artfct.dev is resolved against the running environment', function () {
    $html = $this->renderer->site('Read [the docs](https://artfct.dev/docs#mcp) and [a post](https://artfct.dev/blog/a-post).');

    expect($html)
        ->toContain('href="'.url('/docs').'#mcp"')
        ->toContain('href="'.url('/blog/a-post').'"')
        ->not->toContain('artfct.dev');
});

test('an external link opens in a new tab with no referrer', function () {
    $html = $this->renderer->site('See [the spec](https://modelcontextprotocol.io/).');

    expect($html)->toContain('target="_blank"')
        ->toContain('rel="noreferrer"');
});

test('the heading the drafts use for the faq renders as the site calls it', function () {
    $html = $this->renderer->site("## Questions\n\n### Is it an artifact?\n\nIt is.");

    expect($html)->toContain('Frequently asked questions')
        ->not->toContain('>Questions<');
});

test('a figure fence becomes a block in the position the prose puts it', function () {
    $blocks = $this->renderer->blocks("Before the figure.\n\n```figure\nloop\n```\n\nAfter the figure.");

    expect($blocks)->toHaveCount(3)
        ->and($blocks[0]['type'])->toBe('html')
        ->and($blocks[0]['html'])->toContain('Before the figure.')
        ->and($blocks[1])->toBe(['type' => 'figure', 'name' => 'loop'])
        ->and($blocks[2]['type'])->toBe('html')
        ->and($blocks[2]['html'])->toContain('After the figure.');
});

test('a figure name outside the closed set fails rather than vanishing', function () {
    $this->renderer->blocks("```figure\nslides\n```");
})->throws(RuntimeException::class, 'names `slides`');

test('an unclosed figure fence fails rather than swallowing the rest', function () {
    $this->renderer->blocks("```figure\nloop\n");
})->throws(RuntimeException::class, 'never closed');

test('a fence that is not a figure is still a code block', function () {
    $blocks = $this->renderer->blocks("```figure-not\nloop\n```");

    expect($blocks)->toHaveCount(1)
        ->and($blocks[0]['type'])->toBe('html')
        ->and($blocks[0]['html'])->toContain('<pre class=');
});

test('the document profile drops the site classes and marks a table for its stylesheet', function () {
    $html = $this->renderer->document("| head |\n| --- |\n| cell |");

    expect($html)->toContain('<table class="t">')
        ->not->toContain('leading-[1.75]');
});

test('the document profile shows where a figure sits instead of dropping it', function () {
    $html = $this->renderer->document("Before.\n\n```figure\nbreak\n```");

    expect($html)->toContain('class="fig"')
        ->toContain('Figure: break')
        ->toContain('Before.');
});

test('a heading inside a code fence is content, not structure', function () {
    $html = $this->renderer->site("```\n## not a heading\n```");

    expect($html)->toContain('## not a heading')
        ->not->toContain('<h2');
});
