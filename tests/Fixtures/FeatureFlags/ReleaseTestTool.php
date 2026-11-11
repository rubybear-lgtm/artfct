<?php

namespace Tests\Fixtures\FeatureFlags;

use App\Mcp\Support\FeatureFlaggedTool;
use Laravel\Mcp\Response;

class ReleaseTestTool extends FeatureFlaggedTool
{
    protected function featureKey(): string
    {
        return 'test-feature';
    }

    public function handle(): Response
    {
        return Response::text('Feature executed');
    }
}
