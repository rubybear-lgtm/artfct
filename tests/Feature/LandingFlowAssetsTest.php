<?php

test('the landing animation and its still frame are published and stay small', function () {
    $video = public_path('landing-flow.mp4');
    $poster = public_path('landing-flow-poster.jpg');

    expect($video)->toBeFile()
        ->and($poster)->toBeFile()
        ->and(filesize($video))->toBeLessThan(2 * 1024 * 1024)
        ->and(filesize($poster))->toBeLessThan(400 * 1024);
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
