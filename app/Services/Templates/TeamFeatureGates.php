<?php

namespace App\Services\Templates;

use App\Models\Team;
use App\Models\TeamFeatureGate;

/**
 * The per-team feature gate for template machinery (spec 21). Two keys:
 * `artifact-db` (a template's database needs the spec-20 surface) and
 * `artifact-templates` (the list/pull surfaces). A team is released by
 * writing a row; no row means off. Mirrors the spec-20 unit's seam (same
 * table, same key strings) so wave 2 keeps one of the two implementations.
 */
final class TeamFeatureGates
{
    public const ARTIFACT_DB = 'artifact-db';

    public const ARTIFACT_TEMPLATES = 'artifact-templates';

    public function enabled(string $key, Team $team): bool
    {
        if (! $team->exists) {
            return false;
        }

        return (bool) TeamFeatureGate::query()
            ->where('team_id', $team->id)
            ->where('key', $key)
            ->where('enabled', true)
            ->exists();
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
