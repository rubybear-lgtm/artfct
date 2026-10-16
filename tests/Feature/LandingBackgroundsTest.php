<?php

/** The most a landscape background may weigh. */
const BACKGROUND_BUDGET_BYTES = 260_000;

test('every background the landing lists is published and within budget', function () {
    $source = file_get_contents(resource_path('js/lib/backgrounds.ts'));

    preg_match_all("#entry\('(light|dark)', '([a-z0-9-]+)'#", $source, $matches, PREG_SET_ORDER);

    expect($matches)->not->toBeEmpty();

    foreach ($matches as [, $mode, $slug]) {
        $file = public_path("images/backgrounds/{$mode}/{$slug}.jpg");

        expect($file)->toBeFile()
            ->and(filesize($file))->toBeLessThan(BACKGROUND_BUDGET_BYTES);
    }
});

test('the story and hero only reserve dark backgrounds that exist', function () {
    $source = file_get_contents(resource_path('js/lib/backgrounds.ts'));

    preg_match_all("#'(dark-[a-z0-9-]+)'#", $source, $reserved);

    preg_match_all("#entry\('dark', '([a-z0-9-]+)'#", $source, $entries);

    $known = array_map(fn (string $slug): string => "dark-{$slug}", $entries[1]);

    foreach (array_unique($reserved[1]) as $id) {
        expect($known)->toContain($id);
    }
});
