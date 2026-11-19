<?php

/*
 * RUB-558 / spec 31: the deck starter in a browser. The navigation tests load the
 * starter top-level. The bridge tests frame it from a parent on a different
 * origin, and a sibling frame sends a rogue ready message. The starter is served
 * with the artifact-style CSP by tests/Fixtures/deck-starter/starter-router.php.
 */

use Symfony\Component\Process\Process;

const DECK_STARTER_FIXTURES = __DIR__.'/../Fixtures/deck-starter';
const DECK_STARTER_BUNDLE = __DIR__.'/../../skills/presentation/assets/deck-starter';

/** @var list<Process> */
$deckStarterServers = [];

/** @return int a free loopback TCP port */
function deckStarterFreePort(): int
{
    $socket = stream_socket_server('tcp://127.0.0.1:0');
    $port = (int) parse_url(stream_socket_get_name($socket, false), PHP_URL_PORT);
    fclose($socket);

    return $port;
}

/** @return array{parent: string, starter: string} base URLs of the two origins */
function deckStarterOrigins(): array
{
    static $origins = null;

    if ($origins !== null) {
        return $origins;
    }

    $parentPort = deckStarterFreePort();
    $starterPort = deckStarterFreePort();
    $origins = [
        'parent' => "http://127.0.0.1:{$parentPort}",
        'starter' => "http://127.0.0.1:{$starterPort}",
    ];

    $parentServer = new Process(['php', '-S', "127.0.0.1:{$parentPort}", '-t', DECK_STARTER_FIXTURES]);
    $parentServer->start();

    $starterServer = new Process(
        ['php', '-S', "127.0.0.1:{$starterPort}", DECK_STARTER_FIXTURES.'/starter-router.php'],
        null,
        [
            'DECK_STARTER_DIR' => DECK_STARTER_BUNDLE,
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

function deckStarterParentUrl(): string
{
    $origins = deckStarterOrigins();

    return $origins['parent'].'/parent.html?starter='.urlencode($origins['starter']);
}

function deckStarterUrl(string $query = ''): string
{
    return deckStarterOrigins()['starter'].'/index.html'.$query;
}

test('deck starter handshake works from a different origin', function () {
    visit(deckStarterParentUrl())
        ->waitForText('done')
        ->assertScript('location.origin !== new URLSearchParams(location.search).get("starter")', true)
        ->assertScript('window.results.replies.ready.data.ok', true)
        ->assertScript('window.results.replies.ready.data.result.starter', 'deck@1')
        ->assertScript('window.results.replies.ready.data.result.slideCount', 3)
        ->assertScript('window.results.replies.ready.data.result.protocol', 1);
});

test('deck starter describe returns the three slides', function () {
    visit(deckStarterParentUrl())
        ->waitForText('done')
        ->assertScript('window.results.replies.describe.data.result.slides.length', 3)
        ->assertScript('window.results.replies.describe.data.result.slides[0].runs.some((run) => run.text === "Deck title")', true)
        ->assertScript('window.results.replies.describe.data.result.slides[0].notes', 'Speaker notes for slide 1.')
        ->assertScript('window.results.replies.describe.data.result.slides[2].index', 2)
        ->assertScript('window.results.replies.describe.data.result.slides[1].images.length', 0);
});

test('deck starter goTo answers in range and refuses out-of-range indexes', function () {
    visit(deckStarterParentUrl())
        ->waitForText('done')
        ->assertScript('window.results.replies.goto2.data.ok', true)
        ->assertScript('window.results.replies.goto2.data.result.index', 2)
        ->assertScript('window.results.replies.goto9.data.error.code', 'out_of_range')
        ->assertScript('window.results.replies.gotoNeg.data.error.code', 'out_of_range')
        ->assertScript('window.results.replies.gotoFrac.data.error.code', 'out_of_range');
});

test('deck starter ignores sibling-frame and pre-handshake messages', function () {
    visit(deckStarterParentUrl())
        ->waitForText('done')
        // Only the parent's commands get replies, in order. The pre-handshake describe,
        // the sibling ready and the v=2 message get none.
        ->assertScript('JSON.stringify(window.results.ids)', '["ready","describe","goto2","goto9","gotoNeg","gotoFrac","unknown","payload"]')
        ->assertScript('JSON.stringify(window.results.siblingReplies)', '[]')
        ->assertScript('window.results.replies.pre === undefined', true)
        ->assertScript('window.results.replies.badv === undefined', true)
        ->assertScript('window.results.replies.unknown.data.error.code', 'unknown_command');
});

test('deck starter replies go to the parent origin, not a payload origin', function () {
    visit(deckStarterParentUrl())
        ->waitForText('done')
        // The reply reached this page, so it was sent to the parent origin; the
        // payload origin (evil.example) was not used as the target.
        ->assertScript('window.results.replies.payload.origin === new URL(window.results.replies.payload.origin).origin', true)
        ->assertScript('window.results.replies.payload.data.ok', true);
});

test('deck starter script runs under the artifact CSP', function () {
    // A blocked script would never answer ready, so a successful handshake proves
    // app.js ran under script-src 'self' with no CSP violation.
    visit(deckStarterParentUrl())
        ->waitForText('done')
        ->assertScript('window.results.replies.ready !== undefined', true);
});

test('the keyboard moves the counter', function () {
    // Keys go to the focused control; the keydown listener is on the document.
    $next = 'button[aria-label="Next slide"]';
    $previous = 'button[aria-label="Previous slide"]';

    visit(deckStarterUrl())
        ->assertScript('document.querySelector(".deck-counter").textContent', '1 / 3')
        ->keys($next, 'ArrowRight')
        ->assertScript('document.querySelector(".deck-counter").textContent', '2 / 3')
        ->keys($next, 'ArrowLeft')
        ->assertScript('document.querySelector(".deck-counter").textContent', '1 / 3')
        ->keys($next, 'PageDown')
        ->assertScript('document.querySelector(".deck-counter").textContent', '2 / 3')
        ->keys($next, 'End')
        ->assertScript('document.querySelector(".deck-counter").textContent', '3 / 3')
        ->keys($previous, 'Home')
        ->assertScript('document.querySelector(".deck-counter").textContent', '1 / 3')
        ->assertNoJavaScriptErrors();
});

test('the on-screen buttons move the counter', function () {
    visit(deckStarterUrl())
        ->click('button[aria-label="Next slide"]')
        ->assertScript('document.querySelector(".deck-counter").textContent', '2 / 3')
        ->click('button[aria-label="Previous slide"]')
        ->assertScript('document.querySelector(".deck-counter").textContent', '1 / 3')
        ->assertScript('document.querySelector(\'button[aria-label="Previous slide"]\').disabled', true)
        ->assertNoJavaScriptErrors();
});

test('a deep link opens the slide it names and an out-of-range one shows slide 1', function () {
    visit(deckStarterUrl('#3'))
        ->assertScript('document.querySelector(".deck-counter").textContent', '3 / 3')
        ->assertScript('document.querySelectorAll(".slide")[2].hasAttribute("data-deck-active")', true);

    visit(deckStarterUrl('#9'))
        ->assertScript('document.querySelector(".deck-counter").textContent', '1 / 3')
        ->assertScript('document.querySelectorAll(".slide")[0].hasAttribute("data-deck-active")', true);
});

test('window.artfctDeck goTo and slideCount drive the active slide', function () {
    visit(deckStarterUrl())
        ->assertScript('window.artfctDeck.slideCount', 3)
        ->assertScript('window.artfctDeck.goTo(2)', true)
        ->assertScript('document.querySelectorAll(".slide")[2].hasAttribute("data-deck-active")', true)
        ->assertScript('document.querySelector(".deck-counter").textContent', '3 / 3')
        ->assertScript('window.artfctDeck.goTo(5)', false)
        ->assertScript('document.querySelectorAll(".slide")[2].hasAttribute("data-deck-active")', true);
});

test('the notes are hidden on stage but stay in the DOM', function () {
    visit(deckStarterUrl())
        ->assertScript('getComputedStyle(document.querySelector(".slide .notes")).display', 'none')
        ->assertScript('document.querySelector(".slide .notes").textContent', 'Speaker notes for slide 1.');
});

test('the stage scales to the viewport', function () {
    visit(deckStarterUrl())
        ->resize(1280, 720)
        ->assertScript('Math.round(document.querySelector(".deck-stage").getBoundingClientRect().width)', 1280)
        ->assertScript('Math.round(document.querySelector(".deck-stage").getBoundingClientRect().height)', 720);
});

test('noscale renders the stage at 1920 by 1080 in a narrower viewport', function () {
    visit(deckStarterUrl('?variant=noscale'))
        ->resize(1280, 720)
        ->assertScript('Math.round(document.querySelector(".deck-stage").getBoundingClientRect().width)', 1920)
        ->assertScript('Math.round(document.querySelector(".slide").getBoundingClientRect().height)', 1080);
});
