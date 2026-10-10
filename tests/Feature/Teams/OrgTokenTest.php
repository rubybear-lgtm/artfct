<?php

use App\Enums\TeamRole;
use App\Models\McpConnection;
use App\Models\OrgToken;
use App\Models\Team;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Illuminate\Support\Facades\Http;

test('token_creation_returns_value_once_only', function () {
    configureOrgJwt();
    $team = Team::factory()->create();
    $admin = memberOfTeam($team, TeamRole::Admin);

    $response = test()->actingAs($admin)->postJson("/settings/teams/{$team->slug}/tokens", [
        'name' => 'CI deploy token',
        'role' => 'admin',
    ]);

    $response->assertCreated();
    $rawToken = $response->json('token');
    expect($rawToken)->toBeString()->not->toBeEmpty();

    $claims = (array) JWT::decode($rawToken, new Key(ORG_JWT_TEST_PUBLIC_KEY, 'RS256'));
    expect($claims['org_id'])->toBe($team->slug);
    expect($claims['role'])->toBe('admin');

    $stored = OrgToken::query()->findOrFail($response->json('id'));
    expect($stored->jti)->toBe($claims['jti']);
    // The raw token is never persisted — only jti and a display fragment.
    expect($stored->getAttributes())->not->toHaveKey('token');
    expect(str_ends_with($rawToken, $stored->last_four))->toBeTrue();

    // A second read of the same resource never surfaces the raw value again.
    expect($stored->toArray())->not->toContain($rawToken);
});

test('token_revocation_writes_denylist', function () {
    configureOrgJwt();
    Http::fake([
        'https://worker.test/v1/internal/revocations' => Http::response(['revoked' => true], 200),
    ]);

    $team = Team::factory()->create();
    $admin = memberOfTeam($team, TeamRole::Admin);
    $token = OrgToken::factory()->for($team)->for($admin)->create(['jti' => 'jti-under-test']);
    $connection = McpConnection::factory()->for($team)->for($admin)->create();
    $connection->forceFill(['credential_jti' => $token->jti])->save();

    $response = test()->actingAs($admin)->deleteJson("/settings/teams/{$team->slug}/tokens/{$token->id}");

    $response->assertOk();
    expect($token->fresh()->revoked_at)->not->toBeNull();
    expect($connection->fresh()->revoked_at)->not->toBeNull();

    Http::assertSent(function ($request) {
        return $request->url() === 'https://worker.test/v1/internal/revocations'
            && $request['jti'] === 'jti-under-test'
            && $request->hasHeader('Authorization', 'Bearer test-revocation-secret');
    });
});

test('token_revocation_does_not_claim_success_when_worker_denylist_fails', function () {
    configureOrgJwt();
    Http::fake([
        'https://worker.test/v1/internal/revocations' => Http::response(['error' => 'unavailable'], 503),
    ]);

    $team = Team::factory()->create();
    $admin = memberOfTeam($team, TeamRole::Admin);
    $token = OrgToken::factory()->for($team)->for($admin)->create(['jti' => 'jti-worker-unavailable']);
    $connection = McpConnection::factory()->for($team)->for($admin)->create([
        'credential_jti' => $token->jti,
    ]);

    $response = test()->actingAs($admin)->deleteJson("/settings/teams/{$team->slug}/tokens/{$token->id}");

    $response->assertStatus(503);
    expect($token->fresh()->revoked_at)->toBeNull()
        ->and($connection->fresh()->revoked_at)->toBeNull();
});

test('token_revocation_fails_closed_when_worker_connection_throws', function () {
    configureOrgJwt();
    Http::fake([
        'https://worker.test/v1/internal/revocations' => Http::failedConnection(),
    ]);

    $team = Team::factory()->create();
    $admin = memberOfTeam($team, TeamRole::Admin);
    $token = OrgToken::factory()->for($team)->for($admin)->create();

    test()->actingAs($admin)->deleteJson("/settings/teams/{$team->slug}/tokens/{$token->id}")
        ->assertStatus(503);

    expect($token->fresh()->revoked_at)->toBeNull();
});

test('token_revocation_through_an_inertia_visit_redirects_with_a_toast', function () {
    configureOrgJwt();
    Http::fake([
        'https://worker.test/v1/internal/revocations' => Http::response(['revoked' => true], 200),
    ]);

    $team = Team::factory()->create();
    $admin = memberOfTeam($team, TeamRole::Admin);
    $token = OrgToken::factory()->for($team)->for($admin)->create();

    test()->actingAs($admin)->delete("/settings/teams/{$team->slug}/tokens/{$token->id}")
        ->assertRedirect()
        ->assertInertiaFlash('toast', [
            'type' => 'success',
            'message' => 'Token revoked. Anything using it stops working within a minute.',
        ]);

    expect($token->fresh()->revoked_at)->not->toBeNull();
});

test('token_revocation_with_the_headers_inertia_sends_redirects_instead_of_returning_json', function () {
    configureOrgJwt();
    Http::fake([
        'https://worker.test/v1/internal/revocations' => Http::response(['revoked' => true], 200),
    ]);

    $team = Team::factory()->create();
    $admin = memberOfTeam($team, TeamRole::Admin);
    $token = OrgToken::factory()->for($team)->for($admin)->create();

    test()->actingAs($admin)
        ->withHeaders([
            'X-Inertia' => 'true',
            'X-Requested-With' => 'XMLHttpRequest',
            'Accept' => 'text/html, application/xhtml+xml',
        ])
        ->delete("/settings/teams/{$team->slug}/tokens/{$token->id}")
        ->assertRedirect();

    expect($token->fresh()->revoked_at)->not->toBeNull();
});

test('a_failed_inertia_revocation_redirects_with_an_error_toast', function () {
    configureOrgJwt();
    Http::fake([
        'https://worker.test/v1/internal/revocations' => Http::response(['error' => 'unavailable'], 503),
    ]);

    $team = Team::factory()->create();
    $admin = memberOfTeam($team, TeamRole::Admin);
    $token = OrgToken::factory()->for($team)->for($admin)->create();

    test()->actingAs($admin)->delete("/settings/teams/{$team->slug}/tokens/{$token->id}")
        ->assertRedirect()
        ->assertInertiaFlash('toast', [
            'type' => 'error',
            'message' => "Couldn't revoke the token right now. It still works, so try again in a minute.",
        ]);

    expect($token->fresh()->revoked_at)->toBeNull();
});

test('member_cannot_revoke_another_users_token', function () {
    configureOrgJwt();
    Http::fake([
        'https://worker.test/v1/internal/revocations' => Http::response(['revoked' => true], 200),
    ]);
    $team = Team::factory()->create();
    $owner = memberOfTeam($team, TeamRole::Admin);
    $otherMember = memberOfTeam($team, TeamRole::Member);
    $token = OrgToken::factory()->for($team)->for($owner)->create();

    $response = test()->actingAs($otherMember)->deleteJson("/settings/teams/{$team->slug}/tokens/{$token->id}");

    $response->assertForbidden();
    expect($token->fresh()->revoked_at)->toBeNull();
});

test('member_cannot_mint_an_admin_token', function () {
    $team = Team::factory()->create(['slug' => 'acme']);
    $member = memberOfTeam($team, TeamRole::Member);

    test()->actingAs($member)->postJson("/settings/teams/{$team->slug}/tokens", ['name' => 'sneaky', 'role' => 'admin'])->assertForbidden();

    expect(OrgToken::query()->where('team_id', $team->id)->count())->toBe(0);
});

test('member_can_mint_a_member_or_viewer_token', function () {
    $team = Team::factory()->create(['slug' => 'acme']);
    $member = memberOfTeam($team, TeamRole::Member);
    configureOrgJwt();

    test()->actingAs($member)->postJson("/settings/teams/{$team->slug}/tokens", ['name' => 'ok', 'role' => 'viewer'])->assertCreated();
});

test('viewer_cannot_create_tokens', function () {
    $team = Team::factory()->create(['slug' => 'acme']);
    $viewer = memberOfTeam($team, TeamRole::Viewer);

    test()->actingAs($viewer)->postJson("/settings/teams/{$team->slug}/tokens", ['name' => 'nope', 'role' => 'viewer'])->assertForbidden();
});

test('sending_invitations_is_rate_limited_per_team', function () {
    config(['auth.invitations_per_hour' => 2]);
    $team = Team::factory()->create();
    $admin = memberOfTeam($team, TeamRole::Admin);

    foreach (['a', 'b'] as $name) {
        test()->actingAs($admin)->post(route('teams.invitations.store', $team), ['email' => "{$name}@example.com", 'role' => 'member'])->assertRedirect();
    }

    test()->actingAs($admin)->post(route('teams.invitations.store', $team), ['email' => 'c@example.com', 'role' => 'member'])->assertStatus(429);
});
