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

test('the saved theme choice is applied before first paint', function () {
    $html = $this->get('/login')->assertOk()->getContent();

    expect($html)
        ->toContain("localStorage.getItem('artfct-theme')")
        ->toContain("setAttribute('data-theme'");
});

test('the app palette defines dark values for system and explicit dark', function () {
    $css = file_get_contents(resource_path('css/app.css'));

    expect($css)
        ->toContain(":root:root.app-theme:not([data-theme='light'])")
        ->toContain(":root:root.app-theme[data-theme='dark']");
});

test('form fields use a stronger stroke than the decorative hairline', function () {
    $css = file_get_contents(resource_path('css/app.css'));

    expect($css)
        ->toContain('--field-border: color-mix(in srgb, var(--ink-quiet) 80%, transparent)')
        ->toContain('border-color: var(--field-border)');
});

test('the app stylesheet stops animations when the viewer prefers reduced motion', function () {
    $css = file_get_contents(resource_path('css/app.css'));

    expect($css)
        ->toContain('@media (prefers-reduced-motion: reduce)')
        ->toContain('animation: none !important');
});
