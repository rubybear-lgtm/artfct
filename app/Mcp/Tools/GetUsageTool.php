<?php

namespace App\Mcp\Tools;

use App\Mcp\Support\McpContext;
use App\Mcp\Support\McpErrorResponse;
use App\Mcp\Support\McpTelemetry;
use App\Services\Billing\QuotaService;
use App\Services\Billing\UsageContract;
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

#[Description('Read the authenticated workspace usage, quota limits, and current indexing/rendering status without exposing billing credentials.')]
#[Name('get_usage')]
#[IsReadOnly]
#[IsIdempotent]
#[IsDestructive(false)]
#[IsOpenWorld(false)]
final class GetUsageTool extends Tool
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
                'requiredScopes' => ['usage:read'],
                'compatibility' => 'stable',
                'examples' => [[
                    'description' => 'Inspect current workspace usage and quota status.',
                    'arguments' => (object) [],
                ]],
            ],
        ];
    }

    /**
     * Handle the tool request.
     */
    public function handle(Request $request, QuotaService $quotaService, UsageContract $usage): Response|ResponseFactory
    {
        $startedAt = hrtime(true);
        McpContext::requireScope('usage:read', 'get_usage');

        try {
            $team = McpContext::team();
            $currentUsage = $usage->currentUsage($team->slug);
            $quota = $quotaService->status($team);
            $periodStart = $currentUsage['period_start'] ?? now()->startOfMonth()->toIso8601String();
            $periodEnd = $currentUsage['period_end'] ?? now()->startOfMonth()->addMonth()->toIso8601String();
            $response = Response::structured([
                'organization' => $team->slug,
                'period' => [
                    'name' => 'current',
                    'starts_at' => $periodStart,
                    'resets_at' => $periodEnd,
                ],
                'storage' => [
                    'used_bytes' => $quota->storageBytes,
                    'limit_bytes' => $quota->storageLimitBytes,
                    'percent' => round($quota->storagePercent * 100, 1),
                    'warning' => $quota->storageWarning,
                    'exceeded' => $quota->storageExceeded,
                ],
                'artifacts' => [
                    'used' => $quota->artifactsThisPeriod,
                    'limit' => $quota->artifactsLimit,
                    'percent' => round($quota->artifactsPercent * 100, 1),
                    'warning' => $quota->artifactsWarning,
                    'exceeded' => $quota->artifactsExceeded,
                ],
                'render_minutes' => [
                    'used' => (int) ($currentUsage['render_minutes_this_period'] ?? 0),
                ],
                'can_create' => ! $quota->anyExceeded(),
            ]);
        } catch (\Throwable $exception) {
            report($exception);
            app(McpTelemetry::class)->record('get_usage', 'error', $startedAt);

            return McpErrorResponse::error('Workspace usage is temporarily unavailable.', 'upstream_unavailable', true);
        }

        app(McpTelemetry::class)->record('get_usage', 'success', $startedAt);

        return $response;
    }

    /**
     * Get the tool's input schema.
     *
     * @return array<string, JsonSchema>
     */
    public function schema(JsonSchema $schema): array
    {
        return [];
    }
}
