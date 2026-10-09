<?php

use App\Http\Controllers\ArtifactViewerController;
use App\Http\Controllers\DocsController;
use App\Http\Controllers\JwksController;
use App\Http\Controllers\LegalController;
use App\Http\Controllers\LlmsTextController;
use App\Http\Controllers\PolisWebhookController;
use App\Http\Controllers\StripeWebhookController;
use App\Http\Controllers\WorkerEventController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

$blogPosts = [
    [
        'slug' => 'stop-ai-tools-contradicting-each-other',
        'title' => 'Stopping Claude and Cursor from contradicting each other',
        'date' => '2026-10-05',
        'tag' => 'workflows',
        'description' => 'You drop a phrase in Claude. Cursor brings it back hours later. Here is how a shared library keeps AI tools from repeating decisions you already killed.',
    ],
];

Route::inertia('/', 'landing', [
    'meta' => [
        'title' => 'Artfct — what your AI makes, remembered',
        'description' => 'Share the reports, tables and documents your AI makes, and every AI tool on your team can read them, with sources. Works with Claude, ChatGPT, Copilot, Cursor and other major AI tools.',
    ],
])->name('home');

Route::get('/free', fn () => Inertia::render('welcome', [
    'meta' => [
        'title' => 'artfct — share HTML & markdown instantly',
        'description' => 'Drop a self-contained HTML or Markdown file and get back a private, shareable link. No sign-up required. Encrypted by default.',
    ],
    'mcpEndpoint' => url('/mcp'),
]))->name('free');

Route::get('/docs', DocsController::class)->name('docs');
Route::get('/llms.txt', [LlmsTextController::class, 'index'])->name('llms.index');
Route::get('/llms-full.txt', [LlmsTextController::class, 'full'])->name('llms.full');

Route::get('/terms', [LegalController::class, 'terms'])->name('terms');
Route::get('/privacy', [LegalController::class, 'privacy'])->name('privacy');
Route::middleware('auth')->group(function () {
    Route::get('/terms/accept', [LegalController::class, 'showAcceptance'])->name('terms.accept.show');
    Route::post('/terms/accept', [LegalController::class, 'accept'])->name('terms.accept');
});

Route::inertia('/blog', 'blog', [
    'meta' => [
        'title' => 'blog — artfct',
        'description' => 'Product updates and tips from artfct, where what your AI makes is shared with your team.',
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
        ['loc' => url('/free'), 'priority' => '0.8', 'changefreq' => 'weekly'],
        ['loc' => url('/docs'), 'priority' => '0.8', 'changefreq' => 'weekly'],
        ['loc' => url('/blog'), 'priority' => '0.6', 'changefreq' => 'weekly'],
        ['loc' => url('/terms'), 'priority' => '0.3', 'changefreq' => 'yearly'],
        ['loc' => url('/privacy'), 'priority' => '0.3', 'changefreq' => 'yearly'],
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

// ── artifact viewer (RUB-438 / RUB-439) ──────────────────────────────────────
//
// One short route for every sharing level. The viewer is addressed by artifact
// id alone: a signed-in member's artifact is resolved from their own
// memberships, and an artifact no team of theirs owns is resolved through the
// Worker's public read. A team the visitor is not in is never named; an id
// that is neither the visitor's nor public is one and the same page, or one and
// the same sign-in redirect, so the route is never an existence oracle.
//
// `artifacts.show` and `artifacts.version` are deliberately outside `auth`: a
// public artifact renders for anyone, and a team/private one sends a signed-out
// visitor to sign in and back. A version is named in the path as
// `/a/{id}/v/{n}`; `?version=` on `artifacts.show` stays supported for older
// links. Sharing and download stay authenticated.
Route::get('a/{artifactId}', [ArtifactViewerController::class, 'show'])->name('artifacts.show');
Route::get('a/{artifactId}/v/{version}', [ArtifactViewerController::class, 'show'])
    ->whereNumber('version')
    ->name('artifacts.version');

Route::middleware('auth')->group(function () {
    Route::patch('a/{artifactId}/sharing', [ArtifactViewerController::class, 'updateSharing'])->name('artifacts.sharing.update');
    Route::get('a/{artifactId}/download', [ArtifactViewerController::class, 'download'])->name('artifacts.download');
});

// ── identity (spec 06) ──────────────────────────────────────────────────────

require __DIR__.'/auth.php';
require __DIR__.'/oauth.php';
require __DIR__.'/teams.php';

Route::post('internal/worker-events', WorkerEventController::class)->name('internal.worker-events');

Route::post('webhooks/stripe', StripeWebhookController::class)->name('webhooks.stripe');
Route::post('webhooks/polis', PolisWebhookController::class)->name('webhooks.polis');

Route::get('.well-known/jwks.json', JwksController::class)->name('jwks');
