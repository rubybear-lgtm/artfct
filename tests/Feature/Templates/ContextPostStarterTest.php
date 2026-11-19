<?php

use App\Services\Artifacts\PublishContractValidator;
use App\Services\Templates\TemplateCatalog;

/*
 * RUB-559 / spec 29: the context-post starter. Its four files are the
 * official page for a post. Content lives in index.html; chrome lives in
 * post.css; app.js fills the post-details block from the same-origin db
 * endpoint; manifest.json declares the db for deploy_artifact.
 */

const POST_STARTER_DIR = __DIR__.'/../../../skills/artfct-context-post/assets/post-starter';

/** @return array<string, string> */
function postStarterFiles(array $overrides = []): array
{
    $files = [];
    foreach (['index.html', 'post.css', 'app.js', 'manifest.json'] as $name) {
        $files[$name] = (string) file_get_contents(POST_STARTER_DIR.'/'.$name);
    }

    return array_merge($files, $overrides);
}

/** @return array<string, mixed> */
function postStarterManifest(): array
{
    return json_decode(postStarterFiles()['manifest.json'], true, flags: JSON_THROW_ON_ERROR);
}

test('the context post starter has its four files', function () {
    foreach (['index.html', 'post.css', 'app.js', 'manifest.json'] as $name) {
        expect(POST_STARTER_DIR.'/'.$name)->toBeFile();
    }
});

test('the context post manifest declares both collections and seeds details once', function () {
    $manifest = postStarterManifest();

    expect(array_keys($manifest['db']['collections']))->toEqualCanonicalizing(['post', 'post.reads'])
        ->and($manifest['db']['seed'])->toBe([[
            'collection' => 'post',
            'doc_id' => 'details',
            'body' => ['kind' => 'decision', 'owner' => '', 'status' => 'draft', 'replaced_by' => null],
        ]]);
});

test('validateDbManifest accepts the context post manifest', function () {
    $errors = TemplateCatalog::validateDbManifest(postStarterManifest()['db'], 'Context post');

    expect($errors)->toBe([]);
});

test('validateDbManifest rejects a seed row naming an unknown collection', function () {
    $db = postStarterManifest()['db'];
    $db['seed'][0]['collection'] = 'memos';

    expect(TemplateCatalog::validateDbManifest($db, 'Context post'))
        ->toContain('Context post `db.seed[0]` names an undeclared collection; seed rows may only use `db.collections`.');
});

test('validateDbManifest rejects an invalid seed row', function () {
    $db = postStarterManifest()['db'];
    $db['seed'][0]['doc_id'] = 'has/slash';

    expect(TemplateCatalog::validateDbManifest($db, 'Context post'))
        ->toContain('Context post `db.seed[0]` `doc_id` must be 1-128 characters of [A-Za-z0-9._-].');
});

test('the context post starter passes the publish contract', function () {
    $errors = (new PublishContractValidator)->validate(postStarterFiles(), 'Context post', 'index.html');

    expect($errors)->toBe([]);
});

test('the publish contract refuses violating copies of the starter', function (string $file, string $with, string $message) {
    $files = postStarterFiles([$file => $with]);

    expect((new PublishContractValidator)->validate($files, 'Context post', 'index.html'))->toContain($message);
})->with([
    'inline script' => [
        'index.html', '<!doctype html><script>fetch("/x")</script>',
        'Context post file [index.html] has an inline `<script>`; put scripts in their own file and load them with `<script src="…">`.',
    ],
    'onclick handler' => [
        'index.html', '<!doctype html><button onclick="go()">Go</button>',
        'Context post file [index.html] has an `on*=` handler; move interactivity into a bundled script file.',
    ],
    'data uri image' => [
        'index.html', '<!doctype html><img src="data:image/png;base64,AAAA" alt="">',
        'Context post file [index.html] has a `data:` URI; put images and fonts in files/ and reference them by relative path.',
    ],
    'external origin' => [
        'index.html', '<!doctype html><script src="https://cdn.example.com/x.js"></script>',
        'Context post file [index.html] loads an external origin [https://cdn.example.com/x.js]; bundle every asset in files/.',
    ],
]);

test('context post app.js reads only the same-origin db endpoint', function () {
    $script = postStarterFiles()['app.js'];

    expect($script)->toContain('/__artfct/db/post/details')
        ->and($script)->not->toMatch('/https?:\/\//')
        ->and($script)->not->toMatch('/\bXMLHttpRequest\b|\bWebSocket\b|\bsendBeacon\b|\bEventSource\b/')
        ->and(preg_match_all('/\bfetch\s*\(/', $script))->toBe(1);
});

test('the context post snapshot is in index.html for readers without JavaScript', function () {
    $html = postStarterFiles()['index.html'];

    expect($html)->toContain('data-post-details')
        ->and($html)->toContain('data-field="kind"')
        ->and($html)->toContain('data-field="status"')
        ->and($html)->toContain('data-field="replaced_by"');
});
