<?php

namespace App\Services\Artifacts;

use App\Enums\ArtifactType;
use App\Models\ArtifactTypeAssignment;
use App\Models\Team;
use App\Services\Indexing\ExtractionHeuristics;

/**
 * Decides and stores an artifact's type: the publisher's declared type wins,
 * otherwise it is inferred from the published HTML.
 */
final class ArtifactTypeResolver
{
    /**
     * Cheap, deterministic inference; a declared type always overrides it.
     */
    public function infer(string $html): ArtifactType
    {
        // Markdown (including a .md entrypoint before HTML conversion) is a
        // document, not "other".
        if (ExtractionHeuristics::looksLikeMarkdown($html)) {
            return ArtifactType::Document;
        }

        $lower = strtolower($html);
        $tables = substr_count($lower, '<table');
        $headings = preg_match_all('/<h[1-3][\s>]/', $lower);
        $sections = substr_count($lower, '<section');

        if (preg_match('/class="[^"]*\bslides?\b|data-slide|reveal\.js|<section[^>]*class="[^"]*slide/', $lower) === 1) {
            return ArtifactType::SlideDeck;
        }

        if (str_contains($lower, 'dashboard') || (str_contains($lower, '<canvas') && $tables > 0) || substr_count($lower, '<svg') >= 3) {
            return ArtifactType::Dashboard;
        }

        if ($tables > 0 && $tables >= max(1, (int) ($headings / 2))) {
            return ArtifactType::Table;
        }

        if (str_contains($lower, 'wireframe') || str_contains($lower, 'mockup') || str_contains($lower, 'prototype')) {
            return ArtifactType::Mockup;
        }

        if ($headings >= 3 || $sections >= 3) {
            return ArtifactType::Report;
        }

        if ($headings >= 1) {
            return ArtifactType::Document;
        }

        return ArtifactType::Other;
    }

    /**
     * Records the type for a publish. A new version without a declared type
     * keeps an earlier declared type and otherwise re-infers.
     */
    public function record(Team $team, string $artifactId, string $html, ?ArtifactType $declared): ArtifactType
    {
        $existing = ArtifactTypeAssignment::query()
            ->where('team_id', $team->id)
            ->where('artifact_id', $artifactId)
            ->first();

        if ($declared !== null) {
            $type = $declared;
            $isDeclared = true;
        } elseif ($existing !== null && $existing->declared) {
            return $existing->artifact_type;
        } else {
            $type = $this->infer($html);
            $isDeclared = false;
        }

        ArtifactTypeAssignment::query()->updateOrCreate(
            ['team_id' => $team->id, 'artifact_id' => $artifactId],
            ['artifact_type' => $type, 'declared' => $isDeclared],
        );

        return $type;
    }

    /**
     * @param  list<string>  $artifactIds
     * @return array<string, string> artifact id => type value
     */
    public function typesFor(Team $team, array $artifactIds): array
    {
        if ($artifactIds === []) {
            return [];
        }

        return ArtifactTypeAssignment::query()
            ->where('team_id', $team->id)
            ->whereIn('artifact_id', $artifactIds)
            ->get()
            ->mapWithKeys(fn (ArtifactTypeAssignment $row): array => [$row->artifact_id => $row->artifact_type->value])
            ->all();
    }

    /**
     * @return list<string> ids of the team's artifacts of one type
     */
    public function artifactIdsOfType(Team $team, ArtifactType $type): array
    {
        return ArtifactTypeAssignment::query()
            ->where('team_id', $team->id)
            ->where('artifact_type', $type->value)
            ->orderByDesc('updated_at')
            ->pluck('artifact_id')
            ->all();
    }
}
