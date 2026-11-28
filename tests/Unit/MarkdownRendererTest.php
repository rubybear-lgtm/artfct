<?php

declare(strict_types=1);

use App\Services\MarkdownRenderer;

beforeEach(function () {
    $this->renderer = new MarkdownRenderer;
});

it('uses the shared markdown detection contract', function () {
    $cases = json_decode((string) file_get_contents(dirname(__DIR__).'/Fixtures/markdown-detection.json'), true, flags: JSON_THROW_ON_ERROR);

    foreach ($cases as $case) {
        expect(MarkdownRenderer::shouldRenderMarkdown($case['content_type'], $case['entrypoint']))
            ->toBe($case['render']);
    }
});

it('renders headings', function () {
    $html = $this->renderer->toFragment("# Hello\n\n## World\n\n### Sub");

    expect($html)->toContain('<h1 id="hello">Hello</h1>');
    expect($html)->toContain('<h2 id="world">World</h2>');
    expect($html)->toContain('<h3 id="sub">Sub</h3>');
});

it('renders paragraphs', function () {
    $html = $this->renderer->toFragment("one paragraph.\n\nanother paragraph.");

    expect($html)->toContain('<p>one paragraph.</p>');
    expect($html)->toContain('<p>another paragraph.</p>');
});

it('renders bold and italic', function () {
    $html = $this->renderer->toFragment('**bold** and *italic* and ***both***');

    expect($html)->toContain('<strong>bold</strong>');
    expect($html)->toContain('<em>italic</em>');
    expect($html)->toContain('<em><strong>both</strong></em>');
});

it('renders inline code', function () {
    $html = $this->renderer->toFragment('use the `artfct deploy` command');

    expect($html)->toContain('<code>artfct deploy</code>');
});

it('renders links', function () {
    $html = $this->renderer->toFragment('[artfct](https://artfct.dev)');

    expect($html)->toContain('<a href="https://artfct.dev">artfct</a>');
});

it('renders images', function () {
    $html = $this->renderer->toFragment('![alt](https://example.com/img.png)');

    expect($html)->toContain('<img src="https://example.com/img.png" alt="alt"');
});

it('renders unordered lists', function () {
    $html = $this->renderer->toFragment("- one\n- two\n- three");

    expect($html)->toContain('<ul>');
    expect($html)->toContain('<li>one</li>');
    expect($html)->toContain('<li>two</li>');
    expect($html)->toContain('<li>three</li>');
});

it('renders ordered lists', function () {
    $html = $this->renderer->toFragment("1. first\n2. second\n3. third");

    expect($html)->toContain('<ol>');
    expect($html)->toContain('<li>first</li>');
});

it('renders blockquotes', function () {
    $html = $this->renderer->toFragment('> quoted text');

    expect($html)->toContain('<blockquote>');
    expect($html)->toContain('<p>quoted text</p>');
});

it('renders fenced code blocks', function () {
    $markdown = <<<'MD'
```php
echo "hello";
```
MD;

    $html = $this->renderer->toFragment($markdown);

    expect($html)->toContain('<pre>');
    expect($html)->toContain('<code');
    expect($html)->toContain('echo');
    expect($html)->toContain('hello');
});

it('renders strikethrough and task lists', function () {
    $html = $this->renderer->toFragment("~~retired~~\n\n- [x] done\n- [ ] todo");

    expect($html)->toContain('<del>retired</del>')
        ->and($html)->toContain('type="checkbox"')
        ->and($html)->toContain('checked')
        ->and($html)->toContain('disabled');
});

it('renders horizontal rules', function () {
    $html = $this->renderer->toFragment("before\n\n---\n\nafter");

    expect($html)->toContain('<hr');
});

it('renders tables', function () {
    $markdown = <<<'MD'
| a | b |
|---|---|
| 1 | 2 |
MD;

    $html = $this->renderer->toFragment($markdown);

    expect($html)->toContain('<table>');
    expect($html)->toContain('<th>a</th>');
    expect($html)->toContain('<th>b</th>');
    expect($html)->toContain('<td>1</td>');
    expect($html)->toContain('<td>2</td>');
});

it('renders empty string as empty', function () {
    $html = $this->renderer->toFragment('');

    expect($html)->toBe('');
});

it('renders whitespace-only as empty', function () {
    $html = $this->renderer->toFragment("  \n\n  ");

    expect($html)->toBe('');
});

it('strips raw HTML by default', function () {
    $html = $this->renderer->toFragment('<script>alert("xss")</script>');

    expect($html)->not->toContain('<script>');
    expect($html)->not->toContain('alert');
});

it('neutralizes unsafe link schemes', function () {
    $html = $this->renderer->toFragment('[js](javascript:alert(1)) and [data](data:text/html,<script>alert(1)</script>)');

    expect($html)->not->toContain('javascript:')
        ->and($html)->not->toContain('data:text/html');
});

it('renders large multi-row markdown tables under the 128 KB cap', function () {
    $rows = ['| A | B | C | D | E |', '| --- | --- | --- | --- | --- |'];
    for ($i = 1; $i <= 700; $i++) {
        $rows[] = "| row{$i} | val_a | val_b | val_c | val_d |";
    }
    $markdown = implode("\n", $rows);

    $html = $this->renderer->toFullDocument($markdown);

    expect($html)->toContain('<table>')
        ->and($html)->toContain('<td>row700</td>');
});

it('toFragment does not wrap in document', function () {
    $html = $this->renderer->toFragment('# Test');

    expect($html)->not->toContain('<!doctype html>');
    expect($html)->not->toContain('<html');
    expect($html)->not->toContain('<head>');
});

it('toFullDocument returns complete HTML document', function () {
    $html = $this->renderer->toFullDocument('# Test');

    expect($html)->toContain('<!doctype html>');
    expect($html)->toContain('<html lang="en">');
    expect($html)->toContain('<head>');
    expect($html)->toContain('<style>');
    expect($html)->toContain('<body>');
    expect($html)->toContain('<h1 id="test">Test</h1>');
});

it('toFullDocument includes artfct badge', function () {
    $html = $this->renderer->toFullDocument('hello');

    expect($html)->toContain('artfct-badge-link');
    expect($html)->toContain('hosted by artfct');
});

it('toFullDocument includes embedded CSS', function () {
    $html = $this->renderer->toFullDocument('hello');

    expect($html)->toContain('--bg: #0d1117');
    expect($html)->toContain('--bg: #ffffff');
    expect($html)->toContain('font-family:');
});

it('toFullDocument rejects oversized input before rendering', function () {
    $big = str_repeat("# Heading\n", 200_000);

    expect(fn () => $this->renderer->toFullDocument($big))
        ->toThrow(RuntimeException::class, '128 KB');
});

it('rejects pathological delimiter runs before parsing', function () {
    $markdown = str_repeat('*', 1_000).'x';

    expect(fn () => $this->renderer->toFullDocument($markdown))
        ->toThrow(RuntimeException::class, 'delimiter run');
});

it('rejects oversized lines before parsing', function () {
    $markdown = str_repeat('a', 5_000);

    expect(fn () => $this->renderer->toFullDocument($markdown))
        ->toThrow(RuntimeException::class, 'line exceeds');
});

it('rejects pathological bracket nesting before parsing', function () {
    $markdown = str_repeat(str_repeat('[', 4_095)."\n", 32);

    expect(fn () => $this->renderer->toFullDocument($markdown))
        ->toThrow(RuntimeException::class, 'inline marker count');
});

it('renders large multi-item task lists under the 128 KB cap', function () {
    $markdown = str_repeat("- [ ] task item\n", 1_100);

    $html = $this->renderer->toFullDocument($markdown);

    expect($html)->toContain('type="checkbox"')
        ->and(substr_count($html, 'type="checkbox"'))->toBe(1_100);
});

it('rejects excessive inline work across a paragraph', function () {
    $payloads = [
        implode("\n", array_fill(0, 18, str_repeat('*_~', 1_365))),
        implode("\n", array_fill(0, 32, str_repeat('\\', 4_095))),
        implode("\n", array_fill(0, 32, str_repeat(']', 4_095))),
    ];

    foreach ($payloads as $markdown) {
        expect(fn () => $this->renderer->toFullDocument($markdown))
            ->toThrow(RuntimeException::class, 'inline marker count');
    }
});

it('allows a delimiter run at the safety limit', function () {
    expect($this->renderer->toFullDocument(str_repeat('*', 64)))->toContain('<hr');
});

it('rejects a delimiter run above the safety limit', function () {
    expect(fn () => $this->renderer->toFullDocument(str_repeat('*', 65)))
        ->toThrow(RuntimeException::class, 'delimiter run');
});

it('toFullDocument renders input at the safety limit', function () {
    $markdown = str_repeat("# Heading\n", (int) floor(MarkdownRenderer::MAX_INPUT_BYTES / 10));

    expect($this->renderer->toFullDocument($markdown))->toContain('<h1 id="heading">Heading</h1>');
});

it('preserves line breaks within paragraphs', function () {
    $html = $this->renderer->toFragment("line one\nline two\n\nnew para");

    expect($html)->toContain("line one\nline two");
});

it('renders blockquotes with inline formatting', function () {
    $html = $this->renderer->toFragment('> **bold** and *italic* in a quote');

    expect($html)->toContain('<blockquote>');
    expect($html)->toContain('<strong>bold</strong>');
    expect($html)->toContain('<em>italic</em>');
});
