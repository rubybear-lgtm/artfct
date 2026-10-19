<?php

use App\Contracts\ArtifactDirectory;
use App\Enums\TeamRole;
use App\Models\Team;
use App\Services\Artifacts\FakeArtifactDirectory;

test('the_console_hands_the_grid_a_preview_url_for_every_artifact_that_is_not_revoked', function () {
    $team = Team::factory()->create(['slug' => 'test-org']);
    $admin = memberOfTeam($team, TeamRole::Admin);

    /** @var FakeArtifactDirectory $directory */
    $directory = app(ArtifactDirectory::class);
    $directory->seedArtifact([
        'id' => 'revoked-one',
        'org_id' => $team->slug,
        'user_id' => 1,
        'title' => 'Revoked artifact',
        'description' => '',
        'content_hash' => 'aaaabbbbcccc',
        'created_at' => now()->subDay()->toIso8601String(),
        'revoked_at' => now()->toIso8601String(),
        'provenance' => ['agent' => 'cursor', 'repo_url' => null, 'commit_sha' => null],
    ]);

    test()->actingAs($admin)
        ->get("/settings/teams/{$team->slug}/console")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('view', 'grid')
            ->where('currentUserId', (string) $admin->id)
            ->where('previewUrls.1234567890', route('teams.artifacts.preview', ['team' => $team->slug, 'artifactId' => '1234567890']))
            ->missing('previewUrls.revoked-one')
        );
});

test('the_console_view_is_a_grid_unless_the_list_is_asked_for', function () {
    $team = Team::factory()->create(['slug' => 'test-org']);
    $member = memberOfTeam($team, TeamRole::Member);

    test()->actingAs($member)
        ->get("/settings/teams/{$team->slug}/console?view=list")
        ->assertInertia(fn ($page) => $page->where('view', 'list'));

    test()->actingAs($member)
        ->get("/settings/teams/{$team->slug}/console?view=nonsense")
        ->assertInertia(fn ($page) => $page->where('view', 'grid'));
});
