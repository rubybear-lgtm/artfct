<?php

use App\Actions\Teams\CreateTeam;
use App\Contracts\AnalyticsContract;
use App\Enums\OrgTokenKind;
use App\Enums\Plan;
use App\Enums\TeamRole;
use App\Models\Team;
use App\Models\User;
use App\Services\Analytics\FakeAnalytics;
use App\Services\Analytics\ProductAnalytics;
use App\Services\AuthKit\AuthKitProfile;
use App\Services\AuthKit\FakeAuthKitClient;

beforeEach(function () {
    config([
        'analytics.enabled' => true,
        'analytics.api_key' => 'phc_test',
        'analytics.environment' => 'local',
        'services.authkit.dev_login_enabled' => true,
    ]);

    /** @var FakeAnalytics $analytics */
    $analytics = app(AnalyticsContract::class);
    $analytics->reset();
});

test('C1 user_signed_up and user_logged_in fire on AuthKit callback branches', function () {
    $code = FakeAuthKitClient::codeFor(new AuthKitProfile(
        'ext-signup-1',
        'MagicAuth',
        'new-user@example.com',
        true,
        'New User',
        null,
        null,
    ));

    test()->get(route('authenticate', ['code' => $code]))->assertRedirect();

    /** @var FakeAnalytics $analytics */
    $analytics = app(AnalyticsContract::class);
    $signup = $analytics->eventsNamed(ProductAnalytics::USER_SIGNED_UP);
    expect($signup)->toHaveCount(1);
    $user = User::query()->where('email', 'new-user@example.com')->firstOrFail();
    expect($signup[0]['distinct_id'])->toBe((string) $user->id)
        ->and($analytics->eventsNamed(ProductAnalytics::USER_LOGGED_IN))->toBeEmpty();

    $analytics->reset();
    auth()->logout();

    $code2 = FakeAuthKitClient::codeFor(new AuthKitProfile(
        'ext-signup-1',
        'MagicAuth',
        'new-user@example.com',
        true,
        'New User',
        null,
        null,
    ));

    test()->get(route('authenticate', ['code' => $code2]))->assertRedirect();

    expect($analytics->eventsNamed(ProductAnalytics::USER_LOGGED_IN))->toHaveCount(1)
        ->and($analytics->eventsNamed(ProductAnalytics::USER_SIGNED_UP))->toBeEmpty()
        ->and($analytics->eventsNamed(ProductAnalytics::USER_LOGGED_IN)[0]['distinct_id'])->toBe((string) $user->id);
});

test('C2 team_created and team_upgraded fire on web paths', function () {
    $user = User::factory()->create();
    app(CreateTeam::class)->handle($user, "{$user->name}'s Team", isPersonal: true);

    test()->actingAs($user)->post(route('teams.store'), ['name' => 'Lifecycle Co'])->assertRedirect();

    /** @var FakeAnalytics $analytics */
    $analytics = app(AnalyticsContract::class);
    $created = $analytics->eventsNamed(ProductAnalytics::TEAM_CREATED);
    expect($created)->toHaveCount(1)
        ->and($created[0]['properties']['team_slug'])->not->toBeEmpty()
        ->and($created[0]['properties']['is_personal'])->toBeFalse();

    $analytics->reset();
    $personal = $user->fresh()->personalTeam();

    test()->actingAs($user)->post(route('teams.upgrade', $personal), ['name' => 'Upgraded Co'])->assertRedirect();

    expect($analytics->eventsNamed(ProductAnalytics::TEAM_UPGRADED))->toHaveCount(1)
        ->and($analytics->eventsNamed(ProductAnalytics::TEAM_UPGRADED)[0]['properties']['is_personal'])->toBeFalse();
});

test('C3 invite_sent and member_joined omit invitee email', function () {
    $team = Team::factory()->create(['plan' => Plan::Team, 'slug' => 'invite-analytics']);
    $admin = memberOfTeam($team, TeamRole::Admin);

    test()->actingAs($admin)->post(route('teams.invitations.store', $team), [
        'email' => 'invitee@example.com',
        'role' => 'member',
    ])->assertRedirect();

    /** @var FakeAnalytics $analytics */
    $analytics = app(AnalyticsContract::class);
    $invite = $analytics->eventsNamed(ProductAnalytics::INVITE_SENT)[0]['properties'];
    expect($invite)->not->toHaveKey('email')
        ->and($invite)->not->toHaveKey('invitee_email');

    $invitee = User::factory()->create(['email' => 'invitee@example.com']);
    $invitation = $team->invitations()->where('email', 'invitee@example.com')->firstOrFail();

    test()->actingAs($invitee)->post(route('invitations.accept', $invitation))->assertRedirect();

    $joined = $analytics->eventsNamed(ProductAnalytics::MEMBER_JOINED)[0]['properties'];
    expect($joined)->not->toHaveKey('email')
        ->and($joined['team_slug'])->toBe('invite-analytics');
});

test('C4 bot_connected fires when creating an agent org token', function () {
    config([
        'services.org_jwt.private_key' => testSigningKey(),
        'services.org_jwt.kid' => 'analytics-kid',
        'services.org_jwt.issuer' => 'https://artfct.dev',
        'services.org_jwt.audience' => 'artfct-engine',
    ]);

    $team = Team::factory()->create(['plan' => Plan::Team, 'slug' => 'bot-connect']);
    $admin = memberOfTeam($team, TeamRole::Admin);
    $bot = User::factory()->create(['is_bot' => true, 'name' => 'Grok']);
    $team->memberships()->create(['user_id' => $bot->id, 'role' => TeamRole::Member]);

    test()->actingAs($admin)->postJson(route('teams.tokens.store', $team), [
        'name' => 'Grok token',
        'kind' => OrgTokenKind::Agent->value,
        'bot_id' => $bot->id,
        'scopes' => ['artifacts:read', 'artifacts:deploy'],
        'ttl_seconds' => 3600,
    ])->assertSuccessful();

    /** @var FakeAnalytics $analytics */
    $analytics = app(AnalyticsContract::class);
    $events = $analytics->eventsNamed(ProductAnalytics::BOT_CONNECTED);
    expect($events)->toHaveCount(1)
        ->and($events[0]['properties']['actor_is_bot'])->toBeTrue()
        ->and($events[0]['properties']['source'])->toBe('web');
});

test('C4 human OAuth code exchange does not fire bot_connected', function () {
    configureSigning(testSigningKey());

    $team = Team::factory()->create(['plan' => Plan::Team, 'slug' => 'human-oauth-analytics']);
    $user = User::factory()->create(['is_bot' => false]);
    $team->memberships()->create(['user_id' => $user->id, 'role' => TeamRole::Admin]);
    $user->switchTeam($team);

    $verifier = str_repeat('v', 64);
    $parameters = oauthParameters(oauthChallenge($verifier));

    test()->actingAs($user)->get('/oauth/authorize?'.http_build_query($parameters))->assertOk();

    $authorization = test()->actingAs($user)->post('/oauth/authorize', [
        ...$parameters,
        'decision' => 'approve',
        'team' => $team->slug,
    ]);
    $authorization->assertRedirect();
    parse_str((string) parse_url($authorization->headers->get('Location'), PHP_URL_QUERY), $query);
    expect($query['code'] ?? null)->toBeString();

    /** @var FakeAnalytics $analytics */
    $analytics = app(AnalyticsContract::class);
    $analytics->reset();

    test()->postJson('/oauth/token', [
        'grant_type' => 'authorization_code',
        'code' => $query['code'],
        'client_id' => 'test-native-client',
        'redirect_uri' => $parameters['redirect_uri'],
        'code_verifier' => $verifier,
    ])->assertOk();

    expect($analytics->eventsNamed(ProductAnalytics::BOT_CONNECTED))->toBeEmpty();
});

test('C5 artifact_viewed and artifact_deleted fire on worker-events path', function () {
    config(['services.worker_events.secret' => 'test-secret']);

    $team = Team::factory()->create(['plan' => Plan::Team, 'slug' => 'worker-analytics']);

    postWorkerEvent([
        'type' => 'artifact.viewed',
        'org_id' => $team->slug,
        'data' => [
            'artifact_id' => ARTIFACT_LINK_ID,
            'viewer_user_id' => null,
            'viewer_key' => 'anon-key-1',
        ],
    ])->assertAccepted();

    postWorkerEvent([
        'type' => 'artifact.deleted',
        'org_id' => $team->slug,
        'data' => ['artifact_id' => ARTIFACT_LINK_ID],
    ])->assertAccepted();

    /** @var FakeAnalytics $analytics */
    $analytics = app(AnalyticsContract::class);
    expect($analytics->eventsNamed(ProductAnalytics::ARTIFACT_VIEWED))->toHaveCount(1)
        ->and($analytics->eventsNamed(ProductAnalytics::ARTIFACT_DELETED))->toHaveCount(1)
        ->and($analytics->eventsNamed(ProductAnalytics::ARTIFACT_VIEWED)[0]['properties']['source'])->toBe('worker');
});
