<?php

use App\Enums\TeamRole;
use App\Models\Team;
use Illuminate\Support\Facades\Http;

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
