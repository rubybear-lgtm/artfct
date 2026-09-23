<?php

use App\Contracts\ArtifactDirectory;
use App\Enums\TeamRole;
use App\Models\Team;
use App\Services\Artifacts\FakeArtifactDirectory;
use App\Services\Indexing\EmbeddingsContract;
use App\Services\Indexing\FakeVectorIndex;
use App\Services\Indexing\VectorChunk;
use App\Services\Indexing\VectorIndexContract;

/**
 * Search results are workspace artifacts, so both ways of reading them — the
 * `search_artifacts` MCP tool and the search page — hand back the app's own
 * open route rather than the Worker's raw `/p/{id}` URL. The raw URL is the
 * link a browser cannot present a credential to, so a member clicking a search
 * result for a secure artifact could only 403.
 *
 * The index carries no tier, so each row goes through the app: that route is
 * the one link that is right either way, and it is where the tier is resolved
 * (a public artifact is redirected to its public URL without a token being
 * minted; a secure one gets a signed link minted for the click).
 */
function seedSearchableArtifact(string $orgSlug, string $artifactId, string $text, string $tier = 'secure'): void
{
    /** @var FakeArtifactDirectory $directory */
    $directory = app(ArtifactDirectory::class);
    $directory->seedArtifact([
        'id' => $artifactId, 'org_id' => $orgSlug, 'user_id' => 1,
        'title' => "Artifact {$artifactId}", 'description' => 'd',
        'content_hash' => md5($artifactId), 'created_at' => now()->subDay()->toIso8601String(),
        'revoked_at' => null, 'tier' => $tier,
        'provenance' => ['agent' => 'cursor', 'repo_url' => 'https://github.com/acme/misc', 'commit_sha' => 'abc'],
    ]);

    /** @var FakeVectorIndex $index */
    $index = app(VectorIndexContract::class);
    $index->upsertChunks($orgSlug, $artifactId, [new VectorChunk(
        text: $text, vector: app(EmbeddingsContract::class)->embed([$text])[0],
        artifactId: $artifactId, orgId: $orgSlug,
        createdAt: now()->subDay()->toIso8601String(),
        agent: 'cursor', repoUrl: 'https://github.com/acme/misc', commitSha: 'abc',
    )]);
}

test('search_artifacts returns a view_url on the apps open route and no raw url', function () {
    config(['indexing.enabled' => true, 'app.public_base_url' => 'https://artfct.dev']);

    $team = Team::factory()->create(['slug' => 'link-org']);
    $token = remoteMcpToken($team);
    seedSearchableArtifact($team->slug, 'billing-dash', 'billing dashboard revenue overview');

    $response = $this->withToken($token)->postJson('/mcp', [
        'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call',
        'params' => ['name' => 'search_artifacts', 'arguments' => ['query' => 'billing dashboard revenue overview']],
    ])->assertOk();

    $results = $response->json('result.structuredContent.results');

    expect($results)->toHaveCount(1)
        ->and($results[0]['view_url'])->toBe(
            route('console.open', ['team' => $team->slug, 'artifactId' => 'billing-dash']),
        )
        // One field name for one meaning: no second `url` spelling of the same
        // link, and certainly not the Worker's credential-less one.
        ->and($results[0])->not->toHaveKey('url')
        ->and($results[0]['view_url'])->not->toContain('/p/')
        ->and($results[0]['view_url'])->not->toContain('token');
});

test('search_artifacts explains when indexing is disabled instead of asking the client to retry', function () {
    config(['indexing.enabled' => false]);

    $team = Team::factory()->create(['slug' => 'search-disabled-org']);
    $token = remoteMcpToken($team);

    $this->withToken($token)->postJson('/mcp', [
        'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call',
        'params' => ['name' => 'search_artifacts', 'arguments' => ['query' => 'billing dashboard']],
    ])->assertOk()
        ->assertJsonPath('result.isError', true)
        ->assertJsonPath('result.content.0._meta.artfct.errorCode', 'search_not_configured')
        ->assertJsonPath('result.content.0._meta.artfct.retryable', false)
        ->assertJsonPath('result.content.0._meta.artfct.nextAction', 'enable_indexing');
});

test('the search page links each row the same way the tool does', function () {
    config(['indexing.enabled' => true, 'app.public_base_url' => 'https://artfct.dev']);

    $team = Team::factory()->create(['slug' => 'link-page-org']);
    $member = memberOfTeam($team, TeamRole::Member);
    seedSearchableArtifact($team->slug, 'billing-dash', 'billing dashboard revenue overview');

    test()->actingAs($member)
        ->get(route('teams.search', [$team, 'q' => 'billing dashboard revenue overview']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('results.0.id', 'billing-dash')
            ->where('results.0.openUrl', route('console.open', ['team' => $team->slug, 'artifactId' => 'billing-dash']))
            ->missing('results.0.url'));
});

test('a search row carries no open link when the environment cannot mint one', function () {
    config(['indexing.enabled' => true, 'services.artifact_access.token_secret' => null]);

    $team = Team::factory()->create(['slug' => 'link-no-secret-org']);
    $member = memberOfTeam($team, TeamRole::Member);
    seedSearchableArtifact($team->slug, 'billing-dash', 'billing dashboard revenue overview');

    test()->actingAs($member)
        ->get(route('teams.search', [$team, 'q' => 'billing dashboard revenue overview']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('canOpenArtifacts', false)
            ->where('results.0.id', 'billing-dash'));
});
