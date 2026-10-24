<?php

use App\Actions\Teams\CreateTeam;
use App\Enums\PaymentStatus;
use App\Enums\Plan;
use App\Enums\TeamRole;
use App\Models\Team;
use App\Models\User;
use Illuminate\Testing\Fluent\AssertableJson;

/**
 * RUB-510: an invited Member clicking Allow access on the MCP OAuth consent
 * screen must leave the consent page with a code the client can redeem, and
 * that token must work against /mcp for the Member's team.
 *
 * Production observation (2026-10-22): POST /oauth/authorize returned 409 from
 * the Laravel origin. That status is the intentional Inertia external-redirect
 * response (`Inertia::location` → 409 + X-Inertia-Location) when the consent
 * form submits through Inertia's XHR; a non-Inertia POST must 302 instead.
 *
 * Fresh repro (same day): after abandoning one authorize URL and opening a
 * second with a new state/code_challenge, Allow reloaded the consent screen
 * showing the *abandoned* state's query string. Approve must honour the
 * request being submitted — never rebound to a prior authorize URL left in
 * `_previous.url` — and the redirected callback must carry the second state.
 */
const CURSOR_REDIRECT_URI = 'https://www.cursor.com/agents/mcp/oauth/callback';

/**
 * Owner creates a paid Team-plan workspace and invites a second user who
 * accepts as Member. The invitee also keeps a personal team (the multi-team
 * dropdown case reported in production).
 *
 * @return array{owner: User, member: User, team: Team, personalTeam: Team}
 */
function invitedMemberOnPaidTeam(): array
{
    $owner = User::factory()->create(['name' => 'Team Owner']);
    $team = app(CreateTeam::class)->handle($owner, 'artfct-dev');
    $team->forceFill([
        'plan' => Plan::Team,
        'payment_status' => PaymentStatus::Active,
        'stripe_subscription_id' => 'sub_test_team',
    ])->save();

    $member = User::factory()->create([
        'name' => 'Invited Member',
        'email' => 'member@example.com',
        'terms_version' => config('legal.terms_version'),
        'terms_accepted_at' => now(),
    ]);
    $personalTeam = app(CreateTeam::class)->handle($member, "{$member->name}'s Team", isPersonal: true);

    $invitation = $team->invitations()->create([
        'email' => $member->email,
        'role' => TeamRole::Member,
        'invited_by' => $owner->id,
        'expires_at' => now()->addDays(3),
    ]);

    test()->actingAs($member)
        ->post(route('invitations.accept', $invitation))
        ->assertRedirect();

    expect($team->memberships()->where('user_id', $member->id)->where('role', TeamRole::Member)->exists())->toBeTrue()
        ->and($member->fresh()->teamRole($team))->toBe(TeamRole::Member)
        ->and($member->teams()->pluck('teams.id'))->toContain($personalTeam->id, $team->id);

    // Leave the member's current team on the personal workspace so consent must
    // honour the explicit team query param, not currentTeam.
    $member->switchTeam($personalTeam);

    return [
        'owner' => $owner,
        'member' => $member->fresh(),
        'team' => $team->fresh(),
        'personalTeam' => $personalTeam->fresh(),
    ];
}

/**
 * @return array{client_id: string, verifier: string, state: string, parameters: array<string, mixed>}
 */
function cursorAuthorizeRequest(Team $team, string $statePrefix = 'cursor-oauth-state'): array
{
    $registration = test()->postJson('/oauth/register', [
        'client_name' => 'Cursor',
        'redirect_uris' => [CURSOR_REDIRECT_URI],
    ])->assertCreated();

    $verifier = str_repeat('c', 64);
    $state = $statePrefix.'-'.substr(hash('sha256', $verifier.$team->slug), 0, 16);

    return [
        'client_id' => $registration->json('client_id'),
        'verifier' => $verifier,
        'state' => $state,
        'parameters' => oauthParameters(oauthChallenge($verifier), [
            'client_id' => $registration->json('client_id'),
            'redirect_uri' => CURSOR_REDIRECT_URI,
            'scope' => 'artifacts:read artifacts:deploy collections:read collections:write usage:read',
            'state' => $state,
            'team' => $team->slug,
        ]),
    ];
}

/**
 * Register a public MCP client the way Cursor (and similar hosts) do, then
 * run the PKCE authorize → token → MCP initialize/tools.list path.
 *
 * @param  'inertia'|'redirect'  $submitMode
 * @return array{access_token: string, organization: string, location: string, state: string}
 */
function completeCursorStyleOAuth(User $user, Team $team, string $submitMode = 'inertia'): array
{
    configureSigning(testSigningKey());
    $request = cursorAuthorizeRequest($team, 'cursor-oauth-state-'.$user->id);
    $parameters = $request['parameters'];
    $query = array_diff_key($parameters, array_flip(['consent_token']));

    test()->actingAs($user)
        ->get('/oauth/authorize?'.http_build_query($query))
        ->assertOk()
        ->assertInertia(fn (AssertableJson $page) => $page
            ->component('oauth/authorize')
            ->where('clientName', 'Cursor')
            ->where('team.slug', $team->slug)
            ->where('state', $request['state']));

    $authorization = $submitMode === 'inertia'
        ? test()->actingAs($user)->post('/oauth/authorize', [
            ...$parameters,
            'decision' => 'approve',
            'team' => $team->slug,
        ], ['X-Inertia' => 'true'])
        : test()->actingAs($user)->post('/oauth/authorize', [
            ...$parameters,
            'decision' => 'approve',
            'team' => $team->slug,
        ]);

    if ($submitMode === 'inertia') {
        $authorization->assertStatus(409);
        $location = (string) $authorization->headers->get('X-Inertia-Location');
        expect($authorization->headers->get('Location'))->toBeNull();
    } else {
        $authorization->assertRedirect();
        $location = (string) $authorization->headers->get('Location');
    }

    expect($location)->toStartWith(CURSOR_REDIRECT_URI.'?');
    parse_str((string) parse_url($location, PHP_URL_QUERY), $callbackQuery);
    expect($callbackQuery['state'] ?? null)->toBe($request['state'])
        ->and($callbackQuery['code'] ?? null)->toBeString()->not->toBeEmpty();

    $token = test()->postJson('/oauth/token', [
        'grant_type' => 'authorization_code',
        'code' => $callbackQuery['code'],
        'client_id' => $request['client_id'],
        'redirect_uri' => CURSOR_REDIRECT_URI,
        'code_verifier' => $request['verifier'],
    ])->assertOk()
        ->assertJsonPath('token_type', 'Bearer')
        ->assertJsonPath('organization', $team->slug)
        ->assertJsonStructure(['access_token', 'refresh_token', 'expires_in']);

    $accessToken = $token->json('access_token');

    $organizations = test()->withToken($accessToken)
        ->getJson('/oauth/organizations')
        ->assertOk()
        ->json('organizations');

    expect(collect($organizations)->firstWhere('selected', true)['slug'] ?? null)->toBe($team->slug);

    $initialize = test()->withToken($accessToken)->postJson('/mcp', [
        'jsonrpc' => '2.0',
        'id' => 1,
        'method' => 'initialize',
        'params' => [
            'protocolVersion' => '2025-11-25',
            'clientInfo' => ['name' => 'cursor-rub-510', 'version' => '1.0.0'],
        ],
    ])->assertOk();

    test()->withToken($accessToken)->withHeaders([
        'MCP-Session-Id' => (string) $initialize->headers->get('MCP-Session-Id'),
    ])->postJson('/mcp', [
        'jsonrpc' => '2.0',
        'id' => 2,
        'method' => 'tools/list',
        'params' => [],
    ])->assertOk()
        ->assertJsonPath('result.tools.0.name', 'deploy_artifact');

    return [
        'access_token' => $accessToken,
        'organization' => $token->json('organization'),
        'location' => $location,
        'state' => $request['state'],
    ];
}

test('an invited Member completing Cursor OAuth consent is handed to the callback via Inertia location and can call MCP on that team', function () {
    ['member' => $member, 'team' => $team, 'personalTeam' => $personalTeam] = invitedMemberOnPaidTeam();

    expect($member->currentTeam?->is($personalTeam))->toBeTrue();

    $result = completeCursorStyleOAuth($member, $team, 'inertia');

    expect($result['organization'])->toBe($team->slug)
        ->and($result['location'])->toContain('code=')
        ->and($result['location'])->toContain('state=');
});

test('the team owner completing the same Cursor OAuth consent is a working control', function () {
    ['owner' => $owner, 'team' => $team] = invitedMemberOnPaidTeam();

    $result = completeCursorStyleOAuth($owner, $team, 'inertia');

    expect($result['organization'])->toBe($team->slug);
});

test('a non-Inertia Allow access for an invited Member redirects away with a 302 carrying the authorization code', function () {
    ['member' => $member, 'team' => $team] = invitedMemberOnPaidTeam();

    $result = completeCursorStyleOAuth($member, $team, 'redirect');

    expect($result['location'])->toStartWith(CURSOR_REDIRECT_URI.'?')
        ->and($result['organization'])->toBe($team->slug);
});

test('an invited Member who abandons one authorize flow then starts another is redirected with the second state', function () {
    ['member' => $member, 'team' => $team] = invitedMemberOnPaidTeam();
    configureSigning(testSigningKey());

    $registration = test()->postJson('/oauth/register', [
        'client_name' => 'Cursor',
        'redirect_uris' => [CURSOR_REDIRECT_URI],
    ])->assertCreated();
    $clientId = $registration->json('client_id');

    $abandonedVerifier = str_repeat('a', 64);
    $abandonedState = '303218582_abandonedPriorState';
    $abandoned = oauthParameters(oauthChallenge($abandonedVerifier), [
        'client_id' => $clientId,
        'redirect_uri' => CURSOR_REDIRECT_URI,
        'scope' => 'artifacts:read artifacts:deploy',
        'state' => $abandonedState,
        'team' => $team->slug,
    ]);

    $secondVerifier = str_repeat('b', 64);
    $secondState = 'cursor-fresh-state-second-attempt';
    $second = oauthParameters(oauthChallenge($secondVerifier), [
        'client_id' => $clientId,
        'redirect_uri' => CURSOR_REDIRECT_URI,
        'scope' => 'artifacts:read artifacts:deploy collections:read',
        'state' => $secondState,
        'team' => $team->slug,
    ]);

    $abandonedQuery = array_diff_key($abandoned, array_flip(['consent_token']));
    $secondQuery = array_diff_key($second, array_flip(['consent_token']));

    test()->actingAs($member)
        ->get('/oauth/authorize?'.http_build_query($abandonedQuery))
        ->assertOk();

    // A full-page GET stores the abandoned authorize URL as `_previous.url`.
    // Inertia's empty-200 handler would Redirect::back() there; Allow must not.
    expect((string) session()->get('_previous.url'))->toContain($abandonedState)
        ->and((string) session()->get('_previous.url'))->toContain($abandoned['code_challenge']);

    test()->actingAs($member)
        ->get('/oauth/authorize?'.http_build_query($secondQuery))
        ->assertOk()
        ->assertInertia(fn (AssertableJson $page) => $page
            ->where('state', $secondState)
            ->where('codeChallenge', $second['code_challenge']));

    $authorization = test()->actingAs($member)->post('/oauth/authorize', [
        ...$second,
        'decision' => 'approve',
        'team' => $team->slug,
    ], ['X-Inertia' => 'true']);

    $authorization->assertStatus(409);
    $location = (string) $authorization->headers->get('X-Inertia-Location');

    expect($location)->toStartWith(CURSOR_REDIRECT_URI.'?')
        ->and($location)->not->toContain($abandonedState)
        ->and($location)->not->toContain('/oauth/authorize');

    parse_str((string) parse_url($location, PHP_URL_QUERY), $query);
    expect($query['state'] ?? null)->toBe($secondState)
        ->and($query['code'] ?? null)->toBeString()->not->toBeEmpty();

    test()->postJson('/oauth/token', [
        'grant_type' => 'authorization_code',
        'code' => $query['code'],
        'client_id' => $clientId,
        'redirect_uri' => CURSOR_REDIRECT_URI,
        'code_verifier' => $secondVerifier,
    ])->assertOk()
        ->assertJsonPath('organization', $team->slug);
});
