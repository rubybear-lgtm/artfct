<?php

use App\Contracts\ArtifactDirectory;
use App\Enums\FeatureReleaseState;
use App\Enums\TeamRole;
use App\Models\FeatureRelease;
use App\Models\Team;
use App\Services\Artifacts\FakeArtifactDirectory;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function (): void {
    $this->withoutVite();
});

function seedUnifiedRailConsoleArtifact(string $teamSlug, string $id, string $userId, string $agent, string $title): void
{
    /** @var FakeArtifactDirectory $directory */
    $directory = app(ArtifactDirectory::class);
    $directory->seedArtifact([
        'id' => $id,
        'org_id' => $teamSlug,
        'user_id' => $userId,
        'title' => 'Railprobe '.$title,
        'description' => 'Railprobe '.$title.' description',
        'content_hash' => 'hash-'.$id,
        'created_at' => now()->subDay()->toIso8601String(),
        'revoked_at' => null,
        'provenance' => ['agent' => $agent, 'repo_url' => null, 'commit_sha' => null],
    ]);
}

test('the_console_hides_the_flag_and_keeps_its_filter_props_when_unified_rail_is_off', function (): void {
    $team = Team::factory()->create(['slug' => 'rail-off-console']);
    $member = memberOfTeam($team, TeamRole::Member);
    seedUnifiedRailConsoleArtifact($team->slug, 'art1111111', (string) $member->id, 'cursor', 'Cursor board');
    seedUnifiedRailConsoleArtifact($team->slug, 'art2222222', 'someone-else', 'chatgpt', 'ChatGPT board');

    test()->actingAs($member)->get(route('console.index', $team).'?q=railprobe')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('console/index')
            ->where('features.unified-rail', false)
            ->has('artifacts', 2)
            ->has('agents', 2)
            ->has('filters')
            ->where('filters.user_id', null)
            ->where('filters.agent', null)
            ->has('collections'));
});

test('the_console_keeps_its_facet_props_when_unified_rail_is_on', function (): void {
    $team = Team::factory()->create(['slug' => 'rail-on-console']);
    $member = memberOfTeam($team, TeamRole::Member);
    FeatureRelease::factory()->create(['key' => 'unified-rail', 'state' => FeatureReleaseState::Released]);
    seedUnifiedRailConsoleArtifact($team->slug, 'art1111111', (string) $member->id, 'cursor', 'Cursor board');
    seedUnifiedRailConsoleArtifact($team->slug, 'art2222222', 'someone-else', 'chatgpt', 'ChatGPT board');

    test()->actingAs($member)->get(route('console.index', $team).'?q=railprobe')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('console/index')
            ->where('features.unified-rail', true)
            ->has('artifacts', 2)
            ->has('agents', 2)
            ->has('filters')
            ->has('collections'));
});

test('the_owner_facet_param_narrows_the_console_to_yours', function (): void {
    $team = Team::factory()->create(['slug' => 'rail-owner-console']);
    $member = memberOfTeam($team, TeamRole::Member);
    seedUnifiedRailConsoleArtifact($team->slug, 'art1111111', (string) $member->id, 'cursor', 'Cursor board');
    seedUnifiedRailConsoleArtifact($team->slug, 'art2222222', 'someone-else', 'cursor', 'Teammate board');

    test()->actingAs($member)->get(route('console.index', $team).'?q=railprobe&user_id='.$member->id)
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('console/index')
            ->has('artifacts', 1)
            ->where('artifacts.0.title', 'Railprobe Cursor board')
            ->where('filters.user_id', (string) $member->id));
});

test('the_type_facet_param_narrows_the_console_to_one_ai_tool', function (): void {
    $team = Team::factory()->create(['slug' => 'rail-type-console']);
    $member = memberOfTeam($team, TeamRole::Member);
    seedUnifiedRailConsoleArtifact($team->slug, 'art1111111', (string) $member->id, 'cursor', 'Cursor board');
    seedUnifiedRailConsoleArtifact($team->slug, 'art2222222', (string) $member->id, 'chatgpt', 'ChatGPT board');

    test()->actingAs($member)->get(route('console.index', $team).'?q=railprobe&agent=cursor')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('console/index')
            ->has('artifacts', 1)
            ->where('artifacts.0.title', 'Railprobe Cursor board')
            ->where('filters.agent', 'cursor'));
});
