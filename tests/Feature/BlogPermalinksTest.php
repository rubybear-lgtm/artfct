<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\File;
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
        $sitemap = File::get(public_path('sitemap.xml'));

        $this->assertStringContainsString(
            'https://artfct.dev/blog/developer-tools',
            $sitemap,
        );
        $this->assertStringContainsString(
            'https://artfct.dev/blog/ai-presentations',
            $sitemap,
        );
        $this->assertStringContainsString(
            'https://artfct.dev/blog/mermaid-diagrams',
            $sitemap,
        );
    }
}
