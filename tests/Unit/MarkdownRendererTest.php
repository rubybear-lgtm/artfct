<?php

declare(strict_types=1);

use App\Services\MarkdownRenderer;

beforeEach(function () {
    $this->renderer = new MarkdownRenderer;
});

it('renders headings', function () {
    $html = $this->renderer->toFragment("# Hello\n\n## World\n\n### Sub");

    expect($html)->toContain('<h1>Hello</h1>');
    expect($html)->toContain('<h2>World</h2>');
    expect($html)->toContain('<h3>Sub</h3>');
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
    expect($html)->toContain('<h1>Test</h1>');
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

it('toFullDocument throws on output exceeding limit', function () {
    $big = str_repeat('# ', MarkdownRenderer::MAX_OUTPUT_BYTES);

    expect(fn () => $this->renderer->toFullDocument($big))
        ->toThrow(RuntimeException::class, '1 MB');
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
