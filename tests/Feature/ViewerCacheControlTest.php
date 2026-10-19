<?php

use App\Models\User;

/*
 * Launch review: the viewer page carries a short-lived access token in its
 * props and used to be marked publicly cacheable like a marketing page.
 */
test('a guest still gets a cacheable public page', function () {
    $response = test()->get('/')->assertOk();

    expect($response->headers->get('Cache-Control'))->toContain('s-maxage=300')->toContain('public')->not->toContain('no-store');
});

test('a signed-in visitor never gets a publicly cacheable page', function () {
    $response = test()->actingAs(User::factory()->create())->get('/');

    expect($response->headers->get('Cache-Control'))->toContain('no-store')->toContain('private');
});

test('the artifact viewer is never publicly cacheable, even for a guest', function () {
    $response = test()->get('/a/doesnotexist0');

    expect($response->headers->get('Cache-Control'))->not->toContain('public');
});
