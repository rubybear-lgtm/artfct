<?php

use App\Enums\AuthMode;
use App\Enums\Plan;
use App\Enums\TeamRole;
use App\Models\ExternalIdentity;
use App\Models\McpConnection;
use App\Models\OAuthRefreshToken;
use App\Models\OrgToken;
use App\Models\Team;
use App\Models\User;
use App\Support\TeamSignIn;
use Illuminate\Support\Facades\Http;

/**
 * @return array{0: Team, 1: User}
 */
function polisSwitchableTeam(): array
{
    $team = Team::factory()->create([
        'slug' => 'acme',
        'auth_mode' => AuthMode::AuthKit,
        'plan' => Plan::Enterprise,
    ]);
    $team->domains()->create(['domain' => 'acme.com', 'verified_at' => now()]);
    $admin = User::factory()->create(['email' => 'ada@acme.com']);
    $team->memberships()->create(['user_id' => $admin->id, 'role' => TeamRole::Admin]);
    ExternalIdentity::factory()->for($admin)->polis()->create(['verified_at' => now()]);

    return [$team, $admin];
}

/**
 * Runs the PKCE flow without a Polis session marker, so the refresh token
 * is AuthKit-origin (`polis_proven = false`).
 *
 * @return array{access_token: string, refresh_token: string}
 */
function authKitOriginMcpTokens(Team $team, User $user): array
{
    $verifier = str_repeat('m', 64);
    configureSigning(testSigningKey());
    $parameters = oauthParameters(oauthChallenge($verifier), ['team' => $team->slug]);

    $authorization = test()->actingAs($user)->post('/oauth/authorize', [
        ...$parameters,
        'decision' => 'approve',
        'team' => $team->slug,
    ]);
    parse_str((string) parse_url($authorization->headers->get('Location'), PHP_URL_QUERY), $query);

    $token = test()->postJson('/oauth/token', [
        'grant_type' => 'authorization_code',
        'code' => $query['code'],
        'client_id' => 'test-native-client',
        'redirect_uri' => $parameters['redirect_uri'],
        'code_verifier' => $verifier,
    ])->assertOk();

    return ['access_token' => $token->json('access_token'), 'refresh_token' => $token->json('refresh_token')];
}

test('an authkit origin refresh token is refused for a polis-only team', function () {
    configureOrgJwt();
    Http::fake([
        'https://worker.test/v1/internal/revocations' => Http::response(['revoked' => true], 200),
    ]);

    [$team, $admin] = polisSwitchableTeam();
    $tokens = authKitOriginMcpTokens($team, $admin);

    expect(OAuthRefreshToken::query()->where('team_id', $team->id)->whereNull('revoked_at')->value('polis_proven'))->toBeFalse();

    // Flip auth_mode without the controller so the refresh-path check is what
    // refuses the token — removing that check makes this assertion fail.
    $team->forceFill(['auth_mode' => AuthMode::Polis])->save();

    $this->postJson('/oauth/token', [
        'grant_type' => 'refresh_token',
        'refresh_token' => $tokens['refresh_token'],
        'client_id' => 'test-native-client',
    ])->assertStatus(400)
        ->assertJsonPath('error', 'invalid_grant');

    expect(OAuthRefreshToken::query()->where('team_id', $team->id)->whereNull('revoked_at')->exists())->toBeFalse()
        ->and(McpConnection::query()->where('team_id', $team->id)->whereNull('revoked_at')->exists())->toBeFalse();
});

test('switching a team to polis revokes authkit origin mcp credentials', function () {
    configureOrgJwt();
    Http::fake([
        'https://worker.test/v1/internal/revocations' => Http::response(['revoked' => true], 200),
    ]);

    [$team, $admin] = polisSwitchableTeam();
    $tokens = authKitOriginMcpTokens($team, $admin);
    $orgToken = OrgToken::query()->where('team_id', $team->id)->firstOrFail();
    $connection = McpConnection::query()->where('team_id', $team->id)->firstOrFail();
    $refresh = OAuthRefreshToken::query()
        ->where('team_id', $team->id)
        ->where('token_hash', hash('sha256', $tokens['refresh_token']))
        ->firstOrFail();

    $this->actingAs($admin)
        ->withSession([TeamSignIn::SESSION_KEY => [$team->id => $admin->id]])
        ->patch(route('teams.auth-mode.update', $team), ['auth_mode' => 'polis', 'confirmed' => true])
        ->assertRedirect(route('teams.authentication.show', $team));

    expect($team->fresh()->auth_mode)->toBe(AuthMode::Polis)
        ->and($orgToken->fresh()->revoked_at)->not->toBeNull()
        ->and($connection->fresh()->revoked_at)->not->toBeNull()
        ->and($refresh->fresh()->revoked_at)->not->toBeNull();

    $this->postJson('/oauth/token', [
        'grant_type' => 'refresh_token',
        'refresh_token' => $tokens['refresh_token'],
        'client_id' => 'test-native-client',
    ])->assertStatus(400)->assertJsonPath('error', 'invalid_grant');

    Http::assertSent(fn ($request): bool => $request->url() === 'https://worker.test/v1/internal/revocations'
        && $request['jti'] === $orgToken->jti);
});

test('a polis proven refresh token still rotates on a polis-only team', function () {
    configureOrgJwt();
    Http::fake([
        'https://worker.test/v1/internal/revocations' => Http::response(['revoked' => true], 200),
    ]);

    [$team, $admin] = polisSwitchableTeam();
    $team->forceFill(['auth_mode' => AuthMode::Dual])->save();

    $verifier = str_repeat('p', 64);
    $parameters = oauthParameters(oauthChallenge($verifier), ['team' => $team->slug]);

    $authorization = $this->actingAs($admin)
        ->withSession([TeamSignIn::SESSION_KEY => [$team->id => $admin->id]])
        ->post('/oauth/authorize', [
            ...$parameters,
            'decision' => 'approve',
            'team' => $team->slug,
        ]);
    parse_str((string) parse_url($authorization->headers->get('Location'), PHP_URL_QUERY), $query);

    $token = $this->postJson('/oauth/token', [
        'grant_type' => 'authorization_code',
        'code' => $query['code'],
        'client_id' => 'test-native-client',
        'redirect_uri' => $parameters['redirect_uri'],
        'code_verifier' => $verifier,
    ])->assertOk();

    expect(OAuthRefreshToken::query()->where('team_id', $team->id)->whereNull('revoked_at')->value('polis_proven'))->toBeTrue();

    $team->forceFill(['auth_mode' => AuthMode::Polis])->save();

    $this->postJson('/oauth/token', [
        'grant_type' => 'refresh_token',
        'refresh_token' => $token->json('refresh_token'),
        'client_id' => 'test-native-client',
    ])->assertOk()
        ->assertJsonStructure(['access_token', 'refresh_token']);
});

test('switching to polis is refused when worker revocation fails', function () {
    configureOrgJwt();
    Http::fake([
        'https://worker.test/v1/internal/revocations' => Http::response(['error' => 'no'], 503),
    ]);

    [$team, $admin] = polisSwitchableTeam();
    authKitOriginMcpTokens($team, $admin);

    $this->actingAs($admin)
        ->withSession([TeamSignIn::SESSION_KEY => [$team->id => $admin->id]])
        ->patch(route('teams.auth-mode.update', $team), ['auth_mode' => 'polis', 'confirmed' => true])
        ->assertSessionHasErrors('auth_mode');

    expect($team->fresh()->auth_mode)->toBe(AuthMode::AuthKit)
        ->and(OAuthRefreshToken::query()->where('team_id', $team->id)->whereNull('revoked_at')->exists())->toBeTrue();
});
