<?php

use Inertia\Testing\AssertableInertia as Assert;

test('D4 privacy policy lists PostHog US as a product analytics processor', function () {
    $source = file_get_contents(resource_path('js/pages/legal/privacy.tsx'));

    expect($source)->toContain('PostHog')
        ->and($source)->toContain('United States')
        ->and($source)->toContain('product analytics')
        ->and($source)->toContain('raw search query')
        ->and($source)->toContain('cookieless');

    test()->get(route('privacy'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('legal/privacy'));
});

test('E1 env example documents PostHog variables without a real secret', function () {
    $example = file_get_contents(base_path('.env.example'));

    expect($example)->toContain('POSTHOG_ENABLED=false')
        ->and($example)->toContain('POSTHOG_API_KEY=')
        ->and($example)->toContain('POSTHOG_HOST=https://us.i.posthog.com')
        ->and($example)->toContain('POSTHOG_ENVIRONMENT=')
        ->and($example)->toContain('POSTHOG_INTERNAL_TEAM_SLUGS=ruben-tortoleros-team')
        ->and($example)->not->toContain('phc_AZEa');
});
