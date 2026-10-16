<?php

declare(strict_types=1);

namespace App\Services\Blog;

use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * The blog's only source of posts: Markdown files with front matter under
 * `skills/artfct-content/drafts/`, parsed on demand.
 *
 * No cache in the first cut. Eight files and one Markdown render each are cheap,
 * and a mtime-keyed cache with a stampede on the marketing page is a worse bug
 * than the milliseconds it would save.
 */
final class BlogContent
{
    private const string FAQ_HEADING = '/^(questions?|frequently asked)/i';

    private const string LIMITS_HEADING = '/^(what this (does not do|does not fix|is not|does not include)|limits|out of scope)/i';

    public function __construct(
        private readonly BlogRenderer $renderer,
        private readonly ?string $directory = null,
    ) {}

    public function directory(): string
    {
        return $this->directory ?? base_path('skills/artfct-content/drafts');
    }

    /**
     * Every file, published or held back, newest first.
     *
     * @return list<BlogPost>
     */
    public function every(): array
    {
        $directory = $this->directory();

        if (! is_dir($directory)) {
            Log::warning('The blog drafts directory is missing; /blog will be empty.', ['path' => $directory]);

            return [];
        }

        $posts = [];
        $seen = [];

        foreach (glob($directory.'/*.md') ?: [] as $path) {
            $post = $this->read($path);

            // Two files claiming one slug would produce a duplicate index row, a
            // duplicate sitemap entry, and a permalink that serves whichever file
            // glob returned first.
            if (isset($seen[$post->slug])) {
                throw new RuntimeException(
                    'two drafts declare the slug `'.$post->slug.'`: '
                    .self::name($seen[$post->slug]).' and '.self::name($path)
                );
            }

            $seen[$post->slug] = $path;
            $posts[] = $post;
        }

        if ($posts === []) {
            Log::warning('The blog drafts directory holds no drafts; /blog will be empty.', ['path' => $directory]);
        }

        usort($posts, fn (BlogPost $a, BlogPost $b): int => [$b->date, $a->slug] <=> [$a->date, $b->slug]);

        return $posts;
    }

    /**
     * The published posts, newest first — the index, the sitemap and the
     * structured data all read this.
     *
     * @return list<BlogPost>
     */
    public function all(): array
    {
        return array_values(array_filter($this->every(), fn (BlogPost $post): bool => $post->published));
    }

    public function find(string $slug): ?BlogPost
    {
        foreach ($this->all() as $post) {
            if ($post->slug === $slug) {
                return $post;
            }
        }

        return null;
    }

    /**
     * A draft by the path it is at, including one that is held back — what
     * `blog:render` needs to review a draft before it is published.
     */
    public function fromPath(string $path): BlogPost
    {
        return $this->read($path);
    }

    /**
     * @return list<array{type: 'html', html: string}|array{type: 'figure', name: string}>
     */
    public function blocks(BlogPost $post): array
    {
        return $this->renderer->blocks($post->markdown);
    }

    /**
     * The review document: the shell, table of contents, byline and `details.q`
     * FAQ that the linter and the publish gate both read, so a draft is reviewed
     * as the page it will become.
     */
    public function standalone(BlogPost $post): string
    {
        $toc = [];
        $sections = [];

        foreach ($post->sections as $section) {
            if ($section->isFaq) {
                $sections[] = [
                    'kind' => 'faq',
                    'heading' => $section->heading,
                    'items' => $this->faqItems($section),
                ];

                continue;
            }

            $entry = [
                'kind' => 'prose',
                'heading' => $section->heading,
                'id' => $section->id,
                'html' => $this->renderer->document($section->body),
                'limits' => $section->isLimits,
            ];

            $sections[] = $entry;

            if (! $section->isLimits) {
                $toc[] = ['id' => $section->id, 'heading' => $section->heading];
            }
        }

        $lead = $this->renderer->document($post->lead);

        // The shell styles the lead, so the first paragraph carries the class.
        // A lead can be more than one block — one post opens with a quote — so
        // the class goes on the first paragraph rather than on a wrapper that
        // would nest a paragraph inside a paragraph.
        $lead = preg_replace('/^<p>/', '<p class="lead">', trim($lead), 1) ?? $lead;

        $prose = $post->lead.' '.implode(' ', array_map(
            fn (BlogSection $section): string => $section->body,
            $post->sections,
        ));

        return view('blog.standalone', [
            'post' => $post,
            'lead' => $lead,
            'sections' => $sections,
            'toc' => $toc,
            'readMinutes' => max(1, (int) round(str_word_count($prose) / 200)),
        ])->render();
    }

    /**
     * A draft's FAQ answers are a question heading and its prose, which the
     * review document renders as `<details class="q">` — the shape the linter
     * counts and the anatomy requires.
     *
     * @return list<array{question: string, answer: string}>
     */
    public function faqItems(BlogSection $section): array
    {
        $items = [];
        $question = null;
        $answer = [];
        $fence = false;
        $flush = function () use (&$items, &$question, &$answer): void {
            if ($question === null) {
                return;
            }

            $items[] = [
                'question' => $question,
                'answer' => $this->renderer->document(implode("\n", $answer)),
            ];

            $question = null;
            $answer = [];
        };

        foreach (explode("\n", $section->body) as $line) {
            if (preg_match('/^\s*(```|~~~)/', $line) === 1) {
                $fence = ! $fence;
            }

            if (! $fence && preg_match('/^###\s+(.*)$/', $line, $matches) === 1) {
                $flush();
                $question = trim($matches[1]);

                continue;
            }

            if ($question !== null) {
                $answer[] = $line;
            }
        }

        $flush();

        return $items;
    }

    private function read(string $path): BlogPost
    {
        $raw = (string) file_get_contents($path);

        [$meta, $markdown] = FrontMatter::split($raw, $path);
        [$lead, $sections] = $this->structure($markdown);

        // The generator this replaces threw for both of these, and the reason
        // still holds: half a page on a live marketing site is worse than a
        // failed request that a test catches first.
        if ($lead === '') {
            throw new RuntimeException(self::name($path).' has no lead paragraph before its first `##`.');
        }

        if ($sections === []) {
            throw new RuntimeException(self::name($path).' has no `##` section.');
        }

        return new BlogPost(
            slug: $meta['slug'],
            title: $meta['title'],
            description: $meta['description'],
            date: $meta['date'],
            tag: $meta['tag'],
            published: $meta['published'] === 'true',
            surface: $meta['surface'],
            register: $meta['register'],
            target: $meta['target'],
            lintSkip: ($meta['lint'] ?? 'run') === 'skip',
            markdown: $markdown,
            lead: $lead,
            sections: $sections,
            path: $path,
        );
    }

    /**
     * The lead is the prose before the first `##`, and the sections are the rest.
     * Fence-aware, so a `##` inside a code block is content rather than structure.
     *
     * @return array{0: string, 1: list<BlogSection>}
     */
    private function structure(string $markdown): array
    {
        $lead = [];
        $sections = [];
        $current = null;
        $fence = false;

        foreach (explode("\n", str_replace("\r\n", "\n", $markdown)) as $line) {
            if (preg_match('/^\s*(```|~~~)/', $line) === 1) {
                $fence = ! $fence;
            }

            if (! $fence && preg_match('/^##\s+(.*)$/', $line, $matches) === 1) {
                if ($current !== null) {
                    $sections[] = $current;
                }

                $current = ['heading' => trim($matches[1]), 'lines' => []];

                continue;
            }

            if ($current === null) {
                $lead[] = $line;

                continue;
            }

            $current['lines'][] = $line;
        }

        if ($current !== null) {
            $sections[] = $current;
        }

        $parsed = [];
        $used = [];

        foreach ($sections as $section) {
            $heading = $section['heading'];

            if ($heading === '') {
                throw new RuntimeException('a `##` heading in the draft is empty.');
            }

            // Two headings that slugify the same would give the review document
            // one anchor for two entries in its own table of contents.
            $id = self::anchor($heading);
            $suffix = 2;

            while (isset($used[$id])) {
                $id = self::anchor($heading).'-'.$suffix;
                $suffix++;
            }

            $used[$id] = true;

            $parsed[] = new BlogSection(
                heading: $heading,
                id: $id,
                body: trim(implode("\n", $section['lines'])),
                isLimits: preg_match(self::LIMITS_HEADING, $heading) === 1,
                isFaq: preg_match(self::FAQ_HEADING, $heading) === 1,
            );
        }

        return [trim(implode("\n", $lead)), $parsed];
    }

    private static function anchor(string $heading): string
    {
        $slug = preg_replace('/[^a-z0-9]+/', '-', strtolower($heading)) ?? '';
        $slug = trim($slug, '-');

        return $slug === '' ? 'section' : $slug;
    }

    private static function name(string $path): string
    {
        return 'skills/artfct-content/drafts/'.basename($path);
    }
}
