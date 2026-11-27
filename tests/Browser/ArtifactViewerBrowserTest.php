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
        ->click('@share-option-private')
        ->assertChecked('@share-option-private')
        ->click('@edit-option-edit')
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
        ->click('@share-option-public')
        ->assertChecked('@share-option-public');

    expect(app(ArtifactDirectory::class)->getArtifact($team->slug, VIEWER_BROWSER_ARTIFACT_ID)['sharing'])->toBe('public');

    $page->click('@share-option-team')
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

/**
 * RUB-628: an artifact-to-artifact link followed inside the sandboxed frame
 * must navigate the top window to the next viewer — one header, the next
 * title, URL `/a/B` — with Back returning to A. The frame's shim posts its
 * URL to the parent; the parent only honors a message from its own frame,
 * on its own origin, naming an http(s) URL on its own origin.
 */
test('an_artifact_link_navigates_the_top_window_to_the_next_artifact', function () {
    configureViewerBrowserLinks();

    [$team, $owner] = viewerBrowserOwner('Viewer Cross Link', 'viewer-cross-link@example.com');
    seedViewerBrowserArtifact($team, $owner, ['title' => 'Source artifact']);

    $targetId = 'bcd234efg5678';
    /** @var FakeArtifactDirectory $browserDirectory */
    $browserDirectory = app(ArtifactDirectory::class);
    $browserDirectory->seedArtifact([
        'id' => $targetId,
        'org_id' => $team->slug,
        'user_id' => $owner->id,
        'title' => 'Target artifact',
        'description' => 'Seeded as the link target for the cross-artifact browser test.',
        'content_hash' => md5($targetId),
        'created_at' => now()->subDay()->toIso8601String(),
        'revoked_at' => null,
        'sharing' => 'team',
        'owner_user_id' => (string) $owner->id,
        'provenance' => ['agent' => 'cursor', 'repo_url' => null, 'commit_sha' => null],
    ]);

    test()->actingAs($owner);

    $page = visit(viewerBrowserArtifactUrl())
        ->assertNoJavaScriptErrors()
        ->assertSee('Source artifact')
        ->assertPathIs('/a/'.VIEWER_BROWSER_ARTIFACT_ID)
        ->assertVisible('@artifact-frame');

    // The shim's postMessage, delivered with the frame as its source: the
    // parent navigates the top window instead of nesting a second viewer.
    // The URL is built from the live origin so the same-origin check sees
    // the address the shim would actually post (its own location.href).
    $page->script(
        "window.dispatchEvent(new MessageEvent('message', {origin: window.location.origin, ".
        "source: document.querySelector('[data-testid=\"artifact-frame\"]').contentWindow, ".
        "data: {type: 'artfct:navigate', url: window.location.origin + '/a/{$targetId}'}}))"
    );

    $page->waitForText('Target artifact')
        ->assertPathIs('/a/'.$targetId)
        ->assertSee('Target artifact')
        ->assertScript("document.querySelectorAll('header').length", 1)
        ->assertVisible('@artifact-frame')
        ->assertNoJavaScriptErrors();

    $page->back()
        ->assertPathIs('/a/'.VIEWER_BROWSER_ARTIFACT_ID)
        ->assertSee('Source artifact')
        ->assertVisible('@artifact-frame')
        ->assertNoJavaScriptErrors();
});

/**
 * RUB-628 review-6092991795 finding 1: Back from B must not bounce back to B.
 * A real frame navigation to the shim creates the joint-history middle entry
 * [A, A(shim=B), B]; Back reloads A with the frame restored to the shim URL,
 * which re-posts. The parent resets the restored frame instead of navigating
 * the top window again. The signal is the shim's own navigation type (`via`):
 * a restored shim reports back_forward. The first half is a real link click
 * inside the frame; the restored shim post is simulated after a real Back
 * (which the harness serves with a real back_forward navigation type) because
 * the test server answers the restored frame with a clean URL.
 */
test('a_real_frame_navigation_back_from_the_next_artifact_stays_on_the_source', function () {
    configureViewerBrowserLinks();

    [$team, $owner] = viewerBrowserOwner('Viewer Real Back', 'viewer-real-back@example.com');
    seedViewerBrowserArtifact($team, $owner, ['title' => 'Source artifact']);

    $targetId = 'cde345fga6789';
    /** @var FakeArtifactDirectory $realBackDirectory */
    $realBackDirectory = app(ArtifactDirectory::class);
    $realBackDirectory->seedArtifact([
        'id' => $targetId,
        'org_id' => $team->slug,
        'user_id' => $owner->id,
        'title' => 'Target artifact',
        'description' => 'Seeded as the real-navigation link target.',
        'content_hash' => md5($targetId),
        'created_at' => now()->subDay()->toIso8601String(),
        'revoked_at' => null,
        'sharing' => 'team',
        'owner_user_id' => (string) $owner->id,
        'provenance' => ['agent' => 'cursor', 'repo_url' => null, 'commit_sha' => null],
    ]);

    test()->actingAs($owner);

    $page = visit(viewerBrowserArtifactUrl())
        ->assertNoJavaScriptErrors()
        ->assertSee('Source artifact')
        ->assertPathIs('/a/'.VIEWER_BROWSER_ARTIFACT_ID)
        ->assertVisible('@artifact-frame');

    // A real link click inside the frame to the next viewer URL: the server
    // answers with the shim (Sec-Fetch-Dest: iframe), which posts to the
    // parent, which navigates the top window. The frame is first replaced
    // with a same-origin blank page (replace, not a new history entry), a
    // link is written into it without a history entry, and the link is
    // clicked for real — so the only new joint-history entry is the shim
    // navigation itself, exactly like an artifact link.
    $page->script(
        "const f = document.querySelector('[data-testid=\"artifact-frame\"]'); ".
        "f.contentWindow.location.replace('about:blank')",
    );

    $page->wait(0.5)->script(
        "const f = document.querySelector('[data-testid=\"artifact-frame\"]'); ".
        'f.contentDocument.open(); '.
        "f.contentDocument.write('<a href=\"/a/{$targetId}\" id=\"real-go\">go</a>'); ".
        'f.contentDocument.close(); '.
        "f.contentDocument.querySelector('#real-go').click()",
    );

    $page->waitForText('Target artifact')
        ->assertPathIs('/a/'.$targetId)
        ->assertSee('Target artifact')
        ->assertScript("document.querySelectorAll('header').length", 1)
        ->assertVisible('@artifact-frame')
        ->assertNoJavaScriptErrors();

    // A real Back: the reloaded A reports a back_forward navigation type.
    // The restored shim would re-post B here; simulate that post with the
    // frame as its source and the shim's own back_forward signal. Without the
    // guard the parent bounces straight back to B; with it the frame is reset
    // and the top window stays on A.
    $page->back()
        ->wait(1)
        ->assertPathIs('/a/'.VIEWER_BROWSER_ARTIFACT_ID)
        ->assertScript("(performance.getEntriesByType('navigation')[0]||{}).type", 'back_forward')
        ->assertSee('Source artifact')
        ->assertVisible('@artifact-frame');

    $page->script(
        "window.dispatchEvent(new MessageEvent('message', {origin: window.location.origin, ".
        "source: document.querySelector('[data-testid=\"artifact-frame\"]').contentWindow, ".
        "data: {type: 'artfct:navigate', url: window.location.origin + '/a/{$targetId}', via: 'back_forward'}}))"
    );

    $page->wait(1)
        ->assertPathIs('/a/'.VIEWER_BROWSER_ARTIFACT_ID)
        ->assertSee('Source artifact')
        ->assertVisible('@artifact-frame')
        ->assertNoJavaScriptErrors();
});

/**
 * RUB-628 Ada review (2026-11-27): the guard must swallow only the restored
 * shim, not every later click. A -> B -> Back -> click B again is all real
 * frame navigations except the restored post (which the harness cannot
 * serve): the restored B post (via back_forward) stays on A, then a real
 * second frame click to the same B (via navigate from the real shim)
 * navigates the top window to B again.
 */
test('a_real_frame_click_after_back_navigates_again_instead_of_being_swallowed', function () {
    configureViewerBrowserLinks();

    [$team, $owner] = viewerBrowserOwner('Viewer Back Click Again', 'viewer-back-again@example.com');
    seedViewerBrowserArtifact($team, $owner, ['title' => 'Source artifact']);

    $targetId = 'def456ghi7890';
    /** @var FakeArtifactDirectory $backAgainDirectory */
    $backAgainDirectory = app(ArtifactDirectory::class);
    $backAgainDirectory->seedArtifact([
        'id' => $targetId,
        'org_id' => $team->slug,
        'user_id' => $owner->id,
        'title' => 'Target artifact',
        'description' => 'Seeded to prove a post-Back click still navigates.',
        'content_hash' => md5($targetId),
        'created_at' => now()->subDay()->toIso8601String(),
        'revoked_at' => null,
        'sharing' => 'team',
        'owner_user_id' => (string) $owner->id,
        'provenance' => ['agent' => 'cursor', 'repo_url' => null, 'commit_sha' => null],
    ]);

    test()->actingAs($owner);

    $page = visit(viewerBrowserArtifactUrl())
        ->assertNoJavaScriptErrors()
        ->assertSee('Source artifact')
        ->assertPathIs('/a/'.VIEWER_BROWSER_ARTIFACT_ID)
        ->assertVisible('@artifact-frame');

    // First real artifact link: blank the frame (replace, no history entry),
    // write a link without a history entry, click it for real. The server
    // answers with the shim, which posts and the parent top-navigates to B.
    $page->script(
        "const f = document.querySelector('[data-testid=\"artifact-frame\"]'); ".
        "f.contentWindow.location.replace('about:blank')",
    );

    $page->wait(0.5)->script(
        "const f = document.querySelector('[data-testid=\"artifact-frame\"]'); ".
        'f.contentDocument.open(); '.
        "f.contentDocument.write('<a href=\"/a/{$targetId}\" id=\"real-go\">go</a>'); ".
        'f.contentDocument.close(); '.
        "f.contentDocument.querySelector('#real-go').click()",
    );

    $page->waitForText('Target artifact')
        ->assertPathIs('/a/'.$targetId)
        ->assertSee('Target artifact')
        ->assertScript("document.querySelectorAll('header').length", 1)
        ->assertVisible('@artifact-frame')
        ->assertNoJavaScriptErrors();

    // Real Back: A reloads with a back_forward type and the frame restored
    // to the shim, which would re-post B.
    $page->back()
        ->wait(1)
        ->assertPathIs('/a/'.VIEWER_BROWSER_ARTIFACT_ID)
        ->assertScript("(performance.getEntriesByType('navigation')[0]||{}).type", 'back_forward')
        ->assertSee('Source artifact')
        ->assertVisible('@artifact-frame');

    // The restored shim post (shim-side back_forward): swallowed, the top
    // window stays on A.
    $page->script(
        "window.dispatchEvent(new MessageEvent('message', {origin: window.location.origin, ".
        "source: document.querySelector('[data-testid=\"artifact-frame\"]').contentWindow, ".
        "data: {type: 'artfct:navigate', url: window.location.origin + '/a/{$targetId}', via: 'back_forward'}}))"
    );

    $page->wait(1)
        ->assertPathIs('/a/'.VIEWER_BROWSER_ARTIFACT_ID)
        ->assertSee('Source artifact')
        ->assertVisible('@artifact-frame')
        ->assertNoJavaScriptErrors();

    // A second real artifact link to the same B, after the guard was
    // consumed: blank, write, click for real. Without the single-use fix the
    // sticky back_forward check would swallow this too; with it the top
    // window navigates to B again.
    $page->script(
        "const f = document.querySelector('[data-testid=\"artifact-frame\"]'); ".
        "f.contentWindow.location.replace('about:blank')",
    );

    $page->wait(0.5)->script(
        "const f = document.querySelector('[data-testid=\"artifact-frame\"]'); ".
        'f.contentDocument.open(); '.
        "f.contentDocument.write('<a href=\"/a/{$targetId}\" id=\"real-go-again\">go again</a>'); ".
        'f.contentDocument.close(); '.
        "f.contentDocument.querySelector('#real-go-again').click()",
    );

    $page->waitForText('Target artifact')
        ->assertPathIs('/a/'.$targetId)
        ->assertSee('Target artifact')
        ->assertScript("document.querySelectorAll('header').length", 1)
        ->assertVisible('@artifact-frame')
        ->assertNoJavaScriptErrors();
});

/**
 * RUB-628 Ada review 2026-11-27 round 2: a clean-frame Back must not swallow
 * the very first genuine click. The harness restores A with a clean frame
 * (no shim, so no restored post arrives) while the top still reports
 * back_forward. A viewer-side one-shot guard would consume that first real
 * click; the shim-side `via` signal does not: the real shim posts
 * via=navigate and the top window navigates to B.
 */
test('a_first_real_frame_click_after_a_clean_frame_back_navigates_without_any_injected_message', function () {
    configureViewerBrowserLinks();

    [$team, $owner] = viewerBrowserOwner('Viewer Clean Back', 'viewer-clean-back@example.com');
    seedViewerBrowserArtifact($team, $owner, ['title' => 'Source artifact']);

    $targetId = 'efg567hij8901';
    /** @var FakeArtifactDirectory $cleanBackDirectory */
    $cleanBackDirectory = app(ArtifactDirectory::class);
    $cleanBackDirectory->seedArtifact([
        'id' => $targetId,
        'org_id' => $team->slug,
        'user_id' => $owner->id,
        'title' => 'Target artifact',
        'description' => 'Seeded to prove a clean-frame Back keeps the first click.',
        'content_hash' => md5($targetId),
        'created_at' => now()->subDay()->toIso8601String(),
        'revoked_at' => null,
        'sharing' => 'team',
        'owner_user_id' => (string) $owner->id,
        'provenance' => ['agent' => 'cursor', 'repo_url' => null, 'commit_sha' => null],
    ]);

    test()->actingAs($owner);

    $page = visit(viewerBrowserArtifactUrl())
        ->assertNoJavaScriptErrors()
        ->assertSee('Source artifact')
        ->assertPathIs('/a/'.VIEWER_BROWSER_ARTIFACT_ID)
        ->assertVisible('@artifact-frame');

    // First real artifact link: blank the frame (replace, no history entry),
    // write a link without a history entry, click it for real. The server
    // answers with the shim (via=navigate), which posts and the parent
    // top-navigates to B.
    $page->script(
        "const f = document.querySelector('[data-testid=\"artifact-frame\"]'); ".
        "f.contentWindow.location.replace('about:blank')",
    );

    $page->wait(0.5)->script(
        "const f = document.querySelector('[data-testid=\"artifact-frame\"]'); ".
        'f.contentDocument.open(); '.
        "f.contentDocument.write('<a href=\"/a/{$targetId}\" id=\"real-go\">go</a>'); ".
        'f.contentDocument.close(); '.
        "f.contentDocument.querySelector('#real-go').click()",
    );

    $page->waitForText('Target artifact')
        ->assertPathIs('/a/'.$targetId)
        ->assertSee('Target artifact')
        ->assertScript("document.querySelectorAll('header').length", 1)
        ->assertVisible('@artifact-frame')
        ->assertNoJavaScriptErrors();

    // Real Back: the harness restores A with a clean frame (src still carries
    // the token), so no restored shim post arrives — and none is injected
    // here. The top still reports back_forward for its own navigation. Wait
    // past any history-restoration activity so the frame settles before the
    // first genuine click touches it (a restored shim would re-post and the
    // parent would reset the frame; racing that reset with the blank below
    // flakes the write/click sequence).
    $page->back()
        ->wait(3)
        ->assertPathIs('/a/'.VIEWER_BROWSER_ARTIFACT_ID)
        ->assertScript("(performance.getEntriesByType('navigation')[0]||{}).type", 'back_forward')
        ->assertSee('Source artifact')
        ->assertVisible('@artifact-frame')
        ->assertScript("document.querySelector('[data-testid=\"artifact-frame\"]').getAttribute('src').includes('token=')");

    $page->wait(1);

    // The very first genuine click after that Back, with no injected message
    // before it: blank, write, click for real. The real shim posts
    // via=navigate, so the top window must navigate to B. A viewer-side
    // one-shot back_forward guard would swallow this click and stay on A.
    $page->script(
        "const f = document.querySelector('[data-testid=\"artifact-frame\"]'); ".
        "f.contentWindow.location.replace('about:blank')",
    );

    $page->wait(0.5)->script(
        "const f = document.querySelector('[data-testid=\"artifact-frame\"]'); ".
        'f.contentDocument.open(); '.
        "f.contentDocument.write('<a href=\"/a/{$targetId}\" id=\"real-go-clean\">go clean</a>'); ".
        'f.contentDocument.close(); '.
        "f.contentDocument.querySelector('#real-go-clean').click()",
    );

    $page->waitForText('Target artifact')
        ->assertPathIs('/a/'.$targetId)
        ->assertSee('Target artifact')
        ->assertScript("document.querySelectorAll('header').length", 1)
        ->assertVisible('@artifact-frame')
        ->assertNoJavaScriptErrors();
});

/**
 * RUB-628 review round 4 finding 1: in Firefox, Back cannot leave a viewer
 * after a same-artifact fragment link. Every restored same-path shim must
 * reset the frame — never assign and reload — or each Back reloads back to
 * the fragment and the dashboard is unreachable. No message is injected
 * here: the run clicks a real frame link, follows the real reload, and
 * walks back with real Backs. The frame navigation creates a joint-history
 * entry of its own, so reaching the dashboard takes three Backs
 * (fragment view, plain view, dashboard); what matters is that every Back
 * lands where it should with no reload loop and the frame ends clean.
 */
test('a_real_frame_navigation_to_the_same_artifact_fragment_then_back_to_the_dashboard', function () {
    configureViewerBrowserLinks();

    [$team, $owner] = viewerBrowserOwner('Viewer Fragment Back', 'viewer-fragment-back@example.com');
    seedViewerBrowserArtifact($team, $owner, ['title' => 'Fragment artifact']);
    test()->actingAs($owner);

    // Dashboard first so real Backs have somewhere to go.
    $page = visit('/dashboard');
    $page->script("window.location.assign('".viewerBrowserArtifactUrl()."')");

    $page->wait(1)
        ->assertNoJavaScriptErrors()
        ->assertSee('Fragment artifact')
        ->assertPathIs('/a/'.VIEWER_BROWSER_ARTIFACT_ID)
        ->assertVisible('@artifact-frame');

    // A real navigation inside the frame to the same viewer with a fragment:
    // the server answers with the shim, which posts, and the parent assigns
    // and reloads so the fragment reaches the artifact.
    $page->script(
        "document.querySelector('[data-testid=\"artifact-frame\"]').contentWindow.location.href = ".
        "window.location.origin + '/a/".VIEWER_BROWSER_ARTIFACT_ID."#option-b'",
    );

    $page->wait(2)
        ->assertNoJavaScriptErrors()
        ->assertPathIs('/a/'.VIEWER_BROWSER_ARTIFACT_ID)
        ->assertScript('location.hash', '#option-b')
        ->assertSee('Fragment artifact')
        ->assertVisible('@artifact-frame');

    // No reload loop behind the assertions above: the reloaded document stays
    // put once loaded. A same-path loop would replace the document, so the
    // time origin would change.
    $settledLoad = $page->script('performance.timeOrigin');
    $page->wait(1.5);
    expect($page->script('performance.timeOrigin'))->toBe($settledLoad);

    // First real Back: the frame is restored clean (a reload resets it to
    // its src), so Back lands on A with no hash and the same document — a
    // restored shim that re-posted would assign and reload instead, which
    // the time-origin check below and the hash check would catch. Each Back
    // gets its own wait: traversals commit asynchronously, so chained Backs
    // would race each other.
    $page->back()->wait(1);

    $page->assertNoJavaScriptErrors()
        ->assertPathIs('/a/'.VIEWER_BROWSER_ARTIFACT_ID)
        ->assertScript('location.hash', '')
        ->assertSee('Fragment artifact')
        ->assertVisible('@artifact-frame')
        // The frame holds the clean artifact URL, and the top document never
        // reloaded (no loop).
        ->assertScript("document.querySelector('[data-testid=\"artifact-frame\"]').src.includes('.localhost')")
        ->assertScript('performance.timeOrigin', $settledLoad);

    // Second real Back: the frame navigation created a joint-history entry
    // of its own, so one more Back lands on the plain viewer again.
    $page->back()->wait(1);

    $page->assertNoJavaScriptErrors()
        ->assertPathIs('/a/'.VIEWER_BROWSER_ARTIFACT_ID);

    // Third real Back reaches the dashboard: Back is not trapped. (A new
    // document, so the time origin is not comparable here.)
    $page->back()->wait(1);

    $page->assertNoJavaScriptErrors()
        ->assertPathIs('/');
});

/**
 * RUB-628 review round 4 finding 2: a shim that posts before hydration is
 * never heard, so without a retry the frame would sit on "Open this
 * artifact" with nothing left to recover it. The shim re-announces until
 * the parent acknowledges. This models the SSR order for real: a same-origin
 * page frames the viewer URL (a real shim, real posts), counts them with a
 * passive observer that never acknowledges, then registers the acknowledging
 * listener late — recovery without any injected message.
 */
test('a_shim_without_an_answering_parent_reannounces_until_acknowledged', function () {
    configureViewerBrowserLinks();

    [$team, $owner] = viewerBrowserOwner('Viewer Shim Retry', 'viewer-shim-retry@example.com');
    seedViewerBrowserArtifact($team, $owner, ['title' => 'Retry artifact']);
    test()->actingAs($owner);

    $probeId = 'f6g7h8i9j0k12';

    $page = visit('/dashboard')->assertNoJavaScriptErrors();

    // A passive observer only: it records real shim posts, it never sends
    // one and never acknowledges.
    $page->script(
        'window.__shimProbePosts = []; '.
        'window.addEventListener("message", function (e) { '.
        'if (e && e.data && e.data.type === "artfct:navigate") window.__shimProbePosts.push(e.data.via); '.
        '});',
    );

    // A real subframe navigation to a viewer URL: the server answers with
    // the shim (Sec-Fetch-Dest: iframe), which posts for real.
    $page->script(
        "const f = document.createElement('iframe'); ".
        "f.setAttribute('data-testid', 'shim-probe'); ".
        "f.src = '/a/{$probeId}'; ".
        'document.body.appendChild(f);',
    );

    // While nothing answers, the shim re-announces (500ms cadence).
    $page->wait(2);

    $unanswered = json_decode((string) $page->script('JSON.stringify(window.__shimProbePosts)'), true);

    expect($unanswered)->toBeArray()
        ->and(count($unanswered))->toBeGreaterThanOrEqual(2)
        ->and($unanswered[0])->toBe('navigate');

    // The acknowledging listener registers late, as hydration does: the
    // next announcement is answered and the shim goes quiet.
    $page->script(
        'window.addEventListener("message", function (e) { '.
        'if (e && e.data && e.data.type === "artfct:navigate" && e.source) { '.
        'try { e.source.postMessage({type: "artfct:ack"}, e.origin); } catch (e2) {} '.
        '} '.
        '});',
    );

    $page->wait(2.5);
    $afterAck = json_decode((string) $page->script('JSON.stringify(window.__shimProbePosts)'), true);

    $page->wait(2);
    $settled = json_decode((string) $page->script('JSON.stringify(window.__shimProbePosts)'), true);

    // The ack stopped the retries: nothing new arrived, and the burst
    // stayed within the shim's cap instead of spamming forever.
    expect($settled)->toBe($afterAck)
        ->and(count($settled))->toBeLessThanOrEqual(20);

    $page->assertNoJavaScriptErrors();
});

/**
 * RUB-628 review-6092991795 finding 2: a same-artifact fragment link must not
 * leave the shim stuck in the frame. /a/A -> /a/A#sec differs only by its
 * hash, so assign() alone never reloads; the parent forces a server round
 * trip and the fragment effect then rebuilds the frame with the hash.
 */
test('a_real_frame_navigation_to_the_same_artifact_fragment_reloads_with_the_fragment', function () {
    configureViewerBrowserLinks();

    [$team, $owner] = viewerBrowserOwner('Viewer Real Fragment', 'viewer-real-fragment@example.com');
    seedViewerBrowserArtifact($team, $owner, ['title' => 'Source artifact']);
    test()->actingAs($owner);

    $frameSrc = "document.querySelector('[data-testid=\"artifact-frame\"]').getAttribute('src')";

    $page = visit(viewerBrowserArtifactUrl())
        ->assertNoJavaScriptErrors()
        ->assertSee('Source artifact')
        ->assertPathIs('/a/'.VIEWER_BROWSER_ARTIFACT_ID)
        ->assertVisible('@artifact-frame');

    // A real navigation inside the frame to the same viewer with a fragment:
    // the server answers the frame request with the shim (Sec-Fetch-Dest:
    // iframe), which posts the fragment URL back to the parent. Setting the
    // child location from the parent is allowed cross-origin; the frame's own
    // request still carries the iframe destination, so the shim branch runs
    // for real.
    $page->script(
        "document.querySelector('[data-testid=\"artifact-frame\"]').contentWindow.location.href = ".
        "window.location.origin + '/a/".VIEWER_BROWSER_ARTIFACT_ID."#option-b'",
    );

    $page->wait(2)
        ->assertNoJavaScriptErrors()
        ->assertPathIs('/a/'.VIEWER_BROWSER_ARTIFACT_ID)
        ->assertScript('location.hash', '#option-b')
        ->assertSee('Source artifact')
        ->assertScript("document.querySelectorAll('header').length", 1)
        ->assertVisible('@artifact-frame')
        // The reloaded viewer forwards the fragment to the frame src (with
        // the token ahead of it); a stuck shim would leave the src without
        // the hash.
        ->assertScript("{$frameSrc}.endsWith('#option-b')")
        ->assertScript("{$frameSrc}.includes('token=')")
        ->assertNoJavaScriptErrors();
});
