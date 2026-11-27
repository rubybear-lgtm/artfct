<?php

declare(strict_types=1);

use App\Services\HeadingSlug;
use App\Services\MarkdownRenderer;

/**
 * Cross-language contract (RUB-603): `tests/Fixtures/markdown-heading-ids.md`
 * is also asserted from `backend/src/markdown.rs`
 * (`heading_ids_match_shared_fixture`) with the same ids. If either side
 * changes its slug shape, this fixture — not the other renderer's output —
 * is what both sides update together.
 */
it('emits the same heading ids as the Worker renderer for the shared fixture', function () {
    $markdown = file_get_contents(dirname(__DIR__).'/Fixtures/markdown-heading-ids.md');

    expect($markdown)->not->toBeFalse();

    $html = (new MarkdownRenderer)->toFragment($markdown);

    expect($html)->toContain('<h1 id="hello-world">Hello World</h1>')
        ->toContain('<h2 id="7-dated-cut-marketplace-launch">7. Dated cut: marketplace launch</h2>')
        ->toContain('<h2 id="hello-world-2">Hello World</h2>')
        ->toContain('<h3 id="fix-the-bold-bit">Fix the <strong>bold</strong> bit</h3>')
        ->toContain('<h2 id="see-the-docs">See <a href="https://artfct.dev">the docs</a></h2>')
        ->toContain('<h2 id="use-artfct-deploy-today">Use <code>artfct deploy</code> today</h2>')
        ->toContain('<h2 id="caf-cr-me">Café &amp; crème</h2>')
        ->toContain('<h2 id="section">!!!</h2>')
        ->toContain('<h1 id="hello-world-3">Hello World</h1>')
        ->toContain('<h2 id="see">See ');
});

it('dedupes repeated slugs with -2 and -3 suffixes', function () {
    $html = (new MarkdownRenderer)->toFragment("# Dup\n\n## Dup\n\n### Dup");

    expect($html)->toContain('<h1 id="dup">Dup</h1>')
        ->toContain('<h2 id="dup-2">Dup</h2>')
        ->toContain('<h3 id="dup-3">Dup</h3>');
});

it('falls back to section for headings with no slug text', function () {
    $html = (new MarkdownRenderer)->toFragment("## !!!\n\n## ???");

    expect($html)->toContain('<h2 id="section">!!!</h2>')
        ->toContain('<h2 id="section-2">???</h2>');
});

it('slugifies the BlogContent anchor shape', function () {
    expect(HeadingSlug::slugify('7. Dated cut: marketplace launch'))->toBe('7-dated-cut-marketplace-launch')
        ->and(HeadingSlug::slugify('Café & crème'))->toBe('caf-cr-me')
        ->and(HeadingSlug::slugify('!!!'))->toBe('section')
        ->and(HeadingSlug::slugify(''))->toBe('section');
});
