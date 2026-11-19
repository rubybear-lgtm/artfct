<?php

/*
 * RUB-561 / spec 45: the vendored PDF writer, its bundled OFL fonts and the
 * export glue. Guards read the shipped files directly.
 */

const PDF_EXPORT_DIR = __DIR__.'/../../../public/vendor/pdf-export';

/** @return list<string> */
function pdfExportVendoredFiles(): array
{
    return [
        'pdf-lib.min.js',
        'pdf-lib-LICENSE.md',
        'fontkit.umd.min.js',
        'fontkit-LICENSE',
        'fonts/manrope-latin-400-normal.woff',
        'fonts/newsreader-latin-400-normal.woff',
        'fonts/OFL-manrope.txt',
        'fonts/OFL-newsreader.txt',
        'export.js',
    ];
}

test('every vendored pdf export file exists with its licence', function () {
    foreach (pdfExportVendoredFiles() as $relativePath) {
        expect(PDF_EXPORT_DIR.'/'.$relativePath)->toBeFile("missing vendored file {$relativePath}");
    }
});

test('every bundled pdf export font has an OFL licence text', function () {
    $fonts = glob(PDF_EXPORT_DIR.'/fonts/*.woff');

    expect($fonts)->not->toBeEmpty();

    foreach ($fonts as $fontPath) {
        $family = str_contains(basename($fontPath), 'manrope') ? 'manrope' : 'newsreader';
        $licence = PDF_EXPORT_DIR."/fonts/OFL-{$family}.txt";

        expect($licence)->toBeFile('font '.basename($fontPath).' has no licence text');
        expect(file_get_contents($licence))->toContain('SIL OPEN FONT LICENSE Version 1.1');
    }
});

test('export.js never posts to a wildcard origin', function () {
    $source = file_get_contents(PDF_EXPORT_DIR.'/export.js');

    expect($source)->not->toBeFalse();
    expect($source)->not->toMatch('/postMessage\([^;]*[\'"]\*[\'"]\s*\)/');
    expect($source)->not->toMatch('/postMessage\([^;]*[\'"]\*[\'"]\s*,/');
});

test('export.js makes no network request and never touches the parent document', function () {
    $source = file_get_contents(PDF_EXPORT_DIR.'/export.js');

    foreach (['XMLHttpRequest', 'sendBeacon', 'WebSocket', 'EventSource', 'import(', 'parent.document', 'document.cookie', 'localStorage', 'sessionStorage'] as $forbidden) {
        expect(str_contains($source, $forbidden))->toBeFalse("export.js must not use {$forbidden}");
    }

    expect(substr_count($source, 'fetch('))->toBe(1);
    expect($source)->toContain('fetch(BASE_URL + FONT_FILES[key])');
});

test('export.js only accepts replies whose source is the frame it created', function () {
    $source = file_get_contents(PDF_EXPORT_DIR.'/export.js');

    expect($source)->toContain('event.source !== frame.contentWindow');
});
