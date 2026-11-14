<?php

namespace App\Mcp\Support;

use App\Services\Templates\TeamFeatureGates;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tool;

/**
 * Base for tools gated on a per-team feature release (spec 21). A tool is
 * hidden from the catalog until its feature is released for the calling
 * team; with no resolvable team (contract tests, smoke) it hides rather
 * than throwing. The in-handle guard answers `feature_not_enabled` naming
 * the missing feature, so a caller that invokes the tool by name gets a
 * refusal, not a silent absence.
 */
abstract class FeatureFlaggedTool extends Tool
{
    /** The feature key gating this tool (see TeamFeatureGates). */
    abstract protected function featureKey(): string;

    public function shouldRegister(): bool
    {
        try {
            return app(TeamFeatureGates::class)
                ->enabled($this->featureKey(), McpContext::team());
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * The refusal when the feature is not released for the calling team,
     * else null. Call it first in handle().
     */
    protected function featureNotEnabled(int $startedAt, ?string $feature = null): ?ResponseFactory
    {
        $key = $feature ?? $this->featureKey();

        try {
            $enabled = app(TeamFeatureGates::class)
                ->enabled($key, McpContext::team());
        } catch (\Throwable) {
            $enabled = false;
        }

        if ($enabled) {
            return null;
        }

        app(McpTelemetry::class)->record($this->toolName(), 'feature_not_enabled', $startedAt);

        return McpErrorResponse::error(
            "The `{$key}` feature is not enabled for this workspace yet.",
            'feature_not_enabled',
        );
    }

    private function toolName(): string
    {
        $name = $this->name();

        return $name === '' ? static::class : $name;
    }
}
