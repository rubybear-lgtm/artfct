<?php

namespace App\Mcp\Support;

use App\Models\McpConnection;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;

final class McpErrorResponse
{
    /**
     * Build a customer-safe MCP error with stable machine-readable metadata.
     *
     * Errors expose both content `_meta.artfct` (legacy clients) and top-level
     * `structuredContent` with `code` plus any detail fields (RUB-516). Laravel
     * MCP only attaches `structuredContent` on a ResponseFactory, so this
     * returns a factory wrapping an error Response rather than a bare error.
     *
     * @param  array<string, mixed>  $details  extra machine-readable fields, such as the live version on a conflict
     */
    public static function error(
        string $message,
        string $errorCode,
        bool $retryable = false,
        ?string $nextAction = null,
        array $details = [],
    ): ResponseFactory {
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

        $structured = array_merge([
            'code' => $errorCode,
            'retryable' => $retryable,
        ], $details);

        if ($nextAction !== null) {
            $structured['next_action'] = $nextAction;
        }

        return Response::make(Response::error($message)->withMeta('artfct', $meta))
            ->withStructuredContent($structured);
    }
}
