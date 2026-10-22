<?php

use App\Support\LandingMedia;

test('the landing page no longer ships retired video flow props', function () {
    $this->get(route('home'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('landing')
            ->missing('flow'));
});

test('only published animation files are served when present', function () {
    $this->get('/media/landing/composer.json')->assertNotFound();
    $this->get('/media/landing/landing-flow-wide-high.0000000000.mp4')->assertNotFound();
    $this->get('/media/landing/..%2F..%2F.env')->assertNotFound();
});

test('landing media directory is empty until remotion republishes', function () {
    expect(LandingMedia::forPage())->toBe([]);
});

test('the landing page renders the redesigned page component', function () {
    $this->get(route('home'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('landing'));
});

test('the crawler fallback carries the new landing headline', function () {
    $this->get(route('home'))
        ->assertOk()
        ->assertSee('Every agent sees what the others made.', false)
        ->assertDontSee('Artfct remembers them', false);
});
