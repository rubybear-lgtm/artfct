<?php

/*
 * The Oxblood & Bone palette is scoped by an `app-theme` class on <html>
 * (see `.app-theme` in resources/css/app.css). `useAppTheme()` adds it in a
 * useEffect, which is after first paint, so the server has to render it on the
 * pages that use the palette or they flash the old colours first.
 */

/**
 * The server-rendered <html> tag, whitespace normalised because the tag spans
 * several lines in `app.blade.php`.
 */
function appThemeHtmlTag(string $html): string
{
    preg_match('/<html[^>]*>/', $html, $matches);

    return preg_replace('/\s+/', ' ', $matches[0] ?? '');
}

test('app, auth, site and legal pages server-render app-theme on <html>', function (string $path) {
    $response = $this->get($path);

    $response->assertOk()->assertSee('class="app-theme"', false);

    expect(appThemeHtmlTag($response->getContent()))
        ->toStartWith('<html lang="en"')
        ->toContain('class="app-theme"');
})->with(['/docs', '/terms', '/privacy', '/login']);

test('the free tool and landing page keep their own palette', function (string $path) {
    $response = $this->get($path);

    $response->assertOk()->assertDontSee('class="app-theme"', false);

    expect(appThemeHtmlTag($response->getContent()))->not->toContain('app-theme');
})->with(['/free', '/']);
