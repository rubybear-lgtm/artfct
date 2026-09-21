<?php

namespace App\Mcp\Support;

use App\Models\McpActivity;
use App\Models\McpConnection;
use Throwable;

final class McpTelemetry
{
    /**
     * Record a bounded, non-sensitive summary of one MCP tool invocation.
     * Telemetry failures must never turn a successful tool call into an MCP
     * error, so persistence is deliberately best effort.
     */
    public function record(string $tool, string $outcome, int $startedAt, ?string $artifactId = null): void
    {
        try {
            $claims = McpContext::claims();
            $team = McpContext::team();
            $request = McpContext::httpRequest();
            $connection = $request->attributes->get('mcp_connection');

            McpActivity::create([
                'team_id' => $team->id,
                'credential_jti' => $claims['jti'] ?? null,
                'actor' => $claims['user_id'] ?? 'unknown',
                'tool' => $tool,
                'artifact_id' => $artifactId,
                'transport' => 'streamable_http',
                'request_id' => $request->header('MCP-Request-Id') ?? $request->header('X-Request-Id'),
                'session_id' => $request->header('MCP-Session-Id'),
                'client_name' => $connection instanceof McpConnection
                    ? $connection->client_name
                    : $request->header('MCP-Client-Name'),
                'outcome' => $outcome,
                'latency_ms' => max(0, (int) round((hrtime(true) - $startedAt) / 1_000_000)),
                'created_at' => now(),
            ]);
        } catch (Throwable $exception) {
            report($exception);
        }
    }
}
