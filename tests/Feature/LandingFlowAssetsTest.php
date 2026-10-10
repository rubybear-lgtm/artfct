<?php

use App\Support\LandingMedia;

/** The largest each tier may grow to, so a heavy render is noticed. */
const FLOW_TIER_BUDGET_BYTES = [
    'high' => 1_600_000,
    'medium' => 800_000,
    'low' => 400_000,
];

test('every landing animation tier and poster is published, hashed and within budget', function () {
    $flow = LandingMedia::forPage();

    expect($flow)->toHaveKeys(['wide', 'phone']);

    foreach ($flow as $cut) {
        expect($cut['tiers'])->toHaveCount(3)
            ->and($cut['poster'])->toStartWith('/media/landing/');

        foreach ($cut['tiers'] as $tier) {
            $file = LandingMedia::directory().'/'.basename($tier['src']);

            expect($file)->toBeFile()
                ->and(basename($tier['src']))->toMatch(LandingMedia::FILE_PATTERN)
                ->and(filesize($file))->toBeLessThan(FLOW_TIER_BUDGET_BYTES[$tier['name']]);
        }

        expect(LandingMedia::directory().'/'.basename($cut['poster']))
            ->toBeFile();
    }
});

test('the tiers of a cut get lighter in order', function () {
    foreach (LandingMedia::forPage() as $cut) {
        $widths = array_column($cut['tiers'], 'width');
        $sorted = $widths;
        rsort($sorted);

        expect($widths)->toBe($sorted);
    }
});

test('the landing page hands the animation tiers to the client', function () {
    $this->get(route('home'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('landing')
            ->has('flow.wide.tiers', 3)
            ->has('flow.phone.tiers', 3)
            ->has('flow.wide.poster'));
});

test('an animation file is cached for a year and answers byte ranges', function () {
    $src = LandingMedia::forPage()['phone']['tiers'][2]['src'];

    $response = $this->get($src);

    $response->assertOk();
    expect($response->headers->get('Cache-Control'))
        ->toContain('max-age=31536000')
        ->toContain('immutable')
        ->and($response->headers->get('Set-Cookie'))->toBeNull();

    $this->get($src, ['Range' => 'bytes=0-9'])->assertStatus(206);
});

test('only published animation files are served', function () {
    $this->get('/media/landing/composer.json')->assertNotFound();
    $this->get('/media/landing/landing-flow-wide-high.0000000000.mp4')->assertNotFound();
    $this->get('/media/landing/..%2F..%2F.env')->assertNotFound();
});

test('the landing page renders the redesigned page component', function () {
    $this->get(route('home'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('landing'));
});

test('the crawler fallback carries the new landing headline', function () {
    $this->get(route('home'))
        ->assertOk()
        ->assertSee('Every AI on your team, working from the same memory.', false)
        ->assertDontSee('Artfct remembers them', false);
});
