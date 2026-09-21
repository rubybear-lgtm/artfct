<?php

namespace App\Mcp\Support;

use Laravel\Mcp\Response;

final class McpErrorResponse
{
    /**
     * Build a customer-safe MCP error with stable machine-readable metadata.
     */
    public static function error(
        string $message,
        string $errorCode,
        bool $retryable = false,
        ?string $nextAction = null,
    ): Response {
        $meta = [
            'errorCode' => $errorCode,
            'retryable' => $retryable,
        ];

        if ($nextAction !== null) {
            $meta['nextAction'] = $nextAction;
        }

        return Response::error($message)->withMeta('artfct', $meta);
    }
}
