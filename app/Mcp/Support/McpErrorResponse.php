<?php

namespace App\Mcp\Support;

use App\Models\McpConnection;
use Laravel\Mcp\Response;

final class McpErrorResponse
{
    /**
     * Build a customer-safe MCP error with stable machine-readable metadata.
     *
     * @param  array<string, mixed>  $details  extra machine-readable fields, such as the live version on a conflict
     */
    public static function error(
        string $message,
        string $errorCode,
        bool $retryable = false,
        ?string $nextAction = null,
        array $details = [],
    ): Response {
        $meta = [
            'errorCode' => $errorCode,
            'retryable' => $retryable,
        ];

        if ($nextAction !== null) {
            $meta['nextAction'] = $nextAction;
        }

        if ($details !== []) {
            $meta['details'] = $details;
        }

        $connection = request()->attributes->get('mcp_connection');
        if ($connection instanceof McpConnection) {
            $meta['client'] = $connection->client_name;
            $meta['transport'] = $connection->transport;
            $meta['protocolVersion'] = $connection->protocol_version;
        }

        return Response::error($message)->withMeta('artfct', $meta);
    }
}
