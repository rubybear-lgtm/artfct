<?php

namespace Tests\Fixtures\FeatureFlags;

use Laravel\Mcp\Server;

class ReleaseTestServer extends Server
{
    protected array $tools = [ReleaseTestTool::class];
}
