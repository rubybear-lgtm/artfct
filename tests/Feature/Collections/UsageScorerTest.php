<?php

use App\Enums\UsageEventType;
use App\Models\ArtifactUsageEvent;
use App\Models\Team;
use App\Services\Collections\UsageScorer;
use Illuminate\Support\Carbon;

/**
 * `UsageScorer::score()` itself is pure (no I/O) — see its class doc — but
 * building `ArtifactUsageEvent` rows still needs the app's model/database
 * bootstrapping the plain `Unit` suite doesn't provide, so these run as
 * `Feature` tests, same as the other `ArtifactUsageEvent`-backed tests in
 * this suite. RUB-314 extends distinct-viewer scoring to count distinct
 * `viewer_key`s (anonymous visitors) alongside distinct `actor_user_id`s
 * (identified users), without conflating the two.
 */
test('distinct_viewer_keys_count_toward_distinct_viewer_scoring', function () {
    $team = Team::factory()->create();
    $now = Carbon::now();
    $events = [
        ArtifactUsageEvent::factory()->make(['team_id' => $team->id, 'event_type' => UsageEventType::Viewed, 'actor_user_id' => null, 'viewer_key' => 'visitor-a', 'occurred_at' => $now]),
        ArtifactUsageEvent::factory()->make(['team_id' => $team->id, 'event_type' => UsageEventType::Viewed, 'actor_user_id' => null, 'viewer_key' => 'visitor-b', 'occurred_at' => $now]),
    ];

    $scoreTwoVisitors = UsageScorer::score($events, $now);
    $scoreOneVisitor = UsageScorer::score([$events[0]], $now);

    expect($scoreTwoVisitors)->toBeGreaterThan($scoreOneVisitor);
});

test('the_same_viewer_key_reloading_only_counts_once', function () {
    $team = Team::factory()->create();
    $now = Carbon::now();
    $events = [
        ArtifactUsageEvent::factory()->make(['team_id' => $team->id, 'event_type' => UsageEventType::Viewed, 'actor_user_id' => null, 'viewer_key' => 'visitor-a', 'occurred_at' => $now->copy()->subMinutes(10)]),
        ArtifactUsageEvent::factory()->make(['team_id' => $team->id, 'event_type' => UsageEventType::Viewed, 'actor_user_id' => null, 'viewer_key' => 'visitor-a', 'occurred_at' => $now]),
    ];

    $repeatedScore = UsageScorer::score($events, $now);
    $singleScore = UsageScorer::score([$events[1]], $now);

    expect($repeatedScore)->toBe($singleScore);
});

test('a_viewer_key_and_a_user_id_are_never_conflated', function () {
    $team = Team::factory()->create();
    $now = Carbon::now();
    // Same string value, one as an anonymous visitor key and one as an
    // identified user's row — they must never be treated as the same
    // distinct viewer.
    $events = [
        ArtifactUsageEvent::factory()->make(['team_id' => $team->id, 'event_type' => UsageEventType::Viewed, 'actor_user_id' => null, 'viewer_key' => '42', 'occurred_at' => $now]),
        ArtifactUsageEvent::factory()->make(['team_id' => $team->id, 'event_type' => UsageEventType::Viewed, 'actor_user_id' => 42, 'viewer_key' => null, 'occurred_at' => $now]),
    ];

    $twoDistinctViewers = UsageScorer::score($events, $now);
    $oneViewer = UsageScorer::score([$events[0]], $now);

    expect($twoDistinctViewers)->toBeGreaterThan($oneViewer);
});
