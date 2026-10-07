<?php

use Inertia\Testing\AssertableInertia as Assert;

/**
 * Workflow posts added alongside the team/handoff/search artifacts. Kept in
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
            'How we stopped our AI tools from contradicting each other',
            "Claude knew we'd dropped a phrase. Cursor didn't. How a shared team library lets every AI tool find your latest decisions, with sources, before it writes.",
            '2026-10-05',
            'workflows',
        ],
        'share-ai-agent-knowledge-team' => [
            'share-ai-agent-knowledge-team',
            1,
            'Share AI Agent Knowledge Across Your Team With artfct',
            "Publish an agent's findings to your team's artfct library so other connected agents can find them and use them to guide their next task.",
            '2026-10-04',
            'workflows',
        ],
        'share-context-claude-code-codex-mcp' => [
            'share-context-claude-code-codex-mcp',
            2,
            'Share Context Between Claude Code and Codex With MCP',
            "Save an investigation from Claude Code, find it in Codex, and carry verified findings forward with artfct's hosted MCP server.",
            '2026-10-04',
            'workflows',
        ],
        'semantic-search-ai-generated-reports' => [
            'semantic-search-ai-generated-reports',
            3,
            'Find AI-generated reports when you forget the title',
            'Use semantic search to find published AI reports by the problem they describe, with snippets, provenance and links to inspect the source.',
            '2026-10-04',
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
        ->has('posts', 7)
        ->where("posts.{$index}.slug", $slug));
})->with([
    'developer-tools' => ['developer-tools', 4],
    'ai-presentations' => ['ai-presentations', 5],
    'mermaid-diagrams' => ['mermaid-diagrams', 6],
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
