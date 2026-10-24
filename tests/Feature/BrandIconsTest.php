<?php

test('favicon svg is the oxblood and bone A mark without gradients', function () {
    $svg = file_get_contents(public_path('favicon.svg'));

    expect($svg)->not->toBeFalse()
        ->and($svg)->toContain('#701A24')
        ->and($svg)->toContain('#F7F5F2')
        ->and($svg)->not->toContain('linearGradient')
        ->and($svg)->not->toContain('url(#artfct-mark)');
});

test('brand icon files are present with expected formats', function () {
    expect(public_path('favicon.svg'))->toBeFile()
        ->and(public_path('favicon.ico'))->toBeFile()
        ->and(public_path('apple-touch-icon.png'))->toBeFile()
        ->and(public_path('icon-512.png'))->toBeFile();

    $ico = file_get_contents(public_path('favicon.ico'));
    expect($ico)->not->toBeFalse();
    // ICONDIR: reserved=0, type=1 (icon), count=2 (16 + 32)
    expect(unpack('vreserved/vtype/vcount', substr($ico, 0, 6)))->toMatchArray([
        'reserved' => 0,
        'type' => 1,
        'count' => 2,
    ]);

    $apple = file_get_contents(public_path('apple-touch-icon.png'));
    $icon512 = file_get_contents(public_path('icon-512.png'));
    expect(substr($apple, 0, 8))->toBe("\x89PNG\r\n\x1a\n")
        ->and(substr($icon512, 0, 8))->toBe("\x89PNG\r\n\x1a\n")
        ->and(unpack('N', substr($apple, 16, 4))[1])->toBe(180)
        ->and(unpack('N', substr($apple, 20, 4))[1])->toBe(180)
        ->and(unpack('N', substr($icon512, 16, 4))[1])->toBe(512)
        ->and(unpack('N', substr($icon512, 20, 4))[1])->toBe(512);
});

test('document head links the favicon set', function () {
    $this->get('/')
        ->assertOk()
        ->assertSee('<link rel="icon" href="/favicon.ico" sizes="any">', false)
        ->assertSee('<link rel="icon" href="/favicon.svg" type="image/svg+xml">', false)
        ->assertSee('<link rel="apple-touch-icon" href="/apple-touch-icon.png">', false);
});

test('hand-authored icon sources exist for npm run icons', function () {
    expect(base_path('scripts/icons/favicon-16.png'))->toBeFile()
        ->and(base_path('scripts/icons/favicon-32.png'))->toBeFile()
        ->and(base_path('scripts/icons/apple-touch-icon-180.png'))->toBeFile()
        ->and(base_path('scripts/icons/icon-512.png'))->toBeFile();
});
