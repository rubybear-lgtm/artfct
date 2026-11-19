<?php

use App\Services\Artifacts\PublishContractValidator;

/*
 * RUB-568 / spec 36: the sample design system is a publishable bundle, and its
 * tokens.json obeys the shape the publish guidance documents. The shape check
 * lives here, not in the app: the server does not validate tokens.json.
 */

const DESIGN_SYSTEM_FIXTURE_DIR = __DIR__.'/../../Fixtures/design-system';

const DESIGN_SYSTEM_STARTER_CSS = [
    __DIR__.'/../../../skills/artfct-team-doc/assets/doc-starter/doc.css',
    __DIR__.'/../../../skills/presentation/assets/deck-starter/deck.css',
];

/** @return array<string, string> relative path => bytes */
function designSystemBundle(array $overrides = []): array
{
    $files = [];
    foreach (['README.md', 'tokens.json', 'index.html', 'fonts/Sample-Regular.woff2', 'assets/logo.svg'] as $path) {
        $files[$path] = (string) file_get_contents(DESIGN_SYSTEM_FIXTURE_DIR.'/'.$path);
    }

    return array_merge($files, $overrides);
}

/** @return array<string, mixed> */
function designSystemTokens(): array
{
    return json_decode((string) file_get_contents(DESIGN_SYSTEM_FIXTURE_DIR.'/tokens.json'), true, 512, JSON_THROW_ON_ERROR);
}

/**
 * The documented tokens.json shape, as errors. Empty when the file obeys it.
 *
 * @param  array<string, mixed>  $tokens
 * @param  array<string, string>  $files  the bundle, to check font files exist
 * @return list<string>
 */
function designSystemShapeErrors(array $tokens, array $files): array
{
    $errors = [];
    $topLevel = ['version', 'color', 'type', 'spacing', 'radius'];

    foreach (array_diff($topLevel, array_keys($tokens)) as $missing) {
        $errors[] = "tokens.json has no [{$missing}] key.";
    }

    foreach (array_diff(array_keys($tokens), $topLevel) as $unknown) {
        $errors[] = "tokens.json has an unknown key [{$unknown}].";
    }

    if (! is_string($tokens['version'] ?? null) || trim((string) $tokens['version']) === '') {
        $errors[] = 'version must be a non-empty string.';
    }

    $names = [];
    foreach (['color', 'spacing', 'radius'] as $family) {
        $errors = array_merge($errors, designSystemTokenListErrors($family, $tokens[$family] ?? null, $names));
    }

    $type = $tokens['type'] ?? null;
    if (! is_array($type) || array_diff(array_keys($type), ['families', 'sizes', 'fonts']) !== []) {
        return [...$errors, 'type must be an object with only families, sizes and fonts.'];
    }

    $errors = array_merge(
        $errors,
        designSystemTokenListErrors('type.families', $type['families'] ?? null, $names),
        designSystemTokenListErrors('type.sizes', $type['sizes'] ?? null, $names),
    );

    if (! is_array($type['fonts'] ?? null) || (! array_is_list($type['fonts']) && $type['fonts'] !== [])) {
        return [...$errors, 'type.fonts must be a list.'];
    }

    foreach ($type['fonts'] as $font) {
        if (! is_array($font) || count($font) !== 4 || array_diff(array_keys($font), ['file', 'family', 'weight', 'style']) !== []) {
            $errors[] = 'each type.fonts entry has exactly file, family, weight and style.';

            continue;
        }

        if (! str_starts_with((string) $font['file'], 'fonts/') || ! array_key_exists($font['file'], $files)) {
            $errors[] = "type.fonts names [{$font['file']}], which is not a file in the bundle.";
        }
    }

    return $errors;
}

/**
 * @param  array<string, true>  $names  every token name seen so far, passed by reference
 * @return list<string>
 */
function designSystemTokenListErrors(string $family, mixed $list, array &$names): array
{
    if (! is_array($list) || (! array_is_list($list) && $list !== [])) {
        return ["{$family} must be a list of tokens."];
    }

    $errors = [];
    foreach ($list as $token) {
        if (! is_array($token) || count($token) !== 3 || array_diff(array_keys($token), ['name', 'value', 'usage']) !== []) {
            $errors[] = "each {$family} token has exactly name, value and usage.";

            continue;
        }

        $name = (string) $token['name'];
        if (preg_match('/^--(doc|deck|type)-[a-z0-9-]+$/', $name) !== 1) {
            $errors[] = "{$family} token [{$name}] must be a --doc-, --deck- or --type- custom property name.";
        }

        if (isset($names[$name])) {
            $errors[] = "token [{$name}] is named more than once.";
        }
        $names[$name] = true;

        $value = $token['value'];
        if (! is_string($value) || trim($value) === '' || str_contains($value, 'var(') || preg_match('/[{}\[\]]/', $value) === 1) {
            $errors[] = "token [{$name}] value must be one non-empty string with no var() and no nesting.";
        }

        if (! is_string($token['usage']) || trim($token['usage']) === '') {
            $errors[] = "token [{$name}] needs a usage string.";
        }
    }

    return $errors;
}

test('the sample design system passes the publish contract validator', function () {
    $errors = (new PublishContractValidator)->validate(designSystemBundle(), 'Design system', 'index.html');

    expect($errors)->toBe([]);
});

test('the sample design system carries one font file and one logo', function () {
    $tokens = designSystemTokens();

    expect($tokens['type']['fonts'])->toHaveCount(1)
        ->and(glob(DESIGN_SYSTEM_FIXTURE_DIR.'/fonts/*'))->toHaveCount(1)
        ->and(glob(DESIGN_SYSTEM_FIXTURE_DIR.'/assets/*'))->toHaveCount(1);
});

test('the sample tokens.json matches the documented shape', function () {
    expect(designSystemShapeErrors(designSystemTokens(), designSystemBundle()))->toBe([]);
});

test('every sample token name is a custom property a starter declares', function () {
    $starterCss = implode("\n", array_map(fn (string $path): string => (string) file_get_contents($path), DESIGN_SYSTEM_STARTER_CSS));

    $tokens = designSystemTokens();
    $names = [];
    foreach (['color', 'spacing', 'radius'] as $family) {
        $names = array_merge($names, array_column($tokens[$family], 'name'));
    }
    $names = array_merge($names, array_column($tokens['type']['families'], 'name'), array_column($tokens['type']['sizes'], 'name'));

    expect($names)->not->toBeEmpty();
    foreach ($names as $name) {
        expect($starterCss)->toContain("{$name}:");
    }
});

test('the shape check refuses a token value that uses var()', function () {
    $tokens = designSystemTokens();
    $tokens['color'][0]['value'] = 'var(--doc-accent)';

    expect(designSystemShapeErrors($tokens, designSystemBundle()))->toContain('token [--doc-ink] value must be one non-empty string with no var() and no nesting.');
});

test('the shape check refuses a token name no starter can read', function () {
    $tokens = designSystemTokens();
    $tokens['color'][] = ['name' => '--sample-brand', 'value' => '#701a24', 'usage' => 'Brand colour.'];

    expect(designSystemShapeErrors($tokens, designSystemBundle()))->toContain('color token [--sample-brand] must be a --doc-, --deck- or --type- custom property name.');
});

test('the shape check refuses a font entry whose file is missing from the bundle', function () {
    $tokens = designSystemTokens();
    $tokens['type']['fonts'][0]['file'] = 'fonts/Missing-Regular.woff2';

    expect(designSystemShapeErrors($tokens, designSystemBundle()))->toContain('type.fonts names [fonts/Missing-Regular.woff2], which is not a file in the bundle.');
});

test('the shape check refuses a nested token value', function () {
    $tokens = designSystemTokens();
    $tokens['spacing'][0]['value'] = ['small' => '4px'];

    expect(designSystemShapeErrors($tokens, designSystemBundle()))->not->toBe([]);
});

test('the contract validator refuses a cover page with an inline script', function () {
    $files = designSystemBundle(['index.html' => '<!doctype html><script>alert(1)</script>']);

    expect((new PublishContractValidator)->validate($files, 'Design system', 'index.html'))->not->toBe([]);
});
