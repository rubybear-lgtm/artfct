<?php

use App\Services\Blog\BlogContent;
use App\Services\Blog\BlogRenderer;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * The drafts directory, without going through the container.
 *
 * Pest resolves a `->with(...)` closure before it boots the application, so a
 * dataset that called `base_path()` failed with "Call to undefined method
 * Illuminate\Container\Container::basePath()" — every dataset-driven test in
 * this file reported as an invalid data provider rather than failing.
 */
function draftsPath(): string
{
    return dirname(__DIR__, 2).'/skills/artfct-content/drafts';
}

/**
 * The drafts as files, read here rather than through the service, so these are
 * not the application agreeing with itself.
 *
 * @return list<array{slug: string, title: string, description: string, date: string, tag: string, published: bool}>
 */
function blogMarkdown(): array
{
    $posts = [];

    foreach (glob(draftsPath().'/*.md') ?: [] as $path) {
        $raw = (string) file_get_contents($path);
        $meta = [];

        foreach (explode("\n", substr($raw, 4, (int) strpos($raw, "\n---\n", 4) - 4)) as $line) {
            if (str_contains($line, ':')) {
                [$key, $value] = explode(':', $line, 2);
                $meta[trim($key)] = trim($value);
            }
        }

        $posts[] = [
            'slug' => $meta['slug'] ?? '',
            'title' => $meta['title'] ?? '',
            'description' => $meta['description'] ?? '',
            'date' => $meta['date'] ?? '',
            'tag' => $meta['tag'] ?? '',
            'published' => ($meta['published'] ?? 'false') === 'true',
        ];
    }

    usort($posts, fn (array $a, array $b): int => [$b['date'], $a['slug']] <=> [$a['date'], $b['slug']]);

    return $posts;
}

/**
 * @return list<array{slug: string, title: string, description: string, date: string, tag: string, published: bool}>
 */
function publishedBlogMarkdown(): array
{
    return array_values(array_filter(blogMarkdown(), fn (array $post): bool => $post['published']));
}

/**
 * @return list<array{slug: string, title: string, description: string, date: string, tag: string, published: bool}>
 */
function heldBackBlogMarkdown(): array
{
    return array_values(array_filter(blogMarkdown(), fn (array $post): bool => ! $post['published']));
}

function blogDataset(): array
{
    return collect(publishedBlogMarkdown())
        ->mapWithKeys(fn (array $post, int $index): array => [$post['slug'] => [$post['slug'], $index]])
        ->all();
}

function blogFileContents(): array
{
    $files = [];

    foreach (publishedBlogMarkdown() as $post) {
        $files[] = [
            'post' => $post,
            'markdown' => (string) file_get_contents(draftsPath().'/'.$post['slug'].'.md'),
        ];
    }

    return $files;
}

/**
 * A BlogContent over a directory of its own, so a test that needs a broken or
 * brand-new draft never writes into the real content directory.
 *
 * @param  array<string, string>  $files
 */
function blogTempContent(array $files): BlogContent
{
    $directory = sys_get_temp_dir().'/artfct-blog-'.bin2hex(random_bytes(6));

    mkdir($directory);

    foreach ($files as $name => $contents) {
        file_put_contents($directory.'/'.$name, $contents);
    }

    return new BlogContent(new BlogRenderer, $directory);
}

function blogDraft(string $slug, string $extra = ''): string
{
    return <<<MD
---
slug: {$slug}
title: A post about a temporary subject
description: A description long enough to be real copy and short enough to stay inside the band the linter enforces.
date: 2030-01-01
tag: workflows
published: true
surface: explainer
register: glean-formal
target: a temporary question
---
{$extra}
MD;
}

test('the drafts directory resolves and holds published posts', function () {
    expect(is_dir(base_path('skills/artfct-content/drafts')))->toBeTrue()
        ->and(publishedBlogMarkdown())->not->toBeEmpty()
        ->and(heldBackBlogMarkdown())->not->toBeEmpty();
});

test('the index lists every published file', function () {
    $this->get(route('blog'))
        ->assertOk()
        ->assertInertia(function (Assert $page) {
            $slugs = collect($page->toArray()['props']['posts'])->pluck('slug');

            expect($slugs->all())->toBe(array_column(publishedBlogMarkdown(), 'slug'));
        });
});

test('each published file appears on the index at its date-ordered position', function (string $slug, int $index) {
    $this->get(route('blog'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where("posts.{$index}.slug", $slug));
})->with(fn (): array => blogDataset());

test('each published file carries its own front matter onto the index', function (array $post) {
    $this->get(route('blog'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where("posts.{$post['index']}.title", $post['title'])
            ->where("posts.{$post['index']}.description", $post['description'])
            ->where("posts.{$post['index']}.date", $post['date'])
            ->where("posts.{$post['index']}.tag", $post['tag']));
})->with(fn (): array => collect(publishedBlogMarkdown())
    ->values()
    ->map(fn (array $post, int $index): array => ['post' => [...$post, 'index' => $index]])
    ->all());

test('each published permalink renders its own front matter, SEO meta and title', function (array $post) {
    $response = $this->get(route('blog.show', ['slug' => $post['slug']]));

    $response->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('blog-show')
            ->where('post.slug', $post['slug'])
            ->where('post.title', $post['title'])
            ->where('post.description', $post['description'])
            ->where('post.date', $post['date'])
            ->where('post.tag', $post['tag'])
            ->where('meta.title', $post['title'].' — artfct')
            ->where('meta.description', $post['description']));

    // The served `<title>`, not only the prop: this is the string the search
    // result is built from, and the one `E12` measures. It is HTML-escaped in
    // the document, so the expectation is escaped the same way rather than
    // weakened.
    $response->assertSee(e($post['title']).' — artfct', escape: false);
})->with(fn (): array => collect(publishedBlogMarkdown())
    ->mapWithKeys(fn (array $post): array => [$post['slug'] => [$post]])
    ->all());

/**
 * Whether a draft opens a code fence (as opposed to a figure fence, whose
 * closing line is a bare ``` that a naive pattern reads as code).
 */
function fileHasCodeFence(string $markdown): bool
{
    $open = false;

    foreach (explode("\n", $markdown) as $line) {
        if (preg_match('/^```(.*)$/', $line, $matches) !== 1) {
            continue;
        }

        if ($open) {
            $open = false;

            continue;
        }

        if (trim($matches[1]) !== 'figure') {
            return true;
        }

        $open = true;
    }

    return false;
}

test('every block a published file carries reaches the rendered article', function (array $post, string $markdown) {
    $this->get(route('blog.show', ['slug' => $post['slug']]))
        ->assertOk()
        ->assertInertia(function (Assert $page) use ($post, $markdown) {
            $html = collect($page->toArray()['props']['post']['blocks'])
                ->where('type', 'html')
                ->pluck('html')
                ->implode("\n");

            $markers = [
                'h2' => ['/^## /m', '<h2'],
                'h3' => ['/^### /m', '<h3'],
                'list' => ['/^- /m', '<ul'],
                'table' => ['/^\| /m', '<table'],
                'quote' => ['/^> /m', '<blockquote'],
            ];

            foreach ($markers as $name => [$pattern, $tag]) {
                if (preg_match($pattern, $markdown) !== 1) {
                    continue;
                }

                $this->assertStringContainsString(
                    $tag,
                    $html,
                    "the {$name} in {$post['slug']} never reached the rendered article",
                );
            }

            if (fileHasCodeFence($markdown)) {
                $this->assertStringContainsString(
                    '<pre',
                    $html,
                    "the code block in {$post['slug']} never reached the rendered article",
                );
            }
        });
})->with(fn (): array => blogFileContents());

test('a new published file is enough for the index, the permalink and the sitemap', function () {
    app()->instance(BlogContent::class, blogTempContent([
        'a-temporary-post.md' => blogDraft(
            'a-temporary-post',
            "A lead paragraph for a temporary post.\n\n## A temporary section\n\nThe body of a temporary post, which the permalink should render.\n",
        ),
    ]));

    $this->get(route('blog'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('posts.0.slug', 'a-temporary-post'));

    $this->get('/blog/a-temporary-post')
        ->assertOk()
        ->assertSee('The body of a temporary post');

    expect((string) $this->get(route('sitemap'))->getContent())
        ->toContain('/blog/a-temporary-post');
});

test('an article arrives as ordered blocks, not one html string', function () {
    $this->get(route('blog.show', ['slug' => 'what-is-an-ai-artifact']))
        ->assertOk()
        ->assertInertia(function (Assert $page) {
            $blocks = $page->toArray()['props']['post']['blocks'];

            expect($blocks)->not->toBeEmpty()
                ->and(collect($blocks)->every(fn (array $block): bool => in_array($block['type'], ['html', 'figure'], true)))->toBeTrue();

            $html = collect($blocks)->where('type', 'html')->pluck('html')->implode('');

            // The named blocks this post actually carries, not only "some blocks
            // arrived": this is the assertion that fails if a renderer drops a
            // section. The per-file test below covers every other block type
            // across the whole corpus.
            expect($html)->toContain('<h2')
                ->toContain('<h3')
                ->toContain('<pre')
                ->toContain('<ul');
        });
});

test('the sitemap includes every published permalink', function () {
    $sitemap = (string) $this->get(route('sitemap'))->getContent();

    foreach (publishedBlogMarkdown() as $post) {
        expect($sitemap)->toContain(route('blog.show', ['slug' => $post['slug']]));
    }
});

test('an unknown permalink returns 404', function () {
    $this->get('/blog/not-a-real-post')->assertNotFound();
});

test('a held-back file is absent from the index and the sitemap', function () {
    $slugs = array_column(heldBackBlogMarkdown(), 'slug');

    $this->get(route('blog'))->assertInertia(function (Assert $page) use ($slugs) {
        $indexed = collect($page->toArray()['props']['posts'])->pluck('slug');

        expect($indexed->intersect($slugs)->all())->toBe([]);
    });

    $sitemap = (string) $this->get(route('sitemap'))->getContent();

    foreach ($slugs as $slug) {
        expect($sitemap)->not->toContain('/blog/'.$slug);
    }
});

test('a held-back file is not reachable at its permalink', function () {
    foreach (array_column(heldBackBlogMarkdown(), 'slug') as $slug) {
        $this->get('/blog/'.$slug)->assertNotFound();
    }
});

test('the index is ordered newest first', function () {
    $this->get(route('blog'))->assertInertia(function (Assert $page) {
        $dates = collect($page->toArray()['props']['posts'])->pluck('date');

        expect($dates->all())->toBe($dates->sortDesc()->values()->all());
    });
});

test('the faq heading renders as the site calls it', function () {
    $this->get(route('blog.show', ['slug' => 'what-is-an-ai-artifact']))
        ->assertOk()
        ->assertInertia(function (Assert $page) {
            $html = collect($page->toArray()['props']['post']['blocks'])
                ->where('type', 'html')
                ->pluck('html')
                ->implode('');

            expect($html)->toContain('Frequently asked questions')
                ->not->toContain('>Questions<');
        });
});

test('a figure block sits where the file puts it', function () {
    $this->get(route('blog.show', ['slug' => 'stop-ai-tools-contradicting-each-other']))
        ->assertOk()
        ->assertInertia(function (Assert $page) {
            $blocks = $page->toArray()['props']['post']['blocks'];
            $figures = array_values(array_filter($blocks, fn (array $block): bool => $block['type'] === 'figure'));

            expect(array_column($figures, 'name'))->toBe(['break', 'loop']);

            // Both figures sit after prose, and the first is the second block.
            expect($blocks[1]['type'])->toBe('figure')
                ->and($blocks[0]['type'])->toBe('html')
                ->and(end($blocks)['type'])->toBe('html');
        });
});

test('the post metadata has exactly one home', function () {
    expect(file_exists(resource_path('js/lib/posts.tsx')))->toBeFalse()
        ->and(file_get_contents(base_path('routes/web.php')))->not->toContain('$blogPosts');
});

test('an absent drafts directory yields an empty index rather than an error', function () {
    app()->instance(BlogContent::class, new BlogContent(new BlogRenderer, '/tmp/nonexistent-artfct-drafts'));

    $this->get(route('blog'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->has('posts', 0));
});

test('a file with no section fails instead of rendering half a page', function () {
    $content = blogTempContent(['no-section.md' => blogDraft('no-section', "Only a lead paragraph, and no section.\n")]);

    expect(fn (): array => $content->every())
        ->toThrow(RuntimeException::class, 'has no `##` section');
});

test('a file with no lead fails instead of rendering a bare heading', function () {
    $content = blogTempContent(['no-lead.md' => blogDraft('no-lead', "## A section\n\nBody with no lead before it.\n")]);

    expect(fn (): array => $content->every())
        ->toThrow(RuntimeException::class, 'has no lead paragraph');
});

test('two files declaring one slug fail naming both', function () {
    $content = blogTempContent([
        'first.md' => blogDraft('shared-slug', "A lead.\n\n## A section\n\nBody.\n"),
        'second.md' => blogDraft('shared-slug', "Another lead.\n\n## Another section\n\nBody.\n"),
    ]);

    expect(fn (): array => $content->every())
        ->toThrow(RuntimeException::class, 'two drafts declare the slug `shared-slug`');
});

test('an empty drafts directory is reported rather than served silently', function () {
    Log::spy();

    expect(blogTempContent([])->all())->toBe([]);

    Log::shouldHaveReceived('warning');
});
