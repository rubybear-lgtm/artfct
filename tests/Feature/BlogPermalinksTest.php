<?php

namespace Tests\Feature;

use Tests\TestCase;

class BlogPermalinksTest extends TestCase
{
    public function test_it_renders_the_blog_index_with_post_metadata(): void
    {
        $this->get(route('blog'))
            ->assertOk()
            ->assertSee('stop-ai-tools-contradicting-each-other');
    }

    public function test_it_renders_a_permalink_for_the_blog_post(): void
    {
        $this->get(route('blog.show', ['slug' => 'stop-ai-tools-contradicting-each-other']))
            ->assertOk()
            ->assertSee('stop-ai-tools-contradicting-each-other');
    }

    public function test_it_returns_404_for_removed_and_unknown_blog_permalinks(): void
    {
        $this->get('/blog/not-a-real-post')->assertNotFound();
        $this->get('/blog/mermaid-diagrams')->assertNotFound();
    }

    public function test_it_includes_the_blog_permalink_in_the_sitemap(): void
    {
        $response = $this->get(route('sitemap'));

        $response->assertOk();
        $response->assertHeader('Content-Type', 'text/xml; charset=UTF-8');
        $response->assertSee(
            route('blog.show', ['slug' => 'stop-ai-tools-contradicting-each-other']),
            escape: false,
        );
    }

    public function test_the_blog_post_declares_article_metadata_and_blogposting_structured_data(): void
    {
        $response = $this->get(route('blog.show', ['slug' => 'stop-ai-tools-contradicting-each-other']));

        $response->assertOk();
        $response->assertSee('<meta property="og:type" content="article" />', escape: false);
        $response->assertSee('"@context":"https://schema.org"', escape: false);
        $response->assertSee('"@type":"BlogPosting"', escape: false);
        $response->assertSee('"datePublished":"2026-10-05"', escape: false);
    }

    public function test_the_blog_index_stays_a_website_without_blogposting_data(): void
    {
        $response = $this->get(route('blog'));

        $response->assertOk();
        $response->assertSee('<meta property="og:type" content="website" />', escape: false);
        $response->assertDontSee('BlogPosting', escape: false);
    }
}
