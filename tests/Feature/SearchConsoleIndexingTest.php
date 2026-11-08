<?php

/**
 * RUB-513: Search Console flagged noindex, robots.txt and 4xx on artfct.dev.
 * Marketing URLs stay indexable; retired shells 301; private surfaces stay out.
 */
test('retired marketing shells permanently redirect to a live page', function (string $from, string $to) {
    test()->get($from)
        ->assertRedirect($to)
        ->assertStatus(301);
})->with([
    ['/pricing', '/'],
    ['/signup', '/login?screen_hint=sign-up'],
    ['/app', '/'],
    ['/dashboard', '/'],
]);

test('artifact viewer responses tell crawlers not to index them', function () {
    test()->get('/a/abcdefghijklm')
        ->assertRedirect(route('login'))
        ->assertHeader('X-Robots-Tag', 'noindex, nofollow');
});

test('the login entry is noindexed in production', function () {
    app()->detectEnvironment(fn (): string => 'production');

    test()->get('/login')
        ->assertHeader('X-Robots-Tag', 'noindex, nofollow');
});

test('marketing pages stay indexable in production', function (string $path) {
    app()->detectEnvironment(fn (): string => 'production');

    test()->get($path)->assertHeaderMissing('X-Robots-Tag');
})->with(['/', '/free', '/docs', '/blog', '/connect', '/terms', '/privacy']);

test('production robots keeps marketing open and private paths closed', function () {
    app()->detectEnvironment(fn (): string => 'production');

    $robots = test()->get(route('robots'))
        ->assertOk()
        ->getContent();

    expect($robots)->toContain("User-agent: *\nContent-Signal: search=yes,ai-train=no,use=reference\nAllow: /\nDisallow: /a/\nDisallow: /p/\nDisallow: /v1/\nDisallow: /login\n")
        ->and($robots)->toContain("User-agent: Google-Extended\nAllow: /\nDisallow: /a/\nDisallow: /p/\nDisallow: /v1/\nDisallow: /login\n")
        ->and($robots)->not->toMatch('#Disallow: /blog#')
        ->and($robots)->not->toMatch('#Disallow: /connect#')
        ->and($robots)->toContain('Sitemap: '.url('/sitemap.xml'));
});

test('the sitemap still lists only live marketing URLs', function () {
    $content = test()->get(route('sitemap'))->getContent();

    expect($content)->not->toContain('/pricing')
        ->and($content)->not->toContain('/signup')
        ->and($content)->not->toContain('/dashboard')
        ->and($content)->not->toContain('/a/')
        ->and($content)->toContain(url('/free'))
        ->and($content)->toContain(url('/connect'));
});
