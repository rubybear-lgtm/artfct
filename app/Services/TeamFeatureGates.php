<?php

namespace App\Services;

use App\Models\Team;
use App\Models\TeamFeatureGate;

/**
 * Minimal per-team feature gate for the spec-20 database surface (wave 1).
 * Semantics mirror the shared model: string key, per-team release, default
 * off. `artifact-templates` is a placeholder key the template units will
 * release; this unit only reads `artifact-db`.
 */
final class TeamFeatureGates
{
    public const ARTIFACT_DB = 'artifact-db';

    public const ARTIFACT_TEMPLATES = 'artifact-templates';

    public function enabled(string $key, Team $team): bool
    {
        if (! $team->exists || (method_exists($team, 'trashed') && $team->trashed())) {
            return false;
        }

        return (bool) TeamFeatureGate::query()
            ->where('team_id', $team->id)
            ->where('key', $key)
            ->value('enabled');
    }

    public function enable(Team $team, string $key): void
    {
        TeamFeatureGate::query()->updateOrCreate(
            ['team_id' => $team->id, 'key' => $key],
            ['enabled' => true],
        );
    }

    public function disable(Team $team, string $key): void
    {
        TeamFeatureGate::query()->updateOrCreate(
            ['team_id' => $team->id, 'key' => $key],
            ['enabled' => false],
        );
    }
}
