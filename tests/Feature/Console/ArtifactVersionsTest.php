<?php

use App\Contracts\ArtifactDirectory;
use App\Enums\AuditEventType;
use App\Enums\TeamRole;
use App\Models\AuditEvent;
use App\Models\Team;
use App\Models\User;
use App\Services\Artifacts\FakeArtifactDirectory;
use Inertia\Testing\AssertableInertia as Assert;

/** A 13-character base36 id: the shape a permanent, versioned artifact has. */
const VERSIONED_ARTIFACT_ID = 'abc123def4567';

/**
 * One team with a member and a seeded three-version artifact. Version 3 is
 * current and was published by the member; version 2 by someone who has since
 * left (no display name); version 1 has no recorded publisher.
 *
 * @return array{0: Team, 1: User, 2: FakeArtifactDirectory}
 */
function versionedArtifact(): array
{
    $team = Team::factory()->create(['slug' => 'rub-437-console']);
    $member = memberOfTeam($team, TeamRole::Member);

    /** @var FakeArtifactDirectory $directory */
    $directory = app(ArtifactDirectory::class);
    $directory->seedArtifact([
        'id' => VERSIONED_ARTIFACT_ID,
        'org_id' => $team->slug,
        'user_id' => $member->id,
        'title' => 'Quarterly report',
        'description' => 'The quarterly report.',
        'content_hash' => str_repeat('a', 64),
        'created_at' => now()->subDays(3)->toIso8601String(),
        'revoked_at' => null,
        'provenance' => ['agent' => 'claude-code', 'repo_url' => null],
    ]);
    $directory->seedVersions(VERSIONED_ARTIFACT_ID, [
        [
            'version' => 3, 'created_at' => now()->subDay()->toIso8601String(),
            'created_by' => (string) $member->id, 'agent' => 'claude-code',
            'title' => 'Quarterly report v3', 'current' => true, 'restored_from' => null,
        ],
        [
            'version' => 2, 'created_at' => now()->subDays(2)->toIso8601String(),
            'created_by' => '999999', 'agent' => 'cursor',
            'title' => 'Quarterly report v2', 'current' => false, 'restored_from' => 1,
        ],
        [
            'version' => 1, 'created_at' => now()->subDays(3)->toIso8601String(),
            'created_by' => null, 'agent' => null,
            'title' => 'Quarterly report v1', 'current' => false, 'restored_from' => null,
        ],
    ], 3);

    return [$team, $member, $directory];
}

function versionsUrl(Team $team, string $artifactId = VERSIONED_ARTIFACT_ID): string
{
    return route('console.versions', ['team' => $team->slug, 'artifactId' => $artifactId]);
}

function restoreUrl(Team $team, int $version, string $artifactId = VERSIONED_ARTIFACT_ID): string
{
    return route('console.versions.restore', [
        'team' => $team->slug,
        'artifactId' => $artifactId,
        'version' => $version,
    ]);
}

test('a member sees the artifact version history newest first', function () {
    [$team, $member] = versionedArtifact();

    test()->actingAs($member)->get(versionsUrl($team))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('console/artifact-versions')
            ->where('team.slug', $team->slug)
            ->where('team.name', $team->name)
            ->where('artifactId', VERSIONED_ARTIFACT_ID)
            ->where('title', 'Quarterly report v3')
            ->where('currentVersion', 3)
            ->has('versions', 3)
            ->where('versions.0.number', 3)
            ->where('versions.0.current', true)
            // The publisher is named from the team's members...
            ->where('versions.0.created_by', $member->name)
            ->where('versions.1.number', 2)
            ->where('versions.1.restored_from', 1)
            // ...but a publisher who has left the team, or none recorded, is null.
            ->where('versions.1.created_by', null)
            ->where('versions.2.created_by', null)
            ->where('versions.2.title', 'Quarterly report v1'));
});

test('a version is addressed on the open route by a version query parameter', function () {
    [$team, $member] = versionedArtifact();

    // Each row's View control links through the console's open route with
    // `?version=n`, built client-side (the rendered href itself is asserted in
    // tests/Browser/ArtifactVersionsBrowserTest.php). This pins the parameter
    // name the route reads and that the page passes, and the page's own rows.
    expect(route('console.open', [
        'team' => $team->slug,
        'artifactId' => VERSIONED_ARTIFACT_ID,
        'version' => 2,
    ]))->toContain('version=2');

    test()->actingAs($member)->get(versionsUrl($team))
        ->assertInertia(fn (Assert $page) => $page
            ->where('versions.0.number', 3)
            ->where('versions.1.number', 2)
            ->where('versions.2.number', 1));
});

test('a non-member gets the same 404 as a missing team', function () {
    [$team] = versionedArtifact();
    $outsider = memberOfTeam(Team::factory()->create(), TeamRole::Member);

    test()->actingAs($outsider)->get(versionsUrl($team))->assertNotFound();
});

test('an id that is not a permanent artifact is a 404', function () {
    [$team, $member] = versionedArtifact();

    test()->actingAs($member)
        ->get(versionsUrl($team, 'shortid'))
        ->assertNotFound();

    test()->actingAs($member)
        ->get(versionsUrl($team, '0123456789abcdef0123456789abcdef'))
        // A legacy 32-hex id is permanent, but the fake has no history for it.
        ->assertNotFound();
});

test('restoring a past version flashes success and audits it', function () {
    [$team, $member] = versionedArtifact();

    test()->actingAs($member)
        ->post(restoreUrl($team, 2))
        ->assertRedirect(versionsUrl($team))
        ->assertSessionHas('inertia.flash_data.toast', [
            'type' => 'success',
            'message' => 'Restored version 2.',
        ]);

    expect(AuditEvent::query()
        ->where('team_id', $team->id)
        ->where('event_type', AuditEventType::ArtifactVersionRestored)
        ->where('actor', (string) $member->id)
        ->where('target', 'artifact:'.VERSIONED_ARTIFACT_ID.' version:2')
        ->where('outcome', 'success')
        ->exists())->toBeTrue();
});

test('a restore the worker forbids explains who may restore it', function () {
    [$team, $member, $directory] = versionedArtifact();
    $directory->failRestore(VERSIONED_ARTIFACT_ID, 'forbidden');

    test()->actingAs($member)->post(restoreUrl($team, 2))
        ->assertRedirect(versionsUrl($team))
        ->assertSessionHas('inertia.flash_data.toast', [
            'type' => 'error',
            'message' => 'Only the owner can restore this artifact.',
        ]);

    expect(AuditEvent::query()->where('event_type', AuditEventType::ArtifactVersionRestored)->exists())->toBeFalse();
});

test('a concurrent restore asks the caller to try again', function () {
    [$team, $member, $directory] = versionedArtifact();
    $directory->failRestore(VERSIONED_ARTIFACT_ID, 'conflict');

    test()->actingAs($member)->post(restoreUrl($team, 2))
        ->assertRedirect(versionsUrl($team))
        ->assertSessionHas('inertia.flash_data.toast', [
            'type' => 'error',
            'message' => 'Someone else published a version at the same moment. Try again.',
        ]);
});

test('restoring the current version is a no-op that says so', function () {
    [$team, $member] = versionedArtifact();

    test()->actingAs($member)->post(restoreUrl($team, 3))
        ->assertRedirect(versionsUrl($team))
        ->assertSessionHas('inertia.flash_data.toast', [
            'type' => 'success',
            'message' => 'That version is already current.',
        ]);

    expect(AuditEvent::query()->where('event_type', AuditEventType::ArtifactVersionRestored)->exists())->toBeFalse();
});

test('restoring a version that does not exist flashes not found', function () {
    [$team, $member] = versionedArtifact();

    test()->actingAs($member)->post(restoreUrl($team, 9))
        ->assertRedirect(versionsUrl($team))
        ->assertSessionHas('inertia.flash_data.toast', [
            'type' => 'error',
            'message' => 'That version was not found.',
        ]);
});

test('a restore of a non-permanent artifact is a 404', function () {
    [$team, $member] = versionedArtifact();

    test()->actingAs($member)
        ->post(restoreUrl($team, 2, 'shortid'))
        ->assertNotFound();
});

test('the console index carries the data the History link is conditioned on', function () {
    [$team, $member] = versionedArtifact();

    /** @var FakeArtifactDirectory $directory */
    $directory = app(ArtifactDirectory::class);
    $directory->seedArtifact([
        'id' => 'revokedabcdef',
        'org_id' => $team->slug,
        'user_id' => $member->id,
        'title' => 'Quarterly report (revoked)',
        'description' => 'A withdrawn report.',
        'content_hash' => str_repeat('b', 64),
        'created_at' => now()->subDay()->toIso8601String(),
        'revoked_at' => now()->toIso8601String(),
        'provenance' => ['agent' => 'cursor', 'repo_url' => null],
    ]);

    test()->actingAs($member)
        ->get(route('console.index', $team).'?q=Quarterly+report')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('console/index')
            // The live row renders a History link (`revoked_at === null`); the
            // revoked row renders none. The anchor itself is client-rendered, so
            // the DOM half of this assertion lives in
            // tests/Browser/ArtifactVersionsBrowserTest.php.
            ->where('artifacts.0.revoked_at', null)
            ->where('artifacts.1.revoked_at', fn ($value): bool => $value !== null));
});
