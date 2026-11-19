<?php

use App\Services\Artifacts\PublishContractValidator;

/*
 * RUB-558 / spec 31: the official deck starter. index.html holds author content
 * only; deck.css and app.js hold all chrome. Every bundle check goes through
 * PublishContractValidator.
 */

const DECK_STARTER_DIR = __DIR__.'/../../../skills/presentation/assets/deck-starter';

/** @return array<string, string> */
function deckStarterFiles(array $overrides = []): array
{
    $files = [];
    foreach (['index.html', 'deck.css', 'app.js'] as $name) {
        $files[$name] = (string) file_get_contents(DECK_STARTER_DIR.'/'.$name);
    }

    return array_merge($files, $overrides);
}

test('the deck starter has its three files', function () {
    foreach (['index.html', 'deck.css', 'app.js'] as $name) {
        expect(DECK_STARTER_DIR.'/'.$name)->toBeFile();
    }
});

test('index.html carries the deck@1 meta and the slot and upgrade comment', function () {
    $html = deckStarterFiles()['index.html'];

    expect($html)->toContain('<meta name="artfct-starter" content="deck@1">')
        ->and($html)->toMatch('/<!--.*section.*notes.*deck\.css.*app\.js.*-->/s')
        ->and($html)->toContain('<link rel="stylesheet" href="deck.css">');
});

test('index.html has one script tag, app.js, loaded with defer', function () {
    $html = deckStarterFiles()['index.html'];

    expect(preg_match_all('/<script\b[^>]*>/i', $html))->toBe(1)
        ->and($html)->toContain('<script src="app.js" defer></script>');
});

test('the deck starter passes the publish contract', function () {
    $errors = (new PublishContractValidator)->validate(deckStarterFiles(), 'Deck starter', 'index.html');

    expect($errors)->toBe([]);
});

test('the publish contract refuses violating copies of the deck starter', function (string $file, string $with, string $message) {
    $files = deckStarterFiles([$file => $with]);

    expect((new PublishContractValidator)->validate($files, 'Deck starter', 'index.html'))->toContain($message);
})->with([
    'inline script' => [
        'index.html', '<!doctype html><script>fetch("/x")</script>',
        'Deck starter file [index.html] has an inline `<script>`; put scripts in their own file and load them with `<script src="…">`.',
    ],
    'onclick handler' => [
        'index.html', '<!doctype html><button onclick="go()">Go</button>',
        'Deck starter file [index.html] has an `on*=` handler; move interactivity into a bundled script file.',
    ],
    'data uri image' => [
        'index.html', '<!doctype html><img src="data:image/png;base64,AAAA" alt="">',
        'Deck starter file [index.html] has a `data:` URI; put images and fonts in files/ and reference them by relative path.',
    ],
    'external origin' => [
        'index.html', '<!doctype html><img src="https://images.example.com/x.png" alt="">',
        'Deck starter file [index.html] loads an external origin [https://images.example.com/x.png]; bundle every asset in files/.',
    ],
]);

test('deck.css declares --deck tokens with the system font stack and no Solarized', function () {
    $css = deckStarterFiles()['deck.css'];

    expect($css)->toMatch('/:root\s*\{[^}]*--deck-[a-z-]+:/s')
        ->and($css)->toContain('system-ui')
        ->and($css)->not->toContain('@font-face')
        ->and($css)->not->toContain('@import')
        ->and(strtolower(implode('', deckStarterFiles())))->not->toContain('solarized');
});

test('deck.css declares the one-slide-per-page print rules', function () {
    $css = deckStarterFiles()['deck.css'];

    expect($css)->toContain('@page { size: 1920px 1080px; margin: 0 }')
        ->and($css)->toContain('break-after: page');
});

test('the notes live only in p.notes and no script carries them', function () {
    $files = deckStarterFiles();

    expect($files['index.html'])->toContain('<p class="notes">')
        ->and(preg_match('/<script\b[^>]*>[^<]*notes/i', $files['index.html']))->toBe(0);
});

test('app.js makes no network request and never touches the parent document', function () {
    $script = deckStarterFiles()['app.js'];

    expect($script)->not->toMatch('/\b(fetch|XMLHttpRequest|sendBeacon|WebSocket|EventSource)\b/')
        ->and($script)->not->toContain('import(')
        ->and($script)->not->toContain('parent.document')
        ->and($script)->not->toContain('document.cookie')
        ->and($script)->not->toContain('localStorage');
});

test('app.js writes no markup from strings and builds no slides', function () {
    $script = deckStarterFiles()['app.js'];

    expect($script)->not->toContain('innerHTML')
        ->and($script)->not->toContain('insertAdjacentHTML')
        ->and($script)->not->toContain('class = \'slide\'')
        ->and($script)->not->toContain('className = \'slide\'');
});

test('app.js replies to the parent origin and never with a wildcard target', function () {
    $script = deckStarterFiles()['app.js'];

    expect($script)->toContain('event.origin')
        ->and($script)->toContain('event.source')
        ->and($script)->toContain('window.parent')
        ->and($script)->not->toMatch("/postMessage\\([^)]*['\"]\\*['\"]/");
});

test('app.js has one postMessage call and every reply passes the reply origin first', function () {
    // The argument text of each call is only checked by prefix, so a nested call such as
    // postMessage(f(x), '*') cannot hide a wildcard target. The reply helper's own
    // definition is excluded; it is the one place that forwards `origin` as a parameter.
    $script = deckStarterFiles()['app.js'];

    expect(substr_count($script, 'postMessage'))->toBe(1);

    preg_match_all('/(?<!function )\breply\s*\(\s*([^\n]*)/', $script, $calls);

    expect($calls[1])->not->toBeEmpty()
        ->and(array_filter($calls[1], fn (string $arguments): bool => ! str_starts_with($arguments, 'event.source, event.origin')))->toBe([]);
});

test('app.js exposes the navigation API the export clients drive', function () {
    $script = deckStarterFiles()['app.js'];

    expect($script)->toContain('window.artfctDeck')
        ->and($script)->toContain('goTo')
        ->and($script)->toContain('slideCount')
        ->and($script)->toContain('data-deck-active');
});

test('deck.css declares the --type tokens on :root', function () {
    $css = deckStarterFiles()['deck.css'];

    expect($css)->toMatch('/:root\s*\{[^}]*--type-kicker:[^}]*--type-title:[^}]*--type-heading:[^}]*--type-body:/s');
});

test('a deck themed with a style attribute on the stage passes the publish contract', function () {
    $html = str_replace(
        '<main class="deck-stage" aria-label="Slides">',
        '<main class="deck-stage" aria-label="Slides" style="--deck-accent: #7a2e2e; --type-body: calc(var(--deck-unit) * 48)">',
        deckStarterFiles()['index.html'],
    );

    expect((new PublishContractValidator)->validate(deckStarterFiles(['index.html' => $html]), 'Deck starter', 'index.html'))->toBe([]);
});
