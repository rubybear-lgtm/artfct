<?php

namespace App\Mcp\Servers;

use App\Mcp\Tools\AddCollectionArtifactTool;
use App\Mcp\Tools\CreateCollectionTool;
use App\Mcp\Tools\DeployArtifactTool;
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
#[Instructions('Publish HTML artifacts to the authenticated workspace, then search, retrieve and organize them. deploy_to_canvas is deprecated: it creates anonymous expiring artifacts the workspace cannot search.')]
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
        DeployArtifactTool::class,
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
