<?php

test('the sitemap includes the legal pages', function () {
    $response = test()->get(route('sitemap'));

    $response->assertOk();

    $response->assertSee(url('/terms'), escape: false);
    $response->assertSee(url('/privacy'), escape: false);
    $response->assertSee(url('/support'), escape: false);
});

test('the legal pages are marked as yearly and low priority', function () {
    $content = test()->get(route('sitemap'))->getContent();

    foreach (['terms', 'privacy', 'support'] as $page) {
        expect($content)->toMatch(
            '#<loc>'.preg_quote(url('/'.$page), '#').'</loc>\s*'
                .'<changefreq>yearly</changefreq>\s*'
                .'<priority>0\.3</priority>#',
        );
    }
});
