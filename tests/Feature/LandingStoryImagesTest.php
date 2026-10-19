<?php

/** The pictures behind the landing story, with the most each may weigh. */
const LANDING_STORY_IMAGE_BUDGET_BYTES = [
    'ask' => 300_000,
    'share' => 300_000,
    'find' => 300_000,
    'use' => 300_000,
    'hero-burst' => 350_000,
    'hero-clay' => 250_000,
    'hero-ox' => 250_000,
    'hero-sage' => 250_000,
];

test('every landing story image is published and within budget', function () {
    foreach (LANDING_STORY_IMAGE_BUDGET_BYTES as $name => $budget) {
        $file = public_path("images/landing/{$name}.jpg");

        expect($file)->toBeFile()
            ->and(filesize($file))->toBeLessThan($budget);
    }
});

test('the story component only points at images that exist', function () {
    $source = file_get_contents(resource_path('js/components/home/story.tsx'));

    preg_match_all('#/images/landing/([a-z-]+)\.jpg#', $source, $matches);

    expect($matches[1])->not->toBeEmpty();

    foreach (array_unique($matches[1]) as $name) {
        expect(public_path("images/landing/{$name}.jpg"))->toBeFile();
    }
});
