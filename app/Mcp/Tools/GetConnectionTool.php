<?php

namespace App\Mcp\Tools;

use App\Mcp\Support\McpContext;
use App\Mcp\Support\McpTelemetry;
use App\Mcp\Support\McpTimestamp;
use App\Models\ArtifactIndexEntry;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsOpenWorld;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Description('Inspect the authenticated artfct MCP connection without exposing credentials.')]
#[Name('get_connection')]
#[Title('Show connection')]
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
        $orgToken = McpContext::orgToken();
        $agentName = McpContext::agentName();
        $isAgentToken = $orgToken?->isAgent() === true;

        $response = Response::structured([
            'authenticated' => true,
            'organization' => $team->slug,
            'user_id' => $claims['user_id'],
            'scopes' => preg_split('/\s+/', trim((string) ($claims['scope'] ?? ''))) ?: [],
            'credential_source' => $isAgentToken ? 'agent_token' : 'bearer',
            'agent' => $isAgentToken ? [
                'name' => $agentName,
                'bot' => true,
            ] : null,
            'client' => $httpRequest->header('MCP-Client-Name') ?? $agentName,
            'session_id' => $request->sessionId(),
            'search' => $this->searchReadiness($team->id),
        ]);

        app(McpTelemetry::class)->record('get_connection', 'success', $startedAt);

        return $response;
    }

    /**
     * Whether search works for this workspace, so a caller can tell a disabled
     * search from one that is on but has nothing indexed yet.
     *
     * @return array{enabled: bool, indexed_artifacts: int, last_indexed_at: string|null}
     */
    private function searchReadiness(int $teamId): array
    {
        // Read the latest row so `extracted_at` moves as soon as indexing
        // writes a new entry (RUB-515 item 6), not a stale aggregate cache.
        $latest = ArtifactIndexEntry::query()
            ->where('team_id', $teamId)
            ->orderByDesc('extracted_at')
            ->orderByDesc('id')
            ->first();

        return [
            'enabled' => (bool) config('indexing.enabled'),
            'indexed_artifacts' => ArtifactIndexEntry::query()->where('team_id', $teamId)->count(),
            'last_indexed_at' => $latest === null
                ? null
                : McpTimestamp::format($latest->extracted_at),
        ];
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
