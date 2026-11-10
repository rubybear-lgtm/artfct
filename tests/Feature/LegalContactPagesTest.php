<?php

/**
 * RUB-505: the support route must exist for directory listings, and the
 * sitemap / marketing indexability surface must list it with the other legal
 * pages. Contact-address copy is asserted in the browser suite against the
 * rendered pages.
 */
test('support page is a public inertia page', function () {
    test()->get(route('support'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('legal/support'));
});

test('the sitemap includes the support page with the legal pages', function () {
    $response = test()->get(route('sitemap'))->assertOk();

    $response->assertSee(url('/support'), escape: false);
    $response->assertSee(url('/terms'), escape: false);
    $response->assertSee(url('/privacy'), escape: false);
});

test('the support page is marked as yearly and low priority in the sitemap', function () {
    $content = test()->get(route('sitemap'))->getContent();

    expect($content)->toMatch(
        '#<loc>'.preg_quote(url('/support'), '#').'</loc>\s*'
            .'<changefreq>yearly</changefreq>\s*'
            .'<priority>0\.3</priority>#',
    );
});
