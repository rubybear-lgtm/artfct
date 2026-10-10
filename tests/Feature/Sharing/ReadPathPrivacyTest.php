<?php

use App\Contracts\ArtifactContentSource;
use App\Contracts\ArtifactDirectory;
use App\Enums\TeamRole;
use App\Models\Collection;
use App\Models\ExternalIdentity;
use App\Models\Team;
use App\Models\User;
use App\Services\Artifacts\HttpArtifactContentSource;
use App\Services\Artifacts\HttpArtifactDirectory;
use App\Services\Auth\OrgJwtService;
use App\Services\Indexing\EmbeddingsContract;
use App\Services\Indexing\FakeVectorIndex;
use App\Services\Indexing\VectorChunk;
use App\Services\Indexing\VectorIndexContract;
use App\Services\Slack\ArtifactSharingContract;
use App\Services\Slack\FakeArtifactSharing;
use App\Services\Slack\SharingLevel;
use App\Services\Slack\UnfurlService;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * RUB-438 read-path privacy: every Laravel surface that lists or shows
 * artifacts must get the Private rule applied. Directory-backed surfaces read
 * the Worker with the caller's own token (no `artifacts:read_private` scope),
 * so the Worker filters private artifacts out of the list; each test below
 * binds the real `HttpArtifactDirectory` and fakes the Worker list without the
 * private artifact, then asserts the surface neither shows it nor asks the
 * Worker with a private-capable credential. The system-token content read is
 * tested separately: it *can* see private artifacts, so its callers apply
 * `ArtifactVisibility` themselves.
 */
function useSharingWorkerDirectory(): void
{
    configureSigning(testSigningKey());
    config(['services.worker.base_url' => 'https://worker.test']);
    app()->instance(ArtifactDirectory::class, HttpArtifactDirectory::default());
}

/**
 * @return array<string, mixed>
 */
function sharingWorkerArtifact(string $id, ?string $title = null): array
{
    return [
        'id' => $id,
        'org_id' => 'privacy-org',
        'user_id' => 1,
        'title' => $title ?? "Artifact {$id}",
        'description' => null,
        'content_hash' => md5($id),
        'created_at' => now()->toIso8601String(),
        'revoked_at' => null,
        'provenance' => ['agent' => 'cursor', 'repo_url' => null, 'commit_sha' => null],
    ];
}

function seedSharingVector(Team $team, string $artifactId, string $text): void
{
    /** @var FakeVectorIndex $index */
    $index = app(VectorIndexContract::class);
    $index->upsertChunks($team->slug, $artifactId, [new VectorChunk(
        text: $text,
        vector: app(EmbeddingsContract::class)->embed([$text])[0],
        artifactId: $artifactId,
        orgId: $team->slug,
        createdAt: now()->subDay()->toIso8601String(),
        agent: 'cursor',
        repoUrl: null,
        commitSha: null,
    )]);
}

function sharingWorkerToken(Request $request): string
{
    $header = $request->header('Authorization');

    return str_replace('Bearer ', '', is_array($header) ? ($header[0] ?? '') : (string) $header);
}

function assertSharingViewerToken(string $token, User $user): void
{
    $claims = OrgJwtService::default()->verify($token);

    expect($claims['user_id'])->toBe((string) $user->id)
        // The caller's own credential must never carry the scope that would
        // let it read a private artifact it does not own.
        ->and($claims['scope'] ?? '')->not->toContain('artifacts:read_private');
}

test('the_console_lists_only_artifacts_the_members_own_token_may_see', function () {
    useSharingWorkerDirectory();

    $team = Team::factory()->create(['slug' => 'privacy-org']);
    $member = memberOfTeam($team, TeamRole::Member);
    $seenToken = null;

    Http::fake(['worker.test/*' => function (Request $request) use (&$seenToken) {
        $seenToken = sharingWorkerToken($request);

        return Http::response([
            'artifacts' => [sharingWorkerArtifact('public-1', 'Public One')],
            'next_cursor' => null,
        ]);
    }]);

    $response = test()->actingAs($member)->get(route('console.index', $team));

    $response->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('console/index')
        ->has('artifacts', 1)
        ->where('artifacts.0.id', 'public-1'));

    expect($response->getContent())->not->toContain('private-1');
    assertSharingViewerToken((string) $seenToken, $member);
});

test('the_dashboard_recent_list_excludes_a_private_artifact_the_worker_did_not_list', function () {
    useSharingWorkerDirectory();

    $team = Team::factory()->create(['slug' => 'privacy-org']);
    $member = memberOfTeam($team, TeamRole::Member);
    $member->switchTeam($team);
    $seenToken = null;

    Http::fake(['worker.test/*' => function (Request $request) use (&$seenToken) {
        $seenToken = sharingWorkerToken($request);

        return Http::response(['artifacts' => [sharingWorkerArtifact('public-1', 'Public One')], 'next_cursor' => null]);
    }]);

    $response = test()->actingAs($member)->get(route('dashboard', ['current_team' => $team->slug]));

    $response->assertOk()->assertInertia(fn (Assert $page) => $page
        ->has('home.recent', 1)
        ->where('home.recent.0.id', 'public-1'));

    expect($response->getContent())->not->toContain('private-1');
    assertSharingViewerToken((string) $seenToken, $member);
});

test('dashboard_search_excludes_a_private_artifact_the_worker_did_not_list', function () {
    useSharingWorkerDirectory();
    config(['indexing.enabled' => true]);

    $team = Team::factory()->create(['slug' => 'privacy-org']);
    $member = memberOfTeam($team, TeamRole::Member);
    $member->switchTeam($team);

    seedSharingVector($team, 'public-1', 'quarterly revenue dashboard');
    seedSharingVector($team, 'private-1', 'quarterly revenue dashboard');

    Http::fake(['worker.test/*' => Http::response(['artifacts' => [sharingWorkerArtifact('public-1', 'Public One')], 'next_cursor' => null])]);

    $response = test()->actingAs($member)->get(route('dashboard', [
        'current_team' => $team->slug,
        'q' => 'quarterly revenue dashboard',
    ]));

    $response->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('home.searched', true)
        ->has('home.results', 1)
        ->where('home.results.0.id', 'public-1'));

    expect($response->getContent())->not->toContain('private-1');
});

test('mcp_search_excludes_a_private_artifact_and_uses_the_callers_own_token', function () {
    useSharingWorkerDirectory();
    config(['indexing.enabled' => true]);

    $team = Team::factory()->create(['slug' => 'privacy-org']);
    $member = memberOfTeam($team, TeamRole::Member);
    $token = OrgJwtService::default()->mint($team, $member, TeamRole::Member)['token'];

    seedSharingVector($team, 'public-1', 'quarterly revenue dashboard');
    seedSharingVector($team, 'private-1', 'quarterly revenue dashboard');

    $seenToken = null;
    Http::fake(['worker.test/*' => function (Request $request) use (&$seenToken) {
        $seenToken = sharingWorkerToken($request);

        return Http::response(['artifacts' => [sharingWorkerArtifact('public-1', 'Public One')], 'next_cursor' => null]);
    }]);

    $response = test()->withToken($token)->postJson('/mcp', [
        'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call',
        'params' => ['name' => 'search_artifacts', 'arguments' => ['query' => 'quarterly revenue dashboard']],
    ]);

    $response->assertOk();
    expect($response->json('result.structuredContent.results'))->toHaveCount(1)
        ->and($response->json('result.structuredContent.results.0.id'))->toBe('public-1')
        ->and($response->getContent())->not->toContain('private-1');
    assertSharingViewerToken((string) $seenToken, $member);
});

test('the_collections_page_drops_a_private_artifact_from_titles_and_links', function () {
    useSharingWorkerDirectory();

    $team = Team::factory()->create(['slug' => 'privacy-org']);
    $member = memberOfTeam($team, TeamRole::Member);
    $collection = Collection::create(['team_id' => $team->id, 'name' => 'curated', 'created_by_user_id' => $member->id]);
    $collection->artifacts()->create(['artifact_id' => 'public-1', 'added_at' => now()]);
    $collection->artifacts()->create(['artifact_id' => 'private-1', 'added_at' => now()]);

    Http::fake(['worker.test/*' => Http::response(['artifacts' => [sharingWorkerArtifact('public-1', 'Public One')], 'next_cursor' => null])]);

    $response = test()->actingAs($member)->get(route('teams.collections.index', $team));

    $response->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('collections.0.artifactIds', ['public-1']));

    expect($response->getContent())
        ->toContain('Public One')
        ->not->toContain('private-1');
});

test('the_collections_api_returns_collection_metadata_without_artifact_ids_or_titles', function () {
    $team = Team::factory()->create(['slug' => 'privacy-org']);
    $member = memberOfTeam($team, TeamRole::Member);
    $collection = Collection::create(['team_id' => $team->id, 'name' => 'curated', 'created_by_user_id' => $member->id]);
    $collection->artifacts()->create(['artifact_id' => 'private-1', 'added_at' => now()]);

    configureSigning(testSigningKey());
    $token = OrgJwtService::default()->mint($team, $member, TeamRole::Member, scopes: ['collections:read'])['token'];

    $response = test()->withToken($token)->getJson('/api/collections');

    $response->assertOk();

    expect($response->getContent())
        ->toContain('curated')
        ->not->toContain('private-1')
        ->not->toContain('Artifact private-1');
});

test('mcp_list_collections_returns_no_artifact_ids_or_titles', function () {
    $team = Team::factory()->create(['slug' => 'privacy-org']);
    $member = memberOfTeam($team, TeamRole::Member);
    $collection = Collection::create(['team_id' => $team->id, 'name' => 'curated', 'created_by_user_id' => $member->id]);
    $collection->artifacts()->create(['artifact_id' => 'private-1', 'added_at' => now()]);

    configureSigning(testSigningKey());
    $token = OrgJwtService::default()->mint($team, $member, TeamRole::Member)['token'];

    $response = test()->withToken($token)->postJson('/mcp', [
        'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call',
        'params' => ['name' => 'list_collections', 'arguments' => []],
    ]);

    $response->assertOk();
    expect($response->getContent())->toContain('curated')->not->toContain('private-1');
});

test('mcp_add_collection_artifact_refuses_a_private_artifact_the_caller_cannot_see', function () {
    config(['services.worker.base_url' => 'https://worker.test']);

    $team = Team::factory()->create(['slug' => 'privacy-org']);
    $member = memberOfTeam($team, TeamRole::Member);
    $collection = Collection::create(['team_id' => $team->id, 'name' => 'curated', 'created_by_user_id' => $member->id]);

    configureSigning(testSigningKey());
    $token = OrgJwtService::default()->mint($team, $member, TeamRole::Member)['token'];

    Http::fake(['worker.test/*' => Http::response(['error' => ['code' => 'artifact_not_found']], 404)]);

    $response = test()->withToken($token)->postJson('/mcp', [
        'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call',
        'params' => ['name' => 'add_collection_artifact', 'arguments' => ['collection_id' => $collection->id, 'artifact_id' => 'private1']],
    ]);

    $response->assertOk();

    expect($response->getContent())->toContain('artifact_not_found')
        ->and($collection->artifacts()->count())->toBe(0);
});

test('api_search_excludes_a_private_artifact_and_uses_the_callers_own_token', function () {
    useSharingWorkerDirectory();
    config(['indexing.enabled' => true]);

    $team = Team::factory()->create(['slug' => 'privacy-org']);
    $member = memberOfTeam($team, TeamRole::Member);
    $token = OrgJwtService::default()->mint($team, $member, TeamRole::Member, scopes: ['artifacts:read'])['token'];

    seedSharingVector($team, 'public-1', 'quarterly revenue dashboard');
    seedSharingVector($team, 'private-1', 'quarterly revenue dashboard');

    $seenToken = null;
    Http::fake(['worker.test/*' => function (Request $request) use (&$seenToken) {
        $seenToken = sharingWorkerToken($request);

        return Http::response(['artifacts' => [sharingWorkerArtifact('public-1', 'Public One')], 'next_cursor' => null]);
    }]);

    $response = test()->withToken($token)->postJson('/api/search', ['query' => 'quarterly revenue dashboard']);

    $response->assertOk();
    expect($response->json('results'))->toHaveCount(1)
        ->and($response->json('results.0.id'))->toBe('public-1')
        ->and($response->getContent())->not->toContain('private-1');
    assertSharingViewerToken((string) $seenToken, $member);
});

test('mcp_get_artifact_is_not_found_for_a_private_artifact_the_caller_cannot_see', function () {
    config(['services.worker.base_url' => 'https://worker.test']);

    $team = Team::factory()->create(['slug' => 'privacy-org']);
    $member = memberOfTeam($team, TeamRole::Member);

    configureSigning(testSigningKey());
    $token = OrgJwtService::default()->mint($team, $member, TeamRole::Member)['token'];

    // The tool reads `/v1/artifacts/{id}` with the caller's own bearer, so the
    // Worker's 404 for a private artifact is the whole enforcement.
    Http::fake(['worker.test/*' => Http::response(['error' => ['code' => 'artifact_not_found']], 404)]);

    $response = test()->withToken($token)->postJson('/mcp', [
        'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call',
        'params' => ['name' => 'get_artifact', 'arguments' => ['id' => str_repeat('a', 32)]],
    ]);

    $response->assertOk();
    expect($response->getContent())->toContain('artifact_not_found');
});

test('the_console_export_omits_a_private_artifact_the_worker_did_not_export', function () {
    useSharingWorkerDirectory();

    $team = Team::factory()->create(['slug' => 'privacy-org']);
    $admin = memberOfTeam($team, TeamRole::Admin);

    Http::fake(['worker.test/v1/orgs/privacy-org/export' => Http::response([
        'artifacts' => [sharingWorkerArtifact('public-1', 'Public One')],
        'blobs' => [],
    ])]);

    $response = test()->actingAs($admin)->get(route('console.export', $team));
    $response->assertOk()->assertDownload();

    $zip = new ZipArchive;
    expect($zip->open($response->baseResponse->getFile()->getPathname()))->toBeTrue();
    $metadata = (string) $zip->getFromName('artifacts.json');
    $zip->close();

    expect($metadata)->toContain('public-1')->not->toContain('private-1');
});

test('artifact_version_history_is_a_404_for_a_private_artifact_the_viewer_cannot_see', function () {
    useSharingWorkerDirectory();

    $team = Team::factory()->create(['slug' => 'privacy-org']);
    $member = memberOfTeam($team, TeamRole::Member);

    // The Worker answers another org's artifact, a missing one and a private
    // one the caller may not see with the same 404; the page never asks it to
    // distinguish them.
    Http::fake(['worker.test/*' => Http::response([], 404)]);

    test()->actingAs($member)
        ->get(route('console.versions', ['team' => $team, 'artifactId' => str_repeat('a', 32)]))
        ->assertNotFound();
});

test('the_preview_applies_the_private_rule_to_the_system_content_read', function () {
    configureOrgJwt();
    config(['services.worker.base_url' => 'https://worker.test']);
    app()->instance(ArtifactContentSource::class, HttpArtifactContentSource::default());

    $team = Team::factory()->create(['slug' => 'privacy-org']);
    $owner = memberOfTeam($team, TeamRole::Member);
    $other = memberOfTeam($team, TeamRole::Member);
    $admin = memberOfTeam($team, TeamRole::Admin);
    $artifactId = str_repeat('c', 32);

    Http::fake(['worker.test/*' => Http::response([
        'content' => '<h1>Secret</h1>',
        'tier' => 'secure',
        'sharing' => 'private',
        'owner_user_id' => (string) $owner->id,
    ])]);

    $url = route('teams.artifacts.preview', ['team' => $team->slug, 'artifactId' => $artifactId]);

    // The system content token can read it, but this member may not.
    test()->actingAs($other)->get($url)->assertNotFound()->assertDontSee('Secret');

    // The owner and a team admin may.
    test()->actingAs($owner)->get($url)->assertOk()->assertSee('Secret', false);
    test()->actingAs($admin)->get($url)->assertOk()->assertSee('Secret', false);
});

test('the_slack_slash_command_fails_closed_and_never_surfaces_a_private_artifact', function () {
    useSharingWorkerDirectory();

    $team = Team::factory()->create(['slug' => 'privacy-org', 'slack_workspace_id' => 'T123']);
    $user = memberOfTeam($team, TeamRole::Member);
    ExternalIdentity::create([
        'user_id' => $user->id,
        'provider' => 'slack',
        'external_id' => 'U123',
        'email' => $user->email,
        'verified_at' => now(),
    ]);

    Http::fake(['worker.test/*' => Http::response(['artifacts' => [sharingWorkerArtifact('public-1', 'Public One')], 'next_cursor' => null])]);

    $response = postSlackCommand(['team_id' => 'T123', 'user_id' => 'U123', 'text' => 'anything']);

    // No signed-in viewer means no credential to mint; the search fails closed
    // rather than reading the Worker with a credential that could see more.
    expect($response->status())->toBe(403)
        ->and($response->getContent())->not->toContain('Public One')
        ->and($response->getContent())->not->toContain('private-1');
    Http::assertNothingSent();
});

test('a_private_artifact_unfurls_as_a_bare_card', function () {
    $team = Team::factory()->create(['slug' => 'privacy-org']);
    /** @var FakeArtifactSharing $sharing */
    $sharing = app(ArtifactSharingContract::class);
    $sharing->seed('privacy-org', 'private-1', SharingLevel::OrgPrivate);

    $result = app(UnfurlService::class)->unfurl($team, 'private-1');

    expect($result->bareCard)->toBeTrue()
        ->and($result->title)->toBeNull()
        ->and($result->description)->toBeNull()
        ->and($result->thumbnail)->toBeNull();
});
