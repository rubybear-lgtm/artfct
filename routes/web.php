<?php

use App\Http\Controllers\ArtifactViewerController;
use App\Http\Controllers\DocsController;
use App\Http\Controllers\JwksController;
use App\Http\Controllers\LandingMediaController;
use App\Http\Controllers\LegalController;
use App\Http\Controllers\LlmsTextController;
use App\Http\Controllers\PolisWebhookController;
use App\Http\Controllers\StripeWebhookController;
use App\Http\Controllers\WorkerEventController;
use App\Support\AiToolSetup;
use App\Support\LandingMedia;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

$blogPosts = [
    [
        'slug' => 'finding-what-your-ai-already-made',
        'title' => 'Finding the report your team already wrote',
        'date' => '2026-10-08',
        'tag' => 'search',
        'description' => 'Each AI tool keeps its own history, so finished work goes missing. Artfct indexes the reports, tables and documents your agents produce, so the next question starts from what already exists.',
    ],
    [
        'slug' => 'who-sees-what-your-ai-makes',
        'title' => 'Who sees what your AI makes',
        'date' => '2026-10-07',
        'tag' => 'governance',
        'description' => 'An artifact is shared with the team unless someone says otherwise, and the level can change at any time. How sharing, isolation and revocation work in Artfct.',
    ],
    [
        'slug' => 'one-link-that-keeps-its-history',
        'title' => 'One link that keeps its history',
        'date' => '2026-10-06',
        'tag' => 'workflows',
        'description' => 'Publishing the same content twice resolves to one artifact, and publishing a change creates a new version under the same link. How versions, identity and provenance work in Artfct.',
    ],
    [
        'slug' => 'stop-ai-tools-contradicting-each-other',
        'title' => 'Stopping Claude and Cursor from contradicting each other',
        'date' => '2026-10-05',
        'tag' => 'workflows',
        'description' => 'You drop a phrase in Claude. Cursor brings it back hours later. Here is how a shared library keeps AI tools from repeating decisions you already killed.',
    ],
    [
        'slug' => 'why-your-ai-tools-cannot-see-each-others-work',
        'title' => 'Why your AI tools cannot see each other\'s work',
        'date' => '2026-10-02',
        'tag' => 'explainers',
        'description' => 'Every AI tool keeps its own record of what it made, so the next agent rebuilds work another tool already finished. Why that boundary exists, and what changes when the record is shared.',
    ],
    [
        'slug' => 'what-is-an-ai-artifact',
        'title' => 'What is an AI artifact?',
        'date' => '2026-10-01',
        'tag' => 'explainers',
        'description' => 'An AI artifact is a finished work product an AI tool made and a person can open: a report, dashboard, table or document. This explainer defines the term, separates it from chat scrollback and memory notes, and says where a record of finished work fits.',
    ],
    [
        'slug' => 'artfct-vs-a-shared-drive-folder',
        'title' => 'Artfct vs a shared drive: what works better',
        'date' => '2026-10-04',
        'tag' => 'comparisons',
        'description' => 'A shared drive folder holds the files people open. Artfct holds the pages AI tools make, records which tool produced each one, and answers search for the next agent. Where each one fits, and when a team should keep both.',
    ],
    [
        'slug' => 'artfct-vs-your-ai-tools-own-sharing',
        'title' => "Artfct vs your AI tool's own sharing: what works better",
        'date' => '2026-10-03',
        'tag' => 'comparisons',
        'description' => "Claude Code Artifacts and OpenAI's Sites share work inside one vendor. Artfct keeps the same artifacts findable across tools. A comparison of reach, controls, durability and retrieval.",
    ],
];

Route::get('/', fn () => Inertia::render('landing', [
    'meta' => [
        'title' => 'Artfct — what your AI makes, remembered',
        'description' => 'Share the reports, tables and documents your AI makes, and every AI tool on your team can read them, with sources. Works with Claude, ChatGPT, Copilot, Cursor and other major AI tools.',
    ],
    'flow' => LandingMedia::forPage(),
]))->name('home');

Route::get('/media/landing/{file}', LandingMediaController::class)
    ->withoutMiddleware('web')
    ->name('landing.media');

Route::get('/free', fn () => Inertia::render('welcome', [
    'meta' => [
        'title' => 'artfct — share HTML & markdown instantly',
        'description' => 'Drop a self-contained HTML or Markdown file and get back a private, shareable link. No sign-up required. Encrypted by default.',
    ],
    'mcpEndpoint' => url('/mcp'),
]))->name('free');

Route::get('/docs', DocsController::class)->name('docs');

// One page per connectable AI tool (spec 12's programmatic surface). The
// guides come from AiToolSetup, the same source the docs page and
// /llms-full.txt render from, so a setup step cannot drift between them.
Route::get('/connect/{tool}', function (string $tool) {
    $setup = AiToolSetup::forCurrentEnvironment();
    $guides = collect($setup->guides());
    $guide = $guides->firstWhere('id', $tool);

    abort_if($guide === null, 404);

    $status = $guide['verified']
        ? 'The path is verified end to end against staging.'
        : 'The path is documented; it has not been verified end to end.';

    return Inertia::render('connect-show', [
        'meta' => [
            'title' => "Connect {$guide['label']} to Artfct",
            'description' => "Add Artfct to {$guide['label']} and give it your team's shared store. {$status}",
        ],
        'guide' => $guide,
        'mcpUrl' => $setup->mcpUrl(),
        'quickInstall' => $setup->quickInstall(),
        'related' => $guides
            ->reject(fn (array $candidate): bool => $candidate['id'] === $tool)
            ->map(fn (array $candidate): array => [
                'id' => $candidate['id'],
                'label' => $candidate['label'],
                'verified' => $candidate['verified'],
            ])
            ->values()
            ->all(),
        'troubleshooting' => array_slice($setup->troubleshooting(), 0, 3),
    ]);
})->where('tool', '[a-z0-9-]+')->name('connect.show');
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
    // Newest first, computed rather than relying on the literal's order, so a
    // new post cannot land in the wrong place on the index.
    'posts' => collect($blogPosts)->sortByDesc('date')->values()->all(),
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

    foreach (AiToolSetup::forCurrentEnvironment()->guides() as $guide) {
        $urls[] = [
            'loc' => route('connect.show', ['tool' => $guide['id']]),
            'priority' => '0.6',
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
    Route::post('a/{artifactId}/comments', [ArtifactViewerController::class, 'storeComment'])->middleware('throttle:artifact-comment-store')->name('artifacts.comments.store');
    Route::patch('a/{artifactId}/comments/{commentId}', [ArtifactViewerController::class, 'resolveComment'])->whereNumber('commentId')->middleware('throttle:artifact-comment-resolve')->name('artifacts.comments.resolve');
    Route::get('a/{artifactId}/download', [ArtifactViewerController::class, 'download'])->name('artifacts.download');
});

// ── identity (spec 06) ──────────────────────────────────────────────────────

require __DIR__.'/auth.php';
require __DIR__.'/oauth.php';
require __DIR__.'/teams.php';
require __DIR__.'/ops.php';

Route::post('internal/worker-events', WorkerEventController::class)->name('internal.worker-events');

Route::post('webhooks/stripe', StripeWebhookController::class)->name('webhooks.stripe');
Route::post('webhooks/polis', PolisWebhookController::class)->name('webhooks.polis');

Route::get('.well-known/jwks.json', JwksController::class)->name('jwks');
