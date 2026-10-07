<?php

use App\Contracts\ArtifactContentSource;
use App\Enums\TeamRole;
use App\Jobs\IndexArtifactJob;
use App\Models\Team;
use App\Services\Artifacts\FakeArtifactContentSource;
use Illuminate\Support\Facades\Bus;

/**
 * The content source mints a system token with `artifacts:read_private`, so a
 * private artifact comes back for anyone on the route. The Private rule is
 * applied by the console: private is visible to the owner and to team admins,
 * and to nobody else — a non-owner member gets the same 404 as a missing
 * artifact.
 */
test('the_owner_can_open_their_private_artifact', function () {
    configureArtifactLinks();

    $team = Team::factory()->create(['slug' => 'test-org']);
    $owner = memberOfTeam($team, TeamRole::Member);

    /** @var FakeArtifactContentSource $content */
    $content = app(ArtifactContentSource::class);
    $content->seed($team->slug, ARTIFACT_LINK_ID, '<h1>Hello</h1>', sharing: 'private', ownerUserId: (string) $owner->id);

    test()->actingAs($owner)
        ->get("/settings/teams/{$team->slug}/console/artifacts/".ARTIFACT_LINK_ID.'/open')
        ->assertRedirect();
});

test('a_member_who_is_not_the_owner_cannot_open_a_private_artifact', function () {
    configureArtifactLinks();

    $team = Team::factory()->create(['slug' => 'test-org']);
    $owner = memberOfTeam($team, TeamRole::Member);
    $other = memberOfTeam($team, TeamRole::Member);

    /** @var FakeArtifactContentSource $content */
    $content = app(ArtifactContentSource::class);
    $content->seed($team->slug, ARTIFACT_LINK_ID, '<h1>Hello</h1>', sharing: 'private', ownerUserId: (string) $owner->id);

    test()->actingAs($other)
        ->get("/settings/teams/{$team->slug}/console/artifacts/".ARTIFACT_LINK_ID.'/open')
        ->assertNotFound();
});

test('an_admin_can_open_a_private_artifact_owned_by_someone_else', function () {
    configureArtifactLinks();

    $team = Team::factory()->create(['slug' => 'test-org']);
    $admin = memberOfTeam($team, TeamRole::Admin);

    /** @var FakeArtifactContentSource $content */
    $content = app(ArtifactContentSource::class);
    $content->seed($team->slug, ARTIFACT_LINK_ID, '<h1>Hello</h1>', sharing: 'private', ownerUserId: '999999');

    test()->actingAs($admin)
        ->get("/settings/teams/{$team->slug}/console/artifacts/".ARTIFACT_LINK_ID.'/open')
        ->assertRedirect();
});

test('an_unknown_sharing_level_from_the_worker_fails_closed_on_open', function () {
    configureArtifactLinks();

    $team = Team::factory()->create(['slug' => 'test-org']);
    $member = memberOfTeam($team, TeamRole::Member);

    /** @var FakeArtifactContentSource $content */
    $content = app(ArtifactContentSource::class);
    $content->seed($team->slug, ARTIFACT_LINK_ID, '<h1>Hello</h1>', sharing: 'shared-with-my-friends');

    test()->actingAs($member)
        ->get("/settings/teams/{$team->slug}/console/artifacts/".ARTIFACT_LINK_ID.'/open')
        ->assertNotFound();
});

test('an_admin_can_reindex_a_private_artifact', function () {
    config(['indexing.enabled' => true]);
    Bus::fake();

    $team = Team::factory()->create(['slug' => 'test-org']);
    $admin = memberOfTeam($team, TeamRole::Admin);

    /** @var FakeArtifactContentSource $content */
    $content = app(ArtifactContentSource::class);
    $content->seed($team->slug, '1234567890', '<h1>Hello</h1>', sharing: 'private', ownerUserId: '999999');

    test()->actingAs($admin)
        ->post("/settings/teams/{$team->slug}/console/artifacts/1234567890/reindex")
        ->assertRedirect();

    Bus::assertDispatched(IndexArtifactJob::class);
});

test('a_non_admin_member_cannot_reindex_a_private_artifact', function () {
    config(['indexing.enabled' => true]);
    Bus::fake();

    $team = Team::factory()->create(['slug' => 'test-org']);
    $owner = memberOfTeam($team, TeamRole::Member);

    /** @var FakeArtifactContentSource $content */
    $content = app(ArtifactContentSource::class);
    $content->seed($team->slug, '1234567890', '<h1>Hello</h1>', sharing: 'private', ownerUserId: (string) $owner->id);

    // Reindex is admin-only and stays that way; the owner's membership is not
    // enough to re-run indexing for the team.
    test()->actingAs($owner)
        ->post("/settings/teams/{$team->slug}/console/artifacts/1234567890/reindex")
        ->assertForbidden();

    Bus::assertNothingDispatched();
});

test('a_member_of_another_team_cannot_reindex_a_private_artifact', function () {
    config(['indexing.enabled' => true]);
    Bus::fake();

    $team = Team::factory()->create(['slug' => 'team-1']);
    $other = Team::factory()->create(['slug' => 'team-2']);
    $outsider = memberOfTeam($other, TeamRole::Admin);

    /** @var FakeArtifactContentSource $content */
    $content = app(ArtifactContentSource::class);
    $content->seed($team->slug, '1234567890', '<h1>Hello</h1>', sharing: 'private', ownerUserId: '999999');

    test()->actingAs($outsider)
        ->post("/settings/teams/{$team->slug}/console/artifacts/1234567890/reindex")
        ->assertNotFound();

    Bus::assertNothingDispatched();
});
