<?php

use App\Actions\Teams\CreateTeam;
use App\Contracts\ArtifactDirectory;
use App\Contracts\PublicArtifactSource;
use App\Models\Team;
use App\Models\User;
use App\Services\Artifacts\FakeArtifactDirectory;
use App\Services\Artifacts\FakePublicArtifactSource;
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
        ->assertDisabled('@share-option-public')
        ->assertVisible('@share-close')
        ->click('@share-close')
        ->assertMissing('@share-option-public');
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

test('the_artifact_fills_the_page_and_the_details_panel_slides_in_beside_it', function () {
    configureViewerBrowserLinks();

    [$team, $owner] = viewerBrowserOwner('Viewer Panel', 'viewer-panel@example.com');
    seedViewerBrowserArtifact($team, $owner, ['can_change_sharing' => true]);
    test()->actingAs($owner);

    $frameRect = "document.querySelector('[data-testid=\"artifact-frame\"]').getBoundingClientRect()";

    $page = visit(viewerBrowserArtifactUrl())
        ->resize(1440, 900)
        ->assertNoJavaScriptErrors()
        ->assertMissing('@artifact-panel')
        // The frame spans the viewport width and sits under a thin header.
        ->assertScript("{$frameRect}.width === window.innerWidth")
        ->assertScript("{$frameRect}.top <= 64");

    $page->click('@artifact-panel-toggle')
        ->assertVisible('@artifact-panel')
        ->assertSee('Comments')
        ->assertScript("{$frameRect}.width < window.innerWidth");
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

test('a public artifact renders the thin header with no share or download and a quiet sign in to edit', function () {
    configureViewerBrowserLinks();

    /** @var FakePublicArtifactSource $public */
    $public = app(PublicArtifactSource::class);
    $public->seed(VIEWER_BROWSER_ARTIFACT_ID, 'viewer-public-org', [
        'title' => 'A public browser report',
        'description' => 'Anyone with the link.',
        'version' => 1,
        'version_count' => 1,
        'edit_access' => 'edit',
    ]);

    $page = visit(viewerBrowserArtifactUrl())
        ->assertNoJavaScriptErrors()
        ->assertSee('A public browser report')
        ->assertMissing('@share-control')
        ->assertMissing('@download-artifact')
        ->assertVisible('@open-artifact')
        ->assertVisible('@sign-in-to-edit')
        ->assertVisible('@artifact-frame');

    // A public artifact is framed untokened on its isolated origin.
    expect($page->attribute('@artifact-frame', 'src'))
        ->toContain('viewer-public-org--'.VIEWER_BROWSER_ARTIFACT_ID.'.localhost/p/'.VIEWER_BROWSER_ARTIFACT_ID.'/')
        ->not->toContain('token');

    visit(viewerBrowserArtifactUrl())
        ->resize(375, 812)
        ->assertNoJavaScriptErrors()
        ->assertScript('document.documentElement.scrollWidth <= window.innerWidth');
});

test('a_pinned_version_shows_a_newer_version_banner_after_a_publish', function () {
    configureViewerBrowserLinks();

    [$team, $owner] = viewerBrowserOwner('Viewer Freshness', 'viewer-freshness@example.com');
    seedViewerBrowserArtifact($team, $owner);

    /** @var FakeArtifactDirectory $directory */
    $directory = app(ArtifactDirectory::class);
    $directory->seedVersions(VIEWER_BROWSER_ARTIFACT_ID, [
        ['version' => 1, 'created_at' => now()->subDay()->toIso8601String(), 'current' => false],
    ], 1);
    test()->actingAs($owner);

    $page = visit(viewerBrowserArtifactUrl().'/v/1')
        ->assertNoJavaScriptErrors()
        ->assertVisible('@artifact-frame')
        ->assertMissing('@newer-version-banner');

    // The publish lands after the page rendered; the focus poll sees the new
    // version and offers the banner without yanking the pinned reader there.
    $directory->seedVersions(VIEWER_BROWSER_ARTIFACT_ID, [
        ['version' => 2, 'created_at' => now()->toIso8601String(), 'current' => true],
        ['version' => 1, 'created_at' => now()->subDay()->toIso8601String(), 'current' => false],
    ], 2);

    $page->script('window.dispatchEvent(new Event("focus"))');

    $page->waitForText('A newer version is available')
        ->assertVisible('@newer-version-banner')
        ->assertVisible('@artifact-frame')
        ->assertNoJavaScriptErrors();
});

test('the_live_viewer_reloads_and_keeps_polling_after_a_publish', function () {
    configureViewerBrowserLinks();

    [$team, $owner] = viewerBrowserOwner('Viewer Reload', 'viewer-reload@example.com');
    seedViewerBrowserArtifact($team, $owner);
    test()->actingAs($owner);

    $page = visit(viewerBrowserArtifactUrl())
        ->assertNoJavaScriptErrors()
        ->assertSee('Version 1')
        ->assertVisible('@artifact-frame');

    // The publish lands after the page rendered. The focus poll reloads
    // through the server, and the reloaded page keeps polling: a second
    // publish surfaces too, which a stuck stop-flag would swallow.
    /** @var FakeArtifactDirectory $directory */
    $directory = app(ArtifactDirectory::class);
    $directory->seedVersions(VIEWER_BROWSER_ARTIFACT_ID, [
        ['version' => 2, 'created_at' => now()->toIso8601String(), 'current' => true],
        ['version' => 1, 'created_at' => now()->subDay()->toIso8601String(), 'current' => false],
    ], 2);

    $page->script('window.dispatchEvent(new Event("focus"))');

    $page->waitForText('Version 2 of 2')
        ->assertVisible('@artifact-frame')
        ->assertNoJavaScriptErrors();

    $directory->seedVersions(VIEWER_BROWSER_ARTIFACT_ID, [
        ['version' => 3, 'created_at' => now()->toIso8601String(), 'current' => true],
        ['version' => 2, 'created_at' => now()->subHour()->toIso8601String(), 'current' => false],
        ['version' => 1, 'created_at' => now()->subDay()->toIso8601String(), 'current' => false],
    ], 3);

    $page->script('window.dispatchEvent(new Event("focus"))');

    $page->waitForText('Version 3 of 3')
        ->assertVisible('@artifact-frame')
        ->assertNoJavaScriptErrors();
});

test('a_revoked_artifact_unmounts_the_frame_after_a_poll', function () {
    configureViewerBrowserLinks();

    [$team, $owner] = viewerBrowserOwner('Viewer Revoked', 'viewer-revoked@example.com');
    seedViewerBrowserArtifact($team, $owner);
    test()->actingAs($owner);

    $page = visit(viewerBrowserArtifactUrl())
        ->assertNoJavaScriptErrors()
        ->assertVisible('@artifact-frame')
        ->assertMissing('@artifact-revoked');

    // The first focus poll marks the viewer known; the revoke lands next, so
    // the following poll reports revoked and the frame unmounts in place.
    $page->script('window.dispatchEvent(new Event("focus"))');
    $page->wait(0.5);

    /** @var FakeArtifactDirectory $directory */
    $directory = app(ArtifactDirectory::class);
    $directory->revokeArtifact($team->slug, VIEWER_BROWSER_ARTIFACT_ID);

    $page->script('window.dispatchEvent(new Event("focus"))');

    $page->waitForText('no longer shared')
        ->assertVisible('@artifact-revoked')
        ->assertMissing('@artifact-frame')
        ->assertNoJavaScriptErrors();
});
