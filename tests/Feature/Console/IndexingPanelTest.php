<?php

use App\Contracts\ArtifactContentSource;
use App\Enums\TeamRole;
use App\Jobs\IndexArtifactJob;
use App\Models\ArtifactIndexEntry;
use App\Models\ArtifactIndexingFailure;
use App\Models\Team;
use App\Services\Artifacts\FakeArtifactContentSource;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Inertia\Testing\AssertableInertia as Assert;

function indexingTeam(): array
{
    Cache::flush();
    config(['indexing.enabled' => true]);
    Bus::fake();
    $team = Team::factory()->create();
    $admin = memberOfTeam($team, TeamRole::Admin);
    /** @var FakeArtifactContentSource $content */
    $content = app(ArtifactContentSource::class);
    $content->seed($team->slug, 'art0000001', '<h1>Hi</h1>');

    return [$team, $admin];
}

test('failures_panel_lists_dead_letters_and_states_map', function () {
    [$team, $admin] = indexingTeam();
    ArtifactIndexingFailure::create(['team_id' => $team->id, 'artifact_id' => 'art0000001', 'attempts' => 3, 'reason' => 'render timeout', 'failed_at' => now()]);

    test()->actingAs($admin)->get(route('console.index', $team))
        ->assertInertia(fn (Assert $page) => $page
            ->where('indexingEnabled', true)
            ->where('indexingFailures.0.reason', 'render timeout'));
});

test('retry_dispatches_once_and_a_second_click_is_a_noop', function () {
    [$team, $admin] = indexingTeam();

    test()->actingAs($admin)->post(route('console.reindex', [$team, 'art0000001']))->assertRedirect();
    test()->actingAs($admin)->post(route('console.reindex', [$team, 'art0000001']))->assertRedirect();

    Bus::assertDispatchedTimes(IndexArtifactJob::class, 1);
});

test('retry_is_a_noop_for_an_already_indexed_artifact', function () {
    [$team, $admin] = indexingTeam();
    ArtifactIndexEntry::create(['team_id' => $team->id, 'artifact_id' => 'art0000001', 'rendered' => true, 'extracted_text' => 'Hi', 'extracted_at' => now()]);

    test()->actingAs($admin)->post(route('console.reindex', [$team, 'art0000001']))->assertRedirect();

    Bus::assertNothingDispatched();
});

test('members_cannot_retry', function () {
    [$team] = indexingTeam();
    $member = memberOfTeam($team, TeamRole::Member);

    test()->actingAs($member)->post(route('console.reindex', [$team, 'art0000001']))->assertForbidden();

    Bus::assertNothingDispatched();
});

test('retry_is_refused_when_indexing_is_off', function () {
    [$team, $admin] = indexingTeam();
    config(['indexing.enabled' => false]);

    test()->actingAs($admin)->post(route('console.reindex', [$team, 'art0000001']))->assertStatus(409);
});
