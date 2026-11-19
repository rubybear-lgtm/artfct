<?php

/*
 * RUB-557 / spec 30: the doc starter's export bridge, proven in a browser across
 * two origins. The parent fixture (origin A) frames the starter served from
 * origin B under the artifact CSP; a sibling frame sends a rogue ready message.
 */

use Symfony\Component\Process\Process;

const DOC_STARTER_FIXTURES = __DIR__.'/../Fixtures/doc-starter';
const DOC_STARTER_BUNDLE = __DIR__.'/../../skills/artfct-team-doc/assets/doc-starter';

/** @return int a free loopback TCP port */
function docStarterFreePort(): int
{
    $socket = stream_socket_server('tcp://127.0.0.1:0');
    $port = (int) parse_url(stream_socket_get_name($socket, false), PHP_URL_PORT);
    fclose($socket);

    return $port;
}

/** @return array{parent: string, starter: string} base URLs of the two origins */
function docStarterOrigins(): array
{
    static $origins = null;

    if ($origins !== null) {
        return $origins;
    }

    $parentPort = docStarterFreePort();
    $starterPort = docStarterFreePort();
    $origins = [
        'parent' => "http://127.0.0.1:{$parentPort}",
        'starter' => "http://127.0.0.1:{$starterPort}",
    ];

    $parentServer = new Process(['php', '-S', "127.0.0.1:{$parentPort}", '-t', DOC_STARTER_FIXTURES]);
    $parentServer->start();

    $starterServer = new Process(
        ['php', '-S', "127.0.0.1:{$starterPort}", DOC_STARTER_FIXTURES.'/starter-router.php'],
        null,
        [
            'DOC_STARTER_DIR' => DOC_STARTER_BUNDLE,
            'DOC_STARTER_PARENT_ORIGIN' => $origins['parent'],
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

function docStarterParentUrl(): string
{
    $origins = docStarterOrigins();

    return $origins['parent'].'/parent.html?starter='.urlencode($origins['starter']);
}

test('doc starter handshake works from a different origin', function () {
    $origins = docStarterOrigins();

    visit(docStarterParentUrl())
        ->waitForText('done')
        ->assertScript('location.origin !== new URLSearchParams(location.search).get("starter")', true)
        ->assertScript('window.results.ready.ok', true)
        ->assertScript('window.results.ready.result.starter', 'doc@1')
        ->assertScript('window.results.ready.result.protocol', 1);
});

test('doc starter describe returns the fixture runs', function () {
    visit(docStarterParentUrl())
        ->waitForText('done')
        ->assertScript('window.results.describeRuns > 0', true)
        ->assertScript('window.results.describeHasTitle', true);
});

test('doc starter ignores undeclared-origin and pre-handshake messages', function () {
    visit(docStarterParentUrl())
        ->waitForText('done')
        // Only ready, describe, unknown and the payload-origin ready get replies.
        // The pre-handshake describe, the sibling ready and the v=2 message do not.
        ->assertScript('JSON.stringify(window.results.ids)', '["ready","describe","unknown","payload"]')
        ->assertScript('JSON.stringify(window.results.siblingReplies)', '[]')
        ->assertScript('window.results.unknown.error.code', 'unknown_command');
});

test('doc starter script runs under the artifact CSP', function () {
    // A blocked script would never answer ready, so a successful handshake
    // proves export.js ran under script-src 'self' with no CSP violation.
    visit(docStarterParentUrl())
        ->waitForText('done')
        ->assertScript('window.results.ready !== null', true);
});

/** @return string the driver page, which frames one starter case through the bridge */
function docStarterDriverUrl(string $case): string
{
    $origins = docStarterOrigins();

    return $origins['parent'].'/driver.html?'.http_build_query(['starter' => $origins['starter'], 'case' => $case]);
}

// The fixed and flowing cases carry the same long body. Only the .sheet--fixed
// class differs, so one page versus several pages proves describe reads the flag.
test('doc starter describe keeps a fixed sheet to one page', function () {
    visit(docStarterDriverUrl('fixed'))
        ->waitForText('done')
        ->assertScript('window.results.describe.ok', true)
        ->assertScript('window.results.describe.result.pages.length', 1)
        ->assertScript('JSON.stringify(window.results.describe.result.pages[0].breaks)', '[]')
        ->assertScript('window.results.describe.result.pages[0].runs.length > 0', true);
});

test('doc starter describe splits a flowing sheet into several pages', function () {
    visit(docStarterDriverUrl('flowing'))
        ->waitForText('done')
        ->assertScript('window.results.describe.ok', true)
        ->assertScript('window.results.describe.result.pages.length > 1', true)
        ->assertScript('window.results.describe.result.pages.every((page, index) => page.index === index)', true)
        ->assertScript('window.results.describe.result.pages[0].breaks.length === window.results.describe.result.pages.length - 1', true)
        ->assertScript('JSON.stringify([window.results.describe.result.pages[0].width_pt, window.results.describe.result.pages[0].height_pt])', '[612,792]')
        ->assertScript('window.results.describe.result.pages[1].runs.length > 0', true);
});

test('doc starter describe without a sheet replies with an error instead of throwing', function () {
    visit(docStarterDriverUrl('no-sheet'))
        ->waitForText('done')
        ->assertScript('window.results.describe !== null', true)
        ->assertScript('window.results.describe.ok', false)
        ->assertScript('window.results.describe.error.code', 'no_sheet');
});
