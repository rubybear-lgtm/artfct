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
        ->toContain('<h2 id="see">See ')
        ->toContain('<h1 id="first-second">First<br>')
        ->toContain('<h1 id="a">A</h1>')
        ->toContain('<h2 id="a-2">A</h2>')
        ->toContain('<h2 id="a-2-2">A-2</h2>')
        ->toContain('<h2 id="a-3">A</h2>')
        // Leading punctuation must not shift the cap: trimming it after the
        // truncation minted a 119-character id the Worker never produces. The
        // literal, not the constant, so the two languages' caps cannot drift
        // apart while each side keeps checking itself.
        ->toContain('<h2 id="'.str_repeat('x', 120).'">(')
        ->toContain('<h2 id="long-headings-are-truncated-so-the-viewer-still-forwards-the-fragment-and-scrolling-lands-on-the-intended-section-of-the">');
});

it('reads a hard line break as a separator, like the Worker does', function () {
    // `<br>` plus the newline CommonMark writes make `textContent`
    // "First\nsecond"; the Worker used to drop the break and emit
    // `firstsecond` for the same heading.
    $html = (new MarkdownRenderer)->toFragment("First  \nsecond\n===\n");

    expect($html)->toContain('<h1 id="first-second">First<br>');
});

it('skips suffixes a literal heading already took', function () {
    $html = (new MarkdownRenderer)->toFragment("# A\n\n## A\n\n## A-2\n\n## A");

    expect($html)->toContain('<h1 id="a">A</h1>')
        ->toContain('<h2 id="a-2">A</h2>')
        ->toContain('<h2 id="a-2-2">A-2</h2>')
        ->toContain('<h2 id="a-3">A</h2>');
});

it('keeps repeated heading ids linear', function () {
    // The suffix search restarted at `-2` for every repeat, so N repeats cost
    // N². 20k took minutes there and milliseconds here, so a generous bound
    // still catches a return of the rescans.
    $slugs = new HeadingSlug;
    $started = microtime(true);

    for ($i = 0; $i < 20_000; $i++) {
        $slugs->id('Same heading');
    }

    $elapsed = microtime(true) - $started;

    expect($slugs->id('Same heading'))->toBe('same-heading-20001')
        ->and($elapsed)->toBeLessThan(5.0);
});

it('caps slugs so the viewer still forwards the fragment', function () {
    // FRAME_FRAGMENT drops anything over 128 characters after `#`. The
    // deduped id adds a suffix on top of the cap.
    $long = str_repeat('word ', 100);
    $html = (new MarkdownRenderer)->toFragment("# {$long}\n\n# {$long}");

    preg_match_all('/id="([^"]+)"/', $html, $matches);

    expect($matches[1])->toHaveCount(2)
        ->and($matches[1][0])->toBe(HeadingSlug::slugify($long))
        ->and(strlen($matches[1][0]))->toBeLessThanOrEqual(128)
        ->and($matches[1][1])->toBe(HeadingSlug::slugify($long).'-2')
        ->and(strlen($matches[1][1]))->toBeLessThanOrEqual(128);
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
