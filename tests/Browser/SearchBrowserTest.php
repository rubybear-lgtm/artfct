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
 * Team search, exercised in a browser (RUB-338).
 *
 * Search lives on the dashboard home: typing a query and pressing Enter runs
 * it, and a result row links out through the app's signed open route in a
 * new tab, never with a token in the href. The provider calls are the
 * in-process fakes the Feature suite uses. Ranking against live embeddings is
 * checked on staging, not here.
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
        'id' => 'billing-dash', 'org_id' => $team->slug, 'user_id' => 1, 'title' => 'Billing dashboard '.str_repeat('LongTitle', 15),
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

    $page = visit(route('dashboard', $team))
        ->assertNoJavaScriptErrors()
        ->type('input[aria-label="Search query"]', $text)
        ->keys('input[aria-label="Search query"]', 'Enter')
        ->wait(1)
        ->assertSee('Billing dashboard')
        ->assertSee('Cursor')
        ->assertAttribute('@search-result-link', 'target', '_blank')
        ->assertAttributeContains('@search-result-link', 'href', '/a/')
        ->assertAttributeDoesntContain('@search-result-link', 'href', 'token=')
        ->assertNoJavaScriptErrors();

    foreach ([320, 390, 768, 1280] as $width) {
        $page->resize($width, 850)
            ->assertScript('document.documentElement.scrollWidth <= window.innerWidth')
            ->assertNoJavaScriptErrors();
        if ($width < 768) {
            $page->assertScript('document.querySelector(\'[data-testid="search-result-link"]\').getBoundingClientRect().height >= 44');
        }
    }
});

/**
 * The retired search URL keeps working as a bookmark: it redirects to the
 * dashboard, which shows the query in its search field with results.
 */
test('legacy_search_url_lands_on_the_dashboard_with_results', function () {
    config([
        'indexing.enabled' => true,
        'services.artifact_access.token_secret' => 'browser-artifact-secret',
    ]);

    $owner = User::factory()->create(['name' => 'Legacy Search User', 'email' => 'legacy-search-user@example.com']);
    $team = app(CreateTeam::class)->handle($owner, 'Legacy Search Users');

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

    visit(route('teams.search', [$team, 'q' => $text]))
        ->assertNoJavaScriptErrors()
        ->assertValue('input[aria-label="Search query"]', $text)
        ->assertSee('Billing dashboard')
        ->assertAttribute('@search-result-link', 'target', '_blank')
        ->assertAttributeContains('@search-result-link', 'href', '/a/')
        ->assertAttributeDoesntContain('@search-result-link', 'href', 'token=')
        ->assertNoJavaScriptErrors();
});
