<?php

use App\Http\Controllers\ArtifactViewerController;
use App\Http\Controllers\BlogController;
use App\Http\Controllers\ConnectController;
use App\Http\Controllers\DocsController;
use App\Http\Controllers\JwksController;
use App\Http\Controllers\LandingMediaController;
use App\Http\Controllers\LegalController;
use App\Http\Controllers\LlmsTextController;
use App\Http\Controllers\McpServerCardController;
use App\Http\Controllers\PolisWebhookController;
use App\Http\Controllers\RobotsController;
use App\Http\Controllers\SitemapController;
use App\Http\Controllers\StripeWebhookController;
use App\Http\Controllers\WorkerEventController;
use App\Services\Blog\BlogContent;
use App\Services\Blog\BlogPost;
use App\Support\AiToolSetup;
use App\Support\LandingMedia;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

// The homepage links every setup guide and the newest posts so crawlers reach
// them from `/`, not only from the sitemap (RUB-488).
Route::get('/', fn (BlogContent $blog) => Inertia::render('landing', [
    'meta' => [
        'title' => 'Artfct: shared context and AI artifacts for every agent',
        'description' => 'Artfct indexes the reports, specs, tables and mockups your agents make, so Claude, Cursor, Codex and ChatGPT can search, read and cite each other’s work.',
    ],
    'flow' => LandingMedia::forPage(),
    'agentSetupPrompt' => AiToolSetup::forCurrentEnvironment()->agentSetupPrompt(),
    'connectGuides' => collect(AiToolSetup::forCurrentEnvironment()->guides())
        ->map(fn (array $guide): array => ['id' => $guide['id'], 'label' => $guide['label']])
        ->values()
        ->all(),
    'latestPosts' => collect($blog->all())
        ->take(3)
        ->map(fn (BlogPost $post): array => ['slug' => $post->slug, 'title' => $post->title])
        ->values()
        ->all(),
]))->name('home');

Route::get('/media/landing/{file}', LandingMediaController::class)
    ->withoutMiddleware('web')
    ->name('landing.media');

Route::get('/free', fn () => Inertia::render('welcome', [
    'meta' => [
        'title' => 'Share an HTML or Markdown file as a private link — Artfct',
        'description' => 'Drop a self-contained HTML or Markdown file and get back a private, shareable link. No sign-up required. Encrypted in your browser, and the link expires when it goes unused.',
    ],
    'mcpEndpoint' => url('/mcp'),
]))->name('free');

Route::get('/docs', DocsController::class)->name('docs');

// The /connect hub and one page per connectable AI tool. Both read
// AiToolSetup, the same source the docs page and /llms-full.txt render from,
// so a setup step cannot drift between them.
Route::get('/connect', [ConnectController::class, 'index'])->name('connect.index');
Route::get('/connect/{tool}', [ConnectController::class, 'show'])
    ->where('tool', '[a-z0-9-]+')
    ->name('connect.show');
Route::get('/llms.txt', [LlmsTextController::class, 'index'])->name('llms.index');
Route::get('/llms-full.txt', [LlmsTextController::class, 'full'])->name('llms.full');

Route::get('/terms', [LegalController::class, 'terms'])->name('terms');
Route::get('/privacy', [LegalController::class, 'privacy'])->name('privacy');
Route::middleware('auth')->group(function () {
    Route::get('/terms/accept', [LegalController::class, 'showAcceptance'])->name('terms.accept.show');
    Route::post('/terms/accept', [LegalController::class, 'accept'])->name('terms.accept');
});

// Both blog routes read the Markdown files through BlogContent; there is no
// post list in this file any more.
Route::get('/blog', [BlogController::class, 'index'])->name('blog');

// Retired posts that search engines still list (RUB-491).
Route::get('/blog/{retired}', fn () => redirect('/blog', 301))
    ->whereIn('retired', ['ai-presentations', 'developer-tools', 'mermaid-diagrams']);

Route::get('/blog/{slug}', [BlogController::class, 'show'])
    ->where('slug', '[a-z0-9-]+')
    ->name('blog.show');

// ── sitemap ───────────────────────────────────────────────────────────────────

Route::get('/sitemap.xml', SitemapController::class)->name('sitemap');
Route::get('/robots.txt', RobotsController::class)->name('robots');

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
Route::get('.well-known/mcp/server-card.json', McpServerCardController::class)->name('mcp.server-card');
