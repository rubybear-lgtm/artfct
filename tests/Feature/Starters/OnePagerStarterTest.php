<?php

use App\Services\Artifacts\PublishContractValidator;
use App\Services\Templates\TemplateCatalog;

/*
 * RUB-560 / spec 39: the one-pager starter. index.html holds author content
 * only; doc.css holds all chrome; tweaks.js renders the edit panel from the
 * declaration in tweaks.json and reads and writes the `tweaks` collection.
 */

const ONE_PAGER_STARTER_DIR = __DIR__.'/../../../skills/artfct-team-doc/assets/one-pager-starter';

/** @return array<string, string> */
function onePagerStarterFiles(array $overrides = []): array
{
    $files = [];
    foreach (['index.html', 'doc.css', 'tweaks.js', 'tweaks.json'] as $name) {
        $files[$name] = (string) file_get_contents(ONE_PAGER_STARTER_DIR.'/'.$name);
    }

    return array_merge($files, $overrides);
}

/** @return list<array<string, mixed>> */
function onePagerDeclaration(): array
{
    return json_decode(onePagerStarterFiles()['tweaks.json'], true, flags: JSON_THROW_ON_ERROR);
}

test('the one-pager starter has its four files', function () {
    foreach (['index.html', 'doc.css', 'tweaks.js', 'tweaks.json'] as $name) {
        expect(ONE_PAGER_STARTER_DIR.'/'.$name)->toBeFile();
    }
});

test('tweaks.json is an ordered declaration of the three shipped fields', function () {
    $declaration = onePagerDeclaration();

    expect(array_is_list($declaration))->toBeTrue()
        ->and(array_column($declaration, 'name'))->toBe(['customer_name', 'tier', 'logo_path'])
        ->and(array_column($declaration, 'type'))->toBe(['text', 'choice', 'image']);

    foreach ($declaration as $field) {
        expect($field)->toHaveKeys(['name', 'label', 'type', 'default']);
    }
});

test('the one-pager declaration constrains customer_name to 80 characters and tier to its choices', function () {
    $declaration = collect(onePagerDeclaration())->keyBy('name');

    expect($declaration['customer_name']['max'])->toBe(80)
        ->and($declaration['tier']['choices'])->toBeArray()->not->toBeEmpty()
        ->and($declaration['tier']['choices'])->toContain($declaration['tier']['default'])
        ->and($declaration['logo_path']['default'])->toBe('');
});

test('every data-tweak element in index.html names a declared field and holds a placeholder', function () {
    $html = onePagerStarterFiles()['index.html'];
    $declared = array_column(onePagerDeclaration(), 'name');

    preg_match_all('/<([a-z][a-z0-9]*)\b[^>]*\bdata-tweak="([^"]+)"[^>]*>([^<]*)</i', $html, $matches, PREG_SET_ORDER);

    expect($matches)->not->toBeEmpty();

    $named = [];
    foreach ($matches as $match) {
        $named[] = $match[2];
        expect($declared)->toContain($match[2])
            ->and(trim($match[3]))->not->toBe('');
    }

    expect(array_unique($named))->toEqualCanonicalizing($declared);
});

test('index.html loads tweaks.js with defer, has one script tag and no inline script', function () {
    $html = onePagerStarterFiles()['index.html'];

    expect(preg_match_all('/<script\b[^>]*>/i', $html))->toBe(1)
        ->and($html)->toContain('<script src="tweaks.js" defer></script>')
        ->and($html)->toContain('<link rel="stylesheet" href="doc.css">');
});

test('the one-pager starter passes the publish contract', function () {
    $errors = (new PublishContractValidator)->validate(onePagerStarterFiles(), 'One-pager starter', 'index.html');

    expect($errors)->toBe([]);
});

test('the publish contract refuses violating copies of the one-pager starter', function (string $file, string $with, string $message) {
    $files = onePagerStarterFiles([$file => $with]);

    expect((new PublishContractValidator)->validate($files, 'One-pager starter', 'index.html'))->toContain($message);
})->with([
    'inline script' => [
        'index.html', '<!doctype html><script>fetch("/x")</script>',
        'One-pager starter file [index.html] has an inline `<script>`; put scripts in their own file and load them with `<script src="…">`.',
    ],
    'onclick handler' => [
        'index.html', '<!doctype html><button onclick="go()">Go</button>',
        'One-pager starter file [index.html] has an `on*=` handler; move interactivity into a bundled script file.',
    ],
    'data uri image' => [
        'index.html', '<!doctype html><img src="data:image/png;base64,AAAA" alt="">',
        'One-pager starter file [index.html] has a `data:` URI; put images and fonts in files/ and reference them by relative path.',
    ],
    'external font url' => [
        'doc.css', '@font-face { font-family: X; src: url(https://fonts.example.com/x.woff2) }',
        'One-pager starter file [doc.css] loads an external origin [https://fonts.example.com/x.woff2]; bundle every asset in files/.',
    ],
]);

test('tweaks.js makes only same-origin requests and reads the declaration by relative path', function () {
    $script = onePagerStarterFiles()['tweaks.js'];

    expect($script)->toContain("'tweaks.json'")
        ->and($script)->toContain('/__artfct/db/session')
        ->and($script)->toContain('/__artfct/db/tweaks/fields')
        ->and($script)->not->toMatch('/https?:\/\//')
        ->and($script)->not->toMatch('/\bXMLHttpRequest\b|\bWebSocket\b|\bsendBeacon\b|\bEventSource\b/')
        ->and($script)->not->toContain('parent.document')
        ->and($script)->not->toContain('document.cookie')
        ->and($script)->not->toContain('localStorage');
});

test('tweaks.js exposes its check seam for the browser tests', function () {
    expect(onePagerStarterFiles()['tweaks.js'])->toContain('window.artfctTweaks');
});

test('the one-pager stylesheet keeps the Letter sheet geometry', function () {
    $css = onePagerStarterFiles()['doc.css'];

    expect($css)->toMatch('/@page\s*\{[^}]*size:\s*Letter[^}]*margin:/s')
        ->and($css)->toMatch('/\.sheet\s*\{[^}]*width:\s*8\.5in/s')
        ->and($css)->not->toContain('@font-face')
        ->and($css)->not->toMatch('/@import/');
});

test('the db argument the team-doc guidance gives is a valid manifest', function () {
    $errors = TemplateCatalog::validateDbManifest(['collections' => ['tweaks' => ['index' => 'customer_name']]], 'One-pager');

    expect($errors)->toBe([]);
});
