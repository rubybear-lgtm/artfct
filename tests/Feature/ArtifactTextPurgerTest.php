<?php

use App\Models\ArtifactComment;
use App\Models\ArtifactIndexEntry;
use App\Models\ArtifactIndexingFailure;
use App\Models\Team;
use App\Services\Indexing\ArtifactTextPurger;

function purgeFixture(Team $team, string $artifactId): void
{
    ArtifactIndexEntry::factory()->create(['team_id' => $team->id, 'artifact_id' => $artifactId]);
    ArtifactIndexingFailure::query()->create(['team_id' => $team->id, 'artifact_id' => $artifactId, 'reason' => 'x', 'attempts' => 1, 'failed_at' => now()]);
    ArtifactComment::factory()->create(['team_id' => $team->id, 'artifact_id' => $artifactId]);
}

test('a revoke purge removes index text but keeps comments', function () {
    $team = Team::factory()->create(['slug' => 'purge-revoke']);
    purgeFixture($team, 'abc123def4567');

    ArtifactTextPurger::purgeIndexText($team, 'abc123def4567');

    expect(ArtifactIndexEntry::count())->toBe(0)
        ->and(ArtifactIndexingFailure::count())->toBe(0)
        ->and(ArtifactComment::count())->toBe(1);
});

test('a hard-delete purge removes comments too and never touches another artifact or team', function () {
    $team = Team::factory()->create(['slug' => 'purge-delete']);
    $other = Team::factory()->create(['slug' => 'purge-other']);
    purgeFixture($team, 'abc123def4567');
    purgeFixture($team, 'zzz123def4567');
    purgeFixture($other, 'abc123def4567');

    ArtifactTextPurger::purgeEverything($team, 'abc123def4567');

    expect(ArtifactComment::where('team_id', $team->id)->pluck('artifact_id')->all())->toBe(['zzz123def4567'])
        ->and(ArtifactIndexEntry::where('team_id', $other->id)->count())->toBe(1)
        ->and(ArtifactComment::where('team_id', $other->id)->count())->toBe(1);
});
