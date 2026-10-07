<?php

use App\Actions\Teams\CreateTeam;
use App\Contracts\ArtifactDirectory;
use App\Models\Team;
use App\Models\User;
use App\Services\Artifacts\FakeArtifactDirectory;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * The artifact viewer's DOM (RUB-438), exercised in a browser.
 *
 * The header and the sharing control are client-rendered from Inertia props, so
 * only a browser can prove them: the title, owner and version render; the Share
 * control appears for someone who may change sharing and not for someone who
 * may not; Public is disabled with a plain note when the team turned public
 * links off; and the header wraps at phone width. The props, the mint and the
 * sharing outcomes are asserted in `tests/Feature/ArtifactViewerTest.php`.
 *
 * The frame itself points at the artifact's isolated origin, which this harness
 * does not serve; the test is about the header above it, never about fetching
 * that origin.
 */

/** A 13-character base36 permanent id, matching ArtifactIdShape::isStable. */
const VIEWER_BROWSER_ARTIFACT_ID = 'abc123def4567';

function viewerBrowserOwner(string $teamName = 'Viewer Users', string $email = 'viewer-user@example.com'): array
{
    $owner = User::factory()->create(['name' => 'Viewer Owner', 'email' => $email]);
    $team = app(CreateTeam::class)->handle($owner, $teamName);

    return [$team, $owner];
}

/**
 * @param  array<string, mixed>  $overrides
 */
function seedViewerBrowserArtifact(Team $team, User $owner, array $overrides = []): void
{
    /** @var FakeArtifactDirectory $directory */
    $directory = app(ArtifactDirectory::class);
    $directory->seedArtifact(array_merge([
        'id' => VIEWER_BROWSER_ARTIFACT_ID,
        'org_id' => $team->slug,
        'user_id' => $owner->id,
        'title' => 'Browser viewer report',
        'description' => 'Seeded for the artifact viewer browser tests.',
        'content_hash' => md5(VIEWER_BROWSER_ARTIFACT_ID),
        'created_at' => now()->subDay()->toIso8601String(),
        'revoked_at' => null,
        'sharing' => 'team',
        'owner_user_id' => (string) $owner->id,
        'provenance' => ['agent' => 'cursor', 'repo_url' => null, 'commit_sha' => null],
    ], $overrides));
}

function viewerBrowserArtifactUrl(): string
{
    return '/a/'.VIEWER_BROWSER_ARTIFACT_ID;
}

function configureViewerBrowserLinks(): void
{
    // `.localhost` resolves to the loopback address, so the frame's connection
    // fails immediately instead of hanging on a real DNS lookup — the test is
    // about the header, not about fetching the artifact origin.
    config([
        'services.artifact_access.token_secret' => 'browser-artifact-secret',
        'services.artifact_access.origin_suffix' => '.localhost',
    ]);
}

test('the_header_renders_the_title_owner_version_and_share_control_for_an_owner', function () {
    configureViewerBrowserLinks();

    [$team, $owner] = viewerBrowserOwner();
    seedViewerBrowserArtifact($team, $owner, ['can_change_sharing' => true]);
    test()->actingAs($owner);

    $page = visit(viewerBrowserArtifactUrl())
        ->assertNoJavaScriptErrors()
        ->assertSee('Browser viewer report')
        ->assertSee('Viewer Owner')
        ->assertSee('Version 1')
        ->assertVisible('@share-control')
        ->assertVisible('@open-artifact')
        ->assertVisible('@download-artifact')
        ->assertVisible('@artifact-frame');

    // The frame runs on its isolated origin, sandboxed to scripts, same-origin,
    // popups and forms, and carries a token — never a bare same-origin page.
    expect($page->attribute('@artifact-frame', 'src'))
        ->toContain('--'.VIEWER_BROWSER_ARTIFACT_ID.'.localhost/p/'.VIEWER_BROWSER_ARTIFACT_ID.'/')
        ->toContain('token=');

    $page->assertAttribute(
        '@artifact-frame',
        'sandbox',
        'allow-scripts allow-same-origin allow-popups allow-forms',
    );
});

test('a_member_without_permission_sees_the_current_level_and_no_share_control', function () {
    configureViewerBrowserLinks();

    [$team, $owner] = viewerBrowserOwner('Viewer Members', 'viewer-member@example.com');
    seedViewerBrowserArtifact($team, $owner, ['can_change_sharing' => false]);
    test()->actingAs($owner);

    visit(viewerBrowserArtifactUrl())
        ->assertNoJavaScriptErrors()
        ->assertSee('Browser viewer report')
        ->assertMissing('@share-control')
        ->assertVisible('@artifact-sharing-label')
        // The read-only label names the current level, not a control.
        ->assertSee('Team');
});

test('public_is_disabled_with_a_note_when_the_team_turned_public_links_off', function () {
    configureViewerBrowserLinks();

    [$team, $owner] = viewerBrowserOwner('Viewer Public Off', 'viewer-public-off@example.com');
    $team->forceFill(['public_sharing_allowed' => false])->save();
    seedViewerBrowserArtifact($team, $owner, ['can_change_sharing' => true]);
    test()->actingAs($owner);

    visit(viewerBrowserArtifactUrl())
        ->assertNoJavaScriptErrors()
        ->assertVisible('@share-control')
        ->click('@share-control')
        ->assertSee('Your team has turned off public links')
        ->assertDisabled('@share-option-public');
});

test('the_version_picker_offers_every_version_when_there_is_more_than_one', function () {
    configureViewerBrowserLinks();

    [$team, $owner] = viewerBrowserOwner('Viewer Versions', 'viewer-versions@example.com');
    seedViewerBrowserArtifact($team, $owner, ['can_change_sharing' => true]);

    /** @var FakeArtifactDirectory $directory */
    $directory = app(ArtifactDirectory::class);
    $directory->seedVersions(VIEWER_BROWSER_ARTIFACT_ID, [
        ['version' => 3, 'created_at' => now()->subDay()->toIso8601String(), 'current' => true],
        ['version' => 2, 'created_at' => now()->subDays(2)->toIso8601String(), 'current' => false],
        ['version' => 1, 'created_at' => now()->subDays(3)->toIso8601String(), 'current' => false],
    ], 3);

    test()->actingAs($owner);

    // A collapsed select only renders its selected option, so the options are
    // asserted directly rather than via their (hidden) text.
    visit(viewerBrowserArtifactUrl().'?version=2')
        ->assertNoJavaScriptErrors()
        ->assertVisible('@version-picker')
        ->assertScript("document.querySelector('[data-testid=\"version-picker\"]').value", '2')
        ->assertScript("document.querySelector('[data-testid=\"version-picker\"]').options.length", 3)
        // Newest first, so the current version is the first option.
        ->assertScript("document.querySelector('[data-testid=\"version-picker\"]').options[0].text", 'Version 3 (current)');
});

test('the_header_wraps_at_375px_with_no_horizontal_scroll', function () {
    configureViewerBrowserLinks();

    [$team, $owner] = viewerBrowserOwner('Viewer Phone', 'viewer-phone@example.com');
    seedViewerBrowserArtifact($team, $owner, [
        'can_change_sharing' => true,
        'title' => 'An exceptionally long artifact title that must wrap rather than push the header wider than the phone screen',
    ]);
    test()->actingAs($owner);

    visit(viewerBrowserArtifactUrl())
        ->resize(375, 812)
        ->assertNoJavaScriptErrors()
        ->assertSee('An exceptionally long artifact title')
        ->assertVisible('@share-control')
        ->assertScript('document.documentElement.scrollWidth <= window.innerWidth');
});
