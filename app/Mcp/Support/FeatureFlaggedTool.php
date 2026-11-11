<?php

namespace App\Mcp\Support;

use App\Services\FeatureFlags;
use Laravel\Mcp\Server\Tool;

abstract class FeatureFlaggedTool extends Tool
{
    abstract protected function featureKey(): string;

    public function shouldRegister(): bool
    {
        return app(FeatureFlags::class)->enabled($this->featureKey(), McpContext::team());
    }
}
