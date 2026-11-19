<?php

use App\Services\Artifacts\PublishContractValidator;

/*
 * RUB-565 / spec 42: the sales flier (one fixed Letter sheet on the doc starter's
 * doc.css) and the customer email (table layout at 600px, inline styles, no
 * script). Both pass the one shared PublishContractValidator.
 */

const FLIER_STARTER_DIR = __DIR__.'/../../../skills/artfct-team-doc/assets/flier-starter';
const EMAIL_TEMPLATE_DIR = __DIR__.'/../../../skills/artfct-team-doc/assets/email-template';
const DOC_STARTER_STYLE_PATH = __DIR__.'/../../../skills/artfct-team-doc/assets/doc-starter/doc.css';

/** @return array<string, string> */
function flierStarterFiles(array $overrides = []): array
{
    $files = [];
    foreach (['index.html', 'doc.css'] as $name) {
        $files[$name] = (string) file_get_contents(FLIER_STARTER_DIR.'/'.$name);
    }

    return array_merge($files, $overrides);
}

/** @return array<string, string> */
function emailTemplateFiles(array $overrides = []): array
{
    $files = [
        'index.html' => (string) file_get_contents(EMAIL_TEMPLATE_DIR.'/index.html'),
        'logo.svg' => (string) file_get_contents(EMAIL_TEMPLATE_DIR.'/logo.svg'),
    ];

    return array_merge($files, $overrides);
}

test('the flier and email templates have their files', function () {
    foreach (['index.html', 'doc.css'] as $name) {
        expect(FLIER_STARTER_DIR.'/'.$name)->toBeFile();
    }

    foreach (['index.html', 'logo.svg'] as $name) {
        expect(EMAIL_TEMPLATE_DIR.'/'.$name)->toBeFile();
    }
});

test('the flier and email carry their artfct-starter meta tags', function () {
    expect(flierStarterFiles()['index.html'])->toContain('<meta name="artfct-starter" content="flier@1">')
        ->and(emailTemplateFiles()['index.html'])->toContain('<meta name="artfct-starter" content="email@1">');
});

test('the flier passes the publish contract', function () {
    $errors = (new PublishContractValidator)->validate(flierStarterFiles(), 'Flier starter', 'index.html');

    expect($errors)->toBe([]);
});

test('the email passes the publish contract', function () {
    $errors = (new PublishContractValidator)->validate(emailTemplateFiles(), 'Email template', 'index.html');

    expect($errors)->toBe([]);
});

test('the publish contract refuses violating copies of the flier', function (string $file, string $with, string $message) {
    $errors = (new PublishContractValidator)->validate(flierStarterFiles([$file => $with]), 'Flier starter', 'index.html');

    expect($errors)->toContain($message);
})->with([
    'inline script' => [
        'index.html', '<!doctype html><script>fetch("/x")</script>',
        'Flier starter file [index.html] has an inline `<script>`; put scripts in their own file and load them with `<script src="…">`.',
    ],
    'onclick handler' => [
        'index.html', '<!doctype html><button onclick="go()">Go</button>',
        'Flier starter file [index.html] has an `on*=` handler; move interactivity into a bundled script file.',
    ],
    'data uri image' => [
        'index.html', '<!doctype html><img src="data:image/png;base64,AAAA" alt="">',
        'Flier starter file [index.html] has a `data:` URI; put images and fonts in files/ and reference them by relative path.',
    ],
    'external font url' => [
        'doc.css', '@font-face { font-family: X; src: url(https://fonts.example.com/x.woff2) }',
        'Flier starter file [doc.css] loads an external origin [https://fonts.example.com/x.woff2]; bundle every asset in files/.',
    ],
]);

test('the publish contract refuses violating copies of the email', function (string $file, string $with, string $message) {
    $errors = (new PublishContractValidator)->validate(emailTemplateFiles([$file => $with]), 'Email template', 'index.html');

    expect($errors)->toContain($message);
})->with([
    'inline script' => [
        'index.html', '<!doctype html><table><tr><td><script>fetch("/x")</script></td></tr></table>',
        'Email template file [index.html] has an inline `<script>`; put scripts in their own file and load them with `<script src="…">`.',
    ],
    'onclick handler' => [
        'index.html', '<!doctype html><table><tr><td><a onclick="go()">Reply</a></td></tr></table>',
        'Email template file [index.html] has an `on*=` handler; move interactivity into a bundled script file.',
    ],
    'data uri image' => [
        'index.html', '<!doctype html><table><tr><td><img src="data:image/png;base64,AAAA" alt=""></td></tr></table>',
        'Email template file [index.html] has a `data:` URI; put images and fonts in files/ and reference them by relative path.',
    ],
    'external font url' => [
        'index.html', '<!doctype html><link rel="stylesheet" href="https://fonts.example.com/x.css"><table><tr><td>Hi</td></tr></table>',
        'Email template file [index.html] loads an external origin [https://fonts.example.com/x.css]; bundle every asset in files/.',
    ],
]);

test('the email lays out in tables at 600px with no div grid', function () {
    $html = emailTemplateFiles()['index.html'];

    expect($html)->toContain('<table')
        ->and($html)->toContain('width="600"')
        ->and($html)->not->toContain('<div')
        ->and($html)->not->toContain('<script');
});

test('the email uses inline style attributes and a bundled logo path', function () {
    $html = emailTemplateFiles()['index.html'];

    expect($html)->toContain('style="')
        ->and($html)->toContain('src="logo.svg"');
});

test('the flier is one fixed sheet that reuses the doc starter doc.css', function () {
    $files = flierStarterFiles();

    expect($files['index.html'])->toContain('<main class="sheet sheet--fixed">')
        ->and($files['index.html'])->toContain('<link rel="stylesheet" href="doc.css">')
        ->and($files['doc.css'])->toBe(file_get_contents(DOC_STARTER_STYLE_PATH));
});
