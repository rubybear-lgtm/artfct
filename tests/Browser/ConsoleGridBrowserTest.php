<?php

use App\Actions\Teams\CreateTeam;
use App\Contracts\ArtifactContentSource;
use App\Contracts\ArtifactDirectory;
use App\Models\User;
use App\Services\Artifacts\FakeArtifactContentSource;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * The console's card grid, exercised in a browser: each card carries a live
 * preview of the artifact, and its menu holds the actions the table row used
 * to show inline. `?q=` narrows the console to the one seeded artifact.
 */
function consoleGridArtifact(): array
{
    $owner = User::factory()->create(['name' => 'Grid User', 'email' => 'grid-user@example.com']);
    $team = app(CreateTeam::class)->handle($owner, 'Grid Users');

    /** @var ArtifactDirectory $directory */
    $directory = app(ArtifactDirectory::class);
    $directory->seedArtifact([
        'id' => ARTIFACT_LINK_ID,
        'org_id' => $team->slug,
        'user_id' => $owner->id,
        'title' => 'GRIDCARD report',
        'description' => 'Seeded for the console grid test.',
        'content_hash' => md5(ARTIFACT_LINK_ID),
        'created_at' => now()->subDay()->toIso8601String(),
        'revoked_at' => null,
        'provenance' => ['agent' => 'cursor', 'repo_url' => null, 'commit_sha' => null],
    ]);

    /** @var FakeArtifactContentSource $content */
    $content = app(ArtifactContentSource::class);
    $content->seed($team->slug, ARTIFACT_LINK_ID, '<h1>GRIDCARD body</h1>');

    return [$team, $owner];
}

test('console_grid_shows_a_card_with_a_live_preview_and_a_working_menu', function () {
    config(['services.artifact_access.token_secret' => 'browser-artifact-secret']);

    [$team, $owner] = consoleGridArtifact();
    test()->actingAs($owner);

    $page = visit('/settings/teams/'.$team->slug.'/console?q=GRIDCARD')
        ->assertNoJavaScriptErrors()
        ->assertVisible('@artifact-grid')
        ->assertSee('GRIDCARD report')
        ->assertAttributeContains('@open-artifact-title', 'href', '/open');

    expect($page->script("document.querySelector('[data-testid=\"artifact-grid\"] iframe')?.getAttribute('src') ?? ''"))
        ->toContain('/artifacts/'.ARTIFACT_LINK_ID.'/preview');

    // The menu opens, and cancelling the revoke dialog leaves the page usable.
    $page->click('[aria-label="Actions for GRIDCARD report"]')
        ->assertSee('History')
        ->click('Revoke')
        ->assertSee('Revoke artifact')
        ->click('Keep it')
        ->click('[aria-label="Filter artifacts"]')
        ->assertVisible('#repo_url')
        ->assertNoJavaScriptErrors();
});
