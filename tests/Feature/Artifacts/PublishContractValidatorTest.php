<?php

use App\Services\Artifacts\PublishContractValidator;

/**
 * @param  array<string, string>  $files
 * @return list<string>
 */
function publishContractErrors(array $files, ?string $entrypoint = 'index.html'): array
{
    return (new PublishContractValidator)->validate($files, 'Bundle', $entrypoint);
}

test('a valid bundle returns no errors', function () {
    $errors = publishContractErrors([
        'index.html' => '<!doctype html><link rel="stylesheet" href="styles/app.css"><script src="app.js"></script><img src="img/logo.svg" alt="">',
        'app.js' => 'fetch("/api/data");',
        'styles/app.css' => 'body { background: url("../img/bg.png"); }',
        'img/logo.svg' => '<svg xmlns="http://www.w3.org/2000/svg"><rect width="1" height="1"/></svg>',
        'img/bg.png' => 'png-bytes',
    ]);

    expect($errors)->toBe([]);
});

test('flags an inline script in html', function () {
    $errors = publishContractErrors([
        'index.html' => '<!doctype html><script>alert(1)</script>',
    ]);

    expect($errors)->toContain('Bundle file [index.html] has an inline `<script>`; put scripts in their own file and load them with `<script src="…">`.');
});

test('flags a script element in svg', function () {
    $errors = publishContractErrors([
        'index.html' => '<!doctype html>',
        'icon.svg' => '<svg><script>alert(1)</script></svg>',
    ]);

    expect($errors)->toContain('Bundle file [icon.svg] has an SVG `<script>` element; SVG assets must be static.');
});

test('flags a foreignObject element in svg', function () {
    $errors = publishContractErrors([
        'index.html' => '<!doctype html>',
        'icon.svg' => '<svg><foreignObject><div>hi</div></foreignObject></svg>',
    ]);

    expect($errors)->toContain('Bundle file [icon.svg] has an SVG `<foreignObject>` element; SVG assets must be static.');
});

test('flags an on*= handler', function () {
    $errors = publishContractErrors([
        'index.html' => '<!doctype html><button onclick="go()">Go</button>',
    ]);

    expect($errors)->toContain('Bundle file [index.html] has an `on*=` handler; move interactivity into a bundled script file.');
});

test('flags a data URI', function () {
    $errors = publishContractErrors([
        'index.html' => '<!doctype html><img src="data:image/png;base64,AAAA" alt="">',
    ]);

    expect($errors)->toContain('Bundle file [index.html] has a `data:` URI; put images and fonts in files/ and reference them by relative path.');
});

test('flags an external origin in css', function () {
    $errors = publishContractErrors([
        'index.html' => '<!doctype html>',
        'styles.css' => '@import "https://fonts.example.com/font.css";',
    ]);

    expect($errors)->toContain('Bundle file [styles.css] loads an external origin [https://fonts.example.com/font.css]; bundle every asset in files/.');
});

test('flags an external origin in html and js', function () {
    $errors = publishContractErrors([
        'index.html' => '<!doctype html><script src="https://cdn.example.com/lib.js"></script>',
        'app.js' => 'fetch("https://api.example.com/data");',
    ]);

    expect($errors)->toContain('Bundle file [index.html] loads an external origin [https://cdn.example.com/lib.js]; bundle every asset in files/.')
        ->and($errors)->toContain('Bundle file [app.js] loads an external origin [https://api.example.com/data]; bundle every asset in files/.');
});

test('flags a reference that is not in the bundle', function () {
    $errors = publishContractErrors([
        'index.html' => '<!doctype html><link rel="stylesheet" href="missing.css">',
    ]);

    expect($errors)->toContain('Bundle file [index.html] references [missing.css], which is not in files/.');
});

test('flags a missing entrypoint', function () {
    $errors = publishContractErrors([
        'other.html' => '<!doctype html>',
    ]);

    expect($errors)->toContain('Bundle entrypoint [index.html] is not one of the files in files/.');
});

test('flags a reserved __artfct prefix', function () {
    $errors = publishContractErrors([
        'index.html' => '<!doctype html>',
        '__artfct/secret.js' => 'x',
    ]);

    expect($errors)->toContain('Bundle file [__artfct/secret.js] lives under the reserved `__artfct/` prefix.');
});

test('flags an absolute-path reference', function () {
    $errors = publishContractErrors([
        'index.html' => '<!doctype html><link rel="stylesheet" href="/styles.css">',
        'styles.css' => 'body {}',
    ]);

    expect($errors)->toContain('Bundle file [index.html] references absolute path [/styles.css]; use a relative path inside files/.');
});

test('flags an on*= handler in svg', function () {
    $errors = publishContractErrors([
        'index.html' => '<!doctype html>',
        'icon.svg' => '<svg onload="go()"><rect width="1" height="1"/></svg>',
    ]);

    expect($errors)->toContain('Bundle file [icon.svg] has an `on*=` handler; move interactivity into a bundled script file.');
});

test('flags an external origin in svg', function () {
    $errors = publishContractErrors([
        'index.html' => '<!doctype html>',
        'icon.svg' => '<svg><image href="https://cdn.example.com/a.png"/></svg>',
    ]);

    expect($errors)->toContain('Bundle file [icon.svg] loads an external origin [https://cdn.example.com/a.png]; bundle every asset in files/.');
});

test('flags an invalid relative path', function () {
    $errors = publishContractErrors([
        'index.html' => '<!doctype html>',
        '../escape.js' => 'x',
    ]);

    expect($errors)->toContain('Bundle file [../escape.js] is not a valid relative bundle path.');
});

test('skips the entrypoint check when no entrypoint is given', function () {
    $errors = publishContractErrors(['other.html' => '<!doctype html>'], null);

    expect($errors)->toBe([]);
});

test('flags a bundle over the file-count limit', function () {
    $files = ['index.html' => '<!doctype html>'];
    for ($index = 1; $index <= PublishContractValidator::MAX_BUNDLE_FILES; $index++) {
        $files["files/{$index}.txt"] = 'x';
    }

    expect(publishContractErrors($files))->toContain('Bundle has '.count($files).' files; the bundle limit is '.PublishContractValidator::MAX_BUNDLE_FILES.'.');
});

test('flags an empty bundle total', function () {
    expect(publishContractErrors(['index.html' => '']))->toContain('Bundle totals 0 bytes; the bundle limit is '.PublishContractValidator::MAX_BUNDLE_BYTES.'.');
});
