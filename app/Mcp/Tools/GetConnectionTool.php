<?php

namespace App\Mcp\Tools;

use App\Mcp\Support\McpContext;
use App\Mcp\Support\McpTelemetry;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsOpenWorld;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Description('Inspect the authenticated artfct MCP connection without exposing credentials.')]
#[Name('get_connection')]
#[IsReadOnly]
#[IsIdempotent]
#[IsDestructive(false)]
#[IsOpenWorld(false)]
final class GetConnectionTool extends Tool
{
    /** @var array<string, mixed>|null */
    protected ?array $meta = null;

    public function __construct()
    {
        $this->meta = [
            'artfct' => [
                'contractVersion' => '1.0.0',
                'toolVersion' => '1.0.0',
                'owner' => 'artfct-mcp',
                'requiredScopes' => [],
                'compatibility' => 'stable',
                'examples' => [[
                    'description' => 'Inspect the current authenticated workspace and client.',
                    'arguments' => (object) [],
                ]],
            ],
        ];
    }

    /**
     * Handle the tool request.
     */
    public function handle(Request $request): ResponseFactory
    {
        $startedAt = hrtime(true);
        $claims = McpContext::claims();
        $team = McpContext::team();
        $httpRequest = McpContext::httpRequest();

        $response = Response::structured([
            'authenticated' => true,
            'organization' => $team->slug,
            'user_id' => $claims['user_id'],
            'scopes' => preg_split('/\s+/', trim((string) ($claims['scope'] ?? ''))) ?: [],
            'credential_source' => 'bearer',
            'client' => $httpRequest->header('MCP-Client-Name'),
            'session_id' => $request->sessionId(),
        ]);

        app(McpTelemetry::class)->record('get_connection', 'success', $startedAt);

        return $response;
    }

    /**
     * Get the tool's input schema.
     *
     * @return array<string, JsonSchema>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
        ];
    }
}
