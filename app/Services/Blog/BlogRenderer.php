<?php

declare(strict_types=1);

namespace App\Services\Blog;

use Dom\Element;
use Dom\HTMLDocument;
use Dom\Node;
use League\CommonMark\Environment\Environment;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\Extension\Table\TableExtension;
use League\CommonMark\MarkdownConverter;
use RuntimeException;

/**
 * Markdown to the two HTML shapes the blog needs.
 *
 * `site()` emits the classes the React components emitted before this service
 * existed — that class list is the fidelity contract, and `BlogRendererTest`
 * asserts every row. `document()` emits the plain semantic markup the review
 * document's own stylesheet expects, so the linter and the reviewer keep seeing
 * what they saw when drafts were HTML.
 */
final class BlogRenderer
{
    /** Figure names are a closed set, and the site mounts a component per name. */
    public const array FIGURES = ['break', 'loop'];

    /** @var array<string, string> */
    private const array SITE_CLASSES = [
        'p' => 'mb-5 leading-[1.75] text-muted-foreground',
        'h2' => 'mt-12 mb-4 font-serif text-2xl leading-tight font-medium tracking-tight text-foreground',
        'h3' => 'mt-8 mb-2 font-serif text-lg leading-snug font-medium text-foreground',
        'ul' => 'mb-5 list-disc space-y-2 pl-6 leading-[1.75] text-muted-foreground',
        'ol' => 'mb-5 list-decimal space-y-2 pl-6 leading-[1.75] text-muted-foreground',
        'pre' => 'mb-5 overflow-x-auto rounded border border-border bg-muted p-4 font-mono text-sm leading-relaxed text-foreground',
        'code' => 'rounded bg-muted px-1.5 py-0.5 font-mono text-[0.85em] text-foreground',
        'a' => 'font-semibold text-primary underline-offset-4 hover:underline',
        'strong' => 'text-foreground',
        'blockquote' => 'mb-5 border-l-2 border-border pl-4 leading-[1.75] text-muted-foreground italic',
        'table' => 'w-full border-collapse text-left text-sm',
        'th' => 'border-b border-border pr-4 pb-2 font-mono text-xs tracking-wide text-foreground uppercase',
        'td' => 'border-b border-border py-3 pr-4 align-top leading-[1.6] text-muted-foreground',
    ];

    private const string TABLE_WRAPPER = 'mb-5 overflow-x-auto';

    /** The heading the drafts use for the FAQ section, and what the site calls it. */
    private const string FAQ_HEADING = 'Frequently asked questions';

    private readonly MarkdownConverter $converter;

    public function __construct()
    {
        $environment = new Environment([
            'html_input' => 'strip',
            'allow_unsafe_links' => false,
        ]);
        $environment->addExtension(new CommonMarkCoreExtension);
        $environment->addExtension(new TableExtension);

        $this->converter = new MarkdownConverter($environment);
    }

    /**
     * The article as ordered blocks, because a figure is a React component and
     * cannot be mounted inside a server-rendered string.
     *
     * @return list<array{type: 'html', html: string}|array{type: 'figure', name: string}>
     */
    public function blocks(string $markdown): array
    {
        $blocks = [];

        foreach ($this->segments($markdown) as $segment) {
            if ($segment['type'] === 'figure') {
                $blocks[] = ['type' => 'figure', 'name' => $segment['value']];

                continue;
            }

            $html = $this->site($segment['value']);

            if ($html !== '') {
                $blocks[] = ['type' => 'html', 'html' => $html];
            }
        }

        return $blocks;
    }

    /**
     * A site fragment: paragraphs, headings, lists, tables, quotes and code,
     * each carrying the class its React component carried.
     */
    public function site(string $markdown): string
    {
        return $this->convert($markdown, 'site');
    }

    /**
     * A document fragment: the same content without the site's classes, which is
     * what the review document's stylesheet styles and what the linter reads.
     */
    public function document(string $markdown): string
    {
        return $this->convert($markdown, 'document');
    }

    /**
     * Split a body into prose and figure fences, so a figure keeps its position
     * between the blocks around it. Fence-aware: a `##` or a fence inside a code
     * block is content, not structure.
     *
     * @return list<array{type: 'markdown'|'figure', value: string}>
     */
    public function segments(string $markdown): array
    {
        $segments = [];
        $buffer = [];
        $fence = null;
        $figure = [];
        $lines = explode("\n", str_replace("\r\n", "\n", $markdown));
        $flush = function () use (&$segments, &$buffer): void {
            $text = trim(implode("\n", $buffer));

            if ($text !== '') {
                $segments[] = ['type' => 'markdown', 'value' => $text];
            }

            $buffer = [];
        };

        foreach ($lines as $line) {
            if ($fence === null) {
                if (preg_match('/^```figure\s*$/', $line) === 1) {
                    $flush();
                    $fence = 'figure';
                    $figure = [];

                    continue;
                }

                if (preg_match('/^\s*(```|~~~)/', $line) === 1) {
                    $fence = 'code';
                }

                $buffer[] = $line;

                continue;
            }

            if ($fence === 'code') {
                $buffer[] = $line;

                if (preg_match('/^\s*(```|~~~)\s*$/', $line) === 1) {
                    $fence = null;
                }

                continue;
            }

            if (preg_match('/^\s*```\s*$/', $line) === 1) {
                $name = trim(implode(' ', $figure));

                if (! in_array($name, self::FIGURES, true)) {
                    throw new RuntimeException(
                        'a ```figure fence names `'.$name.'`; expected one of '.implode(', ', self::FIGURES).'.'
                    );
                }

                $segments[] = ['type' => 'figure', 'value' => $name];
                $fence = null;

                continue;
            }

            if (trim($line) !== '') {
                $figure[] = trim($line);
            }
        }

        if ($fence === 'figure') {
            throw new RuntimeException('a ```figure fence is never closed.');
        }

        $flush();

        return $segments;
    }

    private function convert(string $markdown, string $profile): string
    {
        $fragment = trim($this->converter->convert($markdown)->getContent());

        if ($fragment === '') {
            return '';
        }

        $document = HTMLDocument::createFromString(
            '<div id="artfct-blog-root">'.$fragment.'</div>',
            LIBXML_NOERROR,
        );

        $root = $document->getElementById('artfct-blog-root');

        if (! $root instanceof Element) {
            throw new RuntimeException('the rendered fragment could not be parsed.');
        }

        $this->walk($document, $root, $profile);

        $html = '';

        foreach ($root->childNodes as $child) {
            $html .= $document->saveHtml($child);
        }

        return trim($html);
    }

    private function walk(HTMLDocument $document, Node $node, string $profile): void
    {
        foreach (iterator_to_array($node->childNodes) as $child) {
            if (! $child instanceof Element) {
                continue;
            }

            $tag = strtolower($child->nodeName);

            if ($tag === 'code' && $child->getAttribute('class') === 'language-figure') {
                $this->replaceFigure($document, $child);

                continue;
            }

            if ($profile === 'site') {
                $this->applySiteElement($document, $child, $tag);
            } elseif ($tag === 'table') {
                $child->setAttribute('class', 't');
            }

            $this->walk($document, $child, $profile);
        }
    }

    private function applySiteElement(HTMLDocument $document, Element $element, string $tag): void
    {
        // A quote is a single blockquote with inline content in the component the
        // site used to render, not a blockquote holding a paragraph.
        if ($tag === 'blockquote') {
            $this->unwrapSingleParagraph($document, $element);
        }

        // The inline-code class is for code in prose; inside a pre the wrapper
        // carries the styling and the old markup put no class on the inner code.
        // `Dom\Element::nodeName` is upper case for HTML elements, so the parent
        // is compared case-insensitively — a lowercase comparison silently misses.
        $parent = $element->parentNode;

        if ($tag === 'code' && $parent instanceof Element && strtolower($parent->nodeName) === 'pre') {
            $element->removeAttribute('class');
        } elseif (isset(self::SITE_CLASSES[$tag])) {
            $element->setAttribute('class', self::SITE_CLASSES[$tag]);
        }

        if ($tag === 'a') {
            $this->rewriteLink($element);
        }

        if ($tag === 'h2' && preg_match('/^questions?$/i', trim($element->textContent)) === 1) {
            $element->textContent = self::FAQ_HEADING;
        }

        if ($tag === 'table') {
            $wrapper = $document->createElement('div');
            $wrapper->setAttribute('class', self::TABLE_WRAPPER);
            $element->parentNode?->insertBefore($wrapper, $element);
            $wrapper->appendChild($element);
        }
    }

    private function unwrapSingleParagraph(HTMLDocument $document, Element $quote): void
    {
        $children = iterator_to_array($quote->childNodes);
        $elements = array_values(array_filter($children, fn (Node $n): bool => $n instanceof Element));

        if (count($elements) !== 1 || strtolower($elements[0]->nodeName) !== 'p') {
            return;
        }

        foreach (iterator_to_array($elements[0]->childNodes) as $grandchild) {
            $quote->insertBefore($grandchild, $elements[0]);
        }

        $quote->removeChild($elements[0]);
    }

    /**
     * A draft links to `artfct.dev` absolutely, which would send a staging page
     * to production. `url()` resolves the path against the running environment
     * the way the Wayfinder calls did when the body was JSX.
     */
    private function rewriteLink(Element $link): void
    {
        $href = $link->getAttribute('href');
        $parts = parse_url($href === '' ? '/' : $href);

        if (! is_array($parts)) {
            return;
        }

        if (($parts['host'] ?? null) === 'artfct.dev') {
            $suffix = isset($parts['fragment']) ? '#'.$parts['fragment'] : '';
            $link->setAttribute('href', url($parts['path'] ?? '/').$suffix);

            return;
        }

        if (str_starts_with($href, 'http://') || str_starts_with($href, 'https://')) {
            $link->setAttribute('target', '_blank');
            $link->setAttribute('rel', 'noreferrer');
        }
    }

    private function replaceFigure(HTMLDocument $document, Element $code): void
    {
        $name = trim($code->textContent);
        $parent = $code->parentNode;

        if (! in_array($name, self::FIGURES, true)) {
            throw new RuntimeException(
                'a figure fence names `'.$name.'`; expected one of '.implode(', ', self::FIGURES).'.'
            );
        }

        if (! $parent instanceof Element) {
            return;
        }

        $figure = $document->createElement('figure');
        $figure->setAttribute('class', 'fig');
        $caption = $document->createElement('figcaption');
        $caption->textContent = 'Figure: '.$name;
        $figure->appendChild($caption);

        $parent->parentNode?->replaceChild($figure, $parent);
    }
}
