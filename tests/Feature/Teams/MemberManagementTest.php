<?php

use App\Enums\AuditEventType;
use App\Enums\TeamRole;
use App\Models\AuditEvent;
use App\Models\McpConnection;
use App\Models\OAuthRefreshToken;
use App\Models\OrgToken;
use App\Models\Team;
use App\Models\User;
use App\Services\Auth\OrgJwtService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;

/** @return array{string, OrgToken, McpConnection, OAuthRefreshToken} */
function memberManagementOrgToken(Team $team, User $user, TeamRole $role): array
{
    $minted = OrgJwtService::default()->mint($team, $user, $role, 3600);
    $token = OrgToken::factory()->create([
        'team_id' => $team->id,
        'user_id' => $user->id,
        'jti' => $minted['jti'],
        'role' => $role,
        'expires_at' => $minted['expires_at'],
    ]);
    $connection = McpConnection::factory()->create([
        'team_id' => $team->id,
        'user_id' => $user->id,
        'credential_jti' => $minted['jti'],
    ]);
    $refreshToken = OAuthRefreshToken::factory()->create([
        'team_id' => $team->id,
        'user_id' => $user->id,
        'mcp_connection_id' => $connection->id,
    ]);

    return [$minted['token'], $token, $connection, $refreshToken];
}

test('a_role_change_takes_effect_and_is_audited', function () {
    $team = Team::factory()->create();
    $admin = memberOfTeam($team, TeamRole::Admin);
    $member = memberOfTeam($team, TeamRole::Member);

    test()->actingAs($admin)->patch(route('teams.members.update', [$team, $member]), ['role' => 'viewer'])->assertRedirect();

    expect($team->memberships()->where('user_id', $member->id)->value('role'))->toBe(TeamRole::Viewer)
        ->and(AuditEvent::query()->where('team_id', $team->id)->where('event_type', AuditEventType::RoleChanged)->exists())->toBeTrue();
});

test('downgrading_a_member_revokes_their_org_and_oauth_tokens', function () {
    configureOrgJwt();
    Http::fake([
        'https://worker.test/v1/internal/revocations' => Http::response(['revoked' => true], 200),
    ]);

    $team = Team::factory()->create();
    $owner = memberOfTeam($team, TeamRole::Admin);
    $member = memberOfTeam($team, TeamRole::Admin);
    [$rawToken, $token, $connection, $refreshToken] = memberManagementOrgToken($team, $member, TeamRole::Admin);

    $this->withToken($rawToken)->getJson('/api/collections')->assertOk();
    $this->actingAs($owner)
        ->patch(route('teams.members.update', [$team, $member]), ['role' => 'viewer'])
        ->assertRedirect();

    expect($token->fresh()->revoked_at)->not->toBeNull()
        ->and($connection->fresh()->revoked_at)->not->toBeNull()
        ->and($refreshToken->fresh()->revoked_at)->not->toBeNull();

    $this->withToken($rawToken)->getJson('/api/collections')->assertUnauthorized();

    Http::assertSent(fn ($request): bool => $request->url() === 'https://worker.test/v1/internal/revocations'
        && $request['jti'] === $token->jti
        && $request->hasHeader('Authorization', 'Bearer test-revocation-secret'));
});

test('removing_a_member_is_immediate_and_audited', function () {
    $team = Team::factory()->create();
    $admin = memberOfTeam($team, TeamRole::Admin);
    $member = memberOfTeam($team, TeamRole::Member);

    test()->actingAs($admin)->delete(route('teams.members.destroy', [$team, $member]))->assertRedirect();

    expect($team->memberships()->where('user_id', $member->id)->exists())->toBeFalse()
        ->and(AuditEvent::query()->where('team_id', $team->id)->where('event_type', AuditEventType::MemberRemoved)->exists())->toBeTrue();
});

test('removal_and_self_leave_revoke_the_members_org_and_oauth_tokens', function (string $change) {
    configureOrgJwt();
    Http::fake([
        'https://worker.test/v1/internal/revocations' => Http::response(['revoked' => true], 200),
    ]);

    $team = Team::factory()->create();
    $admin = memberOfTeam($team, TeamRole::Admin);
    $member = memberOfTeam($team, TeamRole::Member);
    [$rawToken, $token, $connection, $refreshToken] = memberManagementOrgToken($team, $member, TeamRole::Member);

    $this->withToken($rawToken)->getJson('/api/collections')->assertOk();

    if ($change === 'removal') {
        $this->actingAs($admin)->delete(route('teams.members.destroy', [$team, $member]))->assertRedirect();
    } else {
        $this->actingAs($member)->delete(route('teams.leave', $team))->assertRedirect();
    }

    expect($token->fresh()->revoked_at)->not->toBeNull()
        ->and($connection->fresh()->revoked_at)->not->toBeNull()
        ->and($refreshToken->fresh()->revoked_at)->not->toBeNull();

    $this->withToken($rawToken)->getJson('/api/collections')->assertUnauthorized();

    Http::assertSent(fn ($request): bool => $request->url() === 'https://worker.test/v1/internal/revocations'
        && $request['jti'] === $token->jti
        && $request->hasHeader('Authorization', 'Bearer test-revocation-secret'));
})->with(['removal', 'self-leave']);

test('membership_changes_fail_closed_when_the_worker_denylist_write_fails', function (string $change) {
    configureOrgJwt();
    Http::fake([
        'https://worker.test/v1/internal/revocations' => Http::response(['error' => 'unavailable'], 503),
    ]);

    $team = Team::factory()->create();
    $owner = memberOfTeam($team, TeamRole::Admin);
    $member = memberOfTeam($team, TeamRole::Member);
    [$rawToken, $token, $connection, $refreshToken] = memberManagementOrgToken($team, $member, TeamRole::Member);

    $this->withToken($rawToken)->getJson('/api/collections')->assertOk();

    if ($change === 'role-change') {
        $response = $this->actingAs($owner)
            ->patch(route('teams.members.update', [$team, $member]), ['role' => 'viewer']);
    } elseif ($change === 'removal') {
        $response = $this->actingAs($owner)
            ->delete(route('teams.members.destroy', [$team, $member]));
    } else {
        $response = $this->actingAs($member)
            ->delete(route('teams.leave', $team));
    }

    $response->assertStatus(503);

    expect($team->memberships()->where('user_id', $member->id)->value('role'))->toBe(TeamRole::Member)
        ->and($token->fresh()->revoked_at)->toBeNull()
        ->and($connection->fresh()->revoked_at)->toBeNull()
        ->and($refreshToken->fresh()->revoked_at)->toBeNull();

    $this->withToken($rawToken)->getJson('/api/collections')->assertOk();

    Http::assertSent(fn ($request): bool => $request->url() === 'https://worker.test/v1/internal/revocations'
        && $request['jti'] === $token->jti
        && $request->hasHeader('Authorization', 'Bearer test-revocation-secret'));
})->with(['role-change', 'removal', 'self-leave']);

test('membership_changes_retry_worker_revocation_for_locally_revoked_unexpired_tokens', function () {
    configureOrgJwt();
    Http::fake([
        'https://worker.test/v1/internal/revocations' => Http::response(['revoked' => true], 200),
    ]);

    $team = Team::factory()->create();
    $owner = memberOfTeam($team, TeamRole::Admin);
    $member = memberOfTeam($team, TeamRole::Member);
    $token = OrgToken::factory()->for($team)->for($member)->create([
        'jti' => 'previously-revoked-jti',
        'revoked_at' => now()->subMinute(),
        'expires_at' => now()->addHour(),
    ]);

    $this->actingAs($owner)
        ->patch(route('teams.members.update', [$team, $member]), ['role' => 'viewer'])
        ->assertRedirect();

    expect($team->memberships()->where('user_id', $member->id)->value('role'))->toBe(TeamRole::Viewer);

    Http::assertSent(fn ($request): bool => $request->url() === 'https://worker.test/v1/internal/revocations'
        && $request['jti'] === $token->jti
        && $request->hasHeader('Authorization', 'Bearer test-revocation-secret'));
});

test('members_cannot_change_roles', function () {
    $team = Team::factory()->create();
    $member = memberOfTeam($team, TeamRole::Member);
    $other = memberOfTeam($team, TeamRole::Member);

    test()->actingAs($member)->patch(route('teams.members.update', [$team, $other]), ['role' => 'admin'])->assertForbidden();
});

test('deactivated_members_are_flagged_in_the_member_list', function () {
    $team = Team::factory()->create();
    $admin = memberOfTeam($team, TeamRole::Admin);
    $gone = memberOfTeam($team, TeamRole::Member);
    $gone->forceFill(['deactivated_at' => now()])->save();

    test()->actingAs($admin)->get(route('teams.edit', $team))
        ->assertInertia(fn (Assert $page) => $page
            ->where('members', fn ($members) => collect($members)->firstWhere('id', $gone->id)['deactivated'] === true));
});

test('available_roles_only_lists_roles_the_viewer_can_grant', function () {
    $team = Team::factory()->create();
    $member = memberOfTeam($team, TeamRole::Member);

    test()->actingAs($member)->get(route('teams.edit', $team))
        ->assertInertia(fn (Assert $page) => $page
            ->where('availableRoles', fn ($roles) => collect($roles)->pluck('value')->values()->all() === ['member', 'viewer']));
});

test('a_member_inviting_a_higher_role_is_refused', function () {
    Notification::fake();
    $team = Team::factory()->create();
    $member = memberOfTeam($team, TeamRole::Member);

    test()->actingAs($member)
        ->post(route('teams.invitations.store', $team), ['email' => 'new@example.com', 'role' => 'admin'])
        ->assertForbidden();

    expect($team->invitations()->count())->toBe(0);
    Notification::assertNothingSent();
});
