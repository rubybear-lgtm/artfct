<?php

use App\Contracts\ArtifactContentSource;
use App\Contracts\ArtifactDirectory;
use App\Enums\TeamRole;
use App\Models\Team;
use App\Services\Artifacts\FakeArtifactContentSource;
use App\Services\Artifacts\FakeArtifactDirectory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;

uses(RefreshDatabase::class);

/**
 * The standalone HTML download (RUB-563, spec 40). `artifacts.download.html`
 * packs a published bundle into one HTML file that opens offline. The input
 * must pass the shared publish contract checker; the output is a download
 * format, so these tests assert its shape (no external or relative references,
 * inline style and script, data URIs) rather than the publish contract.
 */
const STANDALONE_TEAM_SLUG = 'standalone-team';

/**
 * Seeds one bundle for the team's artifact. `$files` is path => bytes and must
 * include `index.html`. Every other file is seeded as a blob under its sha256.
 *
 * @param  array<string, string>  $files
 * @param  ?list<array{path: string, sha256: string, content_type: string, size_bytes: int}>  $fileList  overrides the list the Worker returns
 */
function seedStandaloneBundle(Team $team, array $files, ?array $fileList = null): void
{
    /** @var FakeArtifactDirectory $directory */
    $directory = app(ArtifactDirectory::class);
    $list = [];

    foreach ($files as $path => $bytes) {
        $sha256 = hash('sha256', $bytes);
        $list[] = [
            'path' => $path,
            'sha256' => $sha256,
            'content_type' => standaloneContentType($path),
            'size_bytes' => strlen($bytes),
        ];

        if ($path !== 'index.html') {
            $directory->seedBlob($sha256, $bytes);
        }
    }

    usort($list, fn (array $left, array $right): int => strcmp($left['path'], $right['path']));

    /** @var FakeArtifactContentSource $content */
    $content = app(ArtifactContentSource::class);
    $content->seed($team->slug, ARTIFACT_LINK_ID, $files['index.html'], tier: 'secure', sharing: 'team', files: $fileList ?? $list);
}

function standaloneContentType(string $path): string
{
    return match (strtolower(pathinfo($path, PATHINFO_EXTENSION))) {
        'html' => 'text/html',
        'css' => 'text/css',
        'js' => 'text/javascript',
        'png' => 'image/png',
        'woff2' => 'font/woff2',
        'svg' => 'image/svg+xml',
        'json' => 'application/json',
        default => 'application/octet-stream',
    };
}

/**
 * Seeds the artifact's row in the directory, visible to the team.
 *
 * @param  array<string, mixed>  $overrides
 */
function seedStandaloneArtifact(Team $team, array $overrides = []): void
{
    /** @var FakeArtifactDirectory $directory */
    $directory = app(ArtifactDirectory::class);
    $directory->seedArtifact(array_merge([
        'id' => ARTIFACT_LINK_ID,
        'org_id' => $team->slug,
        'user_id' => 1,
        'title' => 'Quarterly deck',
        'description' => 'A seeded deck.',
        'content_hash' => md5(ARTIFACT_LINK_ID),
        'created_at' => now()->subDay()->toIso8601String(),
        'revoked_at' => null,
        'sharing' => 'team',
        'provenance' => ['agent' => 'cursor', 'repo_url' => null, 'commit_sha' => null],
    ], $overrides));
}

/**
 * A member of a team that owns the artifact, with the artifact visible to it.
 */
function standaloneMember(): array
{
    $team = Team::factory()->create(['slug' => STANDALONE_TEAM_SLUG, 'name' => 'Standalone Team']);
    $member = memberOfTeam($team, TeamRole::Member);
    seedStandaloneArtifact($team);

    return [$team, $member];
}

/** The smallest bundle that uses every kind of asset: CSS in a folder, a font, a PNG and a script. */
function standaloneFixtureBundle(): array
{
    return [
        'index.html' => '<!doctype html><html><head><meta charset="utf-8"><title>Fixture</title>'
            .'<link rel="stylesheet" href="css/site.css"></head>'
            .'<body><h1>Fixture</h1><img src="logo.png" alt="Logo">'
            .'<script src="app.js" defer></script></body></html>',
        'css/site.css' => '@font-face { font-family: "Fixture"; src: url("../fonts/fixture.woff2") format("woff2"); }'
            .' body { font-family: "Fixture"; }',
        'fonts/fixture.woff2' => 'woff2-bytes',
        'logo.png' => 'png-bytes',
        'app.js' => 'document.getElementById("logo").src = "logo.png";',
    ];
}

/**
 * @return array<int, string> every src/href attribute value in the HTML that is not a fragment or a data URI
 */
function standaloneRelativeReferences(string $html): array
{
    preg_match_all('/\b(?:src|href)\s*=\s*["\']([^"\']*)["\']/i', $html, $matches);

    return array_values(array_filter(
        $matches[1],
        fn (string $reference): bool => ! str_starts_with($reference, '#')
            && ! str_starts_with($reference, 'data:')
            && ! str_starts_with($reference, 'mailto:'),
    ));
}

/** @return array<string, mixed> */
function standaloneRefusal(TestResponse $response): array
{
    return $response->json();
}

test('a member downloads a bundle as one HTML file with its assets inline', function () {
    [, $member] = standaloneMember();
    seedStandaloneBundle(Team::query()->where('slug', STANDALONE_TEAM_SLUG)->firstOrFail(), standaloneFixtureBundle());

    $response = test()->actingAs($member)->get('/a/'.ARTIFACT_LINK_ID.'/download.html');

    $response->assertOk();

    $html = $response->getContent();

    expect($response->headers->get('Content-Disposition'))
        ->toContain('attachment')
        ->toContain('quarterly-deck.html')
        ->and($response->headers->get('X-Content-Type-Options'))->toBe('nosniff')
        ->and($response->headers->get('Content-Security-Policy'))->toBe('sandbox')
        ->and($html)->toContain('<style>')
        ->toContain('<script>')
        ->toContain('data:image/png;base64,'.base64_encode('png-bytes'))
        ->toContain('data:font/woff2;base64,'.base64_encode('woff2-bytes'))
        ->not->toContain('https://')
        ->not->toContain('http://')
        ->not->toContain('<script src')
        ->not->toContain('<link')
        ->and(standaloneRelativeReferences($html))->toBe([]);
});

test('a font referenced from a stylesheet in a folder resolves against that stylesheet', function () {
    [, $member] = standaloneMember();
    seedStandaloneBundle(Team::query()->where('slug', STANDALONE_TEAM_SLUG)->firstOrFail(), standaloneFixtureBundle());

    $html = test()->actingAs($member)->get('/a/'.ARTIFACT_LINK_ID.'/download.html')->getContent();

    expect($html)->toContain('data:font/woff2;base64,'.base64_encode('woff2-bytes'))
        ->and($html)->not->toContain('../fonts/fixture.woff2');
});

test('a bundle that fails the publish contract is refused with the validator message and nothing is packed', function () {
    [$team, $member] = standaloneMember();
    seedStandaloneBundle($team, [
        'index.html' => '<!doctype html><html><body><script>document.title = "x";</script></body></html>',
    ]);

    $response = test()->actingAs($member)->get('/a/'.ARTIFACT_LINK_ID.'/download.html');

    $response->assertStatus(422)
        ->assertHeaderMissing('Content-Disposition');

    expect(standaloneRefusal($response)['errors'])
        ->toContain('Artifact ['.ARTIFACT_LINK_ID.'] file [index.html] has an inline `<script>`; put scripts in their own file and load them with `<script src="…">`.');
});

test('the packed bytes are identical when the same artifact is packed twice', function () {
    [, $member] = standaloneMember();
    seedStandaloneBundle(Team::query()->where('slug', STANDALONE_TEAM_SLUG)->firstOrFail(), standaloneFixtureBundle());

    $first = test()->actingAs($member)->get('/a/'.ARTIFACT_LINK_ID.'/download.html')->getContent();
    $second = test()->actingAs($member)->get('/a/'.ARTIFACT_LINK_ID.'/download.html')->getContent();

    expect($first)->not->toBe('')
        ->and($second)->toBe($first);
});

test('the download is a 404 for a caller who cannot see the artifact', function () {
    $team = Team::factory()->create(['slug' => STANDALONE_TEAM_SLUG, 'name' => 'Standalone Team']);
    $member = memberOfTeam($team, TeamRole::Member);
    seedStandaloneArtifact($team, ['sharing' => 'private', 'user_id' => 424242]);
    seedStandaloneBundle($team, standaloneFixtureBundle());

    /** @var FakeArtifactContentSource $content */
    $content = app(ArtifactContentSource::class);
    $content->seed($team->slug, ARTIFACT_LINK_ID, standaloneFixtureBundle()['index.html'], sharing: 'private', ownerUserId: '424242');

    test()->actingAs($member)->get('/a/'.ARTIFACT_LINK_ID.'/download.html')->assertNotFound();
});

test('a signed-out visitor is sent to sign in, not shown the file', function () {
    [$team] = standaloneMember();
    seedStandaloneBundle($team, standaloneFixtureBundle());

    test()->get('/a/'.ARTIFACT_LINK_ID.'/download.html')->assertRedirect();
});

test('a script string naming a file that is not in the bundle is reported and nothing is packed', function () {
    [$team, $member] = standaloneMember();
    seedStandaloneBundle($team, [
        'index.html' => '<!doctype html><html><body><script src="app.js"></script></body></html>',
        'app.js' => 'document.body.dataset.logo = "missing-logo.png";',
    ]);

    $response = test()->actingAs($member)->get('/a/'.ARTIFACT_LINK_ID.'/download.html');

    $response->assertStatus(422)
        ->assertHeaderMissing('Content-Disposition');

    expect(implode("\n", standaloneRefusal($response)['errors']))->toContain('missing-logo.png');
});

test('an image referenced from a script string resolves and is inlined', function () {
    [$team, $member] = standaloneMember();
    seedStandaloneBundle($team, [
        'index.html' => '<!doctype html><html><body><script src="app.js"></script></body></html>',
        'app.js' => 'document.body.dataset.logo = "logo.png";',
        'logo.png' => 'png-bytes',
    ]);

    $html = test()->actingAs($member)->get('/a/'.ARTIFACT_LINK_ID.'/download.html')->assertOk()->getContent();

    expect($html)->toContain('data:image/png;base64,'.base64_encode('png-bytes'))
        ->and($html)->not->toContain('logo.png');
});

test('a relative link to another bundle page is refused because one file cannot hold two pages', function () {
    [$team, $member] = standaloneMember();
    seedStandaloneBundle($team, [
        'index.html' => '<!doctype html><html><body><a href="other.html">Next</a></body></html>',
        'other.html' => '<!doctype html><html><body>Other</body></html>',
    ]);

    $response = test()->actingAs($member)->get('/a/'.ARTIFACT_LINK_ID.'/download.html');

    $response->assertStatus(422);
    expect(implode("\n", standaloneRefusal($response)['errors']))->toContain('other.html');
});

test('a module script is refused because import graphs are not packed', function () {
    [$team, $member] = standaloneMember();
    seedStandaloneBundle($team, [
        'index.html' => '<!doctype html><html><body><script type="module" src="app.js"></script></body></html>',
        'app.js' => 'export const value = 1;',
    ]);

    test()->actingAs($member)->get('/a/'.ARTIFACT_LINK_ID.'/download.html')->assertStatus(422);
});

test('a relative stylesheet import is refused because imports are not packed', function () {
    [$team, $member] = standaloneMember();
    seedStandaloneBundle($team, [
        'index.html' => '<!doctype html><html><head><link rel="stylesheet" href="site.css"></head><body></body></html>',
        'site.css' => '@import "theme.css"; body { color: black; }',
        'theme.css' => 'p { color: red; }',
    ]);

    test()->actingAs($member)->get('/a/'.ARTIFACT_LINK_ID.'/download.html')->assertStatus(422);
});

test('a srcset candidate that names a missing file is refused', function () {
    [$team, $member] = standaloneMember();
    seedStandaloneBundle($team, [
        'index.html' => '<!doctype html><html><body><img src="logo.png" srcset="logo.png 1x, logo@2x.png 2x" alt=""></body></html>',
        'logo.png' => 'png-bytes',
    ]);

    $response = test()->actingAs($member)->get('/a/'.ARTIFACT_LINK_ID.'/download.html');

    $response->assertStatus(422);
    expect(implode("\n", standaloneRefusal($response)['errors']))->toContain('logo@2x.png');
});

test('a script that closes its own element is escaped so the inlined code cannot end early', function () {
    [$team, $member] = standaloneMember();
    seedStandaloneBundle($team, [
        'index.html' => '<!doctype html><html><body><p>Hi</p><script src="app.js"></script></body></html>',
        'app.js' => 'var markup = "</script><p>x</p>"; document.body.dataset.markup = markup;',
    ]);

    $html = test()->actingAs($member)->get('/a/'.ARTIFACT_LINK_ID.'/download.html')->assertOk()->getContent();

    expect(substr_count(strtolower($html), '</script>'))->toBe(1)
        ->and($html)->toContain('<\/script>');
});

test('a deferred script moves to the end of the body so its DOM exists when it runs', function () {
    [$team, $member] = standaloneMember();
    seedStandaloneBundle($team, [
        'index.html' => '<!doctype html><html><head><script src="app.js" defer></script></head><body><main id="stage">Hi</main></body></html>',
        'app.js' => 'document.getElementById("stage").dataset.ready = "1";',
    ]);

    $html = test()->actingAs($member)->get('/a/'.ARTIFACT_LINK_ID.'/download.html')->assertOk()->getContent();

    expect(strpos($html, '<script>'))->toBeGreaterThan(strpos($html, '</main>'));
});

test('the real deck starter packs with its static slides and its no-JavaScript rules intact', function () {
    [$team, $member] = standaloneMember();
    $directory = base_path('skills/presentation/assets/deck-starter');
    $files = [];
    foreach (['index.html', 'deck.css', 'app.js'] as $file) {
        $files[$file] = file_get_contents($directory.'/'.$file);
    }
    seedStandaloneBundle($team, $files);

    $html = test()->actingAs($member)->get('/a/'.ARTIFACT_LINK_ID.'/download.html')->assertOk()->getContent();

    expect($html)->toContain('html.deck-js')
        ->toContain('<section class="slide"')
        // app.js names capture.js (RUB-562, spec 46) and loads it only on a PowerPoint
        // capture from the hosted deck. A single-file download is never framed by the
        // export runner, so that one lazy reference is expected and is not packed.
        ->and(standaloneRelativeReferences($html))->toBe(['capture.js'])
        ->and(strpos($html, 'class="deck-stage"'))->not->toBeFalse();
});

test('the real doc starter packs with its static content intact', function () {
    [$team, $member] = standaloneMember();
    $directory = base_path('skills/artfct-team-doc/assets/doc-starter');
    $files = [];
    foreach (['index.html', 'doc.css', 'export.js'] as $file) {
        $files[$file] = file_get_contents($directory.'/'.$file);
    }
    seedStandaloneBundle($team, $files);

    $html = test()->actingAs($member)->get('/a/'.ARTIFACT_LINK_ID.'/download.html')->assertOk()->getContent();

    expect(standaloneRelativeReferences($html))->toBe([])
        ->and($html)->toContain('<style>')
        ->and($html)->not->toContain('<script src');
});

test('a bundle whose Worker does not list its files is refused with 503 and nothing is packed', function () {
    [$team, $member] = standaloneMember();
    seedStandaloneBundle($team, standaloneFixtureBundle());

    // An older Worker omits the file list; the packer must refuse rather than
    // pack a page whose assets are silently missing.
    /** @var FakeArtifactContentSource $content */
    $content = app(ArtifactContentSource::class);
    $content->markFilesUnknown($team->slug, ARTIFACT_LINK_ID);

    test()->actingAs($member)->get('/a/'.ARTIFACT_LINK_ID.'/download.html')
        ->assertStatus(503)
        ->assertHeaderMissing('Content-Disposition');
});
