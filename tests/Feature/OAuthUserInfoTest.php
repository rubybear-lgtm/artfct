<?php

use App\Enums\McpScope;
use App\Enums\OAuthIdentityScope;
use App\Enums\OrgTokenKind;
use App\Enums\TeamRole;
use App\Mcp\Servers\ArtfctServer;
use App\Models\OrgToken;
use App\Models\Team;
use App\Models\User;
use App\Services\Artifacts\ArtifactPublisher;
use App\Services\Auth\OrgJwtService;
use App\Services\Billing\PlanGateException;
use Illuminate\Support\Carbon;
use Illuminate\Testing\Fluent\AssertableJson;
use Laravel\Mcp\Server\Transport\FakeTransporter;

/**
 * @param  list<string>  $scopes
 * @return array{token: string, jti: string, user: User, team: Team}
 */
function mintUserinfoToken(User $user, Team $team, array $scopes, int $ttlSeconds = 300): array
{
    configureSigning(testSigningKey());
    $role = $user->teamRole($team) ?? TeamRole::Member;
    $minted = OrgJwtService::default()->mint($team, $user, $role, $ttlSeconds, $scopes);

    OrgToken::query()->create([
        'team_id' => $team->id,
        'user_id' => $user->id,
        'name' => 'UserInfo test',
        'kind' => OrgTokenKind::Api,
        'jti' => $minted['jti'],
        'role' => $role,
        'scopes' => $scopes,
        'last_four' => substr($minted['token'], -4),
        'expires_at' => $minted['expires_at'],
    ]);

    return [
        'token' => $minted['token'],
        'jti' => $minted['jti'],
        'user' => $user,
        'team' => $team,
    ];
}

test('authorization_server_metadata_advertises_userinfo_and_identity_scopes', function () {
    $this->getJson('/.well-known/oauth-authorization-server')
        ->assertOk()
        ->assertJsonPath('userinfo_endpoint', route('oauth.userinfo'))
        ->assertJson(fn (AssertableJson $json) => $json
            ->where('scopes_supported', OAuthIdentityScope::withMcpScopes())
            ->etc());

    $this->getJson('/.well-known/oauth-protected-resource')
        ->assertOk()
        ->assertJsonPath('scopes_supported', OAuthIdentityScope::withMcpScopes());
});

test('userinfo_returns_sub_email_and_verified_for_openid_email_token', function () {
    $user = User::factory()->create([
        'email' => 'verified@example.test',
        'email_verified_at' => now(),
    ]);
    $team = Team::factory()->create();
    $team->memberships()->create(['user_id' => $user->id, 'role' => TeamRole::Admin]);

    $issued = mintUserinfoToken($user, $team, [
        OAuthIdentityScope::OpenId->value,
        OAuthIdentityScope::Email->value,
        McpScope::ArtifactsRead->value,
    ]);

    $response = $this->withToken($issued['token'])
        ->getJson('/oauth/userinfo')
        ->assertOk()
        ->assertExactJson([
            'sub' => (string) $user->id,
            'email' => 'verified@example.test',
            'email_verified' => true,
        ]);

    expect($response->headers->get('Cache-Control'))->toContain('no-store');
});

test('userinfo_omits_email_without_email_scope', function () {
    $user = User::factory()->create([
        'email' => 'openid-only@example.test',
        'email_verified_at' => now(),
    ]);
    $team = Team::factory()->create();
    $team->memberships()->create(['user_id' => $user->id, 'role' => TeamRole::Admin]);

    $issued = mintUserinfoToken($user, $team, [
        OAuthIdentityScope::OpenId->value,
        McpScope::ArtifactsRead->value,
    ]);

    $response = $this->withToken($issued['token'])
        ->getJson('/oauth/userinfo')
        ->assertOk()
        ->assertJson([
            'sub' => (string) $user->id,
            'email_verified' => true,
        ]);

    expect($response->json())->not->toHaveKey('email');
});

test('userinfo_reports_unverified_email_as_false', function () {
    $user = User::factory()->unverified()->create([
        'email' => 'unverified@example.test',
    ]);
    $team = Team::factory()->create();
    $team->memberships()->create(['user_id' => $user->id, 'role' => TeamRole::Admin]);

    $issued = mintUserinfoToken($user, $team, [
        OAuthIdentityScope::OpenId->value,
        OAuthIdentityScope::Email->value,
    ]);

    $this->withToken($issued['token'])
        ->getJson('/oauth/userinfo')
        ->assertOk()
        ->assertExactJson([
            'sub' => (string) $user->id,
            'email' => 'unverified@example.test',
            'email_verified' => false,
        ]);
});

test('userinfo_rejects_missing_expired_and_revoked_tokens', function () {
    $user = User::factory()->create(['email_verified_at' => now()]);
    $team = Team::factory()->create();
    $team->memberships()->create(['user_id' => $user->id, 'role' => TeamRole::Admin]);

    $this->getJson('/oauth/userinfo')
        ->assertUnauthorized()
        ->assertHeader('WWW-Authenticate', 'Bearer error="invalid_token"')
        ->assertJsonPath('error', 'invalid_token');

    Carbon::setTestNow('2026-01-01 12:00:00');
    $expired = mintUserinfoToken($user, $team, [OAuthIdentityScope::OpenId->value], ttlSeconds: 60);
    Carbon::setTestNow('2026-01-01 12:05:00');

    $this->withToken($expired['token'])
        ->getJson('/oauth/userinfo')
        ->assertUnauthorized()
        ->assertHeader('WWW-Authenticate', 'Bearer error="invalid_token"')
        ->assertJsonPath('error', 'invalid_token');

    Carbon::setTestNow();

    $revoked = mintUserinfoToken($user, $team, [OAuthIdentityScope::OpenId->value]);
    OrgToken::query()->where('jti', $revoked['jti'])->update(['revoked_at' => now()]);

    $this->withToken($revoked['token'])
        ->getJson('/oauth/userinfo')
        ->assertUnauthorized()
        ->assertHeader('WWW-Authenticate', 'Bearer error="invalid_token"')
        ->assertJsonPath('error', 'invalid_token');
});

test('userinfo_rejects_token_without_openid_scope', function () {
    $user = User::factory()->create(['email_verified_at' => now()]);
    $team = Team::factory()->create();
    $team->memberships()->create(['user_id' => $user->id, 'role' => TeamRole::Admin]);

    $issued = mintUserinfoToken($user, $team, [
        McpScope::ArtifactsRead->value,
        OAuthIdentityScope::Email->value,
    ]);

    $this->withToken($issued['token'])
        ->getJson('/oauth/userinfo')
        ->assertForbidden()
        ->assertHeader('WWW-Authenticate', 'Bearer error="insufficient_scope"')
        ->assertJsonPath('error', 'insufficient_scope');
});

test('userinfo_does_not_leak_another_users_email', function () {
    $alice = User::factory()->create([
        'email' => 'alice@example.test',
        'email_verified_at' => now(),
    ]);
    $bob = User::factory()->create([
        'email' => 'bob@example.test',
        'email_verified_at' => now(),
    ]);
    $team = Team::factory()->create();
    $team->memberships()->create(['user_id' => $alice->id, 'role' => TeamRole::Admin]);
    $team->memberships()->create(['user_id' => $bob->id, 'role' => TeamRole::Member]);

    $issued = mintUserinfoToken($alice, $team, [
        OAuthIdentityScope::OpenId->value,
        OAuthIdentityScope::Email->value,
    ]);

    $this->withToken($issued['token'])
        ->getJson('/oauth/userinfo')
        ->assertOk()
        ->assertJsonPath('email', 'alice@example.test')
        ->assertJsonMissing(['email' => 'bob@example.test']);
});

test('viewer_role_can_grant_identity_scopes', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->memberships()->create(['user_id' => $user->id, 'role' => TeamRole::Viewer]);

    $this->actingAs($user)->get('/oauth/authorize?'.http_build_query(oauthParameters('challenge', [
        'scope' => 'openid email artifacts:read',
        'team' => $team->slug,
    ])))->assertOk()
        ->assertInertia(fn (AssertableJson $page) => $page
            ->component('oauth/authorize')
            ->where('requestedScopes.0.value', 'openid')
            ->where('requestedScopes.0.label', 'Confirm who you are')
            ->where('requestedScopes.0.risk', 'read')
            ->where('requestedScopes.1.value', 'email')
            ->where('requestedScopes.1.label', 'See your email address')
            ->where('requestedScopes.2.value', 'artifacts:read'));
});

test('identity_scopes_are_not_in_mcp_scope_values_or_default_claims', function () {
    expect(McpScope::values())->toHaveCount(9)
        ->and(McpScope::values())->not->toContain('openid', 'email')
        ->and(OAuthIdentityScope::values())->toBe(['openid', 'email']);

    foreach ([TeamRole::Admin, TeamRole::Member, TeamRole::Viewer] as $role) {
        expect(McpScope::forRole($role))->not->toContain('openid', 'email')
            ->and(McpScope::claimForRole($role))->not->toContain('openid')
            ->and(McpScope::claimForRole($role))->not->toContain('email');
    }
});

test('identity_scopes_appear_in_token_only_when_requested', function () {
    $verifier = str_repeat('v', 64);
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->memberships()->create(['user_id' => $user->id, 'role' => TeamRole::Admin]);
    configureSigning(testSigningKey());

    $withoutIdentity = oauthParameters(oauthChallenge($verifier), [
        'scope' => 'artifacts:read',
    ]);
    $authorization = $this->actingAs($user)->post('/oauth/authorize', [
        ...$withoutIdentity,
        'decision' => 'approve',
        'team' => $team->slug,
    ]);
    parse_str((string) parse_url($authorization->headers->get('Location'), PHP_URL_QUERY), $query);

    $token = $this->postJson('/oauth/token', [
        'grant_type' => 'authorization_code',
        'code' => $query['code'],
        'client_id' => 'test-native-client',
        'redirect_uri' => $withoutIdentity['redirect_uri'],
        'code_verifier' => $verifier,
    ])->assertOk();

    expect($token->json('scope'))->toBe('artifacts:read')
        ->and($token->json('scope'))->not->toContain('openid')
        ->and($token->json('scope'))->not->toContain('email');

    $claims = OrgJwtService::default()->verify($token->json('access_token'));
    expect($claims['scope'])->toBe('artifacts:read');

    $withIdentityVerifier = str_repeat('w', 64);
    $withIdentity = oauthParameters(oauthChallenge($withIdentityVerifier), [
        'scope' => 'openid email artifacts:read',
        'state' => 'state-identity',
    ]);
    $authorization = $this->actingAs($user)->post('/oauth/authorize', [
        ...$withIdentity,
        'decision' => 'approve',
        'team' => $team->slug,
    ]);
    parse_str((string) parse_url($authorization->headers->get('Location'), PHP_URL_QUERY), $identityQuery);

    $identityToken = $this->postJson('/oauth/token', [
        'grant_type' => 'authorization_code',
        'code' => $identityQuery['code'],
        'client_id' => 'test-native-client',
        'redirect_uri' => $withIdentity['redirect_uri'],
        'code_verifier' => $withIdentityVerifier,
    ])->assertOk();

    expect($identityToken->json('scope'))->toBe('openid email artifacts:read');
});

test('existing_scope_strings_still_authorize', function () {
    $verifier = str_repeat('v', 64);
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->memberships()->create(['user_id' => $user->id, 'role' => TeamRole::Admin]);
    configureSigning(testSigningKey());

    // Claude Code / Cursor style request: MCP scopes only, no identity scopes.
    $parameters = oauthParameters(oauthChallenge($verifier), [
        'scope' => 'artifacts:read artifacts:deploy collections:read usage:read',
    ]);

    $authorization = $this->actingAs($user)->post('/oauth/authorize', [
        ...$parameters,
        'decision' => 'approve',
        'team' => $team->slug,
    ]);
    parse_str((string) parse_url($authorization->headers->get('Location'), PHP_URL_QUERY), $query);

    $this->postJson('/oauth/token', [
        'grant_type' => 'authorization_code',
        'code' => $query['code'],
        'client_id' => 'test-native-client',
        'redirect_uri' => $parameters['redirect_uri'],
        'code_verifier' => $verifier,
    ])->assertOk()
        ->assertJsonPath('scope', 'artifacts:read artifacts:deploy collections:read usage:read');
});

test('openai_challenge_returns_configured_value_as_plain_text', function () {
    config(['services.openai.apps_challenge' => 'portal-challenge-token']);

    $this->get('/.well-known/openai-apps-challenge')
        ->assertOk()
        ->assertHeader('Content-Type', 'text/plain; charset=UTF-8')
        ->assertSee('portal-challenge-token', escape: false);
});

test('openai_challenge_is_404_when_unconfigured', function () {
    config(['services.openai.apps_challenge' => '']);

    $this->get('/.well-known/openai-apps-challenge')->assertNotFound();
});

test('plan_gate_message_has_no_plan_name_or_upgrade_text', function () {
    $message = (new PlanGateException('Search by description'))->getMessage();

    expect($message)->toBe("Search by description isn't available for this workspace. Ask a workspace admin.")
        ->and($message)->not->toMatch('/\b(upgrade|pricing|checkout|plans?|paid|Enterprise)\b/i');
});

test('mcp_surface_contains_no_pricing_or_plan_wording', function () {
    // Spec 22 DoD: ChatGPT-reachable tool/consent/error surfaces must not
    // carry plan/upgrade/pricing/checkout/paid/billing wording. Error codes
    // such as plan_upgrade_required are out of scope for this check.
    $pattern = '/\b(upgrade|pricing|checkout|plans?|paid|billing)\b/i';
    $violations = [];

    $flag = static function (string $where, string $text) use ($pattern, &$violations): void {
        if (preg_match($pattern, $text) === 1) {
            $violations[] = $where;
        }
    };

    foreach (McpScope::cases() as $scope) {
        $flag("McpScope::{$scope->name} label", $scope->label());
    }

    foreach (OAuthIdentityScope::cases() as $scope) {
        $flag("OAuthIdentityScope::{$scope->name} label", $scope->label());
    }

    $flag('PlanGateException message', (new PlanGateException('Feature'))->getMessage());

    $publisher = new ReflectionClass(ArtifactPublisher::class);
    $source = (string) file_get_contents($publisher->getFileName());
    if (preg_match('/quota_exceeded.{0,120}\b(upgrade|pricing|checkout|plans?|paid|billing)\b/is', $source) === 1) {
        $violations[] = 'ArtifactPublisher quota_exceeded message';
    }

    $server = app(ArtfctServer::class, ['transport' => new FakeTransporter]);
    $server->start();

    foreach ($server->createContext()->tools() as $tool) {
        $payload = $tool->toArray();
        $name = $tool->name();
        $flag("tool {$name} description", (string) ($payload['description'] ?? ''));

        foreach ($payload['inputSchema']['properties'] ?? [] as $property => $schema) {
            if (isset($schema['description']) && is_string($schema['description'])) {
                $flag("tool {$name} schema.{$property}", $schema['description']);
            }
        }
    }

    expect($violations)->toBe([]);
});
