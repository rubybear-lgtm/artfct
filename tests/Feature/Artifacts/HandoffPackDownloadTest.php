<?php

use App\Contracts\ArtifactContentSource;
use App\Contracts\ArtifactDirectory;
use App\Enums\ArtifactType;
use App\Enums\TeamRole;
use App\Models\Team;
use App\Services\Artifacts\ArtifactTypeResolver;
use App\Services\Artifacts\FakeArtifactContentSource;
use App\Services\Artifacts\FakeArtifactDirectory;
use App\Services\Artifacts\PublishContractValidator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;

uses(RefreshDatabase::class);

/**
 * The engineering handoff pack (RUB-564, spec 41). `artifacts.download.handoff`
 * zips a mockup bundle and promotes its `HANDOFF.md` to `README.md`, whose first
 * line links the artifact version. Visibility is the same as the HTML download.
 */
const HANDOFF_TEAM_SLUG = 'handoff-team';

const HANDOFF_README = "# Overview\n\nA dashboard mockup.\n\n# About these files\n\nDesign references, not production code.\n";

/**
 * Seeds one bundle for the team's artifact. `$files` is path => bytes and must
 * include `index.html`. Every other file is seeded as a blob under its sha256.
 *
 * @param  array<string, string>  $files
 */
function seedHandoffBundle(Team $team, array $files, ?int $version = 1, ?string $artifactType = 'mockup'): void
{
    /** @var FakeArtifactDirectory $directory */
    $directory = app(ArtifactDirectory::class);
    $list = [];

    foreach ($files as $path => $bytes) {
        $sha256 = hash('sha256', $bytes);
        $list[] = [
            'path' => $path,
            'sha256' => $sha256,
            'content_type' => str_ends_with($path, '.md') ? 'text/markdown' : 'text/html',
            'size_bytes' => strlen($bytes),
        ];

        if ($path !== 'index.html') {
            $directory->seedBlob($sha256, $bytes);
        }
    }

    usort($list, fn (array $left, array $right): int => strcmp($left['path'], $right['path']));

    /** @var FakeArtifactContentSource $content */
    $content = app(ArtifactContentSource::class);
    $content->seed($team->slug, ARTIFACT_LINK_ID, $files['index.html'], tier: 'secure', version: $version, sharing: 'team', files: $list, entrypoint: 'index.html');

    seedHandoffArtifact($team);

    if ($artifactType !== null) {
        app(ArtifactTypeResolver::class)->record($team, ARTIFACT_LINK_ID, $files['index.html'], ArtifactType::from($artifactType));
    }
}

/** @param  array<string, mixed>  $overrides */
function seedHandoffArtifact(Team $team, array $overrides = []): void
{
    /** @var FakeArtifactDirectory $directory */
    $directory = app(ArtifactDirectory::class);
    $directory->seedArtifact(array_merge([
        'id' => ARTIFACT_LINK_ID,
        'org_id' => $team->slug,
        'user_id' => 1,
        'title' => 'Checkout dashboard',
        'description' => 'A seeded mockup.',
        'content_hash' => md5(ARTIFACT_LINK_ID),
        'created_at' => now()->subDay()->toIso8601String(),
        'revoked_at' => null,
        'sharing' => 'team',
        'provenance' => ['agent' => 'cursor', 'repo_url' => null, 'commit_sha' => null],
    ], $overrides));
}

/** A member of the team that owns the artifact. */
function handoffMember(): array
{
    $team = Team::factory()->create(['slug' => HANDOFF_TEAM_SLUG, 'name' => 'Handoff Team']);

    return [$team, memberOfTeam($team, TeamRole::Member)];
}

/** The smallest mockup bundle with a handoff file. */
function handoffFixtureBundle(): array
{
    return [
        'index.html' => '<!doctype html><html><head><meta charset="utf-8"><title>Mockup</title>'
            .'<link rel="stylesheet" href="css/site.css"></head><body><h1>Mockup</h1></body></html>',
        'css/site.css' => 'body { color: #262624; }',
        'HANDOFF.md' => HANDOFF_README,
    ];
}

/**
 * @return array<string, string> entry name => bytes, read from the zip the response points at
 */
function handoffZipEntries(TestResponse $response): array
{
    $zip = new ZipArchive;
    expect($zip->open($response->baseResponse->getFile()->getPathname()))->toBeTrue();

    $entries = [];

    for ($index = 0; $index < $zip->numFiles; $index++) {
        $name = (string) $zip->getNameIndex($index);
        $entries[$name] = (string) $zip->getFromIndex($index);
    }

    $zip->close();

    return $entries;
}

test('a member downloads a mockup handoff pack as a zip with the bundle and a README', function () {
    [$team, $member] = handoffMember();
    seedHandoffBundle($team, handoffFixtureBundle());

    $response = test()->actingAs($member)->get('/a/'.ARTIFACT_LINK_ID.'/download-handoff.zip');

    $response->assertOk()->assertDownload();
    expect($response->headers->get('Content-Disposition'))->toContain('checkout-dashboard-handoff.zip');

    $entries = handoffZipEntries($response);

    expect(array_keys($entries))->toEqualCanonicalizing(['index.html', 'css/site.css', 'README.md'])
        ->and($entries)->not->toHaveKey('HANDOFF.md')
        ->and($entries['css/site.css'])->toBe('body { color: #262624; }')
        ->and($entries['README.md'])->toContain(HANDOFF_README);
});

test('the README first line links the artifact version and names its number', function () {
    [$team, $member] = handoffMember();
    seedHandoffBundle($team, handoffFixtureBundle(), version: 3);

    $response = test()->actingAs($member)->get('/a/'.ARTIFACT_LINK_ID.'/download-handoff.zip');

    $readme = handoffZipEntries($response)['README.md'];
    $firstLine = strtok($readme, "\n");
    $viewUrl = route('artifacts.version', ['artifactId' => ARTIFACT_LINK_ID, 'version' => 3]);

    expect($firstLine)->toContain($viewUrl)
        ->toContain('version 3');
});

test('an unknown version links the current artifact view and writes no version number', function () {
    [$team, $member] = handoffMember();
    seedHandoffBundle($team, handoffFixtureBundle(), version: null);

    $response = test()->actingAs($member)->get('/a/'.ARTIFACT_LINK_ID.'/download-handoff.zip');

    $readme = handoffZipEntries($response)['README.md'];
    $firstLine = strtok($readme, "\n");
    $viewUrl = route('artifacts.show', ['artifactId' => ARTIFACT_LINK_ID]);

    expect($firstLine)->toBe('Artifact view: '.$viewUrl)
        ->and($readme)->not->toContain('/v/1')
        ->and($readme)->not->toContain('version 1');
});

test('the download is a 404 for a caller who cannot see the artifact', function () {
    [$team, $member] = handoffMember();
    seedHandoffArtifact($team, ['sharing' => 'private', 'user_id' => 424242]);

    /** @var FakeArtifactContentSource $content */
    $content = app(ArtifactContentSource::class);
    $content->seed($team->slug, ARTIFACT_LINK_ID, handoffFixtureBundle()['index.html'], sharing: 'private', ownerUserId: '424242', files: []);

    test()->actingAs($member)->get('/a/'.ARTIFACT_LINK_ID.'/download-handoff.zip')->assertNotFound();
});

test('a signed-out visitor is sent to sign in, not given the zip', function () {
    [$team] = handoffMember();
    seedHandoffBundle($team, handoffFixtureBundle());

    test()->get('/a/'.ARTIFACT_LINK_ID.'/download-handoff.zip')->assertRedirect();
});

test('a bundle without HANDOFF.md is refused with handoff_missing and nothing is packed', function () {
    [$team, $member] = handoffMember();
    $bundle = handoffFixtureBundle();
    unset($bundle['HANDOFF.md']);
    seedHandoffBundle($team, $bundle);

    test()->actingAs($member)->get('/a/'.ARTIFACT_LINK_ID.'/download-handoff.zip')
        ->assertStatus(422)
        ->assertJsonPath('code', 'handoff_missing');
});

test('a bundle that already has a README.md is refused with handoff_readme_conflict', function () {
    [$team, $member] = handoffMember();
    seedHandoffBundle($team, handoffFixtureBundle() + ['README.md' => '# Other']);

    test()->actingAs($member)->get('/a/'.ARTIFACT_LINK_ID.'/download-handoff.zip')
        ->assertStatus(422)
        ->assertJsonPath('code', 'handoff_readme_conflict');
});

test('an artifact that is not a mockup is refused with handoff_not_mockup', function () {
    [$team, $member] = handoffMember();
    seedHandoffBundle($team, handoffFixtureBundle(), artifactType: 'document');

    test()->actingAs($member)->get('/a/'.ARTIFACT_LINK_ID.'/download-handoff.zip')
        ->assertStatus(422)
        ->assertJsonPath('code', 'handoff_not_mockup');
});

test('a mockup with no recorded type is refused with handoff_not_mockup', function () {
    [$team, $member] = handoffMember();
    seedHandoffBundle($team, handoffFixtureBundle(), artifactType: null);

    test()->actingAs($member)->get('/a/'.ARTIFACT_LINK_ID.'/download-handoff.zip')
        ->assertStatus(422)
        ->assertJsonPath('code', 'handoff_not_mockup');
});

test('a bundle whose Worker does not list its files is refused with 503', function () {
    [$team, $member] = handoffMember();
    seedHandoffBundle($team, handoffFixtureBundle());

    // An older Worker omits the file list; the pack must refuse rather than zip a partial bundle.
    /** @var FakeArtifactContentSource $content */
    $content = app(ArtifactContentSource::class);
    $content->markFilesUnknown($team->slug, ARTIFACT_LINK_ID);

    test()->actingAs($member)->get('/a/'.ARTIFACT_LINK_ID.'/download-handoff.zip')
        ->assertStatus(503)
        ->assertHeaderMissing('Content-Disposition');
});

test('HANDOFF.md passes the one shared publish contract checker alongside the mockup', function () {
    $errors = app(PublishContractValidator::class)->validate(handoffFixtureBundle(), 'Mockup', 'index.html');

    expect($errors)->toBe([]);
});
