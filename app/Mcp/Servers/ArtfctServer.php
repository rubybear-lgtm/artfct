<?php

namespace App\Mcp\Servers;

use App\Mcp\Support\ArtifactGuidance;
use App\Mcp\Tools\AddArtifactCommentTool;
use App\Mcp\Tools\AddCollectionArtifactTool;
use App\Mcp\Tools\CreateCollectionTool;
use App\Mcp\Tools\DeleteArtifactTool;
use App\Mcp\Tools\DeployArtifactTool;
use App\Mcp\Tools\GetArtifactTool;
use App\Mcp\Tools\GetConnectionTool;
use App\Mcp\Tools\GetGuidanceTool;
use App\Mcp\Tools\GetUsageTool;
use App\Mcp\Tools\ListArtifactCommentsTool;
use App\Mcp\Tools\ListArtifactsTool;
use App\Mcp\Tools\ListCollectionsTool;
use App\Mcp\Tools\ReadArtifactTool;
use App\Mcp\Tools\ResolveArtifactCommentTool;
use App\Mcp\Tools\SearchArtifactsTool;
use App\Mcp\Tools\SetAvatarTool;
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Attributes\Instructions;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Version;

#[Name('artfct')]
#[Version('1.0.0')]
#[Instructions(ArtifactGuidance::INSTRUCTIONS)]
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

    /**
     * One page holds the whole catalog: clients that ignore nextCursor would
     * otherwise silently lose the tools past the default 15.
     */
    public int $defaultPaginationLength = 50;

    protected array $tools = [
        DeployArtifactTool::class,
        SearchArtifactsTool::class,
        GetConnectionTool::class,
        GetGuidanceTool::class,
        ListArtifactsTool::class,
        GetUsageTool::class,
        GetArtifactTool::class,
        ReadArtifactTool::class,
        ListArtifactCommentsTool::class,
        AddArtifactCommentTool::class,
        ResolveArtifactCommentTool::class,
        ListCollectionsTool::class,
        CreateCollectionTool::class,
        AddCollectionArtifactTool::class,
        DeleteArtifactTool::class,
        SetAvatarTool::class,
    ];

    protected array $resources = [
        //
    ];

    protected array $prompts = [
        //
    ];
}
