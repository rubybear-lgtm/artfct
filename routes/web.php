<?php

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
        'slug' => 'share-ai-agent-knowledge-team',
        'title' => 'Share AI Agent Knowledge Across Your Team With artfct',
        'date' => '2026-10-04',
        'tag' => 'workflows',
        'description' => "Publish an agent's findings to your team's artfct library so other connected agents can find them and use them to guide their next task.",
    ],
    [
        'slug' => 'share-context-claude-code-codex-mcp',
        'title' => 'Share Context Between Claude Code and Codex With MCP',
        'date' => '2026-10-04',
        'tag' => 'workflows',
        'description' => "Save an investigation from Claude Code, find it in Codex, and carry verified findings forward with artfct's hosted MCP server.",
    ],
    [
        'slug' => 'semantic-search-ai-generated-reports',
        'title' => 'Find AI-generated reports when you forget the title',
        'date' => '2026-10-04',
        'tag' => 'workflows',
        'description' => 'Use semantic search to find published AI reports by the problem they describe, with snippets, provenance and links to inspect the source.',
    ],
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

// ── identity (spec 06) ──────────────────────────────────────────────────────

require __DIR__.'/auth.php';
require __DIR__.'/oauth.php';
require __DIR__.'/teams.php';

Route::post('internal/worker-events', WorkerEventController::class)->name('internal.worker-events');

Route::post('webhooks/stripe', StripeWebhookController::class)->name('webhooks.stripe');
Route::post('webhooks/polis', PolisWebhookController::class)->name('webhooks.polis');

Route::get('.well-known/jwks.json', JwksController::class)->name('jwks');
