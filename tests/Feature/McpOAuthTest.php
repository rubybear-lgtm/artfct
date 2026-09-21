<?php

use App\Enums\TeamRole;
use App\Models\OAuthClient;
use App\Models\OAuthRefreshToken;
use App\Models\OrgToken;
use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Testing\Fluent\AssertableJson;
use Illuminate\Validation\ValidationException;

test('publishes MCP authorization metadata', function () {
    $this->getJson('/.well-known/oauth-authorization-server')
        ->assertOk()
        ->assertJson(fn (AssertableJson $json) => $json
            ->where('response_types_supported.0', 'code')
            ->where('grant_types_supported.0', 'authorization_code')
            ->where('code_challenge_methods_supported.0', 'S256')
            ->where('token_endpoint_auth_methods_supported.0', 'none')
            ->where('scopes_supported', [
                'artifacts:read',
                'artifacts:deploy',
                'artifacts:delete',
                'collections:read',
                'collections:write',
                'usage:read',
            ])
            ->where('registration_endpoint', route('oauth.register'))
            ->where('revocation_endpoint', route('oauth.revoke'))
            ->where('client_id_metadata_document_supported', false)
            ->etc());

    $this->getJson('/.well-known/oauth-protected-resource')
        ->assertOk()
        ->assertJsonPath('authorization_servers.0', config('services.org_jwt.issuer'))
        ->assertJsonPath('scopes_supported', [
            'artifacts:read',
            'artifacts:deploy',
            'artifacts:delete',
            'collections:read',
            'collections:write',
            'usage:read',
        ]);
});

test('OAuth metadata follows the configured JWT issuer contract', function () {
    config(['services.org_jwt.issuer' => 'https://issuer.example.test/']);

    $this->getJson('/.well-known/oauth-authorization-server')
        ->assertOk()
        ->assertJsonPath('issuer', 'https://issuer.example.test');

    $this->getJson('/.well-known/oauth-protected-resource/mcp')
        ->assertOk()
        ->assertJsonPath('authorization_servers.0', 'https://issuer.example.test');
});

test('a dedicated OAuth issuer overrides the org-token issuer in discovery documents', function () {
    config([
        'services.org_jwt.issuer' => 'https://artfct.dev',
        'services.oauth.issuer' => 'https://staging.example.test/',
    ]);

    $this->getJson('/.well-known/oauth-authorization-server')
        ->assertOk()
        ->assertJsonPath('issuer', 'https://staging.example.test');
    $this->getJson('/.well-known/oauth-protected-resource')
        ->assertOk()
        ->assertJsonPath('authorization_servers.0', 'https://staging.example.test');
});

test('registers a public client and pins its redirect URIs', function () {
    $response = $this->postJson('/oauth/register', [
        'client_name' => 'Acme Agent',
        'redirect_uris' => ['https://agent.acme.test/oauth/callback'],
    ]);

    $response->assertCreated()
        ->assertJsonPath('client_name', 'Acme Agent')
        ->assertJsonPath('token_endpoint_auth_method', 'none')
        ->assertJsonPath('grant_types', ['authorization_code', 'refresh_token']);

    expect(OAuthClient::query()->where('client_id', $response->json('client_id'))->exists())->toBeTrue();
});

test('bounds dynamic registration metadata and rate limits registration attempts', function () {
    config(['auth.oauth_registration_per_hour' => 3]);
    RateLimiter::clear('oauth-registration:127.0.0.1');

    $invalidName = $this->postJson('/oauth/register', [
        'client_name' => str_repeat('a', 129),
        'redirect_uris' => ['https://agent.example.test/callback'],
    ]);
    expect($invalidName->status())->toBe(400)
        ->and($invalidName->json('error'))->toBe('invalid_client_metadata');

    $duplicateRedirect = $this->postJson('/oauth/register', [
        'client_name' => 'Acme Agent',
        'redirect_uris' => [
            'https://agent.example.test/callback',
            'https://agent.example.test/callback',
        ],
    ]);
    expect($duplicateRedirect->status())->toBe(400)
        ->and($duplicateRedirect->json('error'))->toBe('invalid_client_metadata');

    $this->postJson('/oauth/register', [
        'client_name' => 'Acme Agent',
        'redirect_uris' => ['https://agent.example.test/callback'],
    ])->assertCreated();

    $this->postJson('/oauth/register', [
        'client_name' => 'Another Agent',
        'redirect_uris' => ['https://another.example.test/callback'],
    ])->assertTooManyRequests()->assertHeader('Retry-After');
});

test('a registered public client can complete the PKCE authorization flow', function () {
    $registration = $this->postJson('/oauth/register', [
        'client_name' => 'Hosted MCP Client',
        'redirect_uris' => ['https://client.example.test/oauth/callback'],
    ])->assertCreated();
    $clientId = $registration->json('client_id');
    $verifier = str_repeat('v', 64);
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->memberships()->create(['user_id' => $user->id, 'role' => TeamRole::Admin]);
    configureSigning(testSigningKey());
    $parameters = oauthParameters(oauthChallenge($verifier), [
        'client_id' => $clientId,
        'redirect_uri' => 'https://client.example.test/oauth/callback',
    ]);

    $authorization = $this->actingAs($user)->post('/oauth/authorize', [
        ...$parameters,
        'decision' => 'approve',
        'team' => $team->slug,
    ])->assertRedirect();
    parse_str((string) parse_url($authorization->headers->get('Location'), PHP_URL_QUERY), $query);

    $this->postJson('/oauth/token', [
        'grant_type' => 'authorization_code',
        'code' => $query['code'],
        'client_id' => $clientId,
        'redirect_uri' => $parameters['redirect_uri'],
        'code_verifier' => $verifier,
    ])->assertOk()->assertJsonStructure(['access_token', 'refresh_token']);
});

test('rejects a redirect URI that was not registered for the client', function () {
    $client = OAuthClient::factory()->create([
        'redirect_uris' => ['https://agent.acme.test/oauth/callback'],
    ]);

    $this->get('/oauth/authorize?'.http_build_query(oauthParameters('challenge', [
        'client_id' => $client->client_id,
        'redirect_uri' => 'https://agent.acme.test/other',
    ])))->assertRedirect();
});

test('requires a safe redirect URI and S256 PKCE', function () {
    $this->get('/oauth/authorize?'.http_build_query(oauthParameters('challenge', [
        'redirect_uri' => 'https://evil.example/callback',
    ])))->assertRedirect();

    $this->get('/oauth/authorize?'.http_build_query(oauthParameters('challenge', [
        'redirect_uri' => 'javascript:alert(1)',
    ])))->assertRedirect();

    $this->get('/oauth/authorize?'.http_build_query(oauthParameters('challenge', [
        'code_challenge_method' => 'plain',
    ])))->assertRedirect();
});

test('rejects approval when the consent form parameters were modified', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->memberships()->create(['user_id' => $user->id, 'role' => TeamRole::Admin]);

    $parameters = oauthParameters(oauthChallenge(str_repeat('v', 64)));

    $this->withoutExceptionHandling();

    expect(fn () => $this->actingAs($user)->postJson('/oauth/authorize', [
        ...$parameters,
        'scope' => 'artifacts:delete',
        'team' => $team->slug,
        'decision' => 'approve',
    ]))->toThrow(ValidationException::class, 'This authorization request is stale or has been modified. Start the connection again.');
});

test('redirects unauthenticated clients through the normal login flow', function () {
    $response = $this->get('/oauth/authorize?'.http_build_query(oauthParameters('challenge')));

    $response->assertRedirect(route('login'));
    expect(session()->get('_previous.url'))->toContain('/oauth/authorize');
});

test('approves a PKCE request and redeems its code once', function () {
    $verifier = str_repeat('v', 64);
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->memberships()->create(['user_id' => $user->id, 'role' => TeamRole::Admin]);
    $user->switchTeam($team);
    configureSigning(testSigningKey());

    $parameters = oauthParameters(oauthChallenge($verifier));
    $this->actingAs($user)->get('/oauth/authorize?'.http_build_query($parameters))
        ->assertOk()
        ->assertInertia(fn (AssertableJson $page) => $page
            ->component('oauth/authorize')
            ->where('clientId', 'artfct-cli')
            ->where('userName', $user->name)
            ->where('team.slug', $team->slug));

    $authorization = $this->actingAs($user)->post('/oauth/authorize', [
        ...$parameters,
        'decision' => 'approve',
        'team' => $team->slug,
    ]);

    $authorization->assertRedirect();
    parse_str((string) parse_url($authorization->headers->get('Location'), PHP_URL_QUERY), $query);
    expect($query['state'])->toBe('state-123')->and($query['code'])->toBeString();

    $token = $this->postJson('/oauth/token', [
        'grant_type' => 'authorization_code',
        'code' => $query['code'],
        'client_id' => 'artfct-cli',
        'redirect_uri' => $parameters['redirect_uri'],
        'code_verifier' => $verifier,
    ]);

    $token->assertOk()
        ->assertJsonPath('token_type', 'Bearer')
        ->assertJsonPath('organization', $team->slug)
        ->assertJsonPath('scope', 'artifacts:read artifacts:deploy')
        ->assertJsonStructure(['access_token', 'expires_in', 'refresh_token', 'refresh_token_expires_in']);
    expect(OrgToken::query()->where('team_id', $team->id)->count())->toBe(1);

    $this->withToken($token->json('access_token'))
        ->getJson('/oauth/organizations')
        ->assertOk()
        ->assertJsonPath('organizations.0.slug', $team->slug)
        ->assertJsonPath('organizations.0.role', 'admin')
        ->assertJsonPath('organizations.0.selected', true);

    $refreshToken = $token->json('refresh_token');
    $refreshed = $this->postJson('/oauth/token', [
        'grant_type' => 'refresh_token',
        'refresh_token' => $refreshToken,
        'client_id' => 'artfct-cli',
    ]);

    $refreshed->assertOk()
        ->assertJsonPath('token_type', 'Bearer')
        ->assertJsonPath('organization', $team->slug)
        ->assertJsonPath('scope', 'artifacts:read artifacts:deploy')
        ->assertJsonStructure(['access_token', 'refresh_token', 'expires_in']);
    expect(OAuthRefreshToken::query()->where('team_id', $team->id)->count())->toBe(2)
        ->and(OAuthRefreshToken::query()->where('team_id', $team->id)->whereNotNull('revoked_at')->count())->toBe(1);

    $this->postJson('/oauth/token', [
        'grant_type' => 'refresh_token',
        'refresh_token' => $refreshToken,
        'client_id' => 'artfct-cli',
    ])->assertStatus(400)->assertJsonPath('error', 'invalid_grant');

    expect(OAuthRefreshToken::query()->where('team_id', $team->id)->whereNotNull('revoked_at')->count())->toBe(2)
        ->and(OrgToken::query()->where('team_id', $team->id)->whereNotNull('revoked_at')->count())->toBe(2);

    $this->postJson('/oauth/revoke', [
        'token' => $refreshed->json('refresh_token'),
        'token_type_hint' => 'refresh_token',
        'client_id' => 'artfct-cli',
    ])->assertOk();

    expect(OAuthRefreshToken::query()->where('team_id', $team->id)->whereNotNull('revoked_at')->count())->toBe(2);

    $this->postJson('/oauth/revoke', [
        'token' => $refreshed->json('access_token'),
        'token_type_hint' => 'access_token',
        'client_id' => 'artfct-cli',
    ])->assertOk();

    expect(OrgToken::query()->where('team_id', $team->id)->whereNotNull('revoked_at')->count())->toBe(2);

    $this->postJson('/oauth/token', [
        'grant_type' => 'refresh_token',
        'refresh_token' => $refreshToken,
        'client_id' => 'artfct-cli',
    ])->assertStatus(400)->assertJsonPath('error', 'invalid_grant');

    $this->postJson('/oauth/token', [
        'grant_type' => 'authorization_code',
        'code' => $query['code'],
        'client_id' => 'artfct-cli',
        'redirect_uri' => $parameters['redirect_uri'],
        'code_verifier' => $verifier,
    ])->assertStatus(400)->assertJsonPath('error', 'invalid_grant');
});

test('does not mint a token with an invalid verifier', function () {
    $verifier = str_repeat('v', 64);
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->memberships()->create(['user_id' => $user->id, 'role' => TeamRole::Viewer]);
    configureSigning(testSigningKey());

    $parameters = oauthParameters(oauthChallenge($verifier), ['scope' => 'artifacts:read']);
    $authorization = $this->actingAs($user)->post('/oauth/authorize', [
        ...$parameters,
        'decision' => 'approve',
        'team' => $team->slug,
    ]);
    parse_str((string) parse_url($authorization->headers->get('Location'), PHP_URL_QUERY), $query);

    $this->postJson('/oauth/token', [
        'grant_type' => 'authorization_code',
        'code' => $query['code'],
        'client_id' => 'artfct-cli',
        'redirect_uri' => $parameters['redirect_uri'],
        'code_verifier' => str_repeat('x', 64),
    ])->assertStatus(400)->assertJsonPath('error', 'invalid_grant');

    expect(Cache::has('oauth:authorization-code:'.hash('sha256', $query['code'])))->toBeFalse();
});

test('does not mint mutation scopes for a viewer', function () {
    $verifier = str_repeat('v', 64);
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->memberships()->create(['user_id' => $user->id, 'role' => TeamRole::Viewer]);
    configureSigning(testSigningKey());

    $parameters = oauthParameters(oauthChallenge($verifier), [
        'scope' => 'artifacts:deploy',
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
        'client_id' => 'artfct-cli',
        'redirect_uri' => $parameters['redirect_uri'],
        'code_verifier' => $verifier,
    ])->assertStatus(400)->assertJsonPath('error', 'invalid_scope');

    expect(OrgToken::query()->where('team_id', $team->id)->exists())->toBeFalse();
});

test('denying a request returns an OAuth error without issuing a code', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->memberships()->create(['user_id' => $user->id, 'role' => TeamRole::Admin]);

    $parameters = oauthParameters(oauthChallenge(str_repeat('v', 64)));
    $response = $this->actingAs($user)->post('/oauth/authorize', [
        ...$parameters,
        'decision' => 'deny',
        'team' => $team->slug,
    ]);

    $response->assertRedirect();
    parse_str((string) parse_url($response->headers->get('Location'), PHP_URL_QUERY), $query);
    expect($query['error'])->toBe('access_denied')->and($query)->not->toHaveKey('code');
});

test('returns OAuth JSON errors for malformed token requests', function () {
    $response = $this->postJson('/oauth/token', [])->assertStatus(400)
        ->assertJsonPath('error', 'invalid_request');

    expect($response->headers->get('Cache-Control'))->toContain('no-store');
});
