<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\Blog\BlogContent;
use App\Services\Blog\BlogPost;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The blog, from the Markdown files that are the only copy of any of it.
 *
 * A controller rather than a `Route::inertia()` closure prop: the index, the
 * permalink and `standalone()` all read the same metadata, and that metadata
 * lives one layer down in `BlogContent`.
 */
class BlogController extends Controller
{
    public function __construct(private readonly BlogContent $content) {}

    public function index(): Response
    {
        return Inertia::render('blog', [
            'meta' => [
                'title' => 'blog — artfct',
                'description' => 'Product updates and tips from artfct, where what your AI makes is shared with your team.',
            ],
            'posts' => array_map(
                fn (BlogPost $post): array => $post->toIndexArray(),
                $this->content->all(),
            ),
        ]);
    }

    public function show(string $slug): Response
    {
        $post = $this->content->find($slug);

        abort_if($post === null, 404);

        return Inertia::render('blog-show', [
            'meta' => [
                'title' => $post->metaTitle(),
                'description' => $post->description,
            ],
            'post' => [
                ...$post->toIndexArray(),
                'blocks' => $this->content->blocks($post),
            ],
        ]);
    }
}
