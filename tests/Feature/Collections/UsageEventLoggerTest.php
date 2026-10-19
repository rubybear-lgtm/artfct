<?php

use App\Enums\UsageEventType;
use App\Models\ArtifactUsageEvent;
use App\Models\Team;
use App\Models\User;
use App\Services\Collections\UsageEventLogger;
use Illuminate\Support\Carbon;

test('anonymous_views_by_the_same_visitor_key_on_the_same_day_are_deduped', function () {
    $team = Team::factory()->create();
    $logger = app(UsageEventLogger::class);

    $logger->recordAnonymousView($team, 'artifact-1', 'visitor-key-a', Carbon::now());
    $logger->recordAnonymousView($team, 'artifact-1', 'visitor-key-a', Carbon::now()->addMinutes(5));

    expect(ArtifactUsageEvent::query()
        ->where('team_id', $team->id)
        ->where('artifact_id', 'artifact-1')
        ->where('viewer_key', 'visitor-key-a')
        ->count())->toBe(1);
});

test('different_visitor_keys_each_record_their_own_view', function () {
    $team = Team::factory()->create();
    $logger = app(UsageEventLogger::class);

    $logger->recordAnonymousView($team, 'artifact-1', 'visitor-key-a', Carbon::now());
    $logger->recordAnonymousView($team, 'artifact-1', 'visitor-key-b', Carbon::now());

    expect(ArtifactUsageEvent::query()
        ->where('team_id', $team->id)
        ->where('artifact_id', 'artifact-1')
        ->count())->toBe(2);
});

test('a_viewed_and_a_retrieved_then_opened_row_can_coexist_for_the_same_visitor_key', function () {
    $team = Team::factory()->create();
    $logger = app(UsageEventLogger::class);

    $logger->recordAnonymousView($team, 'artifact-1', 'visitor-key-a', Carbon::now());
    $logger->recordRetrievedThenOpened($team, 'artifact-1', viewerKey: 'visitor-key-a', occurredAt: Carbon::now());

    $types = ArtifactUsageEvent::query()
        ->where('team_id', $team->id)
        ->where('artifact_id', 'artifact-1')
        ->pluck('event_type')
        ->map(fn (UsageEventType $type): string => $type->value)
        ->all();

    expect($types)->toEqualCanonicalizing(['viewed', 'retrieved_then_opened']);
});

test('identified_views_are_unaffected_by_the_anonymous_dedup_index', function () {
    $team = Team::factory()->create();
    $logger = app(UsageEventLogger::class);
    $viewerA = User::factory()->create();
    $viewerB = User::factory()->create();

    $logger->recordView($team, 'artifact-1', $viewerA->id, Carbon::now());
    $logger->recordView($team, 'artifact-1', $viewerB->id, Carbon::now());

    expect(ArtifactUsageEvent::query()
        ->where('team_id', $team->id)
        ->where('artifact_id', 'artifact-1')
        ->count())->toBe(2);
});
