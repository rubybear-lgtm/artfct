<?php

use Inertia\Testing\AssertableInertia as Assert;

/**
 * Every post, in the order the index lists them (newest first, which the blog
 * route sorts for itself). Kept in one place so the shared expectations stay in
 * sync with $blogPosts in routes/web.php.
 *
 * @return array<string, array{0: string, 1: int, 2: string, 3: string, 4: string, 5: string}>
 */
function blogPosts(): array
{
    return [
        'finding-what-your-ai-already-made' => [
            'finding-what-your-ai-already-made',
            0,
            'Finding the report your team already wrote',
            'Each AI tool keeps its own history, so finished work goes missing. Artfct indexes the reports, tables and documents your agents produce, so the next question starts from what already exists.',
            '2026-10-08',
            'search',
        ],
        'who-sees-what-your-ai-makes' => [
            'who-sees-what-your-ai-makes',
            1,
            'Who sees what your AI makes',
            'An artifact is shared with the team unless someone says otherwise, and the level can change at any time. How sharing, isolation and revocation work in Artfct.',
            '2026-10-07',
            'governance',
        ],
        'one-link-that-keeps-its-history' => [
            'one-link-that-keeps-its-history',
            2,
            'One link that keeps its history',
            'Publishing the same content twice resolves to one artifact, and publishing a change creates a new version under the same link. How versions, identity and provenance work in Artfct.',
            '2026-10-06',
            'workflows',
        ],
        'stop-ai-tools-contradicting-each-other' => [
            'stop-ai-tools-contradicting-each-other',
            3,
            'Stopping Claude and Cursor from contradicting each other',
            'You drop a phrase in Claude. Cursor brings it back hours later. Here is how a shared library keeps AI tools from repeating decisions you already killed.',
            '2026-10-05',
            'workflows',
        ],
        'artfct-vs-a-shared-drive-folder' => [
            'artfct-vs-a-shared-drive-folder',
            4,
            'Artfct vs a shared drive: what works better',
            'A shared drive folder holds the files people open. Artfct holds the pages AI tools make, records which tool produced each one, and answers search for the next agent. Where each one fits, and when a team should keep both.',
            '2026-10-04',
            'comparisons',
        ],
        'artfct-vs-your-ai-tools-own-sharing' => [
            'artfct-vs-your-ai-tools-own-sharing',
            5,
            "Artfct vs your AI tool's own sharing: what works better",
            "Claude Code Artifacts and OpenAI's Sites share work inside one vendor. Artfct keeps the same artifacts findable across tools. A comparison of reach, controls, durability and retrieval.",
            '2026-10-03',
            'comparisons',
        ],
        'why-your-ai-tools-cannot-see-each-others-work' => [
            'why-your-ai-tools-cannot-see-each-others-work',
            6,
            "Why your AI tools cannot see each other's work",
            'Every AI tool keeps its own record of what it made, so the next agent rebuilds work another tool already finished. Why that boundary exists, and what changes when the record is shared.',
            '2026-10-02',
            'explainers',
        ],
        'what-is-an-ai-artifact' => [
            'what-is-an-ai-artifact',
            7,
            'What is an AI artifact?',
            'An AI artifact is a finished work product an AI tool made and a person can open: a report, dashboard, table or document. This explainer defines the term, separates it from chat scrollback and memory notes, and says where a record of finished work fits.',
            '2026-10-01',
            'explainers',
        ],
    ];
}

test('blog index lists every post at its expected position', function (string $slug, int $index) {
    /** @var TestResponse $response */
    $response = $this->get(route('blog'));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page) => $page
        ->component('blog')
        ->has('posts', count(blogPosts()))
        ->where("posts.{$index}.slug", $slug));
})->with([
    ...collect(blogPosts())
        ->map(fn (array $post): array => [$post[0], $post[1]])
        ->all(),
]);

test('each post lists its full metadata on the blog index', function (string $slug, int $index, string $title, string $description, string $date, string $tag) {
    /** @var TestResponse $response */
    $response = $this->get(route('blog'));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page) => $page
        ->component('blog')
        ->where("posts.{$index}.title", $title)
        ->where("posts.{$index}.description", $description)
        ->where("posts.{$index}.date", $date)
        ->where("posts.{$index}.tag", $tag));
})->with(blogPosts());

test('each post renders its permalink with the Inertia page and SEO meta', function (string $slug, int $index, string $title, string $description, string $date, string $tag) {
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

    // The title is HTML-escaped in <title>, so a title with an apostrophe
    // renders as &#039;. Escape the expectation the same way rather than
    // weakening the assertion.
    $response->assertSee(e($title).' — artfct', escape: false);
})->with(blogPosts());

test('sitemap includes each post permalink', function (string $slug) {
    /** @var TestResponse $response */
    $response = $this->get(route('sitemap'));

    $response->assertOk();
    $response->assertHeader('Content-Type', 'text/xml; charset=UTF-8');

    $response->assertSee(route('blog.show', ['slug' => $slug]), escape: false);
})->with(blogPosts());

test('blog returns 404 for an unknown permalink', function () {
    $this->get('/blog/not-a-real-post')->assertNotFound();
});

/**
 * Every draft in skills/artfct-content/drafts, split by its data-published flag.
 *
 * A draft carrying data-published="false" is held back: it is written, linted and
 * reviewed, but it is not part of the published blog and deliberately has no row in
 * $blogPosts. Anything else is expected to be published, and therefore to have one.
 *
 * @return array{published: array<string, array{title: string, description: string}>, unpublished: array<string, array{title: string, description: string}>}
 */
function draftsByPublication(): array
{
    $published = [];
    $unpublished = [];

    foreach (glob(base_path('skills/artfct-content/drafts/*.html')) ?: [] as $path) {
        $html = (string) file_get_contents($path);

        preg_match('/<meta name="description" content="(.*?)"/s', $html, $description);
        preg_match('/<h1>(.*?)<\/h1>/s', $html, $title);

        $draft = [
            'title' => html_entity_decode(trim($title[1] ?? ''), ENT_QUOTES),
            'description' => html_entity_decode(trim($description[1] ?? ''), ENT_QUOTES),
        ];

        if (str_contains($html, 'data-published="false"')) {
            $unpublished[basename($path, '.html')] = $draft;
        } else {
            $published[basename($path, '.html')] = $draft;
        }
    }

    return ['published' => $published, 'unpublished' => $unpublished];
}

/**
 * The draft is the source of truth and the row is generated from it, so the two
 * cannot be allowed to drift. Without this, editing a draft's headline or meta
 * description silently leaves the index showing the old copy.
 */
test('every published draft matches the post row generated from it', function () {
    $drafts = draftsByPublication()['published'];

    expect($drafts)->not->toBeEmpty();

    $response = $this->get(route('blog'));

    $response->assertOk();
    $response->assertInertia(function (Assert $page) use ($drafts) {
        $posts = collect($page->toArray()['props']['posts'])->keyBy('slug');

        foreach ($drafts as $slug => $draft) {
            expect($posts->has($slug))->toBeTrue("no blogPosts row for the draft {$slug}");
            expect($posts[$slug]['title'])->toBe($draft['title'], "title drifted for {$slug}");
            expect($posts[$slug]['description'])->toBe($draft['description'], "description drifted for {$slug}");
        }
    });
});

/**
 * Held-back drafts are the exception the parity test carves out, so the carve-out
 * needs a test of its own: an unpublished draft must not be readable at its
 * permalink, which is what makes "unpublished" a property of the app rather than a
 * note in a filename.
 */
test('an unpublished draft is not reachable at its permalink', function () {
    $unpublished = draftsByPublication()['unpublished'];

    expect($unpublished)->not->toBeEmpty();

    foreach (array_keys($unpublished) as $slug) {
        $this->get("/blog/{$slug}")->assertNotFound();
    }
});

test('an unpublished draft is absent from the blog index and the sitemap', function () {
    $slugs = array_keys(draftsByPublication()['unpublished']);

    expect($slugs)->not->toBeEmpty();

    $this->get(route('blog'))->assertInertia(function (Assert $page) use ($slugs) {
        $indexed = collect($page->toArray()['props']['posts'])->pluck('slug');

        expect($indexed->intersect($slugs)->all())->toBe([]);
    });

    $sitemap = (string) $this->get(route('sitemap'))->getContent();

    foreach ($slugs as $slug) {
        expect($sitemap)->not->toContain("/blog/{$slug}");
    }
});

test('the index is ordered newest first', function () {
    /** @var TestResponse $response */
    $response = $this->get(route('blog'));

    $response->assertOk();
    $response->assertInertia(function (Assert $page) {
        $dates = collect($page->toArray()['props']['posts'])->pluck('date');

        expect($dates->all())->toBe($dates->sortDesc()->values()->all());
    });
});
