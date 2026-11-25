<?php

use App\Actions\Teams\CreateTeam;
use App\Contracts\ArtifactDirectory;
use App\Contracts\PublicArtifactSource;
use App\Enums\ArtifactType;
use App\Http\Controllers\ArtifactViewerController;
use App\Models\ArtifactComment;
use App\Models\Team;
use App\Models\User;
use App\Services\Artifacts\ArtifactTypeResolver;
use App\Services\Artifacts\FakeArtifactDirectory;
use App\Services\Artifacts\FakePublicArtifactSource;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;

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

test('an_owner_can_change_sharing_and_edit_access_from_the_viewer', function () {
    configureViewerBrowserLinks();
    [$team, $owner] = viewerBrowserOwner('Viewer Sharing', 'viewer-sharing@example.com');
    seedViewerBrowserArtifact($team, $owner, ['can_change_sharing' => true]);
    test()->actingAs($owner);

    visit(viewerBrowserArtifactUrl())
        ->click('@share-control')
        ->assertChecked('@share-option-team')
        ->check('@share-option-private')
        ->assertChecked('@share-option-private')
        ->check('@edit-option-edit')
        ->assertChecked('@edit-option-edit')
        ->click('@share-close')
        ->click('@share-control')
        ->assertChecked('@share-option-private')
        ->assertChecked('@edit-option-edit')
        ->assertNoJavaScriptErrors();

    $metadata = app(ArtifactDirectory::class)->getArtifact($team->slug, VIEWER_BROWSER_ARTIFACT_ID);
    expect($metadata['sharing'])->toBe('private')
        ->and($metadata['edit_access'])->toBe('edit');
});

test('a_rejected_sharing_change_keeps_the_previous_access_level', function () {
    configureViewerBrowserLinks();
    [$team, $owner] = viewerBrowserOwner('Viewer Sharing Error', 'viewer-sharing-error@example.com');
    seedViewerBrowserArtifact($team, $owner, ['can_change_sharing' => true]);
    /** @var FakeArtifactDirectory $directory */
    $directory = app(ArtifactDirectory::class);
    $directory->failSharing(VIEWER_BROWSER_ARTIFACT_ID, 'forbidden');
    test()->actingAs($owner);

    visit(viewerBrowserArtifactUrl())
        ->click('@share-control')
        ->click('@share-option-private')
        ->assertSee('Only the owner or a team admin can change sharing.')
        ->assertChecked('@share-option-team')
        ->assertNoJavaScriptErrors();

    expect($directory->getArtifact($team->slug, VIEWER_BROWSER_ARTIFACT_ID)['sharing'])->toBe('team');
});

test('an_owner_can_switch_between_public_and_team_sharing', function () {
    configureViewerBrowserLinks();
    [$team, $owner] = viewerBrowserOwner('Viewer Public Sharing', 'viewer-public-sharing@example.com');
    seedViewerBrowserArtifact($team, $owner, ['can_change_sharing' => true]);
    test()->actingAs($owner);

    $page = visit(viewerBrowserArtifactUrl())
        ->click('@share-control')
        ->assertEnabled('@share-option-public')
        ->check('@share-option-public')
        ->assertChecked('@share-option-public');

    expect(app(ArtifactDirectory::class)->getArtifact($team->slug, VIEWER_BROWSER_ARTIFACT_ID)['sharing'])->toBe('public');

    $page->check('@share-option-team')
        ->assertChecked('@share-option-team')
        ->click('@share-close')
        ->click('@share-control')
        ->assertChecked('@share-option-team')
        ->assertNoJavaScriptErrors();

    expect(app(ArtifactDirectory::class)->getArtifact($team->slug, VIEWER_BROWSER_ARTIFACT_ID)['sharing'])->toBe('team');
});

test('copying_the_share_link_uses_the_session_aware_viewer_url_without_a_token', function (?int $version) {
    configureViewerBrowserLinks();
    [$team, $owner] = viewerBrowserOwner('Viewer Copy Link', 'viewer-copy-link@example.com');
    seedViewerBrowserArtifact($team, $owner, ['can_change_sharing' => true]);
    /** @var FakeArtifactDirectory $directory */
    $directory = app(ArtifactDirectory::class);
    $directory->seedVersions(VIEWER_BROWSER_ARTIFACT_ID, [
        ['version' => 2, 'created_at' => now()->toIso8601String(), 'current' => true],
        ['version' => 1, 'created_at' => now()->subDay()->toIso8601String(), 'current' => false],
    ], 2);
    test()->actingAs($owner);

    $page = visit(viewerBrowserArtifactUrl().($version === null ? '' : '/v/'.$version))
        ->click('@share-control');
    $page->script("Object.defineProperty(navigator, 'clipboard', { configurable: true, value: { writeText: async (text) => { window.copiedArtifactLink = text; } } })");
    $page->click('@copy-link')->assertSee('Link copied.')->assertNoJavaScriptErrors();

    $expected = $version === null
        ? route('artifacts.show', ['artifactId' => VIEWER_BROWSER_ARTIFACT_ID])
        : route('artifacts.version', ['artifactId' => VIEWER_BROWSER_ARTIFACT_ID, 'version' => $version]);
    expect($page->script('window.copiedArtifactLink'))->toBe($expected)
        ->not->toContain('token=');
})->with(['latest' => [null], 'pinned version' => [1]]);

test('a_viewer_can_add_resolve_and_reopen_an_artifact_comment', function () {
    configureViewerBrowserLinks();
    [$team, $owner] = viewerBrowserOwner('Viewer Comments', 'viewer-comments@example.com');
    seedViewerBrowserArtifact($team, $owner);
    test()->actingAs($owner);

    $page = visit(viewerBrowserArtifactUrl())
        ->click('@artifact-panel-toggle')
        ->assertSee('No comments yet.')
        ->type('textarea[aria-label="Add a comment"]', 'Please review the chart.')
        ->click('Comment')
        ->assertSee('Please review the chart.')
        ->click('Reply')
        ->type('textarea[aria-label="Reply"]', 'I will take a look.')
        ->click('form:has(textarea[aria-label="Reply"]) button[type="submit"]')
        ->assertSee('I will take a look.')
        ->click('Resolve')
        ->assertSee('Reopen');

    $comment = ArtifactComment::query()->where('team_id', $team->id)->where('body', 'Please review the chart.')->sole();
    expect($comment->resolved_at)->not->toBeNull();
    expect(ArtifactComment::query()->where('parent_id', $comment->id)->where('body', 'I will take a look.')->exists())->toBeTrue();

    $page->click('Reopen')
        ->assertSee('Resolve')
        ->assertNoJavaScriptErrors();

    expect($comment->fresh()->resolved_at)->toBeNull();
});

test('a_failed_comment_keeps_its_draft_and_can_be_retried', function () {
    configureViewerBrowserLinks();
    [$team, $owner] = viewerBrowserOwner('Viewer Comment Retry', 'viewer-comment-retry@example.com');
    seedViewerBrowserArtifact($team, $owner);
    test()->actingAs($owner);

    $attempts = 0;
    Route::post('/a/{artifactId}/comments', function (Request $request, string $artifactId) use (&$attempts) {
        if (++$attempts === 1) {
            throw ValidationException::withMessages(['body' => 'Please try again.']);
        }

        return app(ArtifactViewerController::class)->storeComment($request, $artifactId, app(ArtifactDirectory::class));
    })->middleware('web');

    visit(viewerBrowserArtifactUrl())
        ->click('@artifact-panel-toggle')
        ->type('textarea[aria-label="Add a comment"]', 'A comment worth keeping')
        ->click('Comment')
        ->assertSee('Could not post your comment. Check it and try again.')
        ->assertValue('textarea[aria-label="Add a comment"]', 'A comment worth keeping')
        ->click('Comment')
        ->assertSee('A comment worth keeping')
        ->assertValue('textarea[aria-label="Add a comment"]', '')
        ->assertNoJavaScriptErrors();

    expect($attempts)->toBe(2)
        ->and(ArtifactComment::query()->where('team_id', $team->id)->where('body', 'A comment worth keeping')->count())->toBe(1);
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

test('the_share_dialog_stays_fixed_to_the_viewport', function () {
    configureViewerBrowserLinks();

    [$team, $owner] = viewerBrowserOwner('Viewer Share Fixed', 'viewer-share-fixed@example.com');
    seedViewerBrowserArtifact($team, $owner, ['can_change_sharing' => true]);
    test()->actingAs($owner);

    $page = visit(viewerBrowserArtifactUrl())
        ->assertNoJavaScriptErrors()
        ->click('@share-control')
        ->assertVisible('@share-close');

    // A `relative` class on the dialog content silently drops `fixed` through
    // tailwind-merge, which pushes it below the fold while the overlay stays put.
    expect($page->script('getComputedStyle(document.querySelector(\'[role="dialog"]\')).position'))->toBe('fixed');
});

test('the_sharing_dialog_supports_keyboard_dismissal_and_returns_focus', function () {
    configureViewerBrowserLinks();
    [$team, $owner] = viewerBrowserOwner('Viewer Keyboard', 'viewer-keyboard@example.com');
    seedViewerBrowserArtifact($team, $owner, ['can_change_sharing' => true]);
    test()->actingAs($owner);

    $page = visit(viewerBrowserArtifactUrl())
        ->assertNoAccessibilityIssues()
        ->keys('@share-control', 'Enter')
        ->assertVisible('@share-close')
        ->assertNoAccessibilityIssues();

    $page->keys('@share-close', 'Escape')
        ->assertMissing('@share-close')
        ->assertScript('document.activeElement === document.querySelector(\'[data-testid="share-control"]\')')
        ->assertNoJavaScriptErrors();
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
        ->assertScript("document.querySelector('[data-testid=\"version-picker\"]').options[0].text", 'Version 3 (current)')
        ->select('@version-picker', '1')
        ->assertPathIs('/a/'.VIEWER_BROWSER_ARTIFACT_ID.'/v/1')
        ->assertValue('@version-picker', '1')
        ->assertNoJavaScriptErrors();
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

test('comment_bodies_with_script_and_onerror_render_escaped', function () {
    configureViewerBrowserLinks();

    [$team, $owner] = viewerBrowserOwner('Viewer Comment Escape', 'viewer-comment-escape@example.com');
    seedViewerBrowserArtifact($team, $owner, ['can_change_sharing' => true]);

    ArtifactComment::query()->create([
        'team_id' => $team->id,
        'artifact_id' => VIEWER_BROWSER_ARTIFACT_ID,
        'parent_id' => null,
        'author_user_id' => (string) $owner->id,
        'author_name' => $owner->name,
        'body' => '<script>alert(1)</script><img src=x onerror=alert(1)>',
    ]);

    test()->actingAs($owner);

    $page = visit(viewerBrowserArtifactUrl())
        ->assertNoJavaScriptErrors()
        ->click('@artifact-panel-toggle')
        ->assertVisible('@artifact-comments')
        ->assertSee('<script>alert(1)</script>')
        ->assertSee('onerror=alert(1)');

    expect($page->script('document.querySelector("[data-testid=\\"artifact-comments\\"] script")'))
        ->toBeNull();
    expect($page->script('document.querySelector("[data-testid=\\"artifact-comments\\"]").innerHTML.includes("&lt;script&gt;")'))
        ->toBeTrue();
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

test('a slide deck offers Present, which puts the artifact frame in full screen, and a document does not', function () {
    configureViewerBrowserLinks();

    [$team, $owner] = viewerBrowserOwner('Viewer Present', 'viewer-present@example.com');
    seedViewerBrowserArtifact($team, $owner, ['can_change_sharing' => true]);
    test()->actingAs($owner);

    $html = '<!doctype html><html><body><section class="slide"></section></body></html>';
    $frame = "document.querySelector('[data-testid=\"artifact-frame\"]')";

    app(ArtifactTypeResolver::class)->record($team, VIEWER_BROWSER_ARTIFACT_ID, $html, ArtifactType::SlideDeck);

    visit(viewerBrowserArtifactUrl())
        ->assertNoJavaScriptErrors()
        ->assertVisible('@present-artifact')
        ->assertScript("{$frame}.getAttribute('allow')", 'fullscreen')
        ->assertScript('document.fullscreenElement === null', true)
        ->click('@present-artifact')
        ->wait(1)
        ->assertScript("document.fullscreenElement === {$frame}", true)
        ->assertNoJavaScriptErrors();

    app(ArtifactTypeResolver::class)->record($team, VIEWER_BROWSER_ARTIFACT_ID, $html, ArtifactType::Document);

    visit(viewerBrowserArtifactUrl())
        ->assertNoJavaScriptErrors()
        ->assertMissing('@present-artifact')
        ->assertScript("{$frame}.hasAttribute('allow')", false);
});

test('the viewer shows the updated date in the reader\'s own time zone', function () {
    configureViewerBrowserLinks();

    [$team, $owner] = viewerBrowserOwner('Viewer Zone', 'viewer-zone@example.com');
    // 04:07 UTC on the 12th is still the 11th in Denver.
    seedViewerBrowserArtifact($team, $owner, ['updated_at' => '2026-11-12T04:07:29Z']);
    test()->actingAs($owner);

    visit(viewerBrowserArtifactUrl())
        ->withTimezone('America/Denver')
        ->assertSee('Updated Nov 11, 2026')
        ->assertNoJavaScriptErrors();

    visit(viewerBrowserArtifactUrl())
        ->withTimezone('UTC')
        ->assertSee('Updated Nov 12, 2026')
        ->assertNoJavaScriptErrors();
});

test('the server-rendered viewer hydrates when the reader\'s date differs from the server\'s', function () {
    configureViewerBrowserLinks();

    [$team, $owner] = viewerBrowserOwner('Viewer Hydrate', 'viewer-hydrate@example.com');
    // 20:00 UTC on the 12th is already the 13th in Auckland, so a date formatted
    // with the browser's time zone disagrees with one the server formatted.
    seedViewerBrowserArtifact($team, $owner, ['updated_at' => '2026-11-12T20:00:00Z']);
    test()->actingAs($owner);

    visit(viewerBrowserArtifactUrl())
        ->withTimezone('Pacific/Auckland')
        ->assertSee('Updated Nov 13, 2026')
        ->assertNoJavaScriptErrors();

    // The server also renders en-US, so a reader with another locale disagrees
    // with it even in the same time zone.
    visit(viewerBrowserArtifactUrl())
        ->withTimezone('UTC')
        ->withLocale('de-DE')
        ->assertSee('Updated 12.11.2026')
        ->assertNoJavaScriptErrors();
})->skip(fn () => getenv('TEST_INERTIA_SSR') !== 'true', 'Needs the Inertia SSR server (php artisan inertia:start-ssr) and TEST_INERTIA_SSR=true; without it nothing is hydrated.');

test('a_viewer_link_with_a_fragment_passes_the_fragment_to_the_artifact_frame', function () {
    configureViewerBrowserLinks();

    [$team, $owner] = viewerBrowserOwner('Viewer Fragment', 'viewer-fragment@example.com');
    seedViewerBrowserArtifact($team, $owner);
    test()->actingAs($owner);

    $frameSrc = "document.querySelector('[data-testid=\"artifact-frame\"]').getAttribute('src')";

    // `visit()` drops a URL fragment, so load the artifact with its fragment
    // from another page. The fragment is added after mount, so give the effect
    // a moment to run.
    $page = visit('/dashboard');
    $page->script("window.location.assign('".viewerBrowserArtifactUrl()."#option-b')");

    $page->wait(1)
        ->assertNoJavaScriptErrors()
        ->assertVisible('@artifact-frame')
        ->assertScript('location.hash', '#option-b')
        ->assertScript("{$frameSrc}.endsWith('#option-b')")
        // The token stays on the src, ahead of the fragment.
        ->assertScript("{$frameSrc}.includes('token=')")
        ->assertScript("{$frameSrc}.split('#').length === 2");
});

test('a_viewer_link_without_a_fragment_leaves_the_frame_src_untouched', function () {
    configureViewerBrowserLinks();

    [$team, $owner] = viewerBrowserOwner('Viewer No Fragment', 'viewer-nofragment@example.com');
    seedViewerBrowserArtifact($team, $owner);
    test()->actingAs($owner);

    $frameSrc = "document.querySelector('[data-testid=\"artifact-frame\"]').getAttribute('src')";

    visit(viewerBrowserArtifactUrl())
        ->assertNoJavaScriptErrors()
        ->assertVisible('@artifact-frame')
        ->wait(0.5)
        ->assertScript("{$frameSrc}.includes('#') === false")
        ->assertScript("{$frameSrc}.includes('token=')");
});

test('a_viewer_link_with_an_unsafe_fragment_does_not_pass_it_to_the_artifact_frame', function (string $fragment) {
    configureViewerBrowserLinks();

    [$team, $owner] = viewerBrowserOwner('Viewer Unsafe Fragment', 'viewer-unsafe@example.com');
    seedViewerBrowserArtifact($team, $owner);
    test()->actingAs($owner);

    $frameSrc = "document.querySelector('[data-testid=\"artifact-frame\"]').getAttribute('src')";

    $page = visit('/dashboard');
    $page->script("window.location.assign('".viewerBrowserArtifactUrl().$fragment."')");

    $page->wait(1)
        ->assertNoJavaScriptErrors()
        ->assertVisible('@artifact-frame')
        ->assertScript("{$frameSrc}.includes('#') === false")
        ->assertScript("{$frameSrc}.includes('token=')");
})->with([
    'encoded markup' => ['#%3Cimg%20src%3Dx%3E'],
    'script url' => ['#javascript:alert(1)'],
    'path-like' => ['#/../../secret'],
    'a colon' => ['#a:b'],
    'a second hash' => ['#a#b'],
    'a query-like part' => ['#x?y'],
    'an ampersand' => ['#a&b'],
    'a text fragment' => ['#:~:text=foo'],
    'unicode' => ['#caf%C3%A9'],
    'a bare hash' => ['#'],
    'over 128 characters' => ['#'.str_repeat('a', 129)],
]);

test('a_viewer_link_fragment_with_dots_underscores_and_hyphens_is_passed_on', function () {
    configureViewerBrowserLinks();

    [$team, $owner] = viewerBrowserOwner('Viewer Anchor Id', 'viewer-anchor@example.com');
    seedViewerBrowserArtifact($team, $owner);
    test()->actingAs($owner);

    $frameSrc = "document.querySelector('[data-testid=\"artifact-frame\"]').getAttribute('src')";

    $page = visit('/dashboard');
    $page->script("window.location.assign('".viewerBrowserArtifactUrl()."#section_1.2-b')");

    $page->wait(1)
        ->assertNoJavaScriptErrors()
        ->assertScript("{$frameSrc}.endsWith('#section_1.2-b')");
});

test('a_later_hash_change_does_not_reset_the_artifact_frame_src', function () {
    configureViewerBrowserLinks();

    [$team, $owner] = viewerBrowserOwner('Viewer Hash Change', 'viewer-hashchange@example.com');
    seedViewerBrowserArtifact($team, $owner);
    test()->actingAs($owner);

    $frameSrc = "document.querySelector('[data-testid=\"artifact-frame\"]').getAttribute('src')";

    $page = visit('/dashboard');
    $page->script("window.location.assign('".viewerBrowserArtifactUrl()."#option-b')");

    // The frame src is read once: changing the viewer's hash afterwards leaves
    // it alone, so an expired ?token= is never reloaded by a hash change.
    $page->wait(1)
        ->assertScript("{$frameSrc}.endsWith('#option-b')");

    $page->script("window.location.hash = '#option-c'");

    $page->wait(1)
        ->assertNoJavaScriptErrors()
        ->assertScript('location.hash', '#option-c')
        ->assertScript("{$frameSrc}.endsWith('#option-b')");
});
