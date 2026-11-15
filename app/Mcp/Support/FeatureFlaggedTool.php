<?php

namespace App\Mcp\Support;

use App\Services\FeatureFlags;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tool;

abstract class FeatureFlaggedTool extends Tool
{
    abstract protected function featureKey(): string;

    public function shouldRegister(): bool
    {
        return app(FeatureFlags::class)->enabled($this->featureKey(), McpContext::team());
    }

    /**
     * The refusal when the feature is not released for the calling team,
     * else null. Call it first in handle(): it covers the window between
     * listing (gated by shouldRegister) and the call.
     */
    protected function featureNotEnabled(int $startedAt, ?string $feature = null): ?ResponseFactory
    {
        $key = $feature ?? $this->featureKey();

        try {
            $enabled = app(FeatureFlags::class)->enabled($key, McpContext::team());
        } catch (\Throwable) {
            $enabled = false;
        }

        if ($enabled) {
            return null;
        }

        app(McpTelemetry::class)->record($this->toolName($key), 'feature_not_enabled', $startedAt);

        return McpErrorResponse::error(
            "The `{$key}` feature is not enabled in this workspace yet.",
            'feature_not_enabled',
        );
    }

    private function toolName(string $feature): string
    {
        $name = $this->name();

        return $name === '' ? $feature : $name;
    }
}
