<?php

use App\Actions\Teams\CreateTeam;
use App\Contracts\ArtifactDirectory;
use App\Models\User;
use App\Services\Artifacts\FakeArtifactDirectory;
use App\Services\Indexing\EmbeddingsContract;
use App\Services\Indexing\FakeVectorIndex;
use App\Services\Indexing\VectorChunk;
use App\Services\Indexing\VectorIndexContract;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * The search page, exercised in a browser (RUB-338).
 *
 * The provider calls are the in-process fakes the Feature suite uses, so this
 * proves the page a person actually drives: typing a query, submitting it, and
 * getting a result card whose link opens the artifact through the app's signed
 * open route in a new tab, never with a token in the href. Ranking against live
 * embeddings is checked on staging, not here.
 *
 * The submit button is addressed by type because the nav also has a "Search"
 * link, and clicking by text would reload the page instead of submitting.
 */
test('search_and_open_result', function () {
    config([
        'indexing.enabled' => true,
        'services.artifact_access.token_secret' => 'browser-artifact-secret',
    ]);

    $owner = User::factory()->create(['name' => 'Search User', 'email' => 'search-user@example.com']);
    $team = app(CreateTeam::class)->handle($owner, 'Search Users');

    /** @var FakeArtifactDirectory $directory */
    $directory = app(ArtifactDirectory::class);
    $directory->seedArtifact([
        'id' => 'billing-dash', 'org_id' => $team->slug, 'user_id' => 1, 'title' => 'Billing dashboard',
        'description' => 'Revenue overview', 'content_hash' => md5('billing-dash'),
        'created_at' => now()->subDay()->toIso8601String(), 'revoked_at' => null,
        'provenance' => ['agent' => 'cursor', 'repo_url' => 'https://github.com/acme/billing', 'commit_sha' => 'abc'],
    ]);

    $text = 'billing dashboard revenue overview';
    /** @var FakeVectorIndex $index */
    $index = app(VectorIndexContract::class);
    $index->upsertChunks($team->slug, 'billing-dash', [new VectorChunk(
        text: $text, vector: app(EmbeddingsContract::class)->embed([$text])[0], artifactId: 'billing-dash', orgId: $team->slug,
        createdAt: now()->subDay()->toIso8601String(), agent: 'cursor', repoUrl: 'https://github.com/acme/billing', commitSha: 'abc',
    )]);

    test()->actingAs($owner);

    visit('/settings/teams/'.$team->slug.'/search')
        ->assertNoJavaScriptErrors()
        ->type('input[placeholder^=What]', $text)
        ->click('button[type=submit]')
        ->wait(1)
        ->assertSee('Billing dashboard')
        ->assertSee('cursor')
        ->assertAttribute('@search-result-link', 'target', '_blank')
        ->assertAttributeContains('@search-result-link', 'href', '/open')
        ->assertAttributeDoesntContain('@search-result-link', 'href', 'token=')
        ->assertNoJavaScriptErrors();
});
