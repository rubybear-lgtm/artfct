<?php

use Illuminate\Support\Facades\Route;

Route::inertia('/', 'welcome', [
    'meta' => [
        'title' => 'artfct — share HTML & markdown instantly',
        'description' => 'Drop a self-contained HTML or Markdown file — via browser, CLI, API, or AI agent — and get back a shareable link. No sign-up required. Ephemeral by default.',
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
])->name('blog');
