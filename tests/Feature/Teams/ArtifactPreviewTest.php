<?php

use App\Contracts\ArtifactContentSource;
use App\Enums\TeamRole;
use App\Models\Team;
use App\Models\User;
use App\Services\Artifacts\ArtifactAccessLink;
use App\Services\Artifacts\HttpArtifactContentSource;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;

/**
 * The exact policy every preview outcome must carry: no scripts, no external
 * resources, and no parent navigation; the document also cannot keep the
 * app's origin. The `sandbox` directive is what makes the page inert even on
 * a direct navigation, not only inside the app's iframe.
 */
const ARTIFACT_PREVIEW_CSP = "default-src 'none'; style-src 'unsafe-inline'; img-src data: blob:; font-src data:; base-uri 'none'; form-action 'none'; sandbox; frame-ancestors 'self'";

function artifactPreviewUrl(Team|string $team, string $artifactId): string
{
    return route('teams.artifacts.preview', [
        'team' => $team instanceof Team ? $team->slug : $team,
        'artifactId' => $artifactId,
    ]);
}

function assertArtifactPreviewHeaders(TestResponse $response): void
{
    $response
        ->assertHeader('Content-Type', 'text/html; charset=UTF-8')
        // Symfony alphabetizes Cache-Control directives, so the exact wire
        // value is `no-store, private`; both directives are required.
        ->assertHeader('Cache-Control', 'no-store, private')
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('Referrer-Policy', 'no-referrer')
        ->assertHeader('Content-Security-Policy', ARTIFACT_PREVIEW_CSP);
}

test('a_guest_is_redirected_to_sign_in', function () {
    $team = Team::factory()->create();

    test()
        ->get(artifactPreviewUrl($team, 'artifact-1'))
        ->assertRedirect(route('login'));
});

test('every_team_role_may_preview_the_artifact_inertly', function (TeamRole $role) {
    $team = Team::factory()->create(['slug' => 'test-org']);
    $user = memberOfTeam($team, $role);

    app(ArtifactContentSource::class)->seed($team->slug, 'artifact-1', '<!doctype html><h1>Inert</h1>');

    $response = test()->actingAs($user)->get(artifactPreviewUrl($team, 'artifact-1'));

    $response->assertOk()->assertSee('<!doctype html><h1>Inert</h1>', false);
    assertArtifactPreviewHeaders($response);
})->with([TeamRole::Member, TeamRole::Viewer, TeamRole::Admin]);

/**
 * The real HTTP source, both tiers: the preview asks the org-scoped content
 * endpoint with the org credential and renders whatever bytes it stores.
 */
test('the_real_source_previews_public_and_secure_tiers_with_the_org_credential', function (string $tier) {
    configureOrgJwt();
    config(['services.worker.base_url' => 'https://worker.test']);
    app()->instance(ArtifactContentSource::class, HttpArtifactContentSource::default());

    $team = Team::factory()->create(['slug' => 'tier-org']);
    $member = memberOfTeam($team, TeamRole::Member);
    $artifactId = 'facefeedfacefeedfacefeedfacefeed';
    $html = "<!doctype html><h1>{$tier}</h1>";

    Http::fake(['worker.test/*' => Http::response(['content' => $html, 'tier' => $tier], 200)]);

    $response = test()->actingAs($member)->get(artifactPreviewUrl($team, $artifactId));

    $response->assertOk()->assertSee($html, false);
    assertArtifactPreviewHeaders($response);

    Http::assertSent(fn ($request): bool => str_contains($request->url(), "/v1/orgs/tier-org/artifacts/{$artifactId}/content")
        && str_starts_with((string) ($request->header('Authorization')[0] ?? ''), 'Bearer '));
})->with(['public', 'secure']);

/**
 * A preview is not an Open: it authorizes the viewer and reads the content
 * server-side, so it works even when the environment has no signing secret
 * and every other artifact link can only 503.
 */
test('a_preview_needs_no_artifact_signing_secret', function () {
    config(['services.artifact_access.token_secret' => null]);
    expect(ArtifactAccessLink::default()->configured())->toBeFalse();

    $team = Team::factory()->create(['slug' => 'test-org']);
    $member = memberOfTeam($team, TeamRole::Member);

    app(ArtifactContentSource::class)->seed($team->slug, 'artifact-1', '<h1>Secure and shown</h1>');

    $response = test()->actingAs($member)->get(artifactPreviewUrl($team, 'artifact-1'));

    $response->assertOk()->assertSee('<h1>Secure and shown</h1>', false);
    assertArtifactPreviewHeaders($response);
});

test('a_non_member_is_refused_before_the_worker_is_called', function () {
    configureOrgJwt();
    config(['services.worker.base_url' => 'https://worker.test']);
    app()->instance(ArtifactContentSource::class, HttpArtifactContentSource::default());
    Http::fake(['worker.test/*' => Http::response(['content' => '<h1>foreign</h1>', 'tier' => 'secure'], 200)]);

    $team = Team::factory()->create(['slug' => 'foreign-org']);
    $outsider = User::factory()->create();

    $response = test()->actingAs($outsider)->get(artifactPreviewUrl($team, 'artifact-1'));

    $response->assertNotFound()->assertSee('Preview unavailable');
    assertArtifactPreviewHeaders($response);
    Http::assertNothingSent();
});

test('an_unknown_team_reads_the_same_as_a_foreign_one', function () {
    app()->instance(ArtifactContentSource::class, HttpArtifactContentSource::default());
    Http::fake(['worker.test/*' => Http::response(['content' => '<h1>never reached</h1>'], 200)]);

    $response = test()->actingAs(User::factory()->create())->get(artifactPreviewUrl('ghost-org', 'artifact-1'));

    $response->assertNotFound()->assertSee('Preview unavailable');
    assertArtifactPreviewHeaders($response);
    Http::assertNothingSent();
});

/**
 * The real HTTP source is bound on purpose: a 404 from the Worker is the
 * contract that covers both a revoked artifact and one that never existed in
 * this org, and the response must not tell the two apart or echo the id.
 */
test('a_missing_or_revoked_artifact_is_a_generic_404', function () {
    configureOrgJwt();
    config(['services.worker.base_url' => 'https://worker.test']);
    app()->instance(ArtifactContentSource::class, HttpArtifactContentSource::default());
    Http::fake(['worker.test/*' => Http::response(['error' => ['code' => 'artifact_not_found']], 404)]);

    $team = Team::factory()->create(['slug' => 'test-org']);
    $member = memberOfTeam($team, TeamRole::Member);
    $artifactId = 'deadbeefdeadbeefdeadbeefdeadbeef';

    $response = test()->actingAs($member)->get(artifactPreviewUrl($team, $artifactId));

    $response->assertNotFound();
    assertArtifactPreviewHeaders($response);
    expect($response->getContent())
        ->not->toContain($artifactId)
        ->not->toContain('artifact_not_found');

    Http::assertSent(fn ($request): bool => str_contains(
        $request->url(),
        "/v1/orgs/test-org/artifacts/{$artifactId}/content",
    ));
});

/**
 * The in-memory source keys by org, so an artifact seeded for another team
 * misses here exactly as a revoked one would.
 */
test('an_artifact_from_another_org_is_a_generic_404', function () {
    $team = Team::factory()->create(['slug' => 'team-a']);
    $other = Team::factory()->create(['slug' => 'team-b']);
    $member = memberOfTeam($team, TeamRole::Member);

    app(ArtifactContentSource::class)->seed($other->slug, 'artifact-1', '<h1>another org</h1>');

    $response = test()->actingAs($member)->get(artifactPreviewUrl($team, 'artifact-1'));

    $response->assertNotFound();
    assertArtifactPreviewHeaders($response);
    expect($response->getContent())->not->toContain('another org');
});

test('a_worker_connection_failure_is_a_generic_503', function () {
    configureOrgJwt();
    config(['services.worker.base_url' => 'https://worker.test']);
    app()->instance(ArtifactContentSource::class, HttpArtifactContentSource::default());
    Http::fake(fn () => throw new ConnectionException('Connection refused'));

    $team = Team::factory()->create(['slug' => 'test-org']);
    $member = memberOfTeam($team, TeamRole::Member);

    $response = test()->actingAs($member)->get(artifactPreviewUrl($team, 'artifact-1'));

    $response->assertStatus(503);
    assertArtifactPreviewHeaders($response);
    expect($response->getContent())
        ->not->toContain('Connection refused')
        ->not->toContain('artifact-1');
});

test('an_upstream_failure_is_a_generic_503', function () {
    configureOrgJwt();
    config(['services.worker.base_url' => 'https://worker.test']);
    app()->instance(ArtifactContentSource::class, HttpArtifactContentSource::default());
    Http::fake([
        'worker.test/*' => Http::response(['error' => ['code' => 'internal_error', 'message' => 'worker exploded']], 500),
    ]);

    $team = Team::factory()->create(['slug' => 'test-org']);
    $member = memberOfTeam($team, TeamRole::Member);

    $response = test()->actingAs($member)->get(artifactPreviewUrl($team, 'artifact-1'));

    $response->assertStatus(503);
    assertArtifactPreviewHeaders($response);
    expect($response->getContent())
        ->not->toContain('internal_error')
        ->not->toContain('worker exploded')
        ->not->toContain('artifact-1');
});
