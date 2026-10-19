<?php

use App\Enums\TeamRole;
use App\Models\Team;
use App\Services\Sharing\ArtifactVisibility;

test('a_team_artifact_is_visible_to_any_member', function () {
    $team = Team::factory()->create();
    $member = memberOfTeam($team, TeamRole::Member);

    expect(ArtifactVisibility::canView('team', '999', $member, $team))->toBeTrue();
});

test('a_public_artifact_is_visible_to_any_member', function () {
    $team = Team::factory()->create();
    $viewer = memberOfTeam($team, TeamRole::Viewer);

    expect(ArtifactVisibility::canView('public', '999', $viewer, $team))->toBeTrue();
});

test('a_private_artifact_is_visible_to_its_owner_even_when_they_are_just_a_member', function () {
    $team = Team::factory()->create();
    $owner = memberOfTeam($team, TeamRole::Member);

    expect(ArtifactVisibility::canView('private', (string) $owner->id, $owner, $team))->toBeTrue();
});

test('a_private_artifact_is_hidden_from_another_member', function () {
    $team = Team::factory()->create();
    $member = memberOfTeam($team, TeamRole::Member);

    expect(ArtifactVisibility::canView('private', '999', $member, $team))->toBeFalse();
});

test('a_private_artifact_is_visible_to_an_admin_who_is_not_the_owner', function () {
    $team = Team::factory()->create();
    $admin = memberOfTeam($team, TeamRole::Admin);

    expect(ArtifactVisibility::canView('private', '999', $admin, $team))->toBeTrue();
});

test('a_private_artifact_with_a_null_owner_is_still_visible_to_an_admin', function () {
    $team = Team::factory()->create();
    $admin = memberOfTeam($team, TeamRole::Admin);

    expect(ArtifactVisibility::canView('private', null, $admin, $team))->toBeTrue();
});

test('a_null_sharing_level_fails_closed_to_private', function () {
    $team = Team::factory()->create();
    $member = memberOfTeam($team, TeamRole::Member);

    expect(ArtifactVisibility::canView(null, null, $member, $team))->toBeFalse();
});

test('an_unknown_sharing_level_fails_closed_to_private', function () {
    $team = Team::factory()->create();
    $member = memberOfTeam($team, TeamRole::Member);

    expect(ArtifactVisibility::canView('shared-with-my-friends', null, $member, $team))->toBeFalse();
});
