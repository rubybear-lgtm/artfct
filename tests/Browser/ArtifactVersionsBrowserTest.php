<?php

use App\Actions\Teams\CreateTeam;
use App\Contracts\ArtifactDirectory;
use App\Models\Team;
use App\Models\User;
use App\Services\Artifacts\FakeArtifactDirectory;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * The console's version-history surface, exercised in a browser.
 *
 * The link and the rows are client-rendered from Inertia props, so only a
 * browser can prove the DOM: the console list shows History for a live
 * artifact and not for a revoked one, and the history page renders one row per
 * version with the current one marked and a Restore control on the others.
 * The props and the restore outcomes are asserted in
 * `Feature/Console/ArtifactVersionsTest.php`; this file covers only what the
 * props cannot.
 */

/** A 13-character base36 permanent id, matching ArtifactIdShape::isStable. */
const BROWSER_VERSIONED_ARTIFACT_ID = 'abc123def4567';

/** A 13-character base36 id for the withdrawn artifact. */
const BROWSER_REVOKED_ARTIFACT_ID = 'revoked123456';

function versioningBrowserUser(string $teamName = 'Version Users', string $email = 'version-user@example.com'): array
{
    $owner = User::factory()->create(['name' => 'Version User', 'email' => $email]);
    $team = app(CreateTeam::class)->handle($owner, $teamName);

    return [$team, $owner];
}

function seedVersionedConsoleArtifact(Team $team, User $owner, string $artifactId, bool $revoked): void
{
    /** @var FakeArtifactDirectory $directory */
    $directory = app(ArtifactDirectory::class);
    $directory->seedArtifact([
        'id' => $artifactId,
        'org_id' => $team->slug,
        'user_id' => $owner->id,
        'title' => 'Browser version history',
        'description' => 'Seeded for the console version-history browser tests.',
        'content_hash' => str_repeat('c', 64),
        'created_at' => now()->subDays(3)->toIso8601String(),
        'revoked_at' => $revoked ? now()->toIso8601String() : null,
        'provenance' => ['agent' => 'cursor', 'repo_url' => null, 'commit_sha' => null],
    ]);

    if ($revoked) {
        return;
    }

    $directory->seedVersions($artifactId, [
        [
            'version' => 3, 'created_at' => now()->subDay()->toIso8601String(),
            'created_by' => (string) $owner->id, 'agent' => 'claude-code',
            'title' => 'Browser version history v3', 'current' => true, 'restored_from' => null,
        ],
        [
            'version' => 2, 'created_at' => now()->subDays(2)->toIso8601String(),
            'created_by' => '999999', 'agent' => 'cursor',
            'title' => 'Browser version history v2', 'current' => false, 'restored_from' => null,
        ],
        [
            'version' => 1, 'created_at' => now()->subDays(3)->toIso8601String(),
            'created_by' => null, 'agent' => null,
            'title' => 'Browser version history v1', 'current' => false, 'restored_from' => null,
        ],
    ], 3);
}

function versioningConsoleUrl(Team $team): string
{
    return '/settings/teams/'.$team->slug.'/console?q=Browser+version+history';
}

test('the console lists History for a live artifact and not for a revoked one', function () {
    [$team, $owner] = versioningBrowserUser();
    seedVersionedConsoleArtifact($team, $owner, BROWSER_VERSIONED_ARTIFACT_ID, revoked: false);
    seedVersionedConsoleArtifact($team, $owner, BROWSER_REVOKED_ARTIFACT_ID, revoked: true);
    test()->actingAs($owner);

    visit(versioningConsoleUrl($team))
        ->assertNoJavaScriptErrors()
        ->assertSee('Browser version history')
        ->assertSee('Revoked')
        // The live row links to its version history...
        ->assertAttributeContains(
            '@artifact-history-'.BROWSER_VERSIONED_ARTIFACT_ID,
            'href',
            '/console/artifacts/'.BROWSER_VERSIONED_ARTIFACT_ID.'/versions',
        )
        // ...and the revoked row offers no History control at all.
        ->assertMissing('@artifact-history-'.BROWSER_REVOKED_ARTIFACT_ID);
});

test('the version history page renders one row per version with the current marked', function () {
    [$team, $owner] = versioningBrowserUser('Version Page Users', 'version-page@example.com');
    seedVersionedConsoleArtifact($team, $owner, BROWSER_VERSIONED_ARTIFACT_ID, revoked: false);
    test()->actingAs($owner);

    $page = visit('/settings/teams/'.$team->slug.'/console/artifacts/'.BROWSER_VERSIONED_ARTIFACT_ID.'/versions')
        ->assertNoJavaScriptErrors()
        ->assertSee('Version history')
        ->assertSee('Version 3')
        ->assertSee('Version 2')
        ->assertSee('Version 1')
        ->assertSee('Current')
        ->assertSee('Version User')
        ->assertSee('Unknown');

    // The View control addresses the exact version.
    $page->assertAttributeContains('@version-view-2', 'href', 'version=2');

    // The current version has no Restore control; the older ones do.
    expect($page->attribute('@version-restore-1', 'type'))->toBe('submit')
        ->and($page->attribute('@version-restore-2', 'type'))->toBe('submit');

    $page->assertMissing('@version-restore-3');
});
