<?php

use App\Http\Controllers\RobotsController;
use App\Services\Blog\BlogContent;
use App\Support\AiToolSetup;
use Inertia\Ssr\Gateway;
use Inertia\Ssr\Response as SsrResponse;
use Inertia\Testing\AssertableInertia;

/**
 * @return array<string, mixed>
 */
function siteStructuredData(string $html): array
{
    preg_match('#<script type="application/ld\+json">(.*?)</script>#s', $html, $match);

    return json_decode($match[1] ?? '', true, flags: JSON_THROW_ON_ERROR);
}

test('the homepage meta names AI artifacts and shared context for agents', function () {
    test()->get(route('home'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('meta.title', 'Artfct: shared context and AI artifacts for every agent')
            ->where('meta.description', fn (string $description): bool => str_contains($description, 'Claude, Cursor, Codex and ChatGPT')));
});

test('the homepage links every setup guide and the three newest posts', function () {
    $guideIds = collect(AiToolSetup::forCurrentEnvironment()->guides())->pluck('id')->all();
    $newestSlugs = collect(app(BlogContent::class)->all())->take(3)->pluck('slug')->all();

    test()->get(route('home'))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('connectGuides', fn ($guides): bool => collect($guides)->pluck('id')->all() === $guideIds)
            ->where('latestPosts', fn ($posts): bool => collect($posts)->pluck('slug')->all() === $newestSlugs));

    expect($guideIds)->toContain('connect-claude-code', 'connect-cursor', 'connect-chatgpt');
    expect($newestSlugs)->toHaveCount(3);
});

test('structured data names Artfct with an artifact alternate name and an organization', function () {
    $data = siteStructuredData(test()->get(route('home'))->getContent());
    $byType = collect($data['@graph'])->keyBy('@type');

    expect($byType['WebApplication']['name'])->toBe('Artfct')
        ->and($byType['WebApplication']['alternateName'])->toBe('Artfct AI artifacts')
        ->and($byType['WebApplication']['description'])->toContain('each other’s work')
        ->and($byType['Organization']['name'])->toBe('Artfct');
});

test('without server rendering the crawler fallback carries the one headline', function () {
    $html = test()->get(route('home'))->getContent();

    expect($html)->toContain('id="ssr-fallback"')
        ->and(substr_count($html, '<h1'))->toBe(1)
        ->and($html)->toContain('<h1>Every agent sees what the others made.</h1>')
        ->and($html)->not->toContain('export everything any time');
});

test('with server rendering the crawler fallback is left out', function () {
    app()->instance(Gateway::class, new class implements Gateway
    {
        public function dispatch(array $page): ?SsrResponse
        {
            return new SsrResponse('<title>Rendered</title>', '<div id="app" data-server-rendered="true"><h1>Rendered</h1></div>');
        }
    });

    $html = test()->get(route('home'))->getContent();

    expect($html)->not->toContain('id="ssr-fallback"')
        ->and(substr_count($html, '<h1'))->toBe(1);
});

test('the retired presentations post redirects permanently to the blog', function () {
    test()->get('/blog/ai-presentations')
        ->assertStatus(301)
        ->assertRedirect('/blog');
});

test('the free page title says what it does', function () {
    test()->get(route('free'))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('meta.title', 'Share an HTML or Markdown file as a private link — Artfct'));
});

test('production robots lets Google-Extended read the site but not artifacts', function () {
    app()->detectEnvironment(fn (): string => 'production');

    $robots = test()->get(route('robots'))
        ->assertOk()
        ->assertHeader('Content-Type', 'text/plain; charset=UTF-8')
        ->getContent();

    expect($robots)->toContain("User-agent: Google-Extended\nAllow: /\nDisallow: /a/\nDisallow: /p/\nDisallow: /v1/\n")
        ->and($robots)->toContain('Content-Signal: search=yes,ai-train=no,use=reference')
        ->and($robots)->toContain('Sitemap: '.url('/sitemap.xml'))
        ->and($robots)->toMatch('#User-agent: GPTBot\n(User-agent: [^\n]+\n)*Disallow: /\n#')
        ->and($robots)->toMatch('#User-agent: ClaudeBot\n(User-agent: [^\n]+\n)*Disallow: /\n#')
        ->and(RobotsController::TRAINING_CRAWLERS)->not->toContain('Google-Extended');
});

test('robots outside production asks every crawler to stay out', function () {
    test()->get(route('robots'))
        ->assertOk()
        ->assertSeeText("User-agent: *\nDisallow: /\n", false);
});
