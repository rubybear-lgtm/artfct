<?php

/**
 * Railway's Railpack builder installs only the PHP extensions that composer.json
 * declares under "require". A runtime package that needs an extension we do not
 * declare passes CI (setup-php ships it) but breaks the staging and production build.
 */
test('composer.json declares every PHP extension the runtime packages need', function () {
    $root = dirname(__DIR__, 2);
    $composer = json_decode((string) file_get_contents($root.'/composer.json'), true, flags: JSON_THROW_ON_ERROR);
    $lock = json_decode((string) file_get_contents($root.'/composer.lock'), true, flags: JSON_THROW_ON_ERROR);

    $declared = array_keys(array_filter(
        $composer['require'],
        fn (string $constraint, string $name): bool => str_starts_with($name, 'ext-'),
        ARRAY_FILTER_USE_BOTH,
    ));

    $needed = [];
    foreach ($lock['packages'] as $package) {
        foreach (array_keys($package['require'] ?? []) as $requirement) {
            if (str_starts_with($requirement, 'ext-')) {
                $needed[$requirement][] = $package['name'];
            }
        }
    }

    $bundledWithTheBaseImage = [
        'ext-ctype', 'ext-curl', 'ext-dom', 'ext-fileinfo', 'ext-filter', 'ext-hash',
        'ext-json', 'ext-libxml', 'ext-mbstring', 'ext-openssl', 'ext-pcre', 'ext-pdo',
        'ext-session', 'ext-simplexml', 'ext-sodium', 'ext-tokenizer', 'ext-xml',
        'ext-xmlreader', 'ext-xmlwriter', 'ext-iconv', 'ext-random', 'ext-spl', 'ext-reflection', 'ext-zlib',
    ];

    $missing = array_diff_key($needed, array_flip([...$declared, ...$bundledWithTheBaseImage]));

    expect($missing)->toBe([], 'Declare these in composer.json "require": '.json_encode($missing));
});
