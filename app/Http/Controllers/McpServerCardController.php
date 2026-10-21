<?php

namespace App\Http\Controllers;

use App\Mcp\Servers\ArtfctServer;
use App\Support\AiToolSetup;
use Illuminate\Http\JsonResponse;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Version;
use ReflectionClass;

/**
 * `GET /.well-known/mcp/server-card.json`: the MCP Server Card (SEP-1649 /
 * SEP-2127) so agents can discover the hosted server without a directory.
 *
 * The SEP is still a draft, so the card carries both shapes in use today:
 * `serverInfo` + `transport` (what discovery scanners read) and the
 * registry's `server.json` fields (`name`, `remotes`, `websiteUrl`). Every
 * value comes from a source that already exists: the server's own name and
 * version attributes, the registry listing in `server.json`, and the tool
 * list the docs render (DocsTest keeps that equal to the registered tools).
 */
class McpServerCardController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $setup = AiToolSetup::forCurrentEnvironment();
        $listing = json_decode((string) file_get_contents(base_path('server.json')), true, flags: JSON_THROW_ON_ERROR);
        $server = new ReflectionClass(ArtfctServer::class);

        $card = [
            'serverInfo' => [
                'name' => $server->getAttributes(Name::class)[0]->getArguments()[0],
                'title' => $listing['title'],
                'version' => $server->getAttributes(Version::class)[0]->getArguments()[0],
            ],
            'name' => $listing['name'],
            'description' => $listing['description'],
            'websiteUrl' => "{$setup->baseUrl}/connect",
            'documentationUrl' => "{$setup->baseUrl}/llms-full.txt",
            'icons' => [['src' => "{$setup->baseUrl}/favicon.svg", 'mimeType' => 'image/svg+xml', 'sizes' => ['any']]],
            'transport' => [
                'type' => 'streamable-http',
                'endpoint' => $setup->mcpUrl(),
            ],
            'remotes' => [['type' => 'streamable-http', 'url' => $setup->mcpUrl()]],
            'capabilities' => [
                'tools' => ['listChanged' => false],
            ],
            'authentication' => [
                'required' => true,
                'schemes' => ['oauth2'],
                'protectedResourceMetadata' => "{$setup->baseUrl}/.well-known/oauth-protected-resource/mcp",
            ],
            'tools' => array_map(
                fn (array $tool): array => ['name' => $tool['name'], 'description' => $tool['does']],
                $setup->tools(),
            ),
        ];

        return response()->json($card, options: JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)
            ->header('Access-Control-Allow-Origin', '*')
            ->header('Cache-Control', 'public, max-age=3600');
    }
}
