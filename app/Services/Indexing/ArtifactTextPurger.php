<?php

namespace App\Services\Indexing;

use App\Models\ArtifactComment;
use App\Models\ArtifactIndexEntry;
use App\Models\ArtifactIndexingFailure;
use App\Models\ArtifactTypeAssignment;
use App\Models\Team;

/**
 * Removes the stored text an artifact left behind: the extracted text and
 * indexing failures (on revoke or delete), and its comments and type
 * (on hard delete, erasure and retention, where the artifact is gone for good).
 */
final class ArtifactTextPurger
{
    public static function purgeIndexText(Team $team, string $artifactId): void
    {
        ArtifactIndexEntry::query()->where('team_id', $team->id)->where('artifact_id', $artifactId)->delete();
        ArtifactIndexingFailure::query()->where('team_id', $team->id)->where('artifact_id', $artifactId)->delete();
    }

    public static function purgeEverything(Team $team, string $artifactId): void
    {
        self::purgeIndexText($team, $artifactId);
        ArtifactComment::query()->where('team_id', $team->id)->where('artifact_id', $artifactId)->delete();
        ArtifactTypeAssignment::query()->where('team_id', $team->id)->where('artifact_id', $artifactId)->delete();
    }
}
