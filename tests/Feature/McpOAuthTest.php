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

test('consent carries the risk level the server defines for each requested scope', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->memberships()->create(['user_id' => $user->id, 'role' => TeamRole::Admin]);

    $this->actingAs($user)->get('/oauth/authorize?'.http_build_query(oauthParameters('challenge', [
        'scope' => 'artifacts:read artifacts:deploy artifacts:delete',
        'team' => $team->slug,
    ])))->assertOk()
        ->assertInertia(fn (AssertableJson $page) => $page
            ->component('oauth/authorize')
            ->where('requestedScopes.0.value', 'artifacts:read')
            ->where('requestedScopes.0.label', 'Read artifacts and search your workspace')
            ->where('requestedScopes.0.risk', 'read')
            ->where('requestedScopes.1.value', 'artifacts:deploy')
            ->where('requestedScopes.1.risk', 'write')
            ->where('requestedScopes.2.value', 'artifacts:delete')
            ->where('requestedScopes.2.label', 'Delete artifacts from your workspace')
            ->where('requestedScopes.2.risk', 'destructive'));
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
    // These assert the validation error rather than the redirect: an
    // unauthenticated request redirects either way, so `assertRedirect` alone
    // passed whether the URI was accepted or refused.
    $this->get('/oauth/authorize?'.http_build_query(oauthParameters('challenge', [
        'redirect_uri' => 'https://evil.example/callback',
    ])))->assertSessionHasErrors('redirect_uri');

    $this->get('/oauth/authorize?'.http_build_query(oauthParameters('challenge', [
        'redirect_uri' => 'javascript:alert(1)',
    ])))->assertSessionHasErrors('redirect_uri');

    $this->get('/oauth/authorize?'.http_build_query(oauthParameters('challenge', [
        'code_challenge_method' => 'plain',
    ])))->assertSessionHasErrors('code_challenge');
});

test('rejects a non-loopback redirect URI for the built-in CLI client', function () {
    // The built-in CLI's client_id is public and anyone can type it, so it has
    // no registered redirect list to pin it to. Without the loopback rule this
    // request is accepted and the authorization code is delivered to whatever
    // HTTPS host the requester named.
    $this->get('/oauth/authorize?'.http_build_query(oauthParameters('challenge', [
        'redirect_uri' => 'https://attacker.example/oauth/callback',
    ])))->assertSessionHasErrors('redirect_uri');
});

test('issues no authorization code for a non-loopback CLI redirect', function () {
    // The full attack needs an authenticated approval, so this is the half the
    // unauthenticated test above cannot speak to. approve() validates the
    // request before it mints anything, so the attack dies before a code
    // exists: none is written and the attacker's host is never handed one.
    // Asserted as a thrown exception because a ValidationException on this
    // route renders as a redirect, which no status code can distinguish from
    // the successful redirect the vulnerable code produced.
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->memberships()->create(['user_id' => $user->id, 'role' => TeamRole::Admin]);

    $this->withoutExceptionHandling();

    expect(fn () => $this->actingAs($user)->postJson('/oauth/authorize', [
        ...oauthParameters(oauthChallenge(str_repeat('v', 64)), [
            'redirect_uri' => 'https://evil.example/callback',
        ]),
        'decision' => 'approve',
        'team' => $team->slug,
    ]))->toThrow(ValidationException::class, 'The redirect URI is not allowed.');
});

test('rejects a redirect URI that PHP and browsers parse differently', function () {
    // parse_url reads this as host 127.0.0.1 (loopback); a browser reads host
    // evil.example and hands the authorization code to it. A loopback rule
    // cannot rest on parse_url's host while the browser decides the
    // destination, so the raw string is matched instead.
    $redirectUri = 'http://evil.example\@127.0.0.1/cb';

    $this->get('/oauth/authorize?'.http_build_query(oauthParameters('challenge', [
        'redirect_uri' => $redirectUri,
    ])))->assertSessionHasErrors('redirect_uri');

    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->memberships()->create(['user_id' => $user->id, 'role' => TeamRole::Admin]);

    $this->withoutExceptionHandling();

    expect(fn () => $this->actingAs($user)->postJson('/oauth/authorize', [
        ...oauthParameters(oauthChallenge(str_repeat('v', 64)), ['redirect_uri' => $redirectUri]),
        'decision' => 'approve',
        'team' => $team->slug,
    ]))->toThrow(ValidationException::class, 'The redirect URI is not allowed.');
});

test('rejects a redirect URI carrying userinfo or a fragment', function (string $redirectUri) {
    $this->get('/oauth/authorize?'.http_build_query(oauthParameters('challenge', [
        'redirect_uri' => $redirectUri,
    ])))->assertSessionHasErrors('redirect_uri');
})->with([
    'userinfo only, which the old three-key guard let through' => ['http://127.0.0.1@evil.example/cb'],
    'fragment, which RFC 6749 section 3.1.2 forbids' => ['https://evil.example/cb#@127.0.0.1'],
]);

test('refuses to register a redirect URI carrying userinfo or a fragment', function (string $redirectUri) {
    // This is the branch the three-key guard actually governed. It was an &&
    // in disguise -- isset($parts['user'], $parts['pass'], $parts['fragment'])
    // -- so a URI carrying only `user` satisfied none of it and was stored.
    $response = $this->postJson('/oauth/register', [
        'client_name' => 'Sketchy Agent',
        'redirect_uris' => [$redirectUri],
    ]);

    expect($response->status())->toBe(400)
        ->and($response->json('error'))->toBe('invalid_client_metadata');
})->with([
    'plain userinfo' => ['https://evil.example@127.0.0.1/cb'],
    'userinfo plus a raw backslash' => ['https://evil.example\@127.0.0.1/cb'],
    'fragment' => ['https://evil.example/cb#frag'],
]);

test('accepts a loopback redirect URI for the built-in CLI client', function () {
    // The real CLI binds an ephemeral loopback port, so this is the shape it
    // actually sends; the rule must not cut it off.
    $this->get('/oauth/authorize?'.http_build_query(oauthParameters('challenge', [
        'redirect_uri' => 'http://127.0.0.1:43123/oauth/callback',
    ])))->assertRedirect(route('login'))->assertSessionHasNoErrors();
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

test('non-browser OAuth endpoints are exempt from CSRF', function (string $uri) {
    app()['env'] = 'local';

    expect($this->postJson($uri, [])->getStatusCode())->not->toBe(419);
})->with(['/oauth/token', '/oauth/revoke', '/oauth/register']);

test('the consent approval stays protected by CSRF', function () {
    app()['env'] = 'local';

    $this->actingAs(User::factory()->create())->post('/oauth/authorize', [])->assertStatus(419);
});

test('the path-suffixed protected-resource document names the same authorization server and only exists for the MCP resource', function () {
    config([
        'services.org_jwt.issuer' => 'https://artfct.dev',
        'services.oauth.issuer' => 'https://staging.example.test',
    ]);

    $this->getJson('/.well-known/oauth-protected-resource/mcp')
        ->assertOk()
        ->assertJsonPath('resource', url('/mcp'))
        ->assertJsonPath('authorization_servers.0', 'https://staging.example.test');

    $this->getJson('/.well-known/oauth-protected-resource/anything-else')->assertNotFound();
});

test('browser-based MCP clients can reach discovery, registration, token and MCP endpoints cross-origin', function () {
    $origin = 'http://localhost:6274';

    foreach (['/.well-known/oauth-protected-resource/mcp', '/.well-known/oauth-authorization-server'] as $uri) {
        $this->withHeaders(['Origin' => $origin])->get($uri)
            ->assertOk()
            ->assertHeader('Access-Control-Allow-Origin', '*');
    }

    foreach (['/oauth/register', '/oauth/token', '/oauth/revoke', '/mcp'] as $uri) {
        $this->withHeaders([
            'Origin' => $origin,
            'Access-Control-Request-Method' => 'POST',
            'Access-Control-Request-Headers' => 'authorization,content-type,mcp-protocol-version',
        ])->call('OPTIONS', $uri)
            ->assertSuccessful()
            ->assertHeader('Access-Control-Allow-Origin', '*');
    }

    $this->withHeaders(['Origin' => $origin])->postJson('/mcp', [])
        ->assertUnauthorized()
        ->assertHeader('Access-Control-Allow-Origin', '*')
        ->assertHeader('Access-Control-Expose-Headers');

    expect($this->withHeaders(['Origin' => $origin])->get('/oauth/authorize')->headers->has('Access-Control-Allow-Origin'))->toBeFalse();
    expect($this->withHeaders(['Origin' => $origin])->get('/login')->headers->has('Access-Control-Allow-Origin'))->toBeFalse();
});

test('the path-inserted authorization-server document is served for the MCP resource only', function () {
    $this->getJson('/.well-known/oauth-authorization-server/mcp')->assertOk()->assertJsonPath('code_challenge_methods_supported.0', 'S256');
    $this->getJson('/.well-known/oauth-authorization-server/other')->assertNotFound();
});

test('the consent page hands the browser to the client callback with a location visit instead of a cross-origin redirect', function () {
    $verifier = str_repeat('i', 64);
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->memberships()->create(['user_id' => $user->id, 'role' => TeamRole::Admin]);
    $user->switchTeam($team);
    configureSigning(testSigningKey());
    $client = OAuthClient::factory()->create(['client_id' => 'inspector-client', 'redirect_uris' => ['http://localhost:6274/oauth/callback']]);
    $parameters = oauthParameters(oauthChallenge($verifier), ['client_id' => $client->client_id, 'redirect_uri' => 'http://localhost:6274/oauth/callback']);

    foreach (['approve', 'deny'] as $decision) {
        $response = $this->actingAs($user)->withHeaders(['X-Inertia' => 'true'])->post('/oauth/authorize', [
            ...$parameters,
            'decision' => $decision,
            'team' => $team->slug,
        ]);

        $response->assertStatus(409);
        expect($response->headers->get('X-Inertia-Location'))->toStartWith('http://localhost:6274/oauth/callback?')
            ->and($response->headers->get('X-Inertia-Location'))->toContain($decision === 'approve' ? 'code=' : 'error=access_denied');
    }
});
