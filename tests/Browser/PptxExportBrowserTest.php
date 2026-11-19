<?php

/*
 * RUB-562 / spec 46: the PowerPoint export runner in a browser. The parent page
 * (app origin, a fixture) runs the runner against the real deck starter on its
 * own origin. The runner must get one capture per slide, post them to the export
 * route, and start a download. The starter's document must be unreachable from
 * the parent.
 */

use Symfony\Component\Process\Process;

const PPTX_EXPORT_FIXTURES = __DIR__.'/../Fixtures/pptx-export';
const PPTX_STARTER_FIXTURES = __DIR__.'/../Fixtures/deck-starter';
const PPTX_STARTER_BUNDLE = __DIR__.'/../../skills/presentation/assets/deck-starter';

/** @return array{parent: string, starter: string} the base URLs of the two fixture origins */
function pptxExportOrigins(): array
{
    static $origins = null;

    if ($origins !== null) {
        return $origins;
    }

    $parentPort = pptxExportFreePort();
    $starterPort = pptxExportFreePort();
    $origins = [
        'parent' => "http://127.0.0.1:{$parentPort}",
        'starter' => "http://127.0.0.1:{$starterPort}",
    ];

    $parentServer = new Process(['php', '-S', "127.0.0.1:{$parentPort}", PPTX_EXPORT_FIXTURES.'/parent-router.php']);
    $parentServer->start();

    $starterServer = new Process(
        ['php', '-S', "127.0.0.1:{$starterPort}", PPTX_STARTER_FIXTURES.'/starter-router.php'],
        null,
        [
            'DECK_STARTER_DIR' => PPTX_STARTER_BUNDLE,
            'DECK_STARTER_PARENT_ORIGIN' => $origins['parent'],
        ],
    );
    $starterServer->start();

    foreach ([$origins['parent'], $origins['starter']] as $origin) {
        $deadline = microtime(true) + 5;
        while (@fsockopen('127.0.0.1', (int) parse_url($origin, PHP_URL_PORT)) === false) {
            if (microtime(true) > $deadline) {
                throw new RuntimeException("Fixture server {$origin} did not start.");
            }
            usleep(50_000);
        }
    }

    register_shutdown_function(function () use ($parentServer, $starterServer): void {
        $parentServer->stop();
        $starterServer->stop();
    });

    return $origins;
}

/** @return int a free loopback TCP port */
function pptxExportFreePort(): int
{
    $socket = stream_socket_server('tcp://127.0.0.1:0');
    $port = (int) parse_url(stream_socket_get_name($socket, false), PHP_URL_PORT);
    fclose($socket);

    return $port;
}

function pptxExportParentUrl(): string
{
    $origins = pptxExportOrigins();

    return $origins['parent'].'/parent.html?starter='.urlencode($origins['starter']);
}

test('exporting the deck sends one capture per slide and starts the download', function () {
    @unlink(sys_get_temp_dir().'/artfct-pptx-export-fixture-count.txt');

    visit(pptxExportParentUrl())
        ->waitForText('done')
        ->assertScript('window.results.filename', 'fixture-deck.pptx')
        ->assertScript('window.results.captures', 3);

    expect(file_get_contents(sys_get_temp_dir().'/artfct-pptx-export-fixture-count.txt'))->toBe('3');
});

test('deck script that reads the parent document gets SecurityError, and the export still completes', function () {
    visit(pptxExportParentUrl())
        ->waitForText('done')
        ->assertScript('window.results.crossOriginAccess', 'SecurityError')
        ->assertScript('window.results.deckParentAccess', 'parent document: SecurityError')
        ->assertScript('window.results.captures', 3);
});
