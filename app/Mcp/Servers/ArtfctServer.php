<?php

namespace App\Mcp\Servers;

use App\Mcp\Tools\AddCollectionArtifactTool;
use App\Mcp\Tools\CreateCollectionTool;
use App\Mcp\Tools\DeployToCanvasTool;
use App\Mcp\Tools\GetArtifactTool;
use App\Mcp\Tools\GetConnectionTool;
use App\Mcp\Tools\GetUsageTool;
use App\Mcp\Tools\ListCollectionsTool;
use App\Mcp\Tools\SearchArtifactsTool;
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Attributes\Instructions;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Version;

#[Name('artfct')]
#[Version('1.0.0')]
#[Instructions('Publish encrypted HTML artifacts and search the authenticated workspace.')]
final class ArtfctServer extends Server
{
    /**
     * Advertise only capabilities that this server actually implements.
     */
    protected array $capabilities = [
        self::CAPABILITY_TOOLS => [
            'listChanged' => false,
        ],
    ];

    protected array $tools = [
        DeployToCanvasTool::class,
        SearchArtifactsTool::class,
        GetConnectionTool::class,
        GetUsageTool::class,
        GetArtifactTool::class,
        ListCollectionsTool::class,
        CreateCollectionTool::class,
        AddCollectionArtifactTool::class,
    ];

    protected array $resources = [
        //
    ];

    protected array $prompts = [
        //
    ];
}
