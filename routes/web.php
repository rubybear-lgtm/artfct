<?php

use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

$blogPosts = [
    [
        'slug' => 'developer-tools',
        'title' => 'Four developer tools, one skill install',
        'date' => '2026-06-04',
        'tag' => 'skills',
        'description' => 'A walkthrough of the artfct developer-tools skill and the four utilities it deploys.',
    ],
    [
        'slug' => 'ai-presentations',
        'title' => 'AI-generated slide decks, deployed in one step',
        'date' => '2026-06-04',
        'tag' => 'skills',
        'description' => 'How the artfct presentation skill turns a prompt into a shareable HTML deck.',
    ],
    [
        'slug' => 'mermaid-diagrams',
        'title' => 'Share Mermaid diagrams as live links — no screenshots needed',
        'date' => '2026-06-06',
        'tag' => 'skills',
        'description' => 'Why the artfct Mermaid skill exists and how it helps people share diagrams faster.',
    ],
];

Route::inertia('/', 'welcome', [
    'meta' => [
        'title' => 'artfct — share HTML & markdown instantly',
        'description' => 'Drop a self-contained HTML or Markdown file — via browser, CLI, API, or AI agent — and get back a shareable link. No sign-up required. Encrypted by default.',
    ],
])->name('home');

Route::inertia('/docs', 'docs', [
    'meta' => [
        'title' => 'api reference — artfct',
        'description' => 'REST API reference and CLI documentation for artfct. Create, serve, and manage HTML artifacts programmatically.',
    ],
])->name('docs');

Route::inertia('/blog', 'blog', [
    'meta' => [
        'title' => 'blog — artfct',
        'description' => 'Product updates, tips, and behind-the-scenes on artfct — the instant HTML sharing tool for developers.',
    ],
    'posts' => $blogPosts,
])->name('blog');

Route::get('/blog/{slug}', function (string $slug) use ($blogPosts) {
    $post = collect($blogPosts)->first(
        fn (array $candidate): bool => $candidate['slug'] === $slug,
    );

    abort_if($post === null, 404);

    return Inertia::render('blog-show', [
        'meta' => [
            'title' => "{$post['title']} — artfct",
            'description' => $post['description'],
        ],
        'post' => $post,
    ]);
})->where('slug', '[a-z0-9-]+')->name('blog.show');

// ── sitemap ───────────────────────────────────────────────────────────────────

Route::get('/sitemap.xml', function () use ($blogPosts) {
    $urls = [
        ['loc' => url('/'), 'priority' => '1.0', 'changefreq' => 'weekly'],
        ['loc' => url('/docs'), 'priority' => '0.8', 'changefreq' => 'weekly'],
        ['loc' => url('/blog'), 'priority' => '0.6', 'changefreq' => 'weekly'],
    ];

    foreach ($blogPosts as $post) {
        $urls[] = [
            'loc' => route('blog.show', ['slug' => $post['slug']]),
            'priority' => '0.7',
            'changefreq' => 'monthly',
        ];
    }

    $xml = view('sitemap', ['urls' => $urls])->render();

    return response('<?xml version="1.0" encoding="UTF-8"?>'."\n".$xml)
        ->header('Content-Type', 'text/xml');
})->name('sitemap');
