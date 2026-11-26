<?php

use App\Services\Artifacts\PublishContractValidator;

/*
 * RUB-557 / spec 30: the official doc starter. index.html holds author content
 * only; doc.css holds all chrome; export.js is the export bridge and makes no
 * network request. Every bundle check goes through PublishContractValidator.
 */

const DOC_STARTER_DIR = __DIR__.'/../../../skills/artfct-team-doc/assets/doc-starter';

/** @return array<string, string> */
function docStarterFiles(array $overrides = []): array
{
    $files = [];
    foreach (['index.html', 'doc.css', 'export.js'] as $name) {
        $files[$name] = (string) file_get_contents(DOC_STARTER_DIR.'/'.$name);
    }

    return array_merge($files, $overrides);
}

test('the doc starter has its three files', function () {
    foreach (['index.html', 'doc.css', 'export.js'] as $name) {
        expect(DOC_STARTER_DIR.'/'.$name)->toBeFile();
    }
});

test('index.html carries the doc@1 meta and the slot and upgrade comment', function () {
    $html = docStarterFiles()['index.html'];

    expect($html)->toContain('<meta name="artfct-starter" content="doc@1">')
        ->and($html)->toMatch('/<!--.*title.*byline.*body.*footer.*doc\.css.*-->/s')
        ->and($html)->toContain('<link rel="stylesheet" href="doc.css">');
});

test('index.html has one script tag, export.js, loaded with defer', function () {
    $html = docStarterFiles()['index.html'];

    expect(preg_match_all('/<script\b[^>]*>/i', $html))->toBe(1)
        ->and($html)->toContain('<script src="export.js" defer></script>');
});

test('the doc starter passes the publish contract', function () {
    $errors = (new PublishContractValidator)->validate(docStarterFiles(), 'Doc starter', 'index.html');

    expect($errors)->toBe([]);
});

test('the publish contract refuses violating copies of the doc starter', function (string $file, string $with, string $message) {
    $files = docStarterFiles([$file => $with]);

    expect((new PublishContractValidator)->validate($files, 'Doc starter', 'index.html'))->toContain($message);
})->with([
    'inline script' => [
        'index.html', '<!doctype html><script>fetch("/x")</script>',
        'Doc starter file [index.html] has an inline `<script>`; put scripts in their own file and load them with `<script src="…">`.',
    ],
    'onclick handler' => [
        'index.html', '<!doctype html><button onclick="go()">Go</button>',
        'Doc starter file [index.html] has an `on*=` handler; move interactivity into a bundled script file.',
    ],
    'data uri image' => [
        'index.html', '<!doctype html><img src="data:image/png;base64,AAAA" alt="">',
        'Doc starter file [index.html] has a `data:` URI; put images and fonts in files/ and reference them by relative path.',
    ],
    'external font url' => [
        'doc.css', '@font-face { font-family: X; src: url(https://fonts.example.com/x.woff2) }',
        'Doc starter file [doc.css] loads an external origin [https://fonts.example.com/x.woff2]; bundle every asset in files/.',
    ],
]);

test('the doc starter stylesheet declares Letter paper and print geometry', function () {
    $css = docStarterFiles()['doc.css'];

    expect($css)->toMatch('/@page\s*\{[^}]*size:\s*Letter[^}]*margin:/s')
        ->and($css)->toMatch('/:root\s*\{[^}]*--doc-[a-z-]+:/s')
        ->and($css)->toMatch('/\.sheet\s*\{[^}]*width:\s*8\.5in/s')
        ->and($css)->toMatch('/\.sheet\s*\{[^}]*min-height:\s*11in/s')
        ->and($css)->toMatch('/html\.sheet-a4\s+\.sheet\s*\{[^}]*210mm[^}]*297mm|html\.sheet-a4\s+\.sheet\s*\{[^}]*297mm[^}]*210mm/s')
        ->and($css)->toMatch('/\.sheet--fixed\s*\{[^}]*overflow:\s*hidden/s')
        ->and($css)->toMatch('/@media print\s*\{[^@]*\.sheet\s*\{[^}]*box-shadow:\s*none/s');
});

test('the doc starter uses no external fonts and no viewport units or fixed positioning', function () {
    $css = docStarterFiles()['doc.css'];

    expect($css)->not->toContain('@font-face')
        ->and($css)->not->toMatch('/@import/')
        ->and($css)->not->toMatch('/position:\s*fixed/')
        ->and($css)->not->toMatch('/\b\d*\.?\d+(vh|vw|dvh|svh|lvh)\b/');
});

test('the doc starter stylesheet carries the flowing print discipline', function () {
    $css = docStarterFiles()['doc.css'];

    expect($css)->toContain('column-count')
        ->and($css)->toContain('column-gap')
        ->and($css)->toContain('column-span: all')
        ->and($css)->toMatch('/thead\b/')
        ->and($css)->toContain('break-before: page')
        ->and($css)->toContain('break-inside: avoid')
        ->and($css)->toMatch('/orphans:\s*3/')
        ->and($css)->toMatch('/widows:\s*3/')
        ->and($css)->not->toMatch('/display:\s*(flex|grid)[^}]*columns?/s');
});

test('every relative reference in the doc starter resolves inside the bundle', function () {
    $files = docStarterFiles();

    expect(array_keys($files))->toContain('doc.css', 'export.js')
        ->and((new PublishContractValidator)->validate($files, 'Doc starter', 'index.html'))->toBe([]);
});

test('export.js makes no network request and never touches the parent document', function () {
    $script = docStarterFiles()['export.js'];

    expect($script)->not->toMatch('/\b(fetch|XMLHttpRequest|sendBeacon|WebSocket|EventSource)\b/')
        ->and($script)->not->toContain('import(')
        ->and($script)->not->toContain('parent.document')
        ->and($script)->not->toContain('document.cookie')
        ->and($script)->not->toContain('localStorage');
});

test('export.js replies to the parent origin and never with a wildcard target', function () {
    $script = docStarterFiles()['export.js'];

    expect($script)->toContain('event.origin')
        ->and($script)->toContain('event.source')
        ->and($script)->toContain('window.parent')
        ->and($script)->not->toMatch("/postMessage\\([^)]*['\"]\\*['\"]/");
});
