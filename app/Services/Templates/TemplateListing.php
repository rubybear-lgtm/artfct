<?php

namespace App\Services\Templates;

use App\Models\Team;
use App\Services\FeatureFlags;

/**
 * The template rows shown to a team (spec 21). The MCP `list_templates` tool
 * and the library console section share this shape, so the two surfaces can
 * never disagree about what a team may pull.
 */
final class TemplateListing
{
    /**
     * @return list<array{key: string, title: string, description: string, version: int, artifact_type: string, requires: list<string>, available: bool, unavailable_reason: ?string}>
     */
    public static function summaries(TemplateCatalog $catalog, FeatureFlags $gates, Team $team): array
    {
        $rows = [];

        foreach ($catalog->all() as $template) {
            $missing = [];

            foreach ($template->requires as $feature) {
                if (! $gates->enabled($feature, $team)) {
                    $missing[] = $feature;
                }
            }

            $rows[] = [
                'key' => $template->key,
                'title' => $template->title,
                'description' => $template->description,
                'version' => $template->version,
                'artifact_type' => $template->artifactType,
                'requires' => $template->requires,
                'available' => $missing === [],
                'unavailable_reason' => $missing === [] ? null : 'Requires features not released for this workspace: '.implode(', ', $missing).'.',
            ];
        }

        return $rows;
    }
}
