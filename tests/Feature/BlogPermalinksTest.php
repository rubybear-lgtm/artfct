<?php

namespace Tests\Feature;

use Tests\TestCase;

class BlogPermalinksTest extends TestCase
{
    public function test_it_renders_the_blog_index_with_post_metadata(): void
    {
        $this->get(route('blog'))
            ->assertOk()
            ->assertSee('developer-tools')
            ->assertSee('ai-presentations')
            ->assertSee('mermaid-diagrams');
    }

    public function test_it_renders_a_permalink_for_each_blog_post(): void
    {
        $this->get(route('blog.show', ['slug' => 'mermaid-diagrams']))
            ->assertOk()
            ->assertSee('mermaid-diagrams');
    }

    public function test_it_returns_404_for_unknown_blog_permalinks(): void
    {
        $this->get('/blog/not-a-real-post')->assertNotFound();
    }

    public function test_it_includes_blog_permalinks_in_the_sitemap(): void
    {
        $response = $this->get(route('sitemap'));

        $response->assertOk();
        $response->assertHeader('Content-Type', 'text/xml; charset=UTF-8');

        $response->assertSee(
            route('blog.show', ['slug' => 'developer-tools']),
            escape: false,
        );
        $response->assertSee(
            route('blog.show', ['slug' => 'ai-presentations']),
            escape: false,
        );
        $response->assertSee(
            route('blog.show', ['slug' => 'mermaid-diagrams']),
            escape: false,
        );
    }
}
