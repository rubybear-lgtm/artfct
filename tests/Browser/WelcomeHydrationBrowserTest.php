<?php

/*
 * The free tool at /free keeps the links a visitor has made in localStorage,
 * which the server cannot read. A returning visitor's links must appear without
 * making React refuse to hydrate the server-rendered page (error 418). The
 * hydration half only bites with the SSR server running; start it with
 * `php artisan inertia:start-ssr` and run with TEST_INERTIA_SSR=true. Without
 * it the test still proves the saved links are shown.
 */

it('shows the links a returning visitor saved, and hydrates', function () {
    $link = json_encode([[
        'id' => 'abc123',
        'url' => 'https://artfct.dev/p/abc123#key',
        'expiresAt' => now()->addDays(3)->toIso8601String(),
        'filename' => 'quarterly-report.html',
        'deployedAt' => now()->toIso8601String(),
    ]], JSON_THROW_ON_ERROR);

    $page = visit('/free')->assertNoJavaScriptErrors();

    $page->script('localStorage.setItem("artfct_cached_links", '.json_encode($link, JSON_THROW_ON_ERROR).')');

    // The reload is the second visit: the server renders without the links and
    // the browser hydrates with them in storage.
    $page->navigate('/free')
        ->assertSee('quarterly-report.html')
        ->assertNoJavaScriptErrors();
});

it('starts with no links for a first-time visitor', function () {
    visit('/free')
        ->assertNoJavaScriptErrors()
        ->assertDontSee('quarterly-report.html')
        ->assertScript('document.querySelectorAll(".welcome-cached-link-row").length', 0);
});
