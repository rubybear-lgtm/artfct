<?php

use App\Contracts\ArtifactContentSource;
use App\Enums\TeamRole;
use App\Models\Team;
use App\Services\Artifacts\ArtifactAccessLink;
use App\Services\Artifacts\FakeArtifactContentSource;
use Illuminate\Support\Facades\Http;

/** Shared signing secret for the isolated-origin link tests. */
const ARTIFACT_LINK_SECRET = 'artifact-link-test-secret';

/** A 32-character lowercase hex public artifact id, the only shape the isolated hostname accepts. */
const ARTIFACT_LINK_ID = '0123456789abcdef0123456789abcdef';

function configureArtifactLinks(string $secret = ARTIFACT_LINK_SECRET, string $suffix = '.artfct.dev'): void
{
    config([
        'services.artifact_access.token_secret' => $secret,
        'services.artifact_access.origin_suffix' => $suffix,
        'services.artifact_access.token_ttl_minutes' => 60,
    ]);
}

/**
 * Stands in for the Worker's `verify_access_token` (`backend/src/lib.rs`),
 * re-deriving the HMAC from the documented wire form
 * `<artifact_id>.<expires_at_unix>.<hmac_hex>` instead of trusting the
 * minter — a disagreement about the token has to fail here, not pass because
 * one side asserted its own output.
 */
function artifactTokenVerifies(string $token, string $artifactId, string $secret, int $now): bool
{
    $parts = explode('.', $token, 3);

    if (count($parts) !== 3 || ! hash_equals($parts[0], $artifactId)) {
        return false;
    }

    $expiresAt = $parts[1];

    if (! ctype_digit($expiresAt) || $now >= (int) $expiresAt) {
        return false;
    }

    return hash_equals($parts[2], hash_hmac('sha256', "{$parts[0]}.{$expiresAt}", $secret));
}

test('viewer_cannot_revoke', function () {
    Http::fake([
        'https://worker.test/v1/orgs/*' => Http::response([], 200),
    ]);

    $team = Team::factory()->create();
    $viewer = memberOfTeam($team, TeamRole::Viewer);
    $artifact = [
        'id' => '1234567890',
        'org_id' => $team->slug,
        'user_id' => 1,
        'title' => 'Test',
        'revoked_at' => null,
    ];

    $response = test()
        ->actingAs($viewer)
        ->patch(
            "/settings/teams/{$team->slug}/console/artifacts/{$artifact['id']}/revoke",
            ['revoked_at' => now()->toIso8601String()]
        );

    $response->assertForbidden();
    // Verify the API was never called (the guard fired, preventing the call)
    Http::assertNothingSent();
});

test('member_cannot_read_other_org_list', function () {
    Http::fake([
        'https://worker.test/v1/orgs/*' => Http::response([], 200),
    ]);

    $team1 = Team::factory()->create(['slug' => 'team-1']);
    $team2 = Team::factory()->create(['slug' => 'team-2']);

    $member = memberOfTeam($team1, TeamRole::Member);

    $response = test()
        ->actingAs($member)
        ->get("/settings/teams/{$team2->slug}/console");

    $response->assertNotFound();
    // Verify the API was never called (the guard fired at the route resolution level)
    Http::assertNothingSent();
});

test('cross_org_list_returns_404_not_403', function () {
    Http::fake([
        'https://worker.test/v1/orgs/*' => Http::response([], 200),
    ]);

    $team1 = Team::factory()->create(['slug' => 'team-1']);
    $team2 = Team::factory()->create(['slug' => 'team-2']);

    $admin = memberOfTeam($team1, TeamRole::Admin);

    // Admin of team1 tries to access team2's console
    $response = test()
        ->actingAs($admin)
        ->get("/settings/teams/{$team2->slug}/console");

    $response->assertNotFound();
    // Verify the API was never called
    Http::assertNothingSent();
});

test('token_value_shown_once_only_on_reload', function () {
    configureOrgJwt();

    $team = Team::factory()->create();
    $admin = memberOfTeam($team, TeamRole::Admin);

    // Create a token (this is from spec 07, just verifying behavior on reload)
    $response = test()
        ->actingAs($admin)
        ->postJson("/settings/teams/{$team->slug}/tokens", [
            'name' => 'Test token',
            'role' => 'admin',
        ]);

    $response->assertCreated();
    $rawToken = $response->json('token');
    expect($rawToken)->toBeString()->not->toBeEmpty();

    // Now reload the settings page and verify the raw token is NOT in the response
    $settingsResponse = test()
        ->actingAs($admin)
        ->get("/settings/teams/{$team->slug}");

    $settingsResponse->assertOk();
    // The response should not contain the raw token value anywhere
    expect($settingsResponse->getContent())->not->toContain($rawToken);
});

test('export_requires_admin', function () {
    Http::fake([
        'https://worker.test/v1/orgs/*' => Http::response(['artifacts' => [], 'blobs' => []], 200),
    ]);

    $team = Team::factory()->create();
    $viewer = memberOfTeam($team, TeamRole::Viewer);
    $member = memberOfTeam($team, TeamRole::Member);

    // Viewer cannot export
    $response = test()
        ->actingAs($viewer)
        ->get("/settings/teams/{$team->slug}/console/export");

    $response->assertForbidden();
    Http::assertNothingSent();

    // Member cannot export
    $response = test()
        ->actingAs($member)
        ->get("/settings/teams/{$team->slug}/console/export");

    $response->assertForbidden();
    Http::assertNothingSent();
});

test('export_is_rate_limited', function () {
    Http::fake([
        'https://worker.test/v1/orgs/*' => Http::response(['artifacts' => [], 'blobs' => []], 200),
    ]);

    $team = Team::factory()->create(['slug' => 'test-org']);
    $admin = memberOfTeam($team, TeamRole::Admin);

    // First 3 exports should succeed
    for ($i = 0; $i < 3; $i++) {
        $response = test()
            ->actingAs($admin)
            ->get("/settings/teams/{$team->slug}/console/export");

        $response->assertOk();
    }

    // Fourth export should be rate limited (429)
    $response = test()
        ->actingAs($admin)
        ->get("/settings/teams/{$team->slug}/console/export");

    $response->assertStatus(429);
});

test('admin_can_list_artifacts', function () {
    // In the testing environment, ArtifactDirectory resolves to
    // FakeArtifactDirectory (an in-memory double), not an HTTP client — see
    // AppServiceProvider. Assert against its seeded demo data, not a mocked
    // HTTP call the app never actually makes here.
    $team = Team::factory()->create(['slug' => 'test-org']);
    $admin = memberOfTeam($team, TeamRole::Admin);

    $response = test()
        ->actingAs($admin)
        ->get("/settings/teams/{$team->slug}/console");

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('console/index')
        ->where('artifacts.0.id', '1234567890')
        ->where('isAdmin', true)
    );
});

test('viewer_can_list_artifacts_but_no_admin_controls', function () {
    Http::fake([
        'https://worker.test/v1/orgs/test-org/artifacts' => Http::response([
            'artifacts' => [],
            'next_cursor' => null,
        ], 200),
    ]);

    $team = Team::factory()->create(['slug' => 'test-org']);
    $viewer = memberOfTeam($team, TeamRole::Viewer);

    $response = test()
        ->actingAs($viewer)
        ->get("/settings/teams/{$team->slug}/console");

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('console/index')
        ->where('isAdmin', false)
    );
});

test('member_opens_artifact_at_its_isolated_origin_with_a_token_bound_to_it', function () {
    configureArtifactLinks();

    $team = Team::factory()->create(['slug' => 'test-org']);
    // A viewer, not an admin: opening what the console lists is a read.
    $viewer = memberOfTeam($team, TeamRole::Viewer);

    /** @var FakeArtifactContentSource $content */
    $content = app(ArtifactContentSource::class);
    $content->seed($team->slug, ARTIFACT_LINK_ID, '<h1>Hello</h1>');

    $response = test()
        ->actingAs($viewer)
        ->get("/settings/teams/{$team->slug}/console/artifacts/".ARTIFACT_LINK_ID.'/open');

    $response->assertRedirect();

    $location = (string) $response->headers->get('Location');

    expect($location)->toStartWith(
        'https://test-org--'.ARTIFACT_LINK_ID.'.artfct.dev/p/'.ARTIFACT_LINK_ID.'?token=',
    );

    parse_str((string) parse_url($location, PHP_URL_QUERY), $query);
    $token = $query['token'] ?? '';
    $now = now()->timestamp;

    expect($token)->toBeString()->not->toBe('')
        // The token is a credential for this artifact...
        ->and(artifactTokenVerifies($token, ARTIFACT_LINK_ID, ARTIFACT_LINK_SECRET, $now))->toBeTrue()
        // ...and not for another artifact id, nor under another secret.
        ->and(artifactTokenVerifies($token, 'ffffffffffffffffffffffffffffffff', ARTIFACT_LINK_SECRET, $now))->toBeFalse()
        ->and(artifactTokenVerifies($token, ARTIFACT_LINK_ID, 'some-other-secret', $now))->toBeFalse();

    // The token belongs to the redirect and nowhere else. The redirect's own
    // body is Symfony's no-JS fallback, which mirrors the Location URL, so the
    // check that matters is that the console page — props and all — carries
    // neither the token nor the signing secret.
    expect($location)->toContain($token);

    $console = test()->actingAs($viewer)->get("/settings/teams/{$team->slug}/console");

    expect($console->getContent())
        ->not->toContain($token)
        ->not->toContain(ARTIFACT_LINK_SECRET);
});

test('minted_link_pins_the_workers_token_wire_format', function () {
    // Independent vector: `printf '%s' '<id>.1700000000' | openssl dgst -sha256
    // -hmac 'fixed-secret' -r`. Pinned so the Laravel minter and the Worker's
    // `verify_access_token` cannot drift apart silently.
    $link = (new ArtifactAccessLink('fixed-secret', '.artfct.dev', 60))
        ->forArtifact('test-org', ARTIFACT_LINK_ID, now()->setTimestamp(1_700_000_000));

    expect($link)->toBe(
        'https://test-org--'.ARTIFACT_LINK_ID.'.artfct.dev/p/'.ARTIFACT_LINK_ID.'?token='.ARTIFACT_LINK_ID.'.1700000000.'
        .'e22334c0bc1f712b85eff0011dcb6979239521c4d2fa6ff7e9d598d55130911d'
    );

    // The primitive itself matches the vector the Rust implementation asserts
    // (`hmac_sha256_matches_known_test_vector`): RFC 4231 test case 1.
    expect(hash_hmac('sha256', 'Hi There', str_repeat(chr(0x0B), 20)))
        ->toBe('b0344c61d8db38535ca8afceaf0bf12b881dc200c9833da726e9376c2e32cff7');
});

test('open_is_refused_across_teams_without_revealing_whether_the_artifact_exists', function () {
    configureArtifactLinks();

    $team = Team::factory()->create(['slug' => 'team-1']);
    $otherTeam = Team::factory()->create(['slug' => 'team-2']);
    $member = memberOfTeam($team, TeamRole::Member);

    /** @var FakeArtifactContentSource $content */
    $content = app(ArtifactContentSource::class);
    // The artifact does exist — in the other team's org.
    $content->seed($otherTeam->slug, ARTIFACT_LINK_ID, '<h1>Not yours</h1>');

    // A member of team-1 has no business in team-2's console at all.
    test()->actingAs($member)
        ->get("/settings/teams/{$otherTeam->slug}/console/artifacts/".ARTIFACT_LINK_ID.'/open')
        ->assertNotFound();

    // Asking for an id that lives in another org is the same 404 as asking for
    // an id that exists nowhere, with no token minted.
    $foreign = test()->actingAs($member)
        ->get("/settings/teams/{$team->slug}/console/artifacts/".ARTIFACT_LINK_ID.'/open');
    $unknown = test()->actingAs($member)
        ->get("/settings/teams/{$team->slug}/console/artifacts/ffffffffffffffffffffffffffffffff/open");

    $foreign->assertNotFound();
    $unknown->assertNotFound();

    expect($foreign->headers->get('Location'))->toBeNull()
        ->and($foreign->getContent())->not->toContain(ARTIFACT_LINK_ID)
        ->and($foreign->getContent())->toBe($unknown->getContent());
});

test('open_fails_closed_and_hides_the_control_without_a_signing_secret', function () {
    config(['services.artifact_access.token_secret' => null]);

    $team = Team::factory()->create(['slug' => 'test-org']);
    $admin = memberOfTeam($team, TeamRole::Admin);

    /** @var FakeArtifactContentSource $content */
    $content = app(ArtifactContentSource::class);
    $content->seed($team->slug, ARTIFACT_LINK_ID, '<h1>Hello</h1>');

    test()->actingAs($admin)->get("/settings/teams/{$team->slug}/console")
        ->assertInertia(fn ($page) => $page->where('canOpenArtifacts', false));

    test()->actingAs($admin)
        ->get("/settings/teams/{$team->slug}/console/artifacts/".ARTIFACT_LINK_ID.'/open')
        ->assertStatus(503)
        ->assertHeaderMissing('Location');

    configureArtifactLinks();

    test()->actingAs($admin)->get("/settings/teams/{$team->slug}/console")
        ->assertInertia(fn ($page) => $page->where('canOpenArtifacts', true));
});

test('guest_cannot_open_artifact', function () {
    configureArtifactLinks();

    $team = Team::factory()->create(['slug' => 'test-org']);

    test()->get("/settings/teams/{$team->slug}/console/artifacts/".ARTIFACT_LINK_ID.'/open')
        ->assertRedirect(route('login'));
});
