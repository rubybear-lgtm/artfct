<?php

use App\Actions\Teams\CreateTeam;
use App\Contracts\AnalyticsContract;
use App\Enums\HeardAboutSource;
use App\Enums\TeamRole;
use App\Models\Team;
use App\Models\User;
use App\Services\Analytics\FakeAnalytics;
use App\Services\Analytics\ProductAnalytics;

beforeEach(function () {
    config([
        'analytics.enabled' => true,
        'analytics.api_key' => 'phc_test',
        'analytics.environment' => 'local',
    ]);

    /** @var FakeAnalytics $analytics */
    $analytics = app(AnalyticsContract::class);
    $analytics->reset();
});

test('S6 heard_about is optional on web team create and upgrade and skipped on personal auto-create', function () {
    $user = User::factory()->create();
    $personal = app(CreateTeam::class)->handle($user, "{$user->name}'s Team", isPersonal: true);
    expect($personal->heard_about)->toBeNull()
        ->and($personal->heard_about_other)->toBeNull();

    test()->actingAs($user)->post(route('teams.store'), [
        'name' => 'Heard Co',
        'heard_about' => HeardAboutSource::LinkedIn->value,
    ])->assertRedirect();

    $team = Team::query()->where('name', 'Heard Co')->firstOrFail();
    expect($team->heard_about)->toBe(HeardAboutSource::LinkedIn->value)
        ->and($team->heard_about_other)->toBeNull();

    test()->actingAs($user)->post(route('teams.store'), [
        'name' => 'Skip Co',
    ])->assertRedirect();
    expect(Team::query()->where('name', 'Skip Co')->value('heard_about'))->toBeNull();

    test()->actingAs($user)->post(route('teams.store'), [
        'name' => 'Bad Co',
        'heard_about' => 'carrier-pigeon',
    ])->assertSessionHasErrors('heard_about');

    test()->actingAs($user)->post(route('teams.upgrade', $user->fresh()->personalTeam()), [
        'name' => 'Upgraded Heard',
        'heard_about' => HeardAboutSource::Other->value,
        'heard_about_other' => 'A podcast episode',
    ])->assertRedirect();

    $upgraded = Team::query()->where('name', 'Upgraded Heard')->firstOrFail();
    expect($upgraded->heard_about)->toBe(HeardAboutSource::Other->value)
        ->and($upgraded->heard_about_other)->toBe('A podcast episode');

    test()->actingAs($user)->post(route('teams.store'), [
        'name' => 'Long Other',
        'heard_about' => HeardAboutSource::Other->value,
        'heard_about_other' => str_repeat('x', 121),
    ])->assertSessionHasErrors('heard_about_other');
});

test('S7 heard_about props appear on team_created analytics and email-looking other is scrubbed', function () {
    $user = User::factory()->create();
    app(CreateTeam::class)->handle($user, "{$user->name}'s Team", isPersonal: true);

    test()->actingAs($user)->post(route('teams.store'), [
        'name' => 'Analytics Heard',
        'heard_about' => HeardAboutSource::Other->value,
        'heard_about_other' => 'person@example.com',
    ])->assertRedirect();

    $team = Team::query()->where('name', 'Analytics Heard')->firstOrFail();
    expect($team->heard_about)->toBe(HeardAboutSource::Other->value)
        ->and($team->heard_about_other)->toBeNull();

    /** @var FakeAnalytics $analytics */
    $analytics = app(AnalyticsContract::class);
    $created = $analytics->eventsNamed(ProductAnalytics::TEAM_CREATED)[0];
    expect($created['properties']['heard_about'])->toBe(HeardAboutSource::Other->value)
        ->and($created['properties'])->not->toHaveKey('heard_about_other');

    $group = collect($analytics->records)->first(fn (array $r): bool => $r['type'] === 'group_identify' && $r['group_key'] === $team->slug);
    expect($group['properties']['heard_about'])->toBe(HeardAboutSource::Other->value)
        ->and($group['properties'])->not->toHaveKey('heard_about_other');

    expect(app(ProductAnalytics::class)->sanitizeHeardAboutOther('safe note'))->toBe('safe note')
        ->and(app(ProductAnalytics::class)->sanitizeHeardAboutOther('me@artfct.dev'))->toBeNull();
});

test('S6 bot and MCP paths do not require heard_about', function () {
    $team = Team::factory()->create(['slug' => 'bot-no-heard']);
    $bot = User::factory()->create(['is_bot' => true]);
    $team->memberships()->create(['user_id' => $bot->id, 'role' => TeamRole::Member]);

    expect($team->heard_about)->toBeNull();
});
