<?php

use App\Contracts\ArtifactContentSource;
use App\Contracts\ArtifactDirectory;
use App\Enums\ArtifactType;
use App\Enums\TeamRole;
use App\Models\Team;
use App\Models\User;
use App\Services\Artifacts\ArtifactTypeResolver;
use App\Services\Artifacts\FakeArtifactContentSource;
use App\Services\Artifacts\FakeArtifactDirectory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;

uses(RefreshDatabase::class);

/*
 * RUB-562 / spec 46: the PowerPoint export route. The browser captures one PNG
 * per slide and posts them here; the server writes the .pptx. Visibility is the
 * same as the other downloads: no access is the same 404 as a missing artifact.
 */

const PPTX_TEAM_SLUG = 'pptx-team';

/** A 1920 by 1080 PNG with a flat fill, made with GD. */
function pptxCapturePng(int $width = 1920, int $height = 1080): string
{
    $image = imagecreatetruecolor($width, $height);
    imagefilledrectangle($image, 0, 0, $width - 1, $height - 1, imagecolorallocate($image, 180, 60, 40));

    ob_start();
    imagepng($image);

    return (string) ob_get_clean();
}

/** @param  list<string>  $captures  raw PNG bytes */
function pptxExportPost(string $artifactId, array $captures): TestResponse
{
    return test()->postJson(
        '/a/'.$artifactId.'/export/pptx',
        ['captures' => array_map(base64_encode(...), $captures)],
    );
}

/** Seeds the artifact, its type and its content for a team. */
function pptxSeedArtifact(Team $team, ArtifactType $type = ArtifactType::SlideDeck): void
{
    $html = '<!doctype html><html><body><main class="deck-stage"><section class="slide"><h2>One</h2></section></main></body></html>';

    /** @var FakeArtifactDirectory $directory */
    $directory = app(ArtifactDirectory::class);
    $directory->seedArtifact([
        'id' => ARTIFACT_LINK_ID,
        'org_id' => $team->slug,
        'user_id' => 1,
        'title' => 'Q3 pitch',
        'description' => 'A seeded deck.',
        'content_hash' => md5(ARTIFACT_LINK_ID),
        'created_at' => now()->subDay()->toIso8601String(),
        'revoked_at' => null,
        'sharing' => 'team',
        'provenance' => ['agent' => 'cursor', 'repo_url' => null, 'commit_sha' => null],
    ]);

    /** @var FakeArtifactContentSource $content */
    $content = app(ArtifactContentSource::class);
    $content->seed($team->slug, ARTIFACT_LINK_ID, $html, tier: 'secure', version: 1, sharing: 'team', files: [
        ['path' => 'index.html', 'sha256' => hash('sha256', $html), 'content_type' => 'text/html', 'size_bytes' => strlen($html)],
    ], entrypoint: 'index.html');

    app(ArtifactTypeResolver::class)->record($team, ARTIFACT_LINK_ID, $html, $type);
}

/** @return array{0: Team, 1: User} a team and a member of it */
function pptxMember(): array
{
    $team = Team::factory()->create(['slug' => PPTX_TEAM_SLUG, 'name' => 'Pptx Team']);

    return [$team, memberOfTeam($team, TeamRole::Member)];
}

test('a member exports a slide deck as a pptx with one slide per capture', function () {
    [$team, $member] = pptxMember();
    pptxSeedArtifact($team);

    test()->actingAs($member);
    $response = pptxExportPost(ARTIFACT_LINK_ID, [pptxCapturePng(), pptxCapturePng(), pptxCapturePng()]);

    $response->assertOk()->assertDownload('q3-pitch.pptx');
    expect($response->headers->get('Content-Type'))->toBe('application/vnd.openxmlformats-officedocument.presentationml.presentation');

    $zip = new ZipArchive;
    expect($zip->open($response->baseResponse->getFile()->getPathname()))->toBeTrue();
    $slides = array_filter(
        array_map(fn (int $index): string => (string) $zip->getNameIndex($index), range(0, $zip->numFiles - 1)),
        fn (string $name): bool => preg_match('#^ppt/slides/slide\d+\.xml$#', $name) === 1,
    );
    $notes = $zip->getFromName('ppt/notesSlides/notesSlide1.xml');
    $zip->close();

    expect($slides)->toHaveCount(3)
        ->and($notes)->toContain(route('artifacts.show', ARTIFACT_LINK_ID));
});

test('a user outside the owning team gets the same 404 as a missing artifact', function () {
    [$team] = pptxMember();
    pptxSeedArtifact($team);

    $outsider = User::factory()->create();

    test()->actingAs($outsider);
    pptxExportPost(ARTIFACT_LINK_ID, [pptxCapturePng()])->assertNotFound();
});

test('a guest is sent to sign in and nothing is exported', function () {
    [$team] = pptxMember();
    pptxSeedArtifact($team);

    pptxExportPost(ARTIFACT_LINK_ID, [pptxCapturePng()])->assertRedirect();
});

test('a teammate gets the same 404 for a private deck owned by someone else', function () {
    [$team, $teammate] = pptxMember();
    pptxSeedArtifact($team);

    $owner = memberOfTeam($team, TeamRole::Member);
    $html = '<!doctype html><html><body><section class="slide"></section></body></html>';

    /** @var FakeArtifactContentSource $content */
    $content = app(ArtifactContentSource::class);
    $content->seed($team->slug, ARTIFACT_LINK_ID, $html, sharing: 'private', ownerUserId: (string) $owner->id);

    test()->actingAs($teammate);
    pptxExportPost(ARTIFACT_LINK_ID, [pptxCapturePng()])->assertNotFound();
});

test('a non-deck artifact is refused with 422 not_a_slide_deck', function () {
    [$team, $member] = pptxMember();
    pptxSeedArtifact($team, ArtifactType::Document);

    test()->actingAs($member);
    pptxExportPost(ARTIFACT_LINK_ID, [pptxCapturePng()])
        ->assertStatus(422)
        ->assertJsonPath('code', 'not_a_slide_deck');
});

test('more than 30 slides is refused with 422 too_many_slides', function () {
    [$team, $member] = pptxMember();
    pptxSeedArtifact($team);

    test()->actingAs($member);
    pptxExportPost(ARTIFACT_LINK_ID, array_fill(0, 31, pptxCapturePng()))
        ->assertStatus(422)
        ->assertJsonPath('code', 'too_many_slides');
});

test('a request with no captures is refused with 422 invalid_captures', function () {
    [$team, $member] = pptxMember();
    pptxSeedArtifact($team);

    test()->actingAs($member);
    test()->postJson('/a/'.ARTIFACT_LINK_ID.'/export/pptx', ['captures' => []])
        ->assertStatus(422)
        ->assertJsonPath('code', 'invalid_captures');
});

test('a capture without the PNG signature is refused with 422 invalid_png', function () {
    [$team, $member] = pptxMember();
    pptxSeedArtifact($team);

    test()->actingAs($member);
    pptxExportPost(ARTIFACT_LINK_ID, ["\xFF\xD8\xFF not a png"])
        ->assertStatus(422)
        ->assertJsonPath('code', 'invalid_png');
});

test('a PNG that is not 1920 by 1080 is refused with 422 wrong_dimensions', function () {
    [$team, $member] = pptxMember();
    pptxSeedArtifact($team);

    test()->actingAs($member);
    pptxExportPost(ARTIFACT_LINK_ID, [pptxCapturePng(1280, 720)])
        ->assertStatus(422)
        ->assertJsonPath('code', 'wrong_dimensions');
});

test('a truncated PNG with only the signature is refused with 422 wrong_dimensions, not a 500', function () {
    [$team, $member] = pptxMember();
    pptxSeedArtifact($team);

    test()->actingAs($member);
    pptxExportPost(ARTIFACT_LINK_ID, ["\x89PNG\r\n\x1a\n"])
        ->assertStatus(422)
        ->assertJsonPath('code', 'wrong_dimensions');
});

test('a truncated PNG with the signature and 10 more bytes is refused with 422 wrong_dimensions, not a 500', function () {
    [$team, $member] = pptxMember();
    pptxSeedArtifact($team);

    test()->actingAs($member);
    pptxExportPost(ARTIFACT_LINK_ID, ["\x89PNG\r\n\x1a\n".str_repeat("\x00", 10)])
        ->assertStatus(422)
        ->assertJsonPath('code', 'wrong_dimensions');
});

test('a PNG whose first chunk is not IHDR is refused with 422 wrong_dimensions', function () {
    [$team, $member] = pptxMember();
    pptxSeedArtifact($team);

    $png = pptxCapturePng();
    $withWrongChunk = substr_replace($png, 'XXXX', 12, 4);

    test()->actingAs($member);
    pptxExportPost(ARTIFACT_LINK_ID, [$withWrongChunk])
        ->assertStatus(422)
        ->assertJsonPath('code', 'wrong_dimensions');
});

test('the export is rate limited to 10 requests per minute per user', function () {
    [$team, $member] = pptxMember();
    pptxSeedArtifact($team);

    test()->actingAs($member);
    $png = pptxCapturePng();

    for ($attempt = 0; $attempt < 10; $attempt++) {
        pptxExportPost(ARTIFACT_LINK_ID, [$png])->assertOk();
    }

    pptxExportPost(ARTIFACT_LINK_ID, [$png])->assertStatus(429);
});
