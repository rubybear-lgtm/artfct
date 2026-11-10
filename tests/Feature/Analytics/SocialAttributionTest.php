<?php

use App\Actions\Teams\CreateTeam;
use App\Contracts\AnalyticsContract;
use App\Models\Team;
use App\Models\User;
use App\Services\Analytics\FakeAnalytics;
use App\Services\Analytics\FirstTouchAttribution;
use App\Services\Analytics\ProductAnalytics;
use App\Services\AuthKit\AuthKitProfile;
use App\Services\AuthKit\FakeAuthKitClient;

beforeEach(function () {
    config([
        'analytics.enabled' => true,
        'analytics.api_key' => 'phc_test',
        'analytics.environment' => 'local',
        'analytics.internal.team_slugs' => ['ruben-tortoleros-team'],
        'analytics.internal.email_domains' => ['artfct.dev'],
        'analytics.internal.user_ids' => [],
        'services.authkit.dev_login_enabled' => true,
    ]);

    /** @var FakeAnalytics $analytics */
    $analytics = app(AnalyticsContract::class);
    $analytics->reset();
});

test('S1 first-touch UTMs persist in the session across AuthKit and are not overwritten', function () {
    test()->get('/?utm_source=linkedin&utm_medium=social&utm_campaign=launch&utm_content=20261110-li-grok-bot')
        ->assertOk();

    $first = app(FirstTouchAttribution::class)->get();
    expect($first['utm_source'])->toBe('linkedin')
        ->and($first['utm_medium'])->toBe('social')
        ->and($first['utm_campaign'])->toBe('launch')
        ->and($first['utm_content'])->toBe('20261110-li-grok-bot')
        ->and($first['landing_path'])->toBe('/');

    test()->get('/docs?utm_source=x&utm_medium=social')->assertOk();
    expect(app(FirstTouchAttribution::class)->get()['utm_source'])->toBe('linkedin');

    $code = FakeAuthKitClient::codeFor(new AuthKitProfile(
        'ext-attr-1',
        'MagicAuth',
        'attr@example.com',
        true,
        'Attr User',
        null,
        null,
    ));

    test()->get(route('authenticate', ['code' => $code]))->assertRedirect();

    // Session regenerate keeps first-touch; signup $set_once used it.
    $user = User::query()->where('email', 'attr@example.com')->firstOrFail();
    expect($user->acquisition['initial_utm_source'] ?? null)->toBe('linkedin')
        ->and($user->acquisition['initial_utm_content'] ?? null)->toBe('20261110-li-grok-bot');
});

test('S2 user_signed_up uses $set_once initials and login does not overwrite', function () {
    test()->get('/docs?utm_source=x&utm_medium=social&utm_campaign=docs')->assertOk();

    $code = FakeAuthKitClient::codeFor(new AuthKitProfile(
        'ext-attr-2',
        'MagicAuth',
        'setonce@example.com',
        true,
        'Set Once',
        null,
        null,
    ));
    test()->get(route('authenticate', ['code' => $code]))->assertRedirect();

    /** @var FakeAnalytics $analytics */
    $analytics = app(AnalyticsContract::class);
    $signup = $analytics->eventsNamed(ProductAnalytics::USER_SIGNED_UP)[0];
    expect($signup['properties']['$set_once']['initial_utm_source'])->toBe('x')
        ->and($signup['properties']['initial_landing_path'])->toBe('/docs');

    $identifies = array_values(array_filter($analytics->records, fn (array $r): bool => $r['type'] === 'identify'));
    expect($identifies[0]['set_once']['initial_utm_source'])->toBe('x')
        // FakeAnalytics keeps set_once separate; PostHogIdentifySetOnceTest
        // asserts the real posthog-php wire shape ($set_once sibling of $set).
        ->and($identifies[0]['properties'])->not->toHaveKey('$set_once');

    $analytics->reset();
    auth()->logout();

    $code2 = FakeAuthKitClient::codeFor(new AuthKitProfile(
        'ext-attr-2',
        'MagicAuth',
        'setonce@example.com',
        true,
        'Set Once',
        null,
        null,
    ));
    test()->withSession([
        FirstTouchAttribution::SESSION_KEY => [
            'utm_source' => 'should-not-apply',
            'utm_medium' => 'social',
            'utm_campaign' => null,
            'utm_content' => null,
            'referrer' => null,
            'landing_path' => '/elsewhere',
        ],
    ])->get(route('authenticate', ['code' => $code2]))->assertRedirect();

    $login = $analytics->eventsNamed(ProductAnalytics::USER_LOGGED_IN)[0];
    expect($login['properties'])->not->toHaveKey('$set_once')
        ->and($login['properties'])->not->toHaveKey('initial_utm_source');

    $user = User::query()->where('email', 'setonce@example.com')->firstOrFail();
    expect($user->acquisition['initial_utm_source'])->toBe('x');
});

test('S3 team_created copies creator initial_* onto event and groupIdentify', function () {
    $user = User::factory()->create([
        'acquisition' => [
            'initial_utm_source' => 'linkedin',
            'initial_utm_medium' => 'social',
            'initial_utm_campaign' => 'launch',
            'initial_utm_content' => '20261110-li-grok-bot',
            'initial_landing_path' => '/',
        ],
    ]);
    app(CreateTeam::class)->handle($user, "{$user->name}'s Team", isPersonal: true);

    test()->actingAs($user)->post(route('teams.store'), ['name' => 'Attributed Co'])->assertRedirect();

    $team = Team::query()->where('name', 'Attributed Co')->firstOrFail();
    expect($team->acquisition['initial_utm_source'] ?? null)->toBe('linkedin');

    /** @var FakeAnalytics $analytics */
    $analytics = app(AnalyticsContract::class);
    $created = $analytics->eventsNamed(ProductAnalytics::TEAM_CREATED)[0];
    expect($created['properties']['initial_utm_source'])->toBe('linkedin')
        ->and($created['properties']['initial_utm_content'])->toBe('20261110-li-grok-bot');

    $group = collect($analytics->records)->first(fn (array $r): bool => $r['type'] === 'group_identify');
    expect($group['group_key'])->toBe($team->slug)
        ->and($group['properties']['initial_utm_source'])->toBe('linkedin');
});

test('S4 is_internal is true for artfct.dev email and ruben team slug', function () {
    $internalUser = User::factory()->create(['email' => 'ruben@artfct.dev']);
    $externalUser = User::factory()->create(['email' => 'friend@example.com']);
    $internalTeam = Team::factory()->create(['slug' => 'ruben-tortoleros-team']);
    $externalTeam = Team::factory()->create(['slug' => 'customer-team']);

    $product = app(ProductAnalytics::class);
    expect($product->isInternalUser($internalUser))->toBeTrue()
        ->and($product->isInternalUser($externalUser))->toBeFalse()
        ->and($product->isInternalTeam($internalTeam))->toBeTrue()
        ->and($product->isInternalTeam($externalTeam))->toBeFalse();

    $product->artifactSearched($internalUser, $externalTeam, 'web', 1, 0);
    $product->artifactSearched($externalUser, $internalTeam, 'web', 1, 0);
    $product->artifactSearched($externalUser, $externalTeam, 'web', 1, 0);

    /** @var FakeAnalytics $analytics */
    $analytics = app(AnalyticsContract::class);
    $flags = array_map(
        fn (array $e): bool => (bool) $e['properties']['is_internal'],
        $analytics->eventsNamed(ProductAnalytics::ARTIFACT_SEARCHED),
    );

    expect($flags)->toBe([true, true, false]);
});
