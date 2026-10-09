<?php

use Inertia\Testing\AssertableInertia as Assert;

/**
 * The workflow post. Kept in
 * one place so the shared expectations stay in sync with routes/web.php.
 *
 * @return array<string, array{0: string, 1: int, 2: string, 3: string, 4: string, 5: string, 6: string}>
 */
function workflowPosts(): array
{
    return [
        'stop-ai-tools-contradicting-each-other' => [
            'stop-ai-tools-contradicting-each-other',
            0,
            'Stopping Claude and Cursor from contradicting each other',
            'You drop a phrase in Claude. Cursor brings it back hours later. Here is how a shared library keeps AI tools from repeating decisions you already killed.',
            '2026-10-05',
            'workflows',
        ],
    ];
}

test('blog index lists every post including the workflow posts', function (string $slug, int $index) {
    /** @var TestResponse $response */
    $response = $this->get(route('blog'));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page) => $page
        ->component('blog')
        ->has('posts', 1)
        ->where("posts.{$index}.slug", $slug));
})->with([
    ...collect(workflowPosts())
        ->map(fn (array $post): array => [$post[0], $post[1]])
        ->all(),
]);

test('each workflow post lists its full metadata on the blog index', function (string $slug, int $index, string $title, string $description, string $date, string $tag) {
    /** @var TestResponse $response */
    $response = $this->get(route('blog'));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page) => $page
        ->component('blog')
        ->where("posts.{$index}.title", $title)
        ->where("posts.{$index}.description", $description)
        ->where("posts.{$index}.date", $date)
        ->where("posts.{$index}.tag", $tag));
})->with(workflowPosts());

test('each workflow post renders its permalink with the Inertia page and SEO meta', function (string $slug, int $index, string $title, string $description, string $date, string $tag) {
    /** @var TestResponse $response */
    $response = $this->get(route('blog.show', ['slug' => $slug]));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page) => $page
        ->component('blog-show')
        ->where('post.slug', $slug)
        ->where('post.title', $title)
        ->where('post.description', $description)
        ->where('post.date', $date)
        ->where('post.tag', $tag)
        ->where('meta.title', "{$title} — artfct")
        ->where('meta.description', $description));

    $response->assertSee("{$title} — artfct", escape: false);
})->with(workflowPosts());

test('sitemap includes each workflow post permalink', function (string $slug) {
    /** @var TestResponse $response */
    $response = $this->get(route('sitemap'));

    $response->assertOk();
    $response->assertHeader('Content-Type', 'text/xml; charset=UTF-8');

    $response->assertSee(route('blog.show', ['slug' => $slug]), escape: false);
})->with(workflowPosts());

test('blog returns 404 for an unknown permalink', function () {
    $this->get('/blog/not-a-real-post')->assertNotFound();
});
