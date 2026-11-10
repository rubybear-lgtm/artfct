<?php

use App\Contracts\AnalyticsContract;
use App\Contracts\ArtifactDirectory;
use App\Enums\TeamRole;
use App\Models\Team;
use App\Services\Analytics\FakeAnalytics;
use App\Services\Analytics\PostHogAnalytics;
use App\Services\Analytics\ProductAnalytics;
use App\Services\Artifacts\FakeArtifactDirectory;
use App\Services\Search\SearchService;

beforeEach(function () {
    config([
        'analytics.enabled' => true,
        'analytics.api_key' => 'phc_test',
        'analytics.host' => 'https://us.i.posthog.com',
        'analytics.environment' => 'local',
        'indexing.enabled' => true,
    ]);

    /** @var FakeAnalytics $analytics */
    $analytics = app(AnalyticsContract::class);
    $analytics->reset();
});

test('A1 PostHogAnalytics is a no-op without a key and does not throw', function () {
    config(['analytics.enabled' => false, 'analytics.api_key' => null]);

    $sink = new PostHogAnalytics;
    expect($sink->enabled())->toBeFalse();

    $sink->capture('1', 'artifact_searched', ['team_id' => 1]);
    $sink->identify('1', ['is_internal' => false], ['initial_utm_source' => 'x']);
    $sink->groupIdentify('team', 'acme', ['plan' => 'free']);
    $sink->flush();
});

test('A1 real search path succeeds when analytics is disabled', function () {
    config(['analytics.enabled' => false, 'analytics.api_key' => null]);
    app()->instance(AnalyticsContract::class, new PostHogAnalytics);

    $team = Team::factory()->create(['slug' => 'a1-team']);
    $user = memberOfTeam($team, TeamRole::Member);

    $results = app(SearchService::class)->search(
        $team,
        'hello',
        [],
        5,
        actor: (string) $user->id,
        source: 'api',
    );

    expect($results)->toBeArray();
});

test('A2 capture failure does not break a real search request', function () {
    /** @var FakeAnalytics $analytics */
    $analytics = app(AnalyticsContract::class);
    $analytics->throwOnCapture = true;

    $team = Team::factory()->create(['slug' => 'a2-team']);
    $user = memberOfTeam($team, TeamRole::Member);

    $results = app(SearchService::class)->search(
        $team,
        'hello',
        [],
        5,
        actor: (string) $user->id,
        source: 'web',
    );

    expect($results)->toBeArray()
        ->and($analytics->eventsNamed(ProductAnalytics::ARTIFACT_SEARCHED))->toBe([]);
});

test('A3 every captured event includes environment and enable gate blocks sends', function () {
    config(['analytics.environment' => 'staging']);

    $team = Team::factory()->create(['slug' => 'a3-team']);
    $user = memberOfTeam($team, TeamRole::Admin);

    app(ProductAnalytics::class)->artifactSearched($user, $team, 'web', 4, 0);

    /** @var FakeAnalytics $analytics */
    $analytics = app(AnalyticsContract::class);
    $events = $analytics->eventsNamed(ProductAnalytics::ARTIFACT_SEARCHED);
    expect($events)->toHaveCount(1)
        ->and($events[0]['properties']['environment'])->toBe('staging');

    $analytics->reset();
    config(['analytics.enabled' => false, 'analytics.api_key' => 'phc_present']);
    $disabled = new PostHogAnalytics;
    expect($disabled->enabled())->toBeFalse();
    $disabled->capture((string) $user->id, 'artifact_searched', ['environment' => 'staging']);
});

test('A4 team denormalization props are present on share and search events', function () {
    $team = Team::factory()->create(['slug' => 'a4-team', 'is_personal' => false]);
    $user = memberOfTeam($team, TeamRole::Admin);

    app(ProductAnalytics::class)->artifactShared($user, $team, 'mcp');
    app(ProductAnalytics::class)->artifactSearched($user, $team, 'mcp', 3, 1);

    /** @var FakeAnalytics $analytics */
    $analytics = app(AnalyticsContract::class);

    foreach ([ProductAnalytics::ARTIFACT_SHARED, ProductAnalytics::ARTIFACT_SEARCHED] as $event) {
        $record = $analytics->eventsNamed($event)[0];
        expect($record['properties'])
            ->toHaveKey('team_id', $team->id)
            ->toHaveKey('team_slug', 'a4-team')
            ->toHaveKey('is_personal', false)
            ->and($record['groups'])->toHaveKey('team', 'a4-team');
    }
});

test('A5 privacy: search sends query_length not query, invites omit email', function () {
    $team = Team::factory()->create(['slug' => 'a5-team']);
    $user = memberOfTeam($team, TeamRole::Admin);

    app(SearchService::class)->search($team, 'secret query text', [], 5, actor: (string) $user->id, source: 'api');
    app(ProductAnalytics::class)->inviteSent($user, $team, [
        'email' => 'should-not-leak@example.com',
        'invitee_email' => 'also@example.com',
        'role' => 'member',
    ]);

    /** @var FakeAnalytics $analytics */
    $analytics = app(AnalyticsContract::class);
    $search = $analytics->eventsNamed(ProductAnalytics::ARTIFACT_SEARCHED)[0]['properties'];
    expect($search)->toHaveKey('query_length', mb_strlen('secret query text'))
        ->and($search)->not->toHaveKey('query')
        ->and($search)->not->toHaveKey('html')
        ->and($search)->not->toHaveKey('content');

    $invite = $analytics->eventsNamed(ProductAnalytics::INVITE_SENT)[0]['properties'];
    expect($invite)->not->toHaveKey('email')
        ->and($invite)->not->toHaveKey('invitee_email')
        ->and($invite)->toHaveKey('role', 'member');
});

test('A2 web sharing update still succeeds when analytics throws', function () {
    configureArtifactLinks();

    /** @var FakeAnalytics $analytics */
    $analytics = app(AnalyticsContract::class);
    $analytics->throwOnCapture = true;

    $team = Team::factory()->create(['slug' => 'a2-share']);
    $user = memberOfTeam($team, TeamRole::Admin);
    $team->forceFill(['owner_user_id' => $user->id])->save();

    /** @var FakeArtifactDirectory $directory */
    $directory = app(ArtifactDirectory::class);
    $directory->seedArtifact([
        'id' => ARTIFACT_LINK_ID,
        'org_id' => $team->slug,
        'user_id' => (string) $user->id,
        'owner_user_id' => (string) $user->id,
        'title' => 'Share me',
        'sharing' => 'team',
        'can_change_sharing' => true,
        'content_hash' => md5(ARTIFACT_LINK_ID),
        'created_at' => now()->toIso8601String(),
        'revoked_at' => null,
        'provenance' => ['agent' => null, 'repo_url' => null, 'commit_sha' => null],
    ]);

    test()->actingAs($user)
        ->patch('/a/'.ARTIFACT_LINK_ID.'/sharing', ['sharing' => 'public'])
        ->assertRedirect('/a/'.ARTIFACT_LINK_ID);
});
