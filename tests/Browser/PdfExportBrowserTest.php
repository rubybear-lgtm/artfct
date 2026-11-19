<?php

/*
 * RUB-561 / spec 45: the PDF export runner, proven in a browser across two
 * origins. The parent fixture (origin A) plays the app page: it loads the
 * vendored writer and runner from public/vendor/pdf-export, frames a starter
 * served from origin B under the artifact CSP, and writes the PDF from the
 * bridge data.
 */

use Symfony\Component\Process\Process;

const PDF_EXPORT_FIXTURES = __DIR__.'/../Fixtures/pdf-export';
const PDF_EXPORT_DOC_BUNDLE = __DIR__.'/../../skills/artfct-team-doc/assets/doc-starter';
const PDF_EXPORT_DOC_FIXTURES = __DIR__.'/../Fixtures/doc-starter';
const PDF_EXPORT_DECK_BUNDLE = __DIR__.'/../../skills/presentation/assets/deck-starter';
const PDF_EXPORT_DECK_FIXTURES = __DIR__.'/../Fixtures/deck-starter';

/** @return int a free loopback TCP port */
function pdfExportFreePort(): int
{
    $socket = stream_socket_server('tcp://127.0.0.1:0');
    $port = (int) parse_url(stream_socket_get_name($socket, false), PHP_URL_PORT);
    fclose($socket);

    return $port;
}

/** @return array{parent: string, doc: string, deck: string} base URLs of the three fixture origins */
function pdfExportOrigins(): array
{
    static $origins = null;

    if ($origins !== null) {
        return $origins;
    }

    $origins = [
        'parent' => 'http://127.0.0.1:'.pdfExportFreePort(),
        'doc' => 'http://127.0.0.1:'.pdfExportFreePort(),
        'deck' => 'http://127.0.0.1:'.pdfExportFreePort(),
    ];

    $servers = [
        new Process(['php', '-S', '127.0.0.1:'.parse_url($origins['parent'], PHP_URL_PORT), PDF_EXPORT_FIXTURES.'/parent-router.php']),
        new Process(
            ['php', '-S', '127.0.0.1:'.parse_url($origins['doc'], PHP_URL_PORT), PDF_EXPORT_DOC_FIXTURES.'/starter-router.php'],
            null,
            ['DOC_STARTER_DIR' => PDF_EXPORT_DOC_BUNDLE, 'DOC_STARTER_PARENT_ORIGIN' => $origins['parent']],
        ),
        new Process(
            ['php', '-S', '127.0.0.1:'.parse_url($origins['deck'], PHP_URL_PORT), PDF_EXPORT_DECK_FIXTURES.'/starter-router.php'],
            null,
            ['DECK_STARTER_DIR' => PDF_EXPORT_DECK_BUNDLE, 'DECK_STARTER_PARENT_ORIGIN' => $origins['parent']],
        ),
    ];

    foreach ($servers as $server) {
        $server->start();
    }

    foreach ($origins as $origin) {
        $deadline = microtime(true) + 5;

        while (@fsockopen('127.0.0.1', (int) parse_url($origin, PHP_URL_PORT)) === false) {
            if (microtime(true) > $deadline) {
                throw new RuntimeException("Fixture server {$origin} did not start.");
            }

            usleep(50_000);
        }
    }

    register_shutdown_function(function () use ($servers): void {
        foreach ($servers as $server) {
            $server->stop();
        }
    });

    return $origins;
}

/** @return string the parent fixture, framing one starter page and exporting it */
function pdfExportParentUrl(string $frameUrl, string $frameOrigin): string
{
    $origins = pdfExportOrigins();

    return $origins['parent'].'/parent.html?'.http_build_query([
        'frame' => $frameUrl,
        'origin' => $frameOrigin,
        'footer' => 'https://artfct.example/a/pdf-export-fixture',
    ]);
}

function pdfExportDocUrl(string $case): string
{
    return pdfExportOrigins()['doc'].'/cases/'.$case.'/index.html';
}

test('exporting the doc fixture produces a PDF that starts with %PDF-', function () {
    $origins = pdfExportOrigins();

    visit(pdfExportParentUrl(pdfExportDocUrl('flowing'), $origins['doc']))
        ->waitForText('done')
        ->assertScript('window.exportError', null)
        ->assertScript('window.exportResult.starter', 'doc@1')
        ->assertScript('window.exportResult.header', '%PDF-')
        ->assertScript('window.exportResult.pageCount', 4)
        ->assertScript('window.exportResult.replacedCharacters', 0);
});

test('exporting the long-table doc fixture produces the expected page count', function () {
    $origins = pdfExportOrigins();

    visit(pdfExportParentUrl(pdfExportDocUrl('long-table'), $origins['doc']))
        ->waitForText('done')
        ->assertScript('window.exportResult.header', '%PDF-')
        ->assertScript('window.exportResult.pageCount', 3);
});

test('exporting the fixed doc fixture keeps a one-page sheet to one page', function () {
    $origins = pdfExportOrigins();

    visit(pdfExportParentUrl(pdfExportDocUrl('fixed'), $origins['doc']))
        ->waitForText('done')
        ->assertScript('window.exportResult.header', '%PDF-')
        ->assertScript('window.exportResult.pageCount', 1);
});

test('exporting the deck fixture produces a PDF that starts with %PDF-', function () {
    $origins = pdfExportOrigins();

    visit(pdfExportParentUrl($origins['deck'].'/index.html', $origins['deck']))
        ->waitForText('done')
        ->assertScript('window.exportError', null)
        ->assertScript('window.exportResult.starter', 'deck@1')
        ->assertScript('window.exportResult.header', '%PDF-')
        ->assertScript('window.exportResult.pageCount', 3);
});

test('a frame that does not answer the bridge reports no_bridge for the print fallback', function () {
    $origins = pdfExportOrigins();

    // A plain script file loads as a page with no bridge, so nothing answers.
    visit(pdfExportParentUrl($origins['parent'].'/vendor/pdf-export/export.js', $origins['parent']))
        ->waitForText('done', 15)
        ->assertScript('window.exportError', 'no_bridge')
        ->assertScript('window.exportResult', null);
});

test('a bundle script that reads parent.document gets a SecurityError and the export still completes', function () {
    $origins = pdfExportOrigins();

    visit(pdfExportParentUrl(pdfExportDocUrl('probe'), $origins['doc']))
        ->waitForText('done')
        ->assertScript('window.probeOutcome', 'SecurityError')
        ->assertScript('window.exportResult.header', '%PDF-');
});

test('the export page loads no artifact script in the app origin', function () {
    $origins = pdfExportOrigins();
    $artifactOrigin = $origins['doc'];

    visit(pdfExportParentUrl(pdfExportDocUrl('probe'), $artifactOrigin))
        ->waitForText('done')
        ->assertScript(
            'Array.from(document.scripts).every((script) => !script.src.startsWith('.json_encode($artifactOrigin).'))',
            true,
        );
});
