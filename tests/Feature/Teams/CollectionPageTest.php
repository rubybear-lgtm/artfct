<?php

use App\Contracts\ArtifactDirectory;
use App\Enums\TeamRole;
use App\Models\Collection;
use App\Models\Team;
use App\Models\User;
use App\Services\Artifacts\FakeArtifactDirectory;
use Inertia\Testing\AssertableInertia as Assert;

function collectionTeam(): array
{
    $team = Team::factory()->create();
    $admin = memberOfTeam($team, TeamRole::Admin);
    $member = memberOfTeam($team, TeamRole::Member);

    return [$team, $admin, $member];
}

test('a_member_creates_a_collection_and_adds_and_removes_artifacts', function () {
    [$team, , $member] = collectionTeam();

    test()->actingAs($member)->post(route('teams.collections.store', $team), ['name' => 'reporting'])->assertRedirect();
    $collection = Collection::query()->where('team_id', $team->id)->firstOrFail();

    test()->actingAs($member)->post(route('teams.collections.artifacts.add', [$team, $collection]), ['artifact_id' => 'art1'])->assertRedirect();
    expect($collection->artifacts()->count())->toBe(1);

    test()->actingAs($member)->delete(route('teams.collections.artifacts.remove', [$team, $collection, 'art1']))->assertRedirect();
    expect($collection->artifacts()->count())->toBe(0);
});

test('collection_names_are_unique_per_team_and_renamable', function () {
    [$team, , $member] = collectionTeam();
    test()->actingAs($member)->post(route('teams.collections.store', $team), ['name' => 'a']);

    test()->actingAs($member)->post(route('teams.collections.store', $team), ['name' => 'a'])->assertSessionHasErrors('name');

    $collection = Collection::query()->firstOrFail();
    test()->actingAs($member)->patch(route('teams.collections.update', [$team, $collection]), ['name' => 'b'])->assertRedirect();
    expect($collection->fresh()->name)->toBe('b');
});

test('only_admins_can_pin_canonical', function () {
    [$team, $admin, $member] = collectionTeam();
    $collection = Collection::create(['team_id' => $team->id, 'name' => 'c', 'created_by_user_id' => $admin->id]);

    test()->actingAs($member)->post(route('teams.collections.pin', [$team, $collection]))->assertForbidden();
    expect($collection->fresh()->canonical)->toBeFalse();

    test()->actingAs($admin)->post(route('teams.collections.pin', [$team, $collection]))->assertRedirect();
    expect($collection->fresh()->canonical)->toBeTrue();

    test()->actingAs($admin)->delete(route('teams.collections.unpin', [$team, $collection]))->assertRedirect();
    expect($collection->fresh()->canonical)->toBeFalse();
});

test('viewers_cannot_create_collections', function () {
    $team = Team::factory()->create();
    $viewer = memberOfTeam($team, TeamRole::Viewer);

    test()->actingAs($viewer)->post(route('teams.collections.store', $team), ['name' => 'nope'])->assertForbidden();
});

test('a_collection_from_another_team_is_not_reachable', function () {
    [$team, , $member] = collectionTeam();
    $other = Team::factory()->create();
    $foreign = Collection::create(['team_id' => $other->id, 'name' => 'x', 'created_by_user_id' => $member->id]);

    test()->actingAs($member)->post(route('teams.collections.artifacts.add', [$team, $foreign]), ['artifact_id' => 'a'])->assertNotFound();
    test()->actingAs(User::factory()->create())->get(route('teams.collections.index', $team))->assertNotFound();
});

test('the_page_lists_collections_with_permissions', function () {
    [$team, , $member] = collectionTeam();
    Collection::create(['team_id' => $team->id, 'name' => 'c', 'created_by_user_id' => $member->id]);

    test()->actingAs($member)->get(route('teams.collections.index', $team))
        ->assertInertia(fn (Assert $page) => $page
            ->component('teams/collections')
            ->where('canEdit', true)
            ->where('canPin', false)
            ->where('collections.0.name', 'c'));
});

/**
 * A collection lists artifact ids; each one links the same way the console row
 * and the search result do — the app's own open route — so a member clicking it
 * is authorized and, for a secure artifact, gets a link minted for them. The
 * Worker's raw `/p/{id}` URL is not handed to the page as a link.
 */
test('artifact_rows_link_through_the_apps_open_route', function () {
    configureArtifactLinks();

    [$team, , $member] = collectionTeam();
    $collection = Collection::create(['team_id' => $team->id, 'name' => 'c', 'created_by_user_id' => $member->id]);
    $collection->artifacts()->create(['artifact_id' => ARTIFACT_LINK_ID, 'added_at' => now()]);

    $openUrl = route('console.open', ['team' => $team->slug, 'artifactId' => ARTIFACT_LINK_ID]);

    test()->actingAs($member)->get(route('teams.collections.index', $team))
        ->assertInertia(fn (Assert $page) => $page
            ->where('canOpenArtifacts', true)
            ->where('collections.0.artifactIds', [ARTIFACT_LINK_ID])
            ->where('collections.0.openUrls.'.ARTIFACT_LINK_ID, $openUrl));

    config(['services.artifact_access.token_secret' => null]);

    test()->actingAs($member)->get(route('teams.collections.index', $team))
        ->assertInertia(fn (Assert $page) => $page->where('canOpenArtifacts', false));
});

test('every_collection_action_flashes_a_toast', function () {
    [$team, , $member] = collectionTeam();

    test()->actingAs($member)
        ->post(route('teams.collections.store', $team), ['name' => 'reporting'])
        ->assertRedirect()
        ->assertInertiaFlash('toast', [
            'type' => 'success',
            'message' => 'Collection created.',
        ]);

    $collection = Collection::query()->where('team_id', $team->id)->firstOrFail();

    test()->actingAs($member)
        ->patch(route('teams.collections.update', [$team, $collection]), ['name' => 'reporting-v2'])
        ->assertRedirect()
        ->assertInertiaFlash('toast', [
            'type' => 'success',
            'message' => 'Collection renamed.',
        ]);

    test()->actingAs($member)
        ->post(route('teams.collections.artifacts.add', [$team, $collection]), ['artifact_id' => 'art1'])
        ->assertRedirect()
        ->assertInertiaFlash('toast', [
            'type' => 'success',
            'message' => 'Added to reporting-v2.',
        ]);

    test()->actingAs($member)
        ->delete(route('teams.collections.artifacts.remove', [$team, $collection, 'art1']))
        ->assertRedirect()
        ->assertInertiaFlash('toast', [
            'type' => 'success',
            'message' => 'Removed from reporting-v2.',
        ]);
});

test('pinning_and_unpinning_flash_a_toast', function () {
    [$team, $admin] = collectionTeam();
    $collection = Collection::create(['team_id' => $team->id, 'name' => 'c', 'created_by_user_id' => $admin->id]);

    test()->actingAs($admin)
        ->post(route('teams.collections.pin', [$team, $collection]))
        ->assertRedirect()
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Pinned.']);

    test()->actingAs($admin)
        ->delete(route('teams.collections.unpin', [$team, $collection]))
        ->assertRedirect()
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Unpinned.']);
});

test('the_page_offers_active_artifacts_to_add_by_name', function () {
    [$team, , $member] = collectionTeam();

    test()->actingAs($member)->get(route('teams.collections.index', $team))
        ->assertInertia(fn (Assert $page) => $page
            ->component('teams/collections')
            ->has('artifactOptions', 2)
            ->where('artifactOptions.0.id', '1234567890')
            ->where('artifactOptions.0.title', 'Dashboard HTML')
            ->where('artifactOptions.1.id', 'abcdefghij')
            ->where('artifactOptions.1.title', 'API Documentation'));
});

test('revoked_artifacts_are_not_offered', function () {
    [$team, , $member] = collectionTeam();

    /** @var FakeArtifactDirectory $directory */
    $directory = app(ArtifactDirectory::class);
    $directory->revokeArtifact($team->slug, '1234567890');

    test()->actingAs($member)->get(route('teams.collections.index', $team))
        ->assertInertia(fn (Assert $page) => $page
            ->has('artifactOptions', 1)
            ->where('artifactOptions.0.id', 'abcdefghij'));
});

test('an_artifact_without_a_title_is_offered_by_a_short_id', function () {
    [$team, , $member] = collectionTeam();

    /** @var FakeArtifactDirectory $directory */
    $directory = app(ArtifactDirectory::class);
    $directory->seedArtifact([
        'id' => 'noname1234567890',
        'org_id' => $team->slug,
        'user_id' => 1,
        'title' => '',
        'description' => 'Untitled',
        'content_hash' => 'noname',
        'created_at' => now()->toIso8601String(),
        'revoked_at' => null,
        'provenance' => ['agent' => 'cursor', 'repo_url' => 'https://github.com/acme/misc', 'commit_sha' => 'a'],
    ]);

    test()->actingAs($member)->get(route('teams.collections.index', $team))
        ->assertInertia(fn (Assert $page) => $page
            ->where('artifactOptions.2.id', 'noname1234567890')
            ->where('artifactOptions.2.title', 'noname12'));
});
